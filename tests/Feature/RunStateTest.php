<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Retention;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Enums\StepStatus;
use Asignua\FilamentGdprRequests\Erasure\Planner;
use Asignua\FilamentGdprRequests\Erasure\Runner;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Jobs\ErasureGroupJob;
use Asignua\FilamentGdprRequests\Jobs\FinalizeErasureJob;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestExport;
use Asignua\FilamentGdprRequests\Models\GdprRun;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ErasurePlanPage;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\ExportService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Support\Lang;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\Support\StuckDisk;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Order;

/**
 * A request reads `fulfilled` only if everything really finished, and no later event rewrites what is settled.
 */
class RunStateTest extends TestCase
{
    private function confirmed(Customer $customer): GdprRequest
    {
        $request = Shop::request($customer, RequestType::Erasure, $this->user('dpo', 'Confirmer'));
        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));

        return $request->refresh();
    }

    private function execute(GdprRequest $request): void
    {
        app(ErasureService::class)->start($request->refresh(), $this->user('dpo', 'Runner'));
    }

    private function runExpectingFailure(GdprRequest $request): void
    {
        try {
            $this->execute($request);
            $this->fail('The run should stop.');
        } catch (GdprException) {
            $this->addToAssertionCount(1);
        }
    }

    // Item 7

    public function test_a_file_removed_on_retry_finishes_the_group_and_the_request(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        StuckDisk::install('stuck');
        Storage::disk('stuck')->put('docs/ada.txt', 'x');
        Document::query()->where('customer_id', $ada->id)->update(['disk' => 'stuck', 'path' => 'docs/ada.txt']);
        $request = $this->confirmed($ada);

        $this->runExpectingFailure($request);
        $this->assertSame(RequestStatus::Partial, $request->refresh()->status);
        $this->assertSame(StepStatus::Failed, $request->steps()->where('group_key', Document::class)->first()->status);

        // Still stuck: a second resume stops the same way and keeps the list.
        $this->runExpectingFailure($request);
        $this->assertSame(RequestStatus::Partial, $request->refresh()->status);

        StuckDisk::release('stuck');
        $this->execute($request);

        $request->refresh();
        $step = $request->steps()->where('group_key', Document::class)->first();
        $this->assertSame(RequestStatus::Fulfilled, $request->status);
        $this->assertSame(StepStatus::Done, $step->status);
        $this->assertNull($step->error);
        $this->assertSame([], $step->pendingFiles());
        $this->assertFalse(Storage::disk('stuck')->exists('docs/ada.txt'));
        $this->assertContains('erasure_finished', $request->events()->pluck('event')->all());
    }

    public function test_a_step_left_failed_with_its_files_gone_is_repaired_by_a_resume(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        StuckDisk::install('stuck');
        Document::query()->where('customer_id', $ada->id)->update(['disk' => 'stuck', 'path' => 'docs/ada.txt']);
        Storage::disk('stuck')->put('docs/ada.txt', 'x');
        $request = $this->confirmed($ada);
        $this->runExpectingFailure($request);

        // The state the old code left behind: nothing pending, status still failed.
        $step = $request->steps()->where('group_key', Document::class)->first();
        $step->forceFill(['detail' => json_encode(['rows_done' => true, 'files_pending' => []])])->save();

        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(StepStatus::Done, $step->refresh()->status);
    }

    // Item 8

    public function test_a_duplicate_job_on_a_fulfilled_request_touches_nothing(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->confirmed($ada);
        $this->execute($request);
        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $events = $request->events()->count();

        app(Runner::class)->runGroup($request, Order::class);

        $step = $request->steps()->where('group_key', Order::class)->first();
        $this->assertSame(StepStatus::Done, $step->status);
        $this->assertSame(1, $step->attempts);
        $this->assertNull($step->error);
        $this->assertSame($events, $request->events()->count());
        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    public function test_a_late_job_for_an_unknown_group_on_a_finished_request_leaves_no_trace(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->execute($request);
        $steps = $request->steps()->count();
        $events = $request->events()->count();

        try {
            app(Runner::class)->runGroup($request, 'not-a-group');
            $this->fail('Nothing runs outside an erasing request.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($steps, $request->steps()->count());
        $this->assertSame($events, $request->events()->count());
    }

    public function test_a_failure_recorded_late_never_rewrites_a_settled_step(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->execute($request);
        $request->forceFill(['status' => RequestStatus::Erasing])->save();

        (new ErasureGroupJob($request->getKey(), Order::class))->failed(new RuntimeException('late'));

        $step = $request->steps()->where('group_key', Order::class)->first();
        $this->assertSame(StepStatus::Done, $step->status);
        $this->assertSame(1, $step->attempts);
    }

    // Item 9

    public function test_a_mail_that_fails_does_not_undo_a_finished_erasure(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        config(['filament-gdpr-requests.requester_mail.enabled' => true, 'mail.default' => 'nope', 'mail.mailers.nope' => ['transport' => 'nope']]);

        $this->execute($request);

        $request->refresh();
        $this->assertSame(RequestStatus::Fulfilled, $request->status);
        $this->assertNotNull($request->erasure_finished_at);
        $this->assertContains('erasure_finished', $request->events()->pluck('event')->all());
    }

    public function test_a_group_job_that_dies_moves_the_request_to_partial(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $request->forceFill(['status' => RequestStatus::Erasing, 'erasure_heartbeat_at' => now()])->save();

        (new ErasureGroupJob($request->getKey(), Order::class))->failed(new RuntimeException('killed at ada@customer.example'));

        $request->refresh();
        $step = $request->steps()->where('group_key', Order::class)->first();
        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertSame(StepStatus::Failed, $step->status);
        $this->assertStringNotContainsString('ada@customer.example', (string) $step->error);
        $this->assertContains('erasure_group_failed', $request->events()->pluck('event')->all());
    }

    public function test_a_finalize_job_that_dies_moves_the_request_to_partial(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $request->forceFill(['status' => RequestStatus::Erasing, 'erasure_heartbeat_at' => now()])->save();

        (new FinalizeErasureJob($request->getKey()))->failed(new RuntimeException('killed'));

        $this->assertSame(RequestStatus::Partial, $request->refresh()->status);
        $this->assertContains('erasure_interrupted', $request->events()->pluck('event')->all());
    }

    public function test_a_job_that_dies_after_the_run_ended_changes_nothing(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->execute($request);
        $events = $request->events()->count();

        (new FinalizeErasureJob($request->getKey()))->failed(new RuntimeException('late'));
        (new ErasureGroupJob($request->getKey(), Order::class))->failed(new RuntimeException('late'));

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame($events, $request->events()->count());
    }

    public function test_the_page_offers_resume_only_once_a_run_has_gone_quiet_for_the_configured_window(): void
    {
        config(['filament-gdpr-requests.erasure.stale_run_minutes' => 5]);
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->user('dpo', 'Runner');

        $request->forceFill(['status' => RequestStatus::Erasing, 'erasure_heartbeat_at' => now()->subMinutes(4)])->save();
        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])->assertActionHidden('run');

        $request->forceFill(['erasure_heartbeat_at' => now()->subMinutes(6)])->save();
        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->assertActionVisible('run')
            ->assertActionHasLabel('run', Lang::t('plan.resume'))
            ->assertSee(Lang::t('plan.stale_help', ['minutes' => 5]));
    }

    public function test_a_finished_request_with_no_planned_group_is_not_fulfilled(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $plan = $request->erasure_plan;
        $plan['order'] = [];
        $request->forceFill(['status' => RequestStatus::Erasing, 'erasure_plan' => $plan])->save();

        app(ErasureService::class)->finalize($request->getKey());

        $this->assertSame(RequestStatus::Partial, $request->refresh()->status);
    }

    // Item 10

    public function test_an_archive_that_cannot_be_deleted_stays_on_record(): void
    {
        StuckDisk::install('stuck');
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);
        $export = app(ExportService::class)->request($request, $dpo)->refresh();
        Storage::disk('stuck')->put('gdpr-exports/a.zip', 'x');
        $export->forceFill(['disk' => 'stuck', 'path' => 'gdpr-exports/a.zip', 'expires_at' => now()->subDay()])->save();

        $this->artisan('gdpr:prune')->assertFailed();

        $export->refresh();
        $this->assertSame(GdprRequestExport::READY, $export->status);
        $this->assertNull($export->removed_at);
        $this->assertSame('gdpr-exports/a.zip', $export->path);
        $this->assertSame(1, GdprRun::query()->where('command', 'prune')->latest('id')->first()->summary['exports_failed']);
        $this->assertContains('export_remove_failed', $request->events()->pluck('event')->all());

        StuckDisk::release('stuck');
        $this->artisan('gdpr:prune')->assertSuccessful();

        $this->assertSame(GdprRequestExport::REMOVED, $export->refresh()->status);
        $this->assertFalse(Storage::disk('stuck')->exists('gdpr-exports/a.zip'));
    }

    public function test_retention_that_cannot_delete_a_file_is_not_reported_as_applied(): void
    {
        StuckDisk::install('stuck');
        Storage::disk('stuck')->put('docs/old.txt', 'x');
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Document::query()->update(['disk' => 'stuck', 'path' => 'docs/old.txt', 'created_at' => now()->subDays(60)]);
        $this->registry()->forget(Document::class);
        $this->registry()->add(PersonalData::for(Document::class)->label('Documents')->of(Customer::class)->matchedBy('customer_id')
            ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]])
            ->retention(Retention::after(days: 30, from: 'created_at')->then(Erase::delete(files: true))));

        $this->artisan('gdpr:retention', ['--apply' => true, '--only' => Document::class])->assertFailed();

        $entry = GdprRun::query()->where('command', 'retention')->latest('id')->first()->summary['entries'][0];
        $this->assertSame('failed', $entry['status']);
        $this->assertSame(1, $entry['changed'], 'The row itself did go.');
        $this->assertSame([['disk' => 'stuck', 'path' => 'docs/old.txt']], $entry['files_pending']);
        $this->assertTrue(Storage::disk('stuck')->exists('docs/old.txt'));
        $this->assertNotNull($ada->fresh());
    }

    // Item 11

    public function test_the_fingerprint_cannot_be_recomputed_without_the_key(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->confirmed($ada);
        $plan = app(Planner::class)->forRequest($request);
        $unkeyed = hash('sha256', Customer::class.'#'.$ada->id."\n".implode("\n", array_map(static fn ($group): string => $group->entry->definitionSignature(), $plan->groups)));

        $this->assertNotSame($unkeyed, $plan->fingerprint());
        $this->assertSame($plan->fingerprint(), $request->erasure_plan['fingerprint']);

        config(['filament-gdpr-requests.pseudonym.key' => str_repeat('k', 40)]);

        $this->assertNotSame($unkeyed, app(Planner::class)->forRequest($request)->fingerprint());
        $this->assertNotSame($request->erasure_plan['fingerprint'], app(Planner::class)->forRequest($request->forceFill(['pseudonym_key_version' => 1]))->fingerprint());
    }

    public function test_rotating_the_key_does_not_strand_a_confirmed_erasure(): void
    {
        $key = config('filament-gdpr-requests.pseudonym.key');
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));

        config([
            'filament-gdpr-requests.pseudonym.key' => str_repeat('k', 40),
            'filament-gdpr-requests.pseudonym.key_version' => 2,
            'filament-gdpr-requests.pseudonym.previous_keys' => [1 => $key],
        ]);
        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    // Item 12

    public function test_refusing_from_a_stale_model_does_not_overwrite_a_running_erasure(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $stale = GdprRequest::query()->findOrFail($request->getKey());

        GdprRequest::query()->whereKey($request->getKey())->update(['status' => RequestStatus::Erasing->value]);

        try {
            app(RequestService::class)->refuse($stale, 'Changed our mind', $this->user());
            $this->fail('A running erasure cannot be refused.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $fresh = $request->refresh();
        $this->assertSame(RequestStatus::Erasing, $fresh->status);
        $this->assertNotNull($fresh->erasure_plan);
        $this->assertNull($fresh->refused_at);
    }

    public function test_closing_or_completing_from_a_stale_model_does_not_overwrite_a_running_erasure(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);
        $stale = GdprRequest::query()->findOrFail($request->getKey());
        GdprRequest::query()->whereKey($request->getKey())->update(['status' => RequestStatus::Erasing->value]);

        foreach (['close', 'complete'] as $method) {
            try {
                app(RequestService::class)->{$method}($stale, $dpo);
                $this->fail($method.' must not pass.');
            } catch (InvalidTransition) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(RequestStatus::Erasing, $request->refresh()->status);
        $this->assertTrue($request->hasSubject());
    }

    // Item 13

    private function accessExport(Customer $customer): GdprRequestExport
    {
        $dpo = $this->user('dpo', 'Exporter');
        $access = Shop::request($customer, RequestType::Access, $dpo);

        return app(ExportService::class)->request($access, $dpo)->refresh();
    }

    public function test_every_archive_of_the_person_is_purged_when_the_erasure_is_fulfilled(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bruno = Shop::customer('Bruno', 'bruno@customer.example');
        $hers = $this->accessExport($ada);
        $his = $this->accessExport($bruno);
        $path = (string) $hers->path;
        $request = $this->confirmed($ada);

        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(GdprRequestExport::REMOVED, $hers->refresh()->status);
        $this->assertFalse(Storage::disk('local')->exists($path), 'The copy of the erased data went too.');
        $this->assertSame(GdprRequestExport::READY, $his->refresh()->status, 'The neighbour keeps his.');

        $this->expectException(InvalidTransition::class);
        app(ExportService::class)->link($hers, $this->user(), 'admin');
    }

    public function test_an_archive_that_survives_keeps_the_request_partial_until_it_is_gone(): void
    {
        StuckDisk::install('stuck');
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $export = $this->accessExport($ada);
        Storage::disk('stuck')->put('gdpr-exports/a.zip', 'x');
        $export->forceFill(['disk' => 'stuck', 'path' => 'gdpr-exports/a.zip'])->save();
        $request = $this->confirmed($ada);

        $this->execute($request);

        $this->assertSame(RequestStatus::Partial, $request->refresh()->status, 'A copy of the data is still there.');

        StuckDisk::release('stuck');
        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(GdprRequestExport::REMOVED, $export->refresh()->status);
    }
}
