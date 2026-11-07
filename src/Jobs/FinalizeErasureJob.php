<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Jobs;

use Asignua\FilamentGdprRequests\Services\ErasureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * The last link of the chain: marks the request fulfilled only when every group is settled.
 */
final class FinalizeErasureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $requestId) {}

    public function handle(ErasureService $service): void
    {
        $service->finalize($this->requestId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ErasureService::class)->interrupted($this->requestId, $exception);
    }
}
