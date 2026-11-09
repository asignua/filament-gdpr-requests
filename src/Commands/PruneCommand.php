<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Commands;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestExport;
use Asignua\FilamentGdprRequests\Models\GdprRun;
use Asignua\FilamentGdprRequests\Services\ExportService;
use Asignua\FilamentGdprRequests\Support\Environment;
use Illuminate\Console\Command;

/**
 * Housekeeping of the plugin's OWN records: expired export archives, and the name / address / message / notes of
 * requests that ended (fulfilled, refused or closed) longer than `request_retention_days` ago. The pseudonym and the
 * structural log stay.
 */
class PruneCommand extends Command
{
    protected $signature = 'gdpr:prune {--dry-run : Only report what would be removed}';

    protected $description = 'Remove expired export archives and wipe the details of old closed requests';

    public function handle(ExportService $exports): int
    {
        try {
            Environment::assertSupported();
        } catch (GdprException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $run = GdprRun::query()->create(['command' => 'prune', 'dry_run' => $dry, 'started_at' => now()]);

        $expired = GdprRequestExport::query()
            ->whereNull('removed_at')
            ->where(static function ($query): void {
                $query->where('expires_at', '<', now())
                    ->orWhereHas('request', static fn ($request) => $request->whereNotIn('status', RequestStatus::openValues()));
            })
            ->get();

        $days = max(0, (int) config('filament-gdpr-requests.request_retention_days', 90));
        $before = now()->subDays($days);
        // Every way a request ends sets closed_at. Requests completed or refused before it did carry their last change.
        $old = GdprRequest::query()
            ->whereNull('details_purged_at')
            ->where(static function ($query) use ($before): void {
                $query->where('closed_at', '<', $before)
                    ->orWhere(static function ($legacy) use ($before): void {
                        $legacy->whereNull('closed_at')
                            ->whereNotIn('status', RequestStatus::openValues())
                            ->where('updated_at', '<', $before);
                    });
            })
            ->get();

        $failed = 0;

        if (!$dry) {
            foreach ($expired as $export) {
                $failed += $exports->remove($export) ? 0 : 1;
            }

            foreach ($old as $request) {
                $request->forceFill([
                    'subject_name' => null,
                    'subject_email' => null,
                    'message' => null,
                    'notes' => null,
                    'identity_note' => null,
                    'refusal_reason' => null,
                    'extension_reason' => null,
                    'details_purged_at' => now(),
                ])->save();
            }
        }

        $summary = ['exports' => $expired->count() - $failed, 'exports_failed' => $failed, 'requests' => $old->count()];
        $run->forceFill(['summary' => $summary, 'finished_at' => now()])->save();

        $this->components->info(($dry ? 'DRY RUN. ' : '').'Export archives: '.$summary['exports'].'; request details wiped: '.$summary['requests']);

        if ($failed > 0) {
            $this->components->error($failed.' export archive(s) could not be removed. They stay on record and the next run tries again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
