<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ErasurePlanPage;
use Asignua\FilamentGdprRequests\Support\Lang;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Livewire\Livewire;
use Workbench\App\Models\Invoice;

/**
 * What the report says after the run, when the live preview is gone.
 */
class ReportDetailsTest extends TestCase
{
    use RunsErasures;

    public function test_the_report_keeps_the_legal_basis_and_the_date(): void
    {
        $request = $this->confirmed(Shop::customer('Ada', 'ada@customer.example'));
        $this->execute($request);
        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $until = $request->steps()->where('group_key', Invoice::class)->first()->details()['until'];
        $this->assertNotNull($until);

        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->assertSee(Lang::t('plan.basis', ['basis' => 'Accounting law: invoices are kept for 10 years']))
            ->assertSee(Lang::t('plan.until', ['date' => $until]));
    }
}
