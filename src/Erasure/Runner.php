<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\DataMap\Retain;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\StepStatus;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\PlanBlocked;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestStep;
use Asignua\FilamentGdprRequests\Services\RequestLog;
use Asignua\FilamentGdprRequests\Support\Environment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs ONE group of an erasure inside ONE transaction. The request row is locked first (FOR UPDATE), so two
 * workers on the same request take turns and the second sees the step already settled. The rows change and the
 * step is marked in the same transaction: a crash leaves either both or neither, and a rerun continues forward.
 * There is no rollback of a settled group and no copy of the old data.
 */
final class Runner
{
    public function __construct(private readonly Planner $planner, private readonly Applier $applier) {}

    public function runGroup(GdprRequest $request, string $groupKey): void
    {
        Environment::assertSupported();

        try {
            /** @var list<array{disk: string, path: string}> $files */
            $files = DB::transaction(fn (): array => $this->inTransaction($request, $groupKey));
        } catch (Throwable $e) {
            $this->recordFailure($request, $groupKey, $e);

            throw $e;
        }

        $this->removeFiles($request, $groupKey, $files);
    }

    /**
     * @return list<array{disk: string, path: string}> files to remove after the commit
     */
    private function inTransaction(GdprRequest $request, string $groupKey): array
    {
        /** @var GdprRequest $locked */
        $locked = GdprRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== RequestStatus::Erasing) {
            throw InvalidTransition::translated('not_erasing');
        }

        $step = GdprRequestStep::query()->firstOrNew(['request_id' => $locked->getKey(), 'group_key' => $groupKey]);
        $detail = $this->detail($step);

        if ($step->exists && $step->status->isSettled() && ($detail['files_pending'] ?? []) === []) {
            return [];
        }

        $locked->forceFill(['erasure_heartbeat_at' => now()])->save();

        // Rows of this group were already changed and committed earlier; only the file cleanup is left.
        if ($step->exists && ($detail['rows_done'] ?? false) === true) {
            return $detail['files_pending'] ?? [];
        }

        $plan = $this->planner->forRequest($locked, $groupKey);
        $group = $plan->group($groupKey);

        if ($group === null) {
            throw InvalidTransition::translated('group_unknown');
        }

        if ($plan->blockers !== [] || $group->isBlocked()) {
            throw new PlanBlocked([...$plan->blockers, ...$group->blockers]);
        }

        $ceiling = $locked->erasure_plan['counts'][$groupKey] ?? null;

        if ($ceiling === null || $group->matched > (int) $ceiling) {
            throw InvalidTransition::translated('plan_changed');
        }

        $strategy = $group->entry->getErase();
        $result = ['matched' => $group->matched, 'changed' => 0, 'trashed' => $group->trashed, 'files' => []];
        $status = StepStatus::Done;
        $note = null;

        if ($strategy === null) {
            $status = StepStatus::Skipped;
        } elseif ($strategy instanceof Retain) {
            $status = StepStatus::Retained;
            $note = ['basis' => $strategy->basis, 'until' => $group->retainedUntil];
        } else {
            $result = $this->applier->apply($group->selection, $strategy, (int) config('filament-gdpr-requests.erasure.chunk', 200));
        }

        $step->forceFill([
            'label' => $group->label(),
            'strategy' => $group->action,
            'status' => $status,
            'matched' => $result['matched'],
            'changed' => $result['changed'],
            'trashed' => $result['trashed'],
            'attempts' => $step->attempts + 1,
            'error' => null,
            'finished_at' => now(),
            'detail' => $this->encode(['rows_done' => true, 'files_pending' => $result['files']] + ($note ?? [])),
        ])->save();

        RequestLog::record($locked, 'erasure_group_done', null, [
            'group' => $groupKey,
            'action' => $group->action,
            'matched' => $result['matched'],
            'changed' => $result['changed'],
        ]);

        return $result['files'];
    }

    /**
     * @param list<array{disk: string, path: string}> $files
     */
    private function removeFiles(GdprRequest $request, string $groupKey, array $files): void
    {
        if ($files === []) {
            return;
        }

        $left = [];

        foreach ($files as $file) {
            try {
                $disk = Storage::disk($file['disk']);

                if ($disk->exists($file['path']) && !$disk->delete($file['path'])) {
                    $left[] = $file;
                }
            } catch (Throwable) {
                $left[] = $file;
            }
        }

        $step = GdprRequestStep::query()->where(['request_id' => $request->getKey(), 'group_key' => $groupKey])->firstOrFail();
        $detail = $this->detail($step);
        $detail['files_pending'] = $left;
        $step->forceFill(['detail' => $this->encode($detail)]);

        if ($left === []) {
            $step->save();

            return;
        }

        $step->forceFill(['status' => StepStatus::Failed, 'error' => count($left).' file(s) could not be removed; see the pending list'])->save();
        $this->markPartial($request, $groupKey, 'files');

        throw GdprException::translated('files_not_removed', ['count' => count($left)]);
    }

    private function recordFailure(GdprRequest $request, string $groupKey, Throwable $e): void
    {
        $message = Failures::describe($e);

        $step = GdprRequestStep::query()->firstOrNew(['request_id' => $request->getKey(), 'group_key' => $groupKey]);
        $step->forceFill([
            'label' => $step->label ?? $groupKey,
            'strategy' => $step->strategy ?? '',
            'status' => StepStatus::Failed,
            'attempts' => ($step->attempts ?? 0) + 1,
            'error' => $message,
        ])->save();

        $this->markPartial($request, $groupKey, class_basename($e));
    }

    private function markPartial(GdprRequest $request, string $groupKey, string $reason): void
    {
        GdprRequest::query()->whereKey($request->getKey())->where('status', RequestStatus::Erasing->value)
            ->update(['status' => RequestStatus::Partial->value]);

        RequestLog::record($request, 'erasure_group_failed', null, ['group' => $groupKey, 'reason' => $reason]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(GdprRequestStep $step): array
    {
        $decoded = $step->detail === null ? null : json_decode($step->detail, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
