<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Services;

use Asignua\FilamentGdprRequests\Erasure\Failures;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Asignua\FilamentGdprRequests\Export\ExportBuilder;
use Asignua\FilamentGdprRequests\Jobs\BuildExportJob;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestExport;
use Asignua\FilamentGdprRequests\Support\Environment;
use Asignua\FilamentGdprRequests\Support\Gates;
use Asignua\FilamentGdprRequests\Support\PrivateDisk;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Export of the subject's declared data: gated, only for a verified identity, private disk, short-lived link.
 */
final class ExportService
{
    public function __construct(private readonly ExportBuilder $builder) {}

    public function request(GdprRequest $request, Authenticatable $actor): GdprRequestExport
    {
        Gates::authorize(Gates::EXPORT, $actor);
        Environment::assertSupported();
        $this->assertEligible($request);
        PrivateDisk::resolve();

        $export = GdprRequestExport::query()->create([
            'request_id' => $request->getKey(),
            'status' => GdprRequestExport::PENDING,
            'created_by' => (string) $actor->getAuthIdentifier(),
        ]);

        RequestLog::record($request, 'export_requested', $actor);

        $connection = config('filament-gdpr-requests.queue.connection');
        $queue = config('filament-gdpr-requests.queue.name');

        BuildExportJob::dispatch($export->getKey())
            ->onConnection(is_string($connection) && $connection !== '' ? $connection : null)
            ->onQueue(is_string($queue) && $queue !== '' ? $queue : null);

        return $export;
    }

    /**
     * Runs inside the job. Never leaves a half-written archive behind.
     */
    public function build(int $exportId): void
    {
        $export = GdprRequestExport::query()->with('request')->findOrFail($exportId);
        $request = $export->owner();

        if ($export->status !== GdprRequestExport::PENDING) {
            return;
        }

        $zip = null;

        try {
            Environment::assertSupported();
            $this->assertEligible($request);
            $disk = PrivateDisk::resolve();

            $zip = $this->builder->build($request);
            $directory = trim((string) config('filament-gdpr-requests.export.directory', 'gdpr-exports'), '/');
            // The name is random: even knowing the export id one cannot guess where the archive lies.
            $path = $directory.'/'.$export->ulid.'/'.Str::random(40).'.zip';
            $stream = fopen($zip, 'rb');

            if ($stream === false) {
                throw GdprException::translated('export_zip_failed');
            }

            $disk->put($path, $stream);
            fclose($stream);

            $export->forceFill([
                'status' => GdprRequestExport::READY,
                'disk' => PrivateDisk::name(),
                'path' => $path,
                'size' => (int) filesize($zip),
                'expires_at' => now()->addDays(max(1, (int) config('filament-gdpr-requests.export.retention_days', 30))),
            ])->save();

            RequestLog::record($request, 'export_ready', null, ['export' => $export->ulid, 'bytes' => $export->size]);
        } catch (Throwable $e) {
            $export->forceFill(['status' => GdprRequestExport::FAILED, 'error' => Failures::describe($e)])->save();
            RequestLog::record($request, 'export_failed', null, ['export' => $export->ulid]);

            throw $e;
        } finally {
            if ($zip !== null) {
                @unlink($zip);
            }
        }
    }

    /**
     * A signed link bound to this one user and this one panel; short-lived.
     */
    public function link(GdprRequestExport $export, Authenticatable $actor, string $panel): string
    {
        Gates::authorize(Gates::EXPORT, $actor);
        $this->assertDownloadable($export);

        RequestLog::record($export->owner(), 'export_link_issued', $actor, ['export' => $export->ulid]);

        return URL::temporarySignedRoute(
            'filament-gdpr-requests.export.download',
            now()->addMinutes(max(1, (int) config('filament-gdpr-requests.export.link_minutes', 15))),
            ['export' => $export->ulid, 'panel' => $panel, 'uid' => (string) $actor->getAuthIdentifier()],
        );
    }

    public function assertDownloadable(GdprRequestExport $export): void
    {
        $this->assertEligible($export->owner());

        if (!$export->isDownloadable()) {
            throw InvalidTransition::translated('export_unavailable');
        }
    }

    public function noteDownload(GdprRequestExport $export, Authenticatable $actor): void
    {
        $export->increment('downloads');
        RequestLog::record($export->owner(), 'export_downloaded', $actor, ['export' => $export->ulid]);
    }

    public function removeAllFor(GdprRequest $request): void
    {
        foreach ($request->exports()->whereNull('removed_at')->get() as $export) {
            $this->remove($export);
        }
    }

    public function remove(GdprRequestExport $export): void
    {
        if ($export->disk !== null && $export->path !== null) {
            try {
                Storage::disk($export->disk)->delete($export->path);
            } catch (Throwable) {
                // The row is marked removed only if the delete did not throw; otherwise a later prune retries.
                return;
            }
        }

        $export->forceFill(['status' => GdprRequestExport::REMOVED, 'removed_at' => now(), 'path' => null])->save();
    }

    private function assertEligible(GdprRequest $request): void
    {
        if (!$request->type->allowsExport()) {
            throw InvalidTransition::translated('wrong_type');
        }

        if (!$request->hasSubject()) {
            throw InvalidTransition::translated('no_subject');
        }

        if (!$request->isVerified()) {
            throw InvalidTransition::translated('not_verified');
        }

        if (!$request->status->isOpen()) {
            throw NotPermitted::translated('request_closed');
        }
    }
}
