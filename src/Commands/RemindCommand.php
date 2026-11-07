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
        $sent = ['due' => 0, 'overdue' => 0];

        $due = GdprRequest::query()->whereIn('status', RequestStatus::openValues())
            ->whereNull('reminded_due_at')->where('due_at', '>', now())->where('due_at', '<=', now()->addDays($days))->get();

        foreach ($due as $request) {
            $sent['due']++;

            if (!$dry) {
                $this->notify($request, [$this->assignee($request)], false);
                $request->forceFill(['reminded_due_at' => now()])->save();
            }
        }

        $overdue = GdprRequest::query()->whereIn('status', RequestStatus::openValues())
            ->whereNull('reminded_overdue_at')->where('due_at', '<', now())->get();

        foreach ($overdue as $request) {
            $sent['overdue']++;

            if (!$dry) {
                $this->notify($request, [$this->assignee($request), ...GdprRequests::admins()], true);
                $request->forceFill(['reminded_overdue_at' => now()])->save();
            }
        }

        $run->forceFill(['summary' => $sent, 'finished_at' => now()])->save();
        $this->components->info(($dry ? 'DRY RUN. ' : '')."Due soon: {$sent['due']}; overdue: {$sent['overdue']}");

        return self::SUCCESS;
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

    /**
     * @param array<int, object|null> $recipients
     */
    private function notify(GdprRequest $request, array $recipients, bool $overdue): void
    {
        $recipients = array_values(array_filter($recipients));

        if ($recipients === []) {
            return;
        }

        Notification::send($recipients, new DeadlineNotification($request->reference(), $request->due_at->toDateString(), $overdue, $this->url($request)));
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
