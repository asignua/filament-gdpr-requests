<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;

/**
 * Every way a request ends starts the clock of request_retention_days.
 */
class ClosedRetentionTest extends TestCase
{
    use RunsErasures;

    public function test_a_completed_request_is_wiped_after_the_retention_period(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada Lovelace', 'ada@customer.example'), RequestType::Access, $dpo);
        app(RequestService::class)->complete($request, $dpo);

        $this->assertNotNull($request->refresh()->closed_at);
        $this->travelTo(now()->addDays(91));
        $this->artisan('gdpr:prune')->assertSuccessful();

        $this->assertNull($request->refresh()->subject_name);
        $this->assertNotNull($request->details_purged_at);
    }

    public function test_a_refused_request_is_wiped_after_the_retention_period(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada Lovelace', 'ada@customer.example'), RequestType::Erasure, $dpo);
        app(RequestService::class)->refuse($request, 'Manifestly unfounded', $dpo);

        $this->assertNotNull($request->refresh()->closed_at);
        $this->travelTo(now()->addDays(91));
        $this->artisan('gdpr:prune')->assertSuccessful();

        $this->assertNull($request->refresh()->subject_name);
        $this->assertNull($request->refusal_reason);
    }

    public function test_closing_a_completed_request_later_does_not_restart_its_clock(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);
        app(RequestService::class)->complete($request, $dpo);
        $closed = $request->refresh()->closed_at;

        $this->travelTo(now()->addDays(10));
        app(RequestService::class)->close($request, $dpo);

        $this->assertSame(RequestStatus::Closed, $request->refresh()->status);
        $this->assertNull($request->subject_id);
        $this->assertTrue($closed->equalTo($request->closed_at));
    }

    public function test_a_request_that_ended_before_closed_at_was_set_is_still_wiped(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada Lovelace', 'ada@customer.example'), RequestType::Access, $dpo);
        app(RequestService::class)->complete($request, $dpo);
        $request->refresh()->forceFill(['closed_at' => null])->save();

        $this->travelTo(now()->addDays(91));
        $this->artisan('gdpr:prune')->assertSuccessful();

        $this->assertNull($request->refresh()->subject_name);
    }
}
