<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Invoice;

/**
 * A retained row stays whole: no other group of the run may change or delete it.
 */
class RetainOverlapTest extends TestCase
{
    use RunsErasures;

    public function test_a_deletion_that_overlaps_a_retained_group_blocks(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Invoice::class, 'invoice-purge')->of(Customer::class)->matchedBy('customer_id')->erase(Erase::delete());

        $this->assertContains('retain_overlap', $this->codes(app(ErasureService::class)->plan($this->erasureOf($ada))));
    }

    public function test_an_anonymization_that_overlaps_a_retained_group_blocks(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Invoice::class, 'invoice-mask')->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize(['billed_to' => Rule::fixed('Erased')]));

        $this->assertContains('retain_overlap', $this->codes(app(ErasureService::class)->plan($this->erasureOf($ada))));
    }

    public function test_groups_on_the_same_table_that_share_no_rows_with_a_retained_one_are_fine(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Invoice::class, 'invoice-drafts')->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query): void {
                $query->where('number', 'no such invoice');
            });

        $this->assertNotContains('retain_overlap', $this->codes(app(ErasureService::class)->plan($this->erasureOf($ada))));
    }
}
