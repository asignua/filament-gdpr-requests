<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\StepStatus;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ErasurePlanPage;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\Support\StuckDisk;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Order;
use Workbench\App\Models\Ticket;

/**
 * `partial` only goes forward, and forward has to exist: a new row or a fixed map is a new preview and a new
 * confirmation, with the same rules as the first one, and the record of what already happened stays.
 */
class ReconfirmTest extends TestCase
{
    use RunsErasures;

    private function partialWithALateTicket(Customer $customer): GdprRequest
    {
        $request = $this->confirmed($customer);
        $this->runGroups($request);
        Ticket::query()->create(['customer_email' => $customer->email ?? 'ada@customer.example', 'subject' => 'Late', 'body' => 'Still here']);
        $this->finalize($request);
        $this->assertSame(RequestStatus::Partial, $request->status);

        return $request;
    }

    public function test_a_new_row_is_erased_after_a_fresh_preview_and_confirmation(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->partialWithALateTicket($ada);

        $plan = app(ErasureService::class)->plan($request);
        $this->assertFalse($plan->isBlocked(), implode('; ', $this->codes($plan)));
        $this->assertSame(1, $plan->group(Ticket::class)->matched);

        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Second confirmer'));
        $this->assertSame(RequestStatus::ErasureConfirmed, $request->refresh()->status);

        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(0, Ticket::query()->where('customer_email', 'ada@customer.example')->count());
        $this->assertSame('Erased customer', $ada->fresh()->name);
    }

    public function test_resuming_past_a_new_row_is_refused_with_the_way_forward(): void
    {
        $request = $this->partialWithALateTicket(Shop::customer('Ada', 'ada@customer.example'));

        try {
            $this->execute($request);
            $this->fail('The confirmed plan did not cover this row.');
        } catch (InvalidTransition $e) {
            $this->assertSame(__('filament-gdpr-requests::filament-gdpr-requests.errors.plan_changed'), $e->getMessage());
        }

        $this->assertSame(RequestStatus::Partial, $request->refresh()->status);
    }

    public function test_four_eyes_applies_to_the_new_confirmation(): void
    {
        $request = $this->partialWithALateTicket(Shop::customer('Ada', 'ada@customer.example'));
        $same = $this->user('dpo', 'Same person');
        app(ErasureService::class)->confirm($request, $same);

        $this->expectException(NotPermitted::class);

        app(ErasureService::class)->start($request->refresh(), $same);
    }

    public function test_the_first_confirmer_may_run_what_somebody_else_confirmed_again(): void
    {
        $first = $this->user('dpo', 'First');
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->erasureOf($ada);
        app(ErasureService::class)->confirm($request, $first);
        $request->refresh();
        $this->runGroups($request);
        Ticket::query()->create(['customer_email' => 'ada@customer.example', 'subject' => 'Late', 'body' => 'Still here']);
        $this->finalize($request);

        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Second'));
        app(ErasureService::class)->start($request->refresh(), $first);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    public function test_the_report_keeps_what_each_round_did(): void
    {
        $request = $this->partialWithALateTicket(Shop::customer('Ada', 'ada@customer.example'));
        $firstBy = $request->erasure_confirmed_by;

        $second = $this->user('dpo', 'Second confirmer');
        app(ErasureService::class)->confirm($request, $second);

        $step = $request->steps()->where('group_key', Ticket::class)->first();
        $this->assertSame(StepStatus::Pending, $step->status, 'A settled step of the old confirmation does not count for the new one.');
        $this->assertSame(1, $step->details()['earlier'][0]['changed']);

        $this->execute($request);

        $step->refresh();
        $this->assertSame(StepStatus::Done, $step->status);
        $this->assertSame(2, $step->changed, 'One row in each round.');
        $this->assertSame(2, $step->matched);
        $this->assertCount(1, $step->details()['earlier']);

        $events = $request->events()->pluck('event')->all();
        $this->assertSame(2, array_count_values($events)['erasure_confirmed']);
        $this->assertSame(1, array_count_values($events)['erasure_partial']);
        $this->assertSame(1, array_count_values($events)['erasure_reconfirmed']);
        $this->assertSame(1, array_count_values($events)['erasure_finished']);
        $this->assertSame((string) $firstBy, $request->events()->where('event', 'erasure_confirmed')->first()->actor_id);
    }

    public function test_a_step_that_changed_nothing_this_round_keeps_the_rows_of_the_last(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->partialWithALateTicket($ada);
        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Second confirmer'));
        $this->execute($request);

        $orders = $request->steps()->where('group_key', Order::class)->first();

        $this->assertSame(2, $orders->matched);
        $this->assertSame(2, $orders->changed);
    }

    public function test_a_fixed_map_gets_a_new_confirmation_and_the_rows_follow_the_new_rules(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->confirmed($ada);
        $this->runGroups($request);
        PersonalData::for(Order::class)->label('Orders')->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize([
            'shipping_address' => Rule::fixed('Erased'),
            'billing_name' => Rule::fixed('Erased by the second map'),
        ]));
        $this->finalize($request);
        $this->assertSame(RequestStatus::Partial, $request->status);

        try {
            $this->execute($request);
            $this->fail('The confirmation was for the old map.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        app(ErasureService::class)->confirm($request->refresh(), $this->user('dpo', 'Second confirmer'));
        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(['Erased by the second map'], Order::query()->where('customer_id', $ada->id)->distinct()->pluck('billing_name')->all());
    }

    public function test_a_deleted_root_stays_erased_through_a_new_confirmation(): void
    {
        $this->registry()->flush();

        foreach (['orders', 'documents', 'invoices', 'audit_notes'] as $table) {
            GdprRequests::acknowledge($table, 'Not part of this test.');
        }

        PersonalData::for(Customer::class)->subject(email: 'email')->erase(Erase::delete());
        PersonalData::for(Ticket::class)->of(Customer::class)->matchedByEmail('customer_email')->erase(Erase::delete());
        $ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@customer.example']);
        Ticket::query()->create(['customer_email' => 'ada@customer.example', 'subject' => 's', 'body' => 'b']);

        $request = $this->partialWithALateTicket($ada);
        $this->assertNull(Customer::query()->withTrashed()->find($ada->id));

        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Second confirmer'));
        app(ErasureService::class)->confirm($request->refresh(), $this->user('dpo', 'Third confirmer'));
        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_a_file_still_pending_is_not_forgotten_by_a_new_confirmation(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        StuckDisk::install('stuck');
        Storage::disk('stuck')->put('docs/ada.txt', 'x');
        Document::query()->where('customer_id', $ada->id)->update(['disk' => 'stuck', 'path' => 'docs/ada.txt']);
        $request = $this->confirmed($ada);

        try {
            $this->execute($request);
            $this->fail('The disk refuses.');
        } catch (GdprException) {
            $this->addToAssertionCount(1);
        }

        app(ErasureService::class)->confirm($request->refresh(), $this->user('dpo', 'Second confirmer'));
        StuckDisk::release('stuck');
        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertFalse(Storage::disk('stuck')->exists('docs/ada.txt'), 'The row that named the file is long gone; the step still knew.');
    }

    public function test_a_partial_request_stays_on_the_same_person_and_cannot_be_refused_away(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bruno = Shop::customer('Bruno', 'bruno@customer.example');
        $request = $this->partialWithALateTicket($ada);
        $dpo = $this->user('dpo');

        foreach ([
            fn () => app(RequestService::class)->linkSubject($request, Customer::class, $bruno->id, $dpo),
            fn () => app(RequestService::class)->refuse($request, 'No', $dpo),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A partial erasure only goes forward.');
            } catch (InvalidTransition) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame((string) $ada->id, $request->refresh()->subject_id);
    }

    public function test_a_new_confirmation_does_not_open_the_way_to_a_refusal(): void
    {
        $request = $this->partialWithALateTicket(Shop::customer('Ada', 'ada@customer.example'));
        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Second confirmer'));

        $this->expectException(InvalidTransition::class);

        app(RequestService::class)->refuse($request->refresh(), 'No', $this->user('dpo'));
    }

    public function test_the_page_offers_the_new_confirmation_and_shows_the_list(): void
    {
        $request = $this->partialWithALateTicket(Shop::customer('Ada', 'ada@customer.example'));
        $this->user('dpo');

        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->assertActionVisible('confirm')
            ->assertActionEnabled('confirm')
            ->assertSee(__('filament-gdpr-requests::filament-gdpr-requests.plan.finding_remaining', ['group' => 'Support tickets', 'rows' => 1]));
    }
}
