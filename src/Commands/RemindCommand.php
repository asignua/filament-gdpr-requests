<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Commands;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\GdprRequestsPlugin;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRun;
use Asignua\FilamentGdprRequests\Notifications\DeadlineNotification;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource;
use Asignua\FilamentGdprRequests\Support\Environment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Throwable;

class RemindCommand extends Command
{
    protected $signature = 'gdpr:remind {--dry-run : Only report who would be told}';

    protected $description = 'Remind assignees before a request falls due and tell administrators when it is overdue';

    public function handle(): int
    {
        try {
            Environment::assertSupported();
        } catch (GdprException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $run = GdprRun::query()->create(['command' => 'remind', 'dry_run' => $dry, 'started_at' => now()]);
        $days = max(0, (int) config('filament-gdpr-requests.reminders.days_before', 5));
        $sent = ['due' => 0, 'overdue' => 0, 'unreached' => 0];

        $due = GdprRequest::query()->whereIn('status', RequestStatus::openValues())
            ->whereNull('reminded_due_at')->where('due_at', '>', now())->where('due_at', '<=', now()->addDays($days))->get();

        foreach ($due as $request) {
            if ($this->remind($request, [$this->assignee($request)], false, $dry)) {
                $sent['due']++;
            } else {
                $sent['unreached']++;
                $this->components->warn('Request '.$request->reference().' is due soon but has no assignee to remind.');
            }
        }

        $overdue = GdprRequest::query()->whereIn('status', RequestStatus::openValues())
            ->whereNull('reminded_overdue_at')->where('due_at', '<', now())->get();

        foreach ($overdue as $request) {
            if ($this->remind($request, [$this->assignee($request), ...GdprRequests::admins()], true, $dry)) {
                $sent['overdue']++;
            } else {
                $sent['unreached']++;
                $this->components->warn('Request '.$request->reference().' is overdue and nobody could be told.');
            }
        }

        if ($overdue->isNotEmpty() && !GdprRequests::hasAdminResolver()) {
            $this->components->warn('No administrators are defined, so overdue requests reach only their assignee. Call GdprRequests::notifyAdminsUsing(fn () => ...) in a service provider.');
        }

        $run->forceFill(['summary' => $sent, 'finished_at' => now()])->save();
        $this->components->info(($dry ? 'DRY RUN. ' : '')."Due soon: {$sent['due']}; overdue: {$sent['overdue']}; nobody to tell: {$sent['unreached']}");

        return self::SUCCESS;
    }

    /**
     * Marks the request only when somebody was really told; otherwise the next run tries again.
     *
     * @param array<int, object|null> $recipients
     *
     * @return bool whether anybody was (or, on a dry run, would be) told
     */
    private function remind(GdprRequest $request, array $recipients, bool $overdue, bool $dry): bool
    {
        $recipients = array_values(array_filter($recipients));

        if ($recipients === []) {
            return false;
        }

        if ($dry) {
            return true;
        }

        try {
            Notification::send($recipients, new DeadlineNotification($request->reference(), $request->due_at->toDateString(), $overdue, $this->url($request)));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        $request->forceFill([$overdue ? 'reminded_overdue_at' : 'reminded_due_at' => now()])->save();

        return true;
    }

    private function assignee(GdprRequest $request): ?object
    {
        if ($request->assignee_id === null) {
            return null;
        }

        /** @var class-string<Model>|null $model */
        $model = config('auth.providers.users.model');

        return is_string($model) ? $model::query()->find($request->assignee_id) : null;
    }

    private function url(GdprRequest $request): ?string
    {
        try {
            foreach (\Filament\Facades\Filament::getPanels() as $panel) {
                if ($panel->hasPlugin(GdprRequestsPlugin::ID)) {
                    return GdprRequestResource::getUrl('view', ['record' => $request], panel: $panel->getId());
                }
            }
        } catch (Throwable) {
            // A link is a convenience; the reminder goes out without it.
        }

        return null;
    }
}
