<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Erasure\Runner;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ViewGdprRequest;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Livewire\Livewire;
use Workbench\App\Models\Customer;

/**
 * A partial erasure always has a way forward.
 */
class PartialAddressTest extends TestCase
{
    use RunsErasures;

    public function test_a_partial_run_whose_record_changed_its_address_has_a_way_forward(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->confirmed($ada);
        $request->forceFill(['status' => RequestStatus::Erasing, 'erasure_heartbeat_at' => now()])->save();
        app(Runner::class)->runGroup($request, (string) $request->erasure_plan['order'][0]);
        $request->forceFill(['status' => RequestStatus::Partial])->save();
        Customer::query()->whereKey($ada->id)->update(['email' => 'ada.new@customer.example']);
        $this->assertContains('subject_email_mismatch', $this->codes(app(ErasureService::class)->plan($request->refresh())));

        Livewire::test(ViewGdprRequest::class, ['record' => $request->ulid])->assertActionVisible('acceptRecordEmail');
        app(RequestService::class)->acceptRecordEmail($request, $this->user('dpo'));

        $request->refresh();
        $this->assertSame(RequestStatus::Partial, $request->status, 'Still only forward.');
        $this->assertSame('ada.new@customer.example', $request->subject_email);
        $this->assertNull($request->erasure_confirmed_at, 'The old confirmation does not cover the new address.');
        $this->assertNull($request->identity_verified_at);
        $this->assertNotContains('subject_email_mismatch', $this->codes(app(ErasureService::class)->plan($request)));

        app(RequestService::class)->verifyIdentity($request, $this->user('dpo'), 'Confirmed the new address by phone.');
        app(ErasureService::class)->confirm($request->refresh(), $this->user('dpo', 'Confirmer'));
        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }
}
