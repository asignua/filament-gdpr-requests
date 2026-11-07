<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\DataMap\Delete;
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
use Asignua\FilamentGdprRequests\Support\Files;
use Illuminate\Support\Facades\DB;
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
            /** @var list<array{disk: string, path: string}>|null $files */
            $files = DB::transaction(fn (): ?array => $this->inTransaction($request, $groupKey));
        } catch (Throwable $e) {
            $this->recordFailure($request->getKey(), $groupKey, $e);

            throw $e;
        }

        if ($files !== null) {
            $this->removeFiles($request, $groupKey, $files);
        }
    }

    /**
     * @return list<array{disk: string, path: string}>|null files to remove after the commit, null when this call
     *                                                      found the group finished and did nothing
     */
    private function inTransaction(GdprRequest $request, string $groupKey): ?array
    {
        /** @var GdprRequest $locked */
        $locked = GdprRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

        $confirmed = (string) ($locked->erasure_plan['fingerprint'] ?? '');
        $step = GdprRequestStep::query()->firstOrNew(['request_id' => $locked->getKey(), 'group_key' => $groupKey]);

        // First: a duplicate job finds a finished group quietly, whatever the request has moved on to.
        if ($step->exists && $step->isComplete($confirmed)) {
            return null;
        }

        if ($locked->status !== RequestStatus::Erasing) {
            throw InvalidTransition::translated('not_erasing');
        }

        $locked->forceFill(['erasure_heartbeat_at' => now()])->save();

        // Rows of this group were already changed and committed earlier; only the file cleanup is left.
        if ($step->exists && $step->rowsDone($confirmed)) {
            $step->forceFill(['attempts' => $step->attempts + 1])->save();

            return $step->pendingFiles();
        }

        $plan = $this->planner->forRequest($locked, $groupKey);
        $group = $plan->group($groupKey);

        if ($group === null) {
            throw InvalidTransition::translated('group_unknown');
        }

        if ($plan->blockers !== [] || $group->isBlocked()) {
            throw new PlanBlocked([...$plan->blockers, ...$group->blockers]);
        }

        // Same subject as at confirmation (the fingerprint holds model#id) and the same rules.
        if ($confirmed !== $plan->fingerprint()) {
            throw InvalidTransition::translated('plan_changed');
        }

        $ceiling = $locked->erasure_plan['counts'][$groupKey] ?? null;

        if ($ceiling === null || $group->matched > (int) $ceiling) {
            throw InvalidTransition::translated('plan_changed');
        }

        // Fewer rows than confirmed is not good news: for a closure it means the rows can no longer be reached. They go
        // on the report as failed; a new preview shows what is there now.
        if ($group->erases() && $group->matched < (int) $ceiling && !$plan->sharesRowsWithADeletion($group)) {
            throw GdprException::translated('rows_missing', ['group' => $group->label(), 'planned' => (int) $ceiling, 'found' => $group->matched]);
        }

        $strategy = $group->entry->getErase();
        $result = ['matched' => $group->matched, 'changed' => 0, 'trashed' => $group->trashed, 'files' => []];
        // Set only when an earlier confirmation ran this group: the rows that named these files may be gone.
        $owed = $step->exists ? $step->pendingFiles() : [];
        $earlier = $step->exists ? ($step->details()['earlier'] ?? []) : [];
        $status = StepStatus::Done;
        $note = null;

        if ($strategy === null) {
            $status = StepStatus::Skipped;
        } elseif ($strategy instanceof Retain) {
            $status = StepStatus::Retained;
            $note = ['basis' => $strategy->basis, 'until' => $group->retainedUntil];
        } else {
            $result = $this->applier->apply($group->selection, $strategy, (int) config('filament-gdpr-requests.erasure.chunk', 200));
            $handled = $strategy instanceof Delete ? $result['changed'] : $result['matched'];

            if ($handled !== $group->matched) {
                throw GdprException::translated('rows_missing', ['group' => $group->label(), 'planned' => $group->matched, 'found' => $handled]);
            }
        }

        $files = [...$owed, ...$result['files']];

        // A rerun adds to the report, it does not replace it: a row deleted in an earlier round is gone from the new count.
        $step->forceFill([
            'label' => $group->label(),
            'strategy' => $group->action,
            'status' => $status,
            'matched' => $strategy instanceof Delete ? (int) $step->matched + $result['matched'] : max((int) $step->matched, $result['matched']),
            'changed' => (int) $step->changed + $result['changed'],
            'trashed' => max((int) $step->trashed, $result['trashed']),
            'attempts' => $step->attempts + 1,
            'error' => null,
            'finished_at' => now(),
            'fingerprint' => $confirmed,
            'detail' => $this->encode([
                'rows_done' => true,
                'files_pending' => $files,
                // What this run did, for the last look; not comparable when another group shares the rows.
                'handled' => $plan->sharesRowsWithADeletion($group) ? null : ($strategy instanceof Delete ? $result['changed'] : $result['matched']),
            ] + ($note ?? []) + ($earlier === [] ? [] : ['earlier' => $earlier])),
        ])->save();

        RequestLog::record($locked, 'erasure_group_done', null, [
            'group' => $groupKey,
            'action' => $group->action,
            'matched' => $result['matched'],
            'changed' => $result['changed'],
        ]);

        return $files;
    }

    /**
     * @param list<array{disk: string, path: string}> $files
     */
    private function removeFiles(GdprRequest $request, string $groupKey, array $files): void
    {
        $left = array_values(array_filter($files, static fn (array $file): bool => !Files::remove($file['disk'], $file['path'])));
        $step = GdprRequestStep::query()->where(['request_id' => $request->getKey(), 'group_key' => $groupKey])->firstOrFail();

        if ($files === [] && $step->status !== StepStatus::Failed) {
            return;
        }

        $detail = $step->details();
        $detail['files_pending'] = $left;
        $step->forceFill(['detail' => $this->encode($detail)]);

        if ($left === []) {
            // Rows are done and the files are gone: a group failed only by its files is finished now.
            $step->forceFill(['status' => $step->status === StepStatus::Failed ? StepStatus::Done : $step->status, 'error' => null, 'finished_at' => now()])->save();

            return;
        }

        $step->forceFill(['status' => StepStatus::Failed, 'error' => Failures::store('errors.files_not_removed', ['count' => count($left)])])->save();
        $this->markPartial($request->getKey(), $groupKey, 'files');

        throw GdprException::translated('files_not_removed', ['count' => count($left)]);
    }

    /**
     * A failure of a group while the request is erasing. Anything else is a late or duplicate job: the request has
     * moved on and nothing here may rewrite it, and a step already settled under this plan stays what it is.
     */
    public function recordFailure(int $requestId, string $groupKey, Throwable $e): void
    {
        $request = GdprRequest::query()->whereKey($requestId)->first();

        if ($request === null || $request->status !== RequestStatus::Erasing) {
            return;
        }

        $confirmed = (string) ($request->erasure_plan['fingerprint'] ?? '');
        $step = GdprRequestStep::query()->firstOrNew(['request_id' => $requestId, 'group_key' => $groupKey]);

        if (!$step->exists || !$step->status->isSettled() || $step->fingerprint !== $confirmed) {
            $step->forceFill([
                'label' => $step->label ?? $groupKey,
                'strategy' => $step->strategy ?? '',
                'status' => StepStatus::Failed,
                'attempts' => ($step->attempts ?? 0) + 1,
                'error' => Failures::encode($e),
            ])->save();
        }

        $this->markPartial($requestId, $groupKey, class_basename($e));
    }

    private function markPartial(int $requestId, string $groupKey, string $reason): void
    {
        GdprRequest::query()->whereKey($requestId)->where('status', RequestStatus::Erasing->value)
            ->update(['status' => RequestStatus::Partial->value]);

        RequestLog::record(GdprRequest::query()->findOrFail($requestId), 'erasure_group_failed', null, ['group' => $groupKey, 'reason' => $reason]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
