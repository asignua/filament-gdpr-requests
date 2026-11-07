<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRun;
use Asignua\FilamentGdprRequests\Notifications\DeadlineNotification;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Facades\Notification;

class HousekeepingTest extends TestCase
{
    public function test_names_and_addresses_vanish_after_the_retention_period_but_the_pseudonym_stays(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada Lovelace', 'ada@customer.example'), RequestType::Access, $dpo);
        $service = app(RequestService::class);
        $service->addNote($request, 'Phoned the customer', $dpo);
        $service->complete($request->refresh(), $dpo);
        $service->close($request->refresh(), $dpo);
        $pseudonym = $request->refresh()->pseudonym;

        $this->travelTo(now()->addDays(89));
        $this->artisan('gdpr:prune')->assertSuccessful();
        $this->assertSame('Ada Lovelace', $request->refresh()->subject_name, 'Not before the period ends.');

        $this->travelTo(now()->addDays(2));
        $this->artisan('gdpr:prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('Ada Lovelace', $request->refresh()->subject_name, 'A dry run changes nothing.');

        $this->artisan('gdpr:prune')->assertSuccessful();

        $request->refresh();
        $this->assertNull($request->subject_name);
        $this->assertNull($request->subject_email);
        $this->assertNull($request->message);
        $this->assertNull($request->notes);
        $this->assertNull($request->identity_note);
        $this->assertNotNull($request->details_purged_at);
        $this->assertSame($pseudonym, $request->pseudonym);
        $this->assertNotEmpty($request->events()->count());
    }

    public function test_an_open_request_is_never_purged(): void
    {
        GdprRequests::submit(RequestType::Access, 'a@customer.example', 'Ada');
        $this->travelTo(now()->addYears(2));

        $this->artisan('gdpr:prune')->assertSuccessful();

        $this->assertSame('Ada', GdprRequest::query()->first()->subject_name);
    }

    public function test_the_assignee_is_reminded_once_before_the_deadline(): void
    {
        Notification::fake();
        $assignee = $this->user('manager', 'Assignee');
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example');
        app(RequestService::class)->assign($request, $assignee->id, $this->user('dpo'));

        $this->artisan('gdpr:remind')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo($request->due_at->copy()->subDays(3));
        $this->artisan('gdpr:remind')->assertSuccessful();
        $this->artisan('gdpr:remind')->assertSuccessful();

        Notification::assertSentToTimes($assignee, DeadlineNotification::class, 1);
    }

    public function test_administrators_are_told_when_a_request_is_overdue(): void
    {
        Notification::fake();
        $admin = $this->user('dpo', 'Admin');
        GdprRequests::notifyAdminsUsing(fn () => [$admin]);
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example');

        $this->travelTo($request->due_at->copy()->addDay());
        $this->artisan('gdpr:remind')->assertSuccessful();
        $this->artisan('gdpr:remind')->assertSuccessful();

        Notification::assertSentToTimes($admin, DeadlineNotification::class, 1);
        $this->assertNotNull($request->refresh()->reminded_overdue_at);
    }

    public function test_a_closed_request_is_not_reminded_and_a_dry_run_marks_nothing(): void
    {
        Notification::fake();
        $dpo = $this->user();
        GdprRequests::notifyAdminsUsing(fn () => [$dpo]);
        $closed = GdprRequests::submit(RequestType::Access, 'a@customer.example');
        $closed->forceFill(['status' => RequestStatus::Closed, 'closed_at' => now()])->save();
        $open = GdprRequests::submit(RequestType::Access, 'b@customer.example');

        $this->travelTo($open->due_at->copy()->addDay());
        $this->artisan('gdpr:remind', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($open->refresh()->reminded_overdue_at);

        $this->artisan('gdpr:remind')->assertSuccessful();
        Notification::assertSentToTimes($dpo, DeadlineNotification::class, 1);
        $this->assertNull($closed->refresh()->reminded_overdue_at);
    }

    public function test_an_unassigned_request_due_soon_is_not_marked_reminded_when_nobody_was_told(): void
    {
        Notification::fake();
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example');
        $this->travelTo($request->due_at->copy()->subDays(3));

        $this->artisan('gdpr:remind')->expectsOutputToContain('no assignee')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($request->refresh()->reminded_due_at);
        $this->assertSame(['due' => 0, 'overdue' => 0, 'unreached' => 1], GdprRun::query()->where('command', 'remind')->latest('id')->first()->summary);

        // Someone takes it: the reminder goes out after all.
        $assignee = $this->user('manager', 'Assignee');
        app(RequestService::class)->assign($request, $assignee->id, $this->user('dpo'));
        $this->artisan('gdpr:remind')->assertSuccessful();

        Notification::assertSentToTimes($assignee, DeadlineNotification::class, 1);
        $this->assertNotNull($request->refresh()->reminded_due_at);
    }

    public function test_an_overdue_request_is_not_marked_when_no_resolver_tells_anyone(): void
    {
        Notification::fake();
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example');
        $this->travelTo($request->due_at->copy()->addDay());

        $this->artisan('gdpr:remind')->expectsOutputToContain('notifyAdminsUsing')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($request->refresh()->reminded_overdue_at);
        $this->assertSame(['due' => 0, 'overdue' => 0, 'unreached' => 1], GdprRun::query()->where('command', 'remind')->latest('id')->first()->summary);

        $admin = $this->user('dpo', 'Admin');
        GdprRequests::notifyAdminsUsing(fn () => [$admin]);
        $this->artisan('gdpr:remind')->assertSuccessful();

        Notification::assertSentToTimes($admin, DeadlineNotification::class, 1);
        $this->assertNotNull($request->refresh()->reminded_overdue_at);
    }

    public function test_an_overdue_request_with_an_assignee_warns_about_the_resolver_but_is_marked(): void
    {
        Notification::fake();
        $assignee = $this->user('manager', 'Assignee');
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example');
        app(RequestService::class)->assign($request, $assignee->id, $this->user('dpo'));
        $this->travelTo($request->due_at->copy()->addDay());

        $this->artisan('gdpr:remind')->expectsOutputToContain('notifyAdminsUsing')->assertSuccessful();

        Notification::assertSentToTimes($assignee, DeadlineNotification::class, 1);
        $this->assertNotNull($request->refresh()->reminded_overdue_at);
    }

    public function test_a_resolver_that_returns_nobody_is_the_same_as_no_resolver(): void
    {
        Notification::fake();
        GdprRequests::notifyAdminsUsing(fn () => []);
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example');
        $this->travelTo($request->due_at->copy()->addDay());

        $this->artisan('gdpr:remind')->assertSuccessful();

        $this->assertNull($request->refresh()->reminded_overdue_at);
    }

    public function test_a_reminder_that_cannot_be_delivered_is_not_marked(): void
    {
        $assignee = $this->user('manager', 'Assignee');
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example');
        app(RequestService::class)->assign($request, $assignee->id, $this->user('dpo'));
        $this->travelTo($request->due_at->copy()->subDays(3));
        config(['mail.default' => 'nope', 'mail.mailers.nope' => ['transport' => 'nope']]);

        $this->artisan('gdpr:remind')->assertSuccessful();

        $this->assertNull($request->refresh()->reminded_due_at);
    }
}
