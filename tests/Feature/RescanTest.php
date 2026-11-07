<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\DataMap\Subject;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\StepStatus;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Order;
use Workbench\App\Models\Ticket;

/**
 * `fulfilled` means the data was looked at again after the run and nothing the plan said would go is still there. That
 * the steps finished is not the same thing.
 */
class RescanTest extends TestCase
{
    use RunsErasures;

    /**
     * A map small enough to reason about: only what a test adds to it, on top of the account itself.
     */
    private function smallMap(): void
    {
        $this->registry()->flush();

        foreach (['orders', 'documents', 'invoices', 'audit_notes'] as $table) {
            GdprRequests::acknowledge($table, 'Not part of this test.');
        }

        PersonalData::for(Customer::class)->subject(email: 'email')->erase(Erase::anonymize([
            'name' => Rule::fixed('Erased customer'),
            'email' => Rule::uniqueToken('erased-', '@erased.invalid'),
        ]));
    }

    public function test_a_clean_run_reads_fulfilled(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));

        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertNotNull($request->erasure_finished_at);
    }

    public function test_a_row_added_to_a_deleted_group_after_it_ran_is_found(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->runGroups($request);
        Ticket::query()->create(['customer_email' => 'ada@customer.example', 'subject' => 'Late', 'body' => 'Still here']);

        $this->finalize($request);

        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertNull($request->erasure_finished_at);
        $this->assertSame([Ticket::class => 1], $request->erasure_plan['findings']['remaining']);
        $this->assertSame([Ticket::class => 1], $this->context($request, 'erasure_partial')['remaining']);
    }

    public function test_a_row_added_to_an_anonymized_group_after_it_ran_is_found(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->confirmed($ada);
        $this->runGroups($request);
        Order::query()->create(['customer_id' => $ada->id, 'number' => 'Late', 'total' => 1, 'shipping_address' => 'Ada street 3', 'billing_name' => 'Ada']);

        $this->finalize($request);

        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertSame([Order::class => 1], $request->erasure_plan['findings']['remaining']);
    }

    public function test_rows_the_plan_keeps_and_rows_of_other_people_do_not_stop_it(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Bruno', 'bruno@customer.example');
        $request = $this->confirmed($ada);
        $this->runGroups($request);
        Invoice::query()->create(['customer_id' => $ada->id, 'number' => 'INV-late', 'amount' => 1, 'billed_to' => 'Ada', 'issued_at' => now()]);
        Ticket::query()->create(['customer_email' => 'bruno@customer.example', 'subject' => 'Late', 'body' => 'Bruno']);

        $this->finalize($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->status);
    }

    public function test_a_file_that_should_be_gone_is_found(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Document::class)->of(Customer::class)->matchedBy('customer_id')
            ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]])
            ->erase(Erase::anonymize(['title' => Rule::fixed('Erased')], files: true));
        $request = $this->confirmed($ada);
        $this->runGroups($request);
        Storage::disk('local')->put('docs/Ada.txt', 'written again after the group ran');

        $this->finalize($request);

        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertSame([Document::class => 1], $request->erasure_plan['findings']['files']);
    }

    public function test_a_planned_count_the_run_did_not_handle_is_a_mismatch(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->runGroups($request);
        $plan = $request->erasure_plan;
        $plan['counts'][Ticket::class] = 5;
        $request->forceFill(['erasure_plan' => $plan])->save();

        $this->finalize($request);

        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertSame([Ticket::class => '5 / 1'], $request->erasure_plan['findings']['mismatch']);
    }

    public function test_a_map_that_changed_during_the_run_cannot_vouch_for_it(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->runGroups($request);
        PersonalData::for(Order::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize(['billing_name' => Rule::fixed('Gone')]));

        $this->finalize($request);

        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertTrue($request->erasure_plan['findings']['plan_changed']);
    }

    public function test_a_rescan_that_cannot_run_is_no_success(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->runGroups($request);
        config(['filament-gdpr-requests.pseudonym.key' => null]);

        $this->finalize($request);

        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertContains('pseudonym_key_missing', array_column($request->erasure_plan['findings']['blockers'], 'code'));
    }

    public function test_the_page_lists_what_the_rescan_found(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->runGroups($request);
        Ticket::query()->create(['customer_email' => 'ada@customer.example', 'subject' => 'Late', 'body' => 'Still here']);
        $this->finalize($request);

        $lines = \Asignua\FilamentGdprRequests\Erasure\Findings::fromArray($request->erasure_plan['findings'])->lines();

        $this->assertSame([__('filament-gdpr-requests::filament-gdpr-requests.plan.finding_remaining', ['group' => 'Support tickets', 'rows' => 1])], $lines);
    }

    // Item 5

    public function test_a_closure_group_runs_before_the_table_it_reads(): void
    {
        $this->smallMap();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Invoice::query()->where('customer_id', $ada->id)->update(['billed_to' => 'ada@customer.example']);
        PersonalData::for(Invoice::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::delete());
        PersonalData::for(Ticket::class)->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject): void {
                $query->whereIn('customer_email', Invoice::query()->where('customer_id', $subject->keyValue())->select('billed_to'));
            });
        $request = $this->confirmed($ada);

        $order = $request->erasure_plan['order'];
        $this->assertLessThan(array_search(Invoice::class, $order, true), array_search(Ticket::class, $order, true));

        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(0, Ticket::query()->where('customer_email', 'ada@customer.example')->count());
    }

    public function test_a_query_the_closure_runs_itself_counts_too(): void
    {
        $this->smallMap();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Invoice::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::delete());
        PersonalData::for(Ticket::class)->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject): void {
                $query->whereIn('customer_email', Invoice::query()->where('customer_id', $subject->keyValue())->pluck('billed_to')->all());
            });

        $order = app(ErasureService::class)->plan($this->erasureOf($ada))->groups;
        $keys = array_map(static fn ($group): string => $group->key(), $order);

        $this->assertLessThan(array_search(Invoice::class, $keys, true), array_search(Ticket::class, $keys, true));
    }

    public function test_a_closure_group_that_cannot_run_first_blocks(): void
    {
        $this->smallMap();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        GdprRequests::acknowledge('order_items', 'Handled below.');
        PersonalData::for(\Workbench\App\Models\OrderItem::class)->label('Items')->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject): void {
                $query->whereIn('order_id', Order::query()->where('customer_id', $subject->keyValue())->select('id'));
            });
        // The foreign key wants the items first, this closure wants them still there.
        PersonalData::for(Order::class)->label('Orders')->of(Customer::class)->erase(Erase::anonymize(['billing_name' => Rule::fixed('Erased')]))
            ->matchedUsing(static function (Builder $query, Subject $subject): void {
                $query->whereIn('id', \Workbench\App\Models\OrderItem::query()->where('sku', 'SKU-1')->select('order_id'));
            });

        $plan = app(ErasureService::class)->plan($this->erasureOf($ada));

        $this->assertContains('closure_order', $this->codes($plan));
    }

    public function test_a_first_run_that_finds_fewer_rows_fails_the_group(): void
    {
        $this->smallMap();
        $state = new \ArrayObject(['hide' => false]);
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Ticket::class)->label('Support tickets')->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject) use ($state): void {
                $state['hide'] ? $query->whereRaw('1 = 0') : $query->where('customer_email', $subject->email);
            });
        $request = $this->confirmed($ada);
        $state['hide'] = true;

        try {
            $this->runGroups($request);
            $this->fail('The group found nothing where the plan counted a row.');
        } catch (GdprException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(RequestStatus::Partial, $request->refresh()->status);
        $step = $request->steps()->where('group_key', Ticket::class)->first();
        $this->assertSame(StepStatus::Failed, $step->status);
        $this->assertStringContainsString('Support tickets', (string) $step->errorMessage());
        $this->assertSame(1, Ticket::query()->where('customer_email', 'ada@customer.example')->count());
    }

    public function test_rows_that_vanished_before_the_start_need_a_new_confirmation(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        Ticket::query()->delete();

        $this->expectException(InvalidTransition::class);

        try {
            $this->execute($request);
        } finally {
            $this->assertSame(RequestStatus::ErasureConfirmed, $request->refresh()->status);
            $this->assertSame(0, $request->steps()->count());
        }
    }

    public function test_two_groups_on_one_table_may_share_rows(): void
    {
        $this->smallMap();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Ticket::class, 'by-address')->of(Customer::class)->matchedByEmail('customer_email')->erase(Erase::delete());
        PersonalData::for(Ticket::class, 'by-subject')->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject): void {
                $query->where('body', 'Ada wrote this');
            });
        $request = $this->confirmed($ada);

        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_a_callback_that_changes_its_own_output_blocks_before_anything_happens(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Order::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize([
            'shipping_address' => Rule::using(static fn (mixed $original): string => 'x'.$original),
        ]));

        $this->assertContains('rule_not_repeatable', $this->codes(app(ErasureService::class)->plan($this->erasureOf($ada))));
    }

    public function test_a_value_the_database_stores_differently_is_not_read_as_left_over(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Invoice::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize([
            'issued_at' => Rule::fixed('2000-01-01'),
            'amount' => Rule::fixed(0),
        ]));
        $request = $this->confirmed($ada);

        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }
}
