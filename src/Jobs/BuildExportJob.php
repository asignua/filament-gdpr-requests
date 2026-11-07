<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Jobs;

use Asignua\FilamentGdprRequests\Services\ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class BuildExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $exportId) {}

    public function handle(ExportService $service): void
    {
        $service->build($this->exportId);
    }
}
