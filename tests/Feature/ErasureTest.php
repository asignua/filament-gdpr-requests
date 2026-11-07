<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Enums\StepStatus;
use Asignua\FilamentGdprRequests\Erasure\Runner;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Asignua\FilamentGdprRequests\Exceptions\PlanBlocked;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;
use Workbench\App\Models\Ticket;

class ErasureTest extends TestCase
{
    private function erasure(Customer $customer, bool $verify = true): GdprRequest
    {
        return Shop::request($customer, RequestType::Erasure, $this->user('dpo', 'Confirmer'), $verify);
    }

    private function confirm(GdprRequest $request): void
    {
        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));
    }

    private function execute(GdprRequest $request): void
    {
        app(ErasureService::class)->start($request->refresh(), $this->user('dpo', 'Runner'));
    }

    public function test_the_preview_counts_soft_deleted_rows_and_changes_nothing(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Order::query()->where('number', 'Ada-1')->first()->delete();
        $request = $this->erasure($ada);

        $plan = app(ErasureService::class)->plan($request);

        $orders = $plan->group(Order::class);
        $this->assertSame(2, $orders->matched);
        $this->assertSame(1, $orders->trashed);
        $this->assertSame(2, $orders->changing);
        $this->assertSame(2, $plan->group(OrderItem::class)->matched);
        $this->assertSame(1, $plan->group(Ticket::class)->matched);
        $this->assertSame(2, $plan->group(Invoice::class)->matched);
        $this->assertFalse($plan->isBlocked(), implode('; ', array_map(fn ($b) => $b->message(), $plan->allBlockers())));
        $this->assertSame('Ada', $ada->fresh()->name);
        $this->assertSame(0, $request->steps()->count());
    }

    public function test_children_run_before_the_rows_they_reference(): void
    {
        $plan = app(ErasureService::class)->plan($this->erasure(Shop::customer('Ada', 'ada@customer.example')));
        $keys = array_map(fn ($group) => $group->key(), $plan->groups);

        $this->assertGreaterThan(array_search(OrderItem::class, $keys, true), array_search(Order::class, $keys, true));
        $this->assertGreaterThan(array_search(Order::class, $keys, true), array_search(Customer::class, $keys, true));
    }

    public function test_it_erases_only_the_requested_subject_and_reports_every_group(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bruno = Shop::customer('Bruno', 'bruno@customer.example');
        Order::query()->where('number', 'Ada-1')->first()->delete();
        $request = $this->erasure($ada);

        $this->confirm($request);
        $this->execute($request);

        $request->refresh();
        $this->assertSame(RequestStatus::Fulfilled, $request->status);

        $ada->refresh();
        $this->assertSame('Erased customer', $ada->name);
        $this->assertNull($ada->phone);
        $this->assertStringStartsWith('erased-', $ada->email);
        $this->assertSame(['Erased', 'Erased'], Order::withTrashed()->where('customer_id', $ada->id)->pluck('shipping_address')->all());
        $this->assertSame(0, OrderItem::query()->whereIn('order_id', Order::withTrashed()->where('customer_id', $ada->id)->select('id'))->count());
        $this->assertSame(0, Ticket::query()->where('customer_email', 'ada@customer.example')->count());
        $this->assertFalse(Storage::disk('local')->exists('docs/Ada.txt'), 'The declared file is removed.');
        $this->assertSame(2, Invoice::query()->where('customer_id', $ada->id)->where('billed_to', 'Ada')->count(), 'Retained rows are untouched.');

        // The neighbour is untouched.
        $bruno->refresh();
        $this->assertSame('Bruno', $bruno->name);
        $this->assertSame('bruno@customer.example', $bruno->email);
        $this->assertSame(2, Order::query()->where('customer_id', $bruno->id)->where('billing_name', 'Bruno')->count());
        $this->assertSame(1, Ticket::query()->where('customer_email', 'bruno@customer.example')->count());
        $this->assertTrue(Storage::disk('local')->exists('docs/Bruno.txt'));

        $steps = $request->steps()->get()->keyBy('group_key');
        $this->assertCount(6, $steps);
        $this->assertSame(StepStatus::Retained, $steps[Invoice::class]->status);
        $this->assertSame(2, $steps[Invoice::class]->matched);
        $this->assertSame(0, $steps[Invoice::class]->changed);
        $this->assertSame(2, $steps[Order::class]->changed);
        $this->assertSame(1, $steps[Order::class]->trashed);
        $this->assertSame(StepStatus::Done, $steps[Customer::class]->status);

        $events = $request->events()->pluck('event')->all();
        $this->assertContains('erasure_confirmed', $events);
        $this->assertContains('erasure_finished', $events);
    }

    public function test_the_erasure_is_blocked_until_the_identity_is_verified(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'), verify: false);

        $this->expectException(InvalidTransition::class);

        $this->confirm($request);
    }

    public function test_confirming_and_running_are_separate_and_run_needs_a_confirmation(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));

        $this->expectException(InvalidTransition::class);

        $this->execute($request);
    }

    public function test_four_eyes_the_confirmer_cannot_run_it(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));
        $confirmer = $this->user('dpo', 'Confirmer');
        $service = app(ErasureService::class);
        $service->confirm($request, $confirmer);

        try {
            $service->start($request->refresh(), $confirmer);
            $this->fail('The same person must not run it.');
        } catch (NotPermitted) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(RequestStatus::ErasureConfirmed, $request->refresh()->status);
        $this->assertSame('ada@customer.example', Customer::query()->first()->email);

        $service->start($request, $this->user('dpo', 'Second person'));
        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    public function test_switching_four_eyes_off_allows_one_person_but_is_logged_each_time(): void
    {
        config(['filament-gdpr-requests.four_eyes' => false]);
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));
        $solo = $this->user('dpo', 'Solo');
        $service = app(ErasureService::class);

        $service->confirm($request, $solo);
        $service->start($request->refresh(), $solo);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertContains('four_eyes_disabled', $request->events()->pluck('event')->all());
    }

    public function test_a_user_without_the_erase_gate_cannot_confirm_or_run(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));
        $manager = $this->user('manager');

        $this->expectException(NotPermitted::class);

        app(ErasureService::class)->confirm($request, $manager);
    }

    public function test_a_second_start_while_running_or_after_completion_is_refused(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));
        $this->confirm($request);
        $this->execute($request);

        $this->expectException(InvalidTransition::class);

        $this->execute($request);
    }

    public function test_the_claim_is_atomic_a_run_in_progress_cannot_be_claimed_again(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));
        $this->confirm($request);

        // Another worker holds the request: status erasing with a fresh heartbeat.
        $request->forceFill(['status' => RequestStatus::Erasing, 'erasure_heartbeat_at' => now()])->save();

        $this->expectException(InvalidTransition::class);

        $this->execute($request);
    }

    public function test_a_dead_run_with_a_stale_heartbeat_can_be_resumed(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));
        $this->confirm($request);
        $request->forceFill(['status' => RequestStatus::Erasing, 'erasure_heartbeat_at' => now()->subHour()])->save();

        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    public function test_running_one_group_twice_does_nothing_the_second_time(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->erasure($ada);
        $this->confirm($request);
        $request->forceFill(['status' => RequestStatus::Erasing])->save();
        $runner = app(Runner::class);

        $runner->runGroup($request, Order::class);
        $changed = $request->steps()->where('group_key', Order::class)->value('changed');
        $runner->runGroup($request, Order::class);

        $this->assertSame(2, $changed);
        $this->assertSame(2, (int) $request->steps()->where('group_key', Order::class)->value('changed'));
        $this->assertSame(1, $request->steps()->where('group_key', Order::class)->count());
    }

    public function test_a_failure_in_the_middle_leaves_a_partial_status_and_a_rerun_finishes_forward(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite trigger syntax; the same scenario runs on MariaDB / Postgres in tests/Database.');
        }

        $ada = Shop::customer('Ada', 'ada@customer.example');
        // A real database failure inside the last group: the customer update is refused by a trigger.
        DB::unprepared("CREATE TRIGGER gdpr_test_fail BEFORE UPDATE ON customers BEGIN SELECT RAISE(ABORT, 'disk full at ada@customer.example'); END");
        $request = $this->erasure($ada);
        $this->confirm($request);

        try {
            $this->execute($request);
            $this->fail('The chain should stop with the exception.');
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        }

        $request->refresh();
        $this->assertSame(RequestStatus::Partial, $request->status, 'A failed run is never shown as a success.');
        $steps = $request->steps()->get()->keyBy('group_key');
        $this->assertSame(StepStatus::Done, $steps[Order::class]->status);
        $this->assertSame(StepStatus::Failed, $steps[Customer::class]->status);
        $this->assertStringNotContainsString('ada@customer.example', (string) $steps[Customer::class]->error, 'Raw messages are not kept.');
        // The failed group rolled back completely; earlier groups stay done.
        $this->assertSame('Ada', Customer::query()->first()->name);
        $this->assertSame(0, Ticket::query()->count());

        DB::unprepared('DROP TRIGGER gdpr_test_fail');
        $this->execute($request);

        $request->refresh();
        $this->assertSame(RequestStatus::Fulfilled, $request->status);
        $steps = $request->steps()->get()->keyBy('group_key');
        $this->assertSame(2, $steps[Order::class]->changed, 'The finished group is not redone or recounted.');
        $this->assertSame(1, $steps[Order::class]->attempts);
        $this->assertSame(2, $steps[Customer::class]->attempts);
        $this->assertSame('Erased customer', Customer::query()->first()->name);
    }

    public function test_changed_data_after_confirmation_requires_a_new_confirmation(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->erasure($ada);
        $this->confirm($request);

        Order::query()->create(['customer_id' => $ada->id, 'number' => 'late', 'total' => 1]);

        $this->expectException(InvalidTransition::class);

        $this->execute($request);
    }

    public function test_a_changed_data_map_after_confirmation_invalidates_it(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->erasure($ada);
        $this->confirm($request);

        $this->registry()->add(PersonalData::for(Ticket::class)->label('Support tickets')->of(Customer::class)->matchedByEmail('customer_email')->erase(Erase::anonymize(['body' => Rule::fixed('x')])));

        $this->expectException(InvalidTransition::class);

        $this->execute($request);
    }

    public function test_an_old_confirmation_expires(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));
        $this->confirm($request);
        $this->travelTo(now()->addHours(73));

        $this->expectException(InvalidTransition::class);

        $this->execute($request);
    }

    public function test_a_rule_that_breaks_not_null_blocks_the_preview(): void
    {
        $this->registry()->add(PersonalData::for(Customer::class)->subject()->erase(Erase::anonymize(['name' => Rule::null()])));
        $plan = app(ErasureService::class)->plan($this->erasure(Shop::customer('Ada', 'ada@customer.example')));

        $this->assertContains('not_null', $this->codes($plan));
        $this->assertTrue($plan->isBlocked());
    }

    public function test_a_constant_in_a_unique_column_blocks_when_more_than_one_row_is_touched(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Order::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize(['number' => Rule::fixed('X')])));
        $plan = app(ErasureService::class)->plan($this->erasure($ada));

        $this->assertContains('unique_fixed', $this->codes($plan));
    }

    public function test_a_constant_that_another_row_already_holds_blocks(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Customer::query()->create(['name' => 'Taken', 'email' => 'erased@erased.invalid']);
        $this->registry()->add(PersonalData::for(Customer::class)->subject()->erase(Erase::anonymize(['email' => Rule::fixed('erased@erased.invalid')])));
        $plan = app(ErasureService::class)->plan($this->erasure($ada));

        $this->assertContains('unique_conflict', $this->codes($plan));
    }

    public function test_a_callback_on_a_unique_column_blocks_unless_it_vouches_for_uniqueness(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Customer::class)->subject()->erase(Erase::anonymize(['email' => Rule::using(fn () => 'x@y.invalid')])));
        $this->assertContains('unique_callback', $this->codes(app(ErasureService::class)->plan($this->erasure($ada))));

        $this->registry()->add(PersonalData::for(Customer::class)->subject()->erase(Erase::anonymize(['email' => Rule::using(fn ($v) => $v, unique: true)])));
        $this->assertNotContains('unique_callback', $this->codes(app(ErasureService::class)->plan($this->erasure($ada))));
    }

    public function test_a_value_too_long_for_the_column_and_a_hash_in_a_number_column_block(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Customer::class)->subject()->erase(Erase::anonymize([
            'name' => Rule::fixed(str_repeat('x', 300)),
            'birthday' => Rule::hash(),
        ])));
        $codes = $this->codes(app(ErasureService::class)->plan($this->erasure($ada)));

        // SQLite does not report a declared length, so this one is asserted on MariaDB / Postgres only.
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertContains('too_long', $codes);
        }

        $this->assertContains('type_mismatch', $codes);
    }

    public function test_rules_on_the_key_the_match_column_or_a_foreign_key_block(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Order::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize([
            'id' => Rule::fixed(1),
            'customer_id' => Rule::fixed(9),
        ])));
        $codes = $this->codes(app(ErasureService::class)->plan($this->erasure($ada)));

        $this->assertContains('rule_on_key', $codes);
        $this->assertContains('rule_on_match_column', $codes);
        $this->assertContains('foreign_key_column', $codes);
    }

    public function test_deleting_a_row_that_something_unmapped_or_unwatched_still_references_blocks(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        // Orders become a delete, but the invoices (retained) have no FK to orders; use customers as the deleted parent.
        $this->registry()->add(PersonalData::for(Customer::class)->subject()->erase(Erase::delete()));
        $plan = app(ErasureService::class)->plan($this->erasure($ada));

        $this->assertContains('still_referenced', $this->codes($plan), 'Orders/invoices/documents still point at the customer.');
    }

    public function test_a_table_outside_the_map_that_references_a_mapped_table_blocks_until_acknowledged(): void
    {
        $this->registry()->flush();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Customer::class)->subject()->erase(Erase::anonymize(['phone' => Rule::null()])));
        $request = $this->erasure($ada);

        $codes = $this->codes(app(ErasureService::class)->plan($request));
        $this->assertContains('unmapped_reference', $codes);

        $this->expectException(PlanBlocked::class);
        $this->confirm($request);
    }

    public function test_no_matcher_and_a_missing_subject_model_block(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Ticket::class)->label('Loose')->erase(Erase::delete()));
        $codes = $this->codes(app(ErasureService::class)->plan($this->erasure($ada)));

        $this->assertContains('no_matcher', $codes);
        $this->assertContains('no_subject_model', $codes);
    }

    public function test_an_entry_without_an_erase_rule_is_left_untouched_and_said_so(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Ticket::class)->label('Support tickets')->of(Customer::class)->matchedByEmail('customer_email')->export(['id']));
        $request = $this->erasure($ada);
        $this->confirm($request);
        $this->execute($request);

        $this->assertSame(1, Ticket::query()->where('customer_email', 'ada@customer.example')->count());
        $this->assertSame(StepStatus::Skipped, $request->steps()->where('group_key', Ticket::class)->first()->status);
    }

    public function test_the_email_match_is_exact_not_a_search(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Ticket::query()->create(['customer_email' => 'ada@customer.example.org', 'subject' => 's', 'body' => 'someone else']);
        Ticket::query()->create(['customer_email' => ' ADA@Customer.Example ', 'subject' => 's', 'body' => 'same person, other spelling']);
        $request = $this->erasure($ada);
        $this->confirm($request);
        $this->execute($request);

        $left = Ticket::query()->pluck('customer_email')->all();
        $this->assertSame(['ada@customer.example.org'], $left);
    }

    public function test_it_refuses_to_work_on_a_panel_with_tenancy(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));
        \Filament\Facades\Filament::getPanel('admin')->tenant(Customer::class);

        $this->expectException(\Asignua\FilamentGdprRequests\Exceptions\UnsupportedEnvironment::class);

        app(ErasureService::class)->plan($request);
    }

    public function test_without_the_pseudonym_key_the_plan_is_blocked(): void
    {
        $request = $this->erasure(Shop::customer('Ada', 'ada@customer.example'));
        config(['filament-gdpr-requests.pseudonym.key' => null]);

        $this->assertContains('pseudonym_key_missing', $this->codes(app(ErasureService::class)->plan($request)));
    }

    public function test_an_unwritable_file_keeps_the_group_failed_with_a_pending_list(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Document::query()->where('customer_id', $ada->id)->update(['disk' => 'vanished-disk']);
        $request = $this->erasure($ada);
        $this->confirm($request);

        try {
            $this->execute($request);
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $request->refresh();
        $this->assertSame(RequestStatus::Partial, $request->status);
        $step = $request->steps()->where('group_key', Document::class)->first();
        $this->assertSame(StepStatus::Failed, $step->status);
        $this->assertStringContainsString('vanished-disk', (string) $step->detail);
        $this->assertSame(0, Document::query()->count(), 'Rows are gone; the pending files are what is left to do.');
    }

    /**
     * @return list<string>
     */
    private function codes(\Asignua\FilamentGdprRequests\Erasure\ErasurePlan $plan): array
    {
        return array_map(fn ($blocker) => $blocker->code, $plan->allBlockers());
    }
}
