<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Ticket;

/**
 * The database finds every address PHP would accept, whatever its letters.
 */
class UnicodeAddressTest extends TestCase
{
    use RunsErasures;

    /**
     * @return array<string, array{string}>
     */
    public static function unicodeAddresses(): array
    {
        return [
            'dotted capital I' => ['İnci@customer.example'],
            'final sigma' => ['ΟΔΥΣ@customer.example'],
        ];
    }

    #[DataProvider('unicodeAddresses')]
    public function test_an_address_whose_lowercase_is_special_is_found_in_sql(string $address): void
    {
        $customer = Shop::customer('Someone', $address);

        $plan = app(ErasureService::class)->plan($this->erasureOf($customer));

        $this->assertSame(1, $plan->group(Ticket::class)?->matched);
        $this->assertSame($customer->id, (int) app(RequestService::class)->findSubjectByEmail(Customer::class, $address));
    }

    public function test_an_address_that_only_folds_the_same_way_is_still_someone_else(): void
    {
        $customer = Shop::customer('Someone', 'ΟΔΥΣ@customer.example');
        // PHP lowercases the final Σ to ς: this σ spelling is a different address, though the query folds them together.
        Ticket::query()->create(['customer_email' => 'οδυσ@customer.example', 'subject' => 's', 'body' => 'someone else']);

        $plan = app(ErasureService::class)->plan($this->erasureOf($customer));

        $this->assertSame(1, $plan->group(Ticket::class)?->matched);
        $this->assertNotContains('subject_email_shared', $this->codes($plan));
    }
}
