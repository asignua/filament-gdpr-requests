<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use InvalidArgumentException;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Ticket;

/**
 * A map entry is declared once: a second declaration of the same group must not quietly take the first one's place.
 */
class MapDeclarationTest extends TestCase
{
    public function test_a_second_entry_for_the_same_group_is_refused(): void
    {
        $first = $this->registry()->find(Ticket::class);

        try {
            PersonalData::for(Ticket::class)->of(Customer::class)->matchedBy('customer_email');
            $this->fail('A second declaration of the same group must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(Ticket::class, $e->getMessage());
        }

        $this->assertSame($first, $this->registry()->find(Ticket::class), 'The first declaration stays in force.');
    }

    public function test_the_same_model_under_another_group_is_fine(): void
    {
        $entry = PersonalData::for(Ticket::class, 'archive')->of(Customer::class)->matchedByEmail('customer_email')->erase(Erase::delete());

        $this->assertSame($entry, $this->registry()->find('archive'));
        $this->assertNotSame($entry, $this->registry()->find(Ticket::class));
    }

    public function test_registering_the_same_entry_again_is_harmless(): void
    {
        $entry = PersonalData::for(Ticket::class, 'archive')->of(Customer::class)->matchedByEmail('customer_email');

        $this->registry()->add($entry);

        $this->assertSame($entry, $this->registry()->find('archive'));
    }

    public function test_a_group_can_be_replaced_on_purpose(): void
    {
        $this->registry()->forget(Ticket::class);
        $entry = PersonalData::for(Ticket::class)->of(Customer::class)->matchedByEmail('customer_email')->erase(Erase::delete());

        $this->assertSame($entry, $this->registry()->find(Ticket::class));
    }
}
