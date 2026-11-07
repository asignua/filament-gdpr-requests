<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ViewGdprRequest;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Livewire\Livewire;
use Workbench\App\Models\Ticket;

/**
 * A finished erasure closes itself: nothing else would ever start the clock on the name and address it still holds.
 */
class AutoCloseTest extends TestCase
{
    use RunsErasures;

    public function test_a_fulfilled_erasure_is_closed_and_cut_from_the_record(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $pseudonym = $request->pseudonym;

        $this->execute($request);

        $request->refresh();
        $this->assertSame(RequestStatus::Fulfilled, $request->status, 'The outcome stays readable in the register.');
        $this->assertNotNull($request->closed_at);
        $this->assertNull($request->subject_model);
        $this->assertNull($request->subject_id);
        $this->assertSame($pseudonym, $request->pseudonym);
        $this->assertContains('closed', $request->events()->pluck('event')->all());
    }

    public function test_the_details_go_after_the_retention_period_without_anyone_closing_it(): void
    {
        $request = $this->confirmed(Shop::customer('Ada Lovelace', 'ada@customer.example'));
        app(RequestService::class)->addNote($request, 'Phoned the customer', $this->user('dpo'));
        $this->execute($request);

        $this->travelTo(now()->addDays(89));
        $this->artisan('gdpr:prune')->assertSuccessful();
        $this->assertSame('Ada Lovelace', $request->refresh()->subject_name, 'Not before the period ends.');

        $this->travelTo(now()->addDays(2));
        $this->artisan('gdpr:prune')->assertSuccessful();

        $request->refresh();
        $this->assertNull($request->subject_name);
        $this->assertNull($request->subject_email);
        $this->assertNull($request->notes);
        $this->assertNotNull($request->details_purged_at);
    }

    public function test_a_partial_erasure_stays_open_and_linked(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->runGroups($request);
        Ticket::query()->create(['customer_email' => 'ada@customer.example', 'subject' => 'Late', 'body' => 'Still here']);

        $this->finalize($request);

        $this->assertNull($request->closed_at);
        $this->assertTrue($request->hasSubject());
    }

    public function test_it_is_not_closed_a_second_time(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->execute($request);
        $closed = $request->refresh()->closed_at;
        $this->travelTo(now()->addDays(30));

        try {
            app(RequestService::class)->close($request, $this->user('dpo'));
            $this->fail('The clock must not start again.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $this->assertEquals($closed, $request->refresh()->closed_at);
        $this->assertSame(RequestStatus::Fulfilled, $request->status);
    }

    public function test_the_page_no_longer_offers_to_close_it(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->execute($request);
        $this->user('dpo');

        Livewire::test(ViewGdprRequest::class, ['record' => $request->ulid])->assertActionHidden('close');
    }
}
