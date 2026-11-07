<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\CreateGdprRequest;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ErasurePlanPage;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ListGdprRequests;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ViewGdprRequest;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Support\Lang;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Livewire\Livewire;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

class ResourceTest extends TestCase
{
    public function test_a_user_without_the_view_gate_cannot_open_the_register(): void
    {
        $this->actingAs($this->user('none'))->get(GdprRequestResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(GdprRequestResource::getUrl('index'))->assertRedirect();
    }

    public function test_the_list_shows_requests_overdue_ones_marked(): void
    {
        $this->user('manager');
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $this->user('manager'));
        $this->travelTo(now()->addMonths(2));

        Livewire::test(ListGdprRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertSee(Lang::t('table.overdue'));
    }

    public function test_a_manager_creates_a_request_and_the_email_is_looked_up_exactly(): void
    {
        $this->user('manager');
        $ada = Shop::customer('Ada', 'ada@customer.example');

        Livewire::test(CreateGdprRequest::class)
            ->fillForm([
                'type' => 'access',
                'channel' => 'email',
                'received_at' => now()->toDateTimeString(),
                'name' => 'Ada',
                'email' => 'ADA@customer.example',
                'subject_model' => Customer::class,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = GdprRequest::query()->first();
        $this->assertSame((string) $ada->id, $request->subject_id);
        $this->assertFalse($request->isVerified());
    }

    public function test_the_view_page_walks_verification_extension_and_notes(): void
    {
        $manager = $this->user('manager');
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = \Asignua\FilamentGdprRequests\GdprRequests::submit(RequestType::Access, 'ada@customer.example', 'Ada');

        $page = Livewire::test(ViewGdprRequest::class, ['record' => $request->ulid]);
        $page->assertActionVisible('link')->assertActionVisible('verify');
        $page->callAction('link', ['model' => Customer::class, 'id' => null, 'email' => 'ada@customer.example'])
            ->assertNotified(Lang::t('notifications.linked'));
        $this->assertSame((string) $ada->id, $request->refresh()->subject_id);

        $page->callAction('verify', ['note' => 'Replied from the registered address.'])
            ->assertNotified(Lang::t('notifications.verified'));
        $this->assertTrue($request->refresh()->isVerified());

        $page->callAction('extend', ['months' => 1, 'reason' => 'Many systems involved']);
        $this->assertNotNull($request->refresh()->extended_at);

        $page->callAction('note', ['note' => 'Spoke to the customer']);
        $this->assertStringContainsString('Spoke to the customer', (string) $request->refresh()->notes);

        // A manager has neither the export nor the erase right: the buttons are not even there.
        $page->assertActionHidden('export')->assertActionHidden('erasure');
        $this->assertSame($manager->id, $manager->id);
    }

    public function test_verify_without_a_note_is_rejected(): void
    {
        $this->user('manager');
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = \Asignua\FilamentGdprRequests\GdprRequests::submit(RequestType::Access, 'ada@customer.example', subjectModel: Customer::class, subjectId: $ada->id);

        Livewire::test(ViewGdprRequest::class, ['record' => $request->ulid])
            ->callAction('verify', ['note' => ''])
            ->assertHasActionErrors(['note' => 'required']);

        $this->assertFalse($request->refresh()->isVerified());
    }

    public function test_the_erasure_page_shows_the_numbers_and_needs_the_typed_reference(): void
    {
        $this->user('dpo', 'Confirmer');
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Order::query()->where('number', 'Ada-1')->first()->delete();
        $request = Shop::request($ada, RequestType::Erasure, $this->user('dpo', 'Confirmer'));

        $page = Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->assertSee('Orders')
            ->assertSee(Lang::t('action.delete'))
            ->assertSee(Lang::t('plan.irreversible'));

        $page->callAction('confirm', ['reference' => 'WRONG123'])->assertHasActionErrors(['reference']);
        $this->assertSame(RequestStatus::InProgress, $request->refresh()->status);

        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->callAction('confirm', ['reference' => strtolower($request->reference())])->assertHasNoActionErrors();
        $this->assertSame(RequestStatus::ErasureConfirmed, $request->refresh()->status);
        $this->assertSame('Ada', $ada->fresh()->name, 'Confirming erases nothing.');
    }

    public function test_the_same_person_cannot_run_and_a_second_one_can_from_the_page(): void
    {
        $confirmer = $this->user('dpo', 'Confirmer');
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = Shop::request($ada, RequestType::Erasure, $confirmer);
        app(ErasureService::class)->confirm($request, $confirmer);

        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->callAction('run', ['reference' => $request->reference()]);
        $this->assertSame(RequestStatus::ErasureConfirmed, $request->refresh()->status, 'Four eyes held.');
        $this->assertSame('Ada', $ada->fresh()->name);

        $this->actingAs($this->user('dpo', 'Second'));
        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->callAction('run', ['reference' => $request->reference()]);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame('Erased customer', $ada->fresh()->name);
    }

    public function test_the_erasure_page_is_closed_to_those_without_the_erase_gate(): void
    {
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Erasure, $this->user('dpo'));

        $this->actingAs($this->user('manager'))->get(GdprRequestResource::getUrl('erasure', ['record' => $request]))->assertForbidden();
    }

    public function test_a_blocked_plan_disables_the_confirm_button_and_lists_the_reasons(): void
    {
        $dpo = $this->user('dpo');
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Erasure, $dpo);
        $this->registry()->flush();
        $this->registry()->add(\Asignua\FilamentGdprRequests\DataMap\PersonalData::for(Customer::class)->subject()
            ->erase(\Asignua\FilamentGdprRequests\DataMap\Erase::anonymize(['phone' => \Asignua\FilamentGdprRequests\DataMap\Rule::null()])));

        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->assertSee(Lang::t('plan.blocked'))
            ->assertActionDisabled('confirm');
    }

    public function test_the_view_page_after_a_partial_erasure_shows_the_failure_not_a_success(): void
    {
        $dpo = $this->user('dpo');
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Erasure, $dpo);
        $request->forceFill(['status' => RequestStatus::Partial, 'erasure_confirmed_at' => now(), 'erasure_confirmed_by' => '999', 'erasure_plan' => ['order' => []]])->save();
        $request->steps()->create(['group_key' => 'x', 'label' => 'Orders', 'strategy' => 'anonymize', 'status' => 'failed', 'error' => 'Database error (SQLSTATE 23000, driver code 19)']);

        Livewire::test(ViewGdprRequest::class, ['record' => $request->ulid])
            ->assertSee(RequestStatus::Partial->label())
            ->assertSee('SQLSTATE 23000');
        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->assertSee(Lang::t('plan.partial'))
            ->assertDontSee(Lang::t('plan.done'));
    }
}
