<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Retention;

use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\DataMap\Retain;
use Asignua\FilamentGdprRequests\DataMap\Selection;
use Asignua\FilamentGdprRequests\Erasure\Applier;
use Asignua\FilamentGdprRequests\Erasure\Failures;
use Asignua\FilamentGdprRequests\Erasure\Planner;
use Asignua\FilamentGdprRequests\Models\GdprRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `gdpr:retention`. The plan is always computed; rows only change when $apply is true, which only the command's
 * explicit --apply flag sets (there is no config switch that turns it on).
 */
final class RetentionRunner
{
    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly Planner $planner,
        private readonly Applier $applier,
    ) {}

    /**
     * @return array{run: GdprRun, rows: list<array<string, mixed>>, failed: bool}
     */
    public function run(bool $apply, ?string $only = null): array
    {
        $run = GdprRun::query()->create(['command' => 'retention', 'dry_run' => !$apply, 'started_at' => now()]);
        $rows = [];
        $failed = false;

        foreach ($this->registry->all() as $entry) {
            if ($only !== null && $entry->group !== $only && $entry->model !== $only) {
                continue;
            }

            $retention = $entry->getRetention();
            $erase = $entry->getErase();
            $retain = $erase instanceof Retain ? $erase : null;

            if ($retention === null && $retain === null) {
                continue;
            }

            $row = $this->entry($entry, $retention?->days, $retention?->from, $retention?->then, $retain, $apply);
            $failed = $failed || ($row['status'] === 'failed' || $row['status'] === 'blocked');
            $rows[] = $row;
        }

        $run->forceFill(['summary' => ['entries' => $rows], 'finished_at' => now()])->save();

        return ['run' => $run, 'rows' => $rows, 'failed' => $apply && $failed];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(PersonalData $entry, ?int $days, ?string $from, mixed $action, ?Retain $retain, bool $apply): array
    {
        $table = (new ($entry->model))->getTable();
        $row = ['group' => $entry->group, 'action' => $action === null ? 'none' : $action->kind(), 'expired' => 0, 'changing' => 0, 'changed' => 0, 'status' => 'planned', 'blockers' => []];

        $selection = Selection::constrained($entry, static function (Builder $query) use ($table, $days, $from, $retain): void {
            /** @var Builder<Model> $query */
            if ($days !== null && $from !== null) {
                $query->where($table.'.'.$from, '<', now()->subDays($days));
            }

            // A row still inside its legal retention period is never in the plan, whatever the policy says.
            if ($retain !== null) {
                $query->where($table.'.'.$retain->from, '<', now()->subDays($retain->days));
            }
        });

        if ($action === null) {
            // Only a retain declaration: list what has expired, change nothing.
            $row['expired'] = $selection->query()->count();
            $row['status'] = 'info';

            return $row;
        }

        try {
            $plan = $this->planner->forRetention($entry, $selection, $action);
        } catch (Throwable $e) {
            return [...$row, 'status' => 'failed', 'blockers' => [Failures::describe($e)]];
        }

        $row['expired'] = $plan->matched;
        $row['changing'] = $action->kind() === 'delete' ? $plan->matched : $plan->changing;
        $row['blockers'] = array_map(static fn ($blocker): string => $blocker->message(), $plan->blockers);

        if ($plan->isBlocked()) {
            $row['status'] = 'blocked';

            return $row;
        }

        if (!$apply) {
            return $row;
        }

        try {
            $result = DB::transaction(fn (): array => $this->applier->apply($selection, $action, (int) config('filament-gdpr-requests.erasure.chunk', 200)));
            $row['changed'] = $result['changed'];
            $row['status'] = 'applied';
            $this->removeFiles($result['files']);
        } catch (Throwable $e) {
            $row['status'] = 'failed';
            $row['blockers'] = [Failures::describe($e)];
        }

        return $row;
    }

    /**
     * @param list<array{disk: string, path: string}> $files
     */
    private function removeFiles(array $files): void
    {
        foreach ($files as $file) {
            try {
                \Illuminate\Support\Facades\Storage::disk($file['disk'])->delete($file['path']);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
