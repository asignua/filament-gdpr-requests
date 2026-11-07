<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Services;

use Asignua\FilamentGdprRequests\DataMap\SubjectResolver;
use Asignua\FilamentGdprRequests\Erasure\Failures;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Asignua\FilamentGdprRequests\Export\ExportBuilder;
use Asignua\FilamentGdprRequests\Jobs\BuildExportJob;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestExport;
use Asignua\FilamentGdprRequests\Support\Environment;
use Asignua\FilamentGdprRequests\Support\Files;
use Asignua\FilamentGdprRequests\Support\Gates;
use Asignua\FilamentGdprRequests\Support\PrivateDisk;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Export of the subject's declared data: gated, only for a verified identity, private disk, short-lived link.
 */
final class ExportService
{
    public function __construct(private readonly ExportBuilder $builder, private readonly SubjectResolver $subjects) {}

    public function request(GdprRequest $request, Authenticatable $actor): GdprRequestExport
    {
        Gates::authorize(Gates::EXPORT, $actor);
        Environment::assertSupported();
        $this->assertEligible($request);
        $this->assertSubjectClear($request);
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

            // Only a job still pending may publish its archive: a relink or a purge during the build has marked this
            // export removed, and the data just written belongs to a person this request is no longer about.
            $published = GdprRequestExport::query()->whereKey($export->getKey())->where('status', GdprRequestExport::PENDING)->update([
                'status' => GdprRequestExport::READY,
                'disk' => PrivateDisk::name(),
                'path' => $path,
                'size' => (int) filesize($zip),
                'expires_at' => now()->addDays(max(1, (int) config('filament-gdpr-requests.export.retention_days', 30))),
            ]);

            if ($published !== 1) {
                Files::remove(PrivateDisk::name(), $path);

                return;
            }

            $export->refresh();
            RequestLog::record($request, 'export_ready', null, ['export' => $export->ulid, 'bytes' => $export->size]);
        } catch (Throwable $e) {
            $export->forceFill(['status' => GdprRequestExport::FAILED, 'error' => Failures::encode($e)])->save();
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

    /**
     * @return int how many archives could not be removed
     */
    public function removeAllFor(GdprRequest $request): int
    {
        $failed = 0;

        foreach ($request->exports()->whereNull('removed_at')->get() as $export) {
            if (!$this->remove($export)) {
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * Every archive of the person this request is about, across their requests: an access request's archive is a
     * copy of what the erasure removed.
     *
     * @return int how many archives could not be removed
     */
    public function removeAllForSubject(GdprRequest $request): int
    {
        if (!$request->hasSubject()) {
            return $this->removeAllFor($request);
        }

        $owners = GdprRequest::query()->select('id')->where(static function ($query) use ($request): void {
            $query->where(['subject_model' => $request->subject_model, 'subject_id' => $request->subject_id]);

            if ($request->pseudonym !== null) {
                $query->orWhere('pseudonym', $request->pseudonym);
            }
        });

        $failed = 0;

        foreach (GdprRequestExport::query()->with('request')->whereNull('removed_at')->whereIn('request_id', $owners)->get() as $export) {
            if (!$this->remove($export)) {
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * @return bool false when the archive is still there; the row then stays as it is, on the log, and a later prune
     *              retries
     */
    public function remove(GdprRequestExport $export): bool
    {
        if ($export->disk !== null && $export->path !== null && !Files::remove($export->disk, $export->path)) {
            RequestLog::record($export->owner(), 'export_remove_failed', null, ['export' => $export->ulid]);

            return false;
        }

        $export->forceFill(['status' => GdprRequestExport::REMOVED, 'removed_at' => now(), 'path' => null])->save();

        return true;
    }

    private function assertSubjectClear(GdprRequest $request): void
    {
        $blockers = $this->subjects->resolve($request)->blockers ?? [];

        if ($blockers !== []) {
            throw GdprException::translated('export_blocked', ['reason' => $blockers[0]->message()]);
        }
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
