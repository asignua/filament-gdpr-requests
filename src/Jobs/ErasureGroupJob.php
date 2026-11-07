<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Jobs;

use Asignua\FilamentGdprRequests\Erasure\Runner;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One job per map entry. It never retries by itself (tries = 1): a failure marks the request `partial` and stops
 * the chain, and a human resumes it after looking at the report. The runner is safe to call twice.
 */
final class ErasureGroupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $requestId, public readonly string $groupKey) {}

    public function handle(Runner $runner): void
    {
        $request = GdprRequest::query()->findOrFail($this->requestId);

        $runner->runGroup($request, $this->groupKey);
    }
}
