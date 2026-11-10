<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Selection;
use Asignua\FilamentGdprRequests\DataMap\Subject;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\PlanBlocked;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\ExportService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Ticket;

/**
 * An address finds rows only while it points at one person, and it reads the same in SQL as in PHP on every driver.
 */
class SubjectAddressTest extends TestCase
{
    use RunsErasures;

    private const string NBSP = "\u{00A0}";

    /**
     * @return array<string, array{string}>
     */
    public static function spellings(): array
    {
        return [
            'tab before' => ["\tada@customer.example"],
            'newline after' => ["ada@customer.example\n"],
            'carriage return and newline' => ["\r\nada@customer.example\r\n"],
            'no-break space' => [self::NBSP.'ada@customer.example'.self::NBSP],
            'mixed case' => ['AdA@Customer.EXAMPLE'],
            'all of it' => [" \t".self::NBSP."ADA@customer.example\r\n "],
        ];
    }

    #[DataProvider('spellings')]
    public function test_every_spelling_reads_the_same_in_sql_and_in_php(string $spelling): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $ticket = Ticket::query()->create(['customer_email' => $spelling, 'subject' => 's', 'body' => 'b']);
        Ticket::query()->create(['customer_email' => 'ada@customer.example.org', 'subject' => 's', 'body' => 'someone else']);
        Ticket::query()->create(['customer_email' => 'a da@customer.example', 'subject' => 's', 'body' => 'someone else']);

        $this->assertSame('ada@customer.example', Pseudonym::normalizeEmail($spelling));

        $this->registry()->forget(Ticket::class);
        $entry = PersonalData::for(Ticket::class)->of(Customer::class)->matchedByEmail('customer_email')->export(['id'])->erase(Erase::delete());
        $selection = Selection::forSubject($entry, new Subject($this->registry()->rootFor(Customer::class), $ada->id, 'ada@customer.example'));
        $keys = array_map(static fn (array $row): int => (int) $row['id'], iterator_to_array($selection->rows(), false));

        $this->assertContains($ticket->id, $keys);
        $this->assertCount(2, $keys, 'The two spellings of this address and nothing else.');
    }

    #[DataProvider('spellings')]
    public function test_no_spelling_survives_an_erasure(string $spelling): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Ticket::query()->create(['customer_email' => $spelling, 'subject' => 's', 'body' => 'b']);
        $request = $this->confirmed($ada);

        $this->execute($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_letters_outside_ascii_are_folded_too(): void
    {
        $ada = Shop::customer('Ada', 'адаа@customer.example');
        Ticket::query()->create(['customer_email' => 'АДАА@customer.example', 'subject' => 's', 'body' => 'b']);
        $request = $this->confirmed($ada);

        $this->execute($request);

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_the_control_characters_php_trims_are_gone_from_the_normal_form(): void
    {
        $this->assertSame('ada@customer.example', Pseudonym::normalizeEmail("\0\x0B\x0Cada@customer.example\u{FEFF}\u{3000}"));
        $this->assertNull(Pseudonym::normalizeEmail(" \t\n".self::NBSP));
    }

    public function test_two_records_with_one_address_block_the_erasure_of_either(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        // The unique index does not see these as one address; the matching does.
        $eve = Shop::customer('Eve', ' ada@customer.example');
        $request = $this->erasureOf($ada);

        $plan = app(ErasureService::class)->plan($request);

        $this->assertContains('subject_email_shared', $this->codes($plan));

        try {
            app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));
            $this->fail('A shared address must not be confirmed.');
        } catch (PlanBlocked) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, Ticket::query()->where('body', 'Eve wrote this')->count());
        $this->assertSame('Eve', $eve->fresh()->name);
    }

    public function test_two_records_with_one_address_block_the_export(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Eve', ' ada@customer.example');
        $dpo = $this->user('dpo');
        $request = Shop::request($ada, RequestType::Access, $dpo);

        $this->expectException(GdprException::class);
        $this->expectExceptionMessageContaining(__('filament-gdpr-requests::filament-gdpr-requests.blocker.subject_email_shared', ['rows' => 2]));

        app(ExportService::class)->request($request, $dpo);
    }

    public function test_a_soft_deleted_record_shares_its_address_all_the_same(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Eve', ' ada@customer.example')->delete();

        $this->assertContains('subject_email_shared', $this->codes(app(ErasureService::class)->plan($this->erasureOf($ada))));
    }

    public function test_an_address_taken_over_after_the_root_was_erased_blocks_the_rest(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->confirmed($ada);
        $this->runGroups($request);
        Shop::customer('Eve', 'ada@customer.example');

        $this->finalize($request);

        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertContains('subject_email_shared', array_column($request->erasure_plan['findings']['blockers'], 'code'));
        $this->assertSame(1, Ticket::query()->where('body', 'Eve wrote this')->count());
    }

    public function test_a_lookup_by_address_sees_every_spelling(): void
    {
        Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Eve', "\tada@customer.example");

        $this->expectException(GdprException::class);

        app(RequestService::class)->findSubjectByEmail(Customer::class, 'ada@customer.example');
    }
}
