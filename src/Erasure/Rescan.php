<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\DataMap\Anonymize;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The last look before a request may read `fulfilled`: the plan is built again for the same person and compared with
 * what the confirmation promised. Steps only say that work happened; this says what is there now.
 *
 * It sees what the map can reach. A closure group that reads a table this run already erased finds nothing here, by
 * nature; the planner runs such groups first, and the first run is held to the confirmed count.
 */
final class Rescan
{
    public function __construct(private readonly Planner $planner, private readonly Applier $applier) {}

    /**
     * @param Collection<string, GdprRequestStep> $steps by group key
     */
    public function run(GdprRequest $request, Collection $steps): Findings
    {
        $plan = $this->planner->forRequest($request);
        $blockers = $plan->allBlockers();

        // A plan with a blocker may not even have a fingerprint: the key that makes one can be the thing missing.
        $changed = $blockers === [] && ($request->erasure_plan['fingerprint'] ?? null) !== $plan->fingerprint();

        $remaining = [];
        $files = [];
        $mismatch = [];

        foreach ($plan->groups as $group) {
            $key = $group->key();

            if ($group->remaining() > 0) {
                $remaining[$key] = $group->remaining();
            }

            $left = $this->filesLeft($group);

            if ($left > 0) {
                $files[$key] = $left;
            }

            $planned = $request->erasure_plan['counts'][$key] ?? null;
            $handled = $steps->get($key)?->details()['handled'] ?? null;

            if ($group->erases() && is_int($handled) && $planned !== null && (int) $planned !== $handled) {
                $mismatch[$key] = $planned.' / '.$handled;
            }
        }

        return new Findings($remaining, $files, $mismatch, $blockers, $changed);
    }

    /**
     * A deleted row names no file any more, and its files were listed by the step. A row that stays still names its own.
     */
    private function filesLeft(GroupPlan $group): int
    {
        $strategy = $group->entry->getErase();

        if (!$strategy instanceof Anonymize || !$strategy->removesFiles()) {
            return 0;
        }

        $left = 0;

        foreach ($group->selection->rows() as $row) {
            foreach ($this->applier->filesOf($group->selection, $row) as $file) {
                $left += $this->exists($file['disk'], $file['path']) ? 1 : 0;
            }
        }

        return $left;
    }

    private function exists(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->exists($path);
        } catch (Throwable) {
            return true; // a disk that cannot answer cannot vouch for the file being gone
        }
    }
}
