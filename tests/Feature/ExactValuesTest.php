<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\DataMap\Subject;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Erasure\RowRules;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Models\Account;
use Workbench\App\Models\Customer;

/**
 * Numbers too long for an int or a float are compared as the text they are.
 */
class ExactValuesTest extends TestCase
{
    use RunsErasures;

    private const string TOP = '9223372036854775807';

    private const string OVER = '18446744073709551000';

    private function accounts(): void
    {
        $this->registry()->flush();
        PersonalData::for(Account::class)->subject()->export(['id', 'name'])->erase(Erase::anonymize(['name' => Rule::fixed('Erased')]));
        DB::table('accounts')->insert(['id' => self::TOP, 'name' => 'Top of the range']);

        // An unsigned key goes further than PHP can count; Postgres has no such thing.
        if (DB::getDriverName() === 'mariadb') {
            DB::table('accounts')->insert(['id' => self::OVER, 'name' => 'Past what PHP can hold']);
        }
    }

    private function accountRequest(): GdprRequest
    {
        return GdprRequests::submit(RequestType::Erasure, null, 'Somebody', 'please');
    }

    // Item 10

    public function test_a_key_past_the_range_finds_nothing(): void
    {
        $this->accounts();

        foreach ([self::OVER, '9223372036854775808', '99999999999999999999999999'] as $key) {
            try {
                app(RequestService::class)->linkSubject($this->accountRequest(), Account::class, $key, $this->user('dpo'));
                $this->fail($key.' is not row '.self::TOP.'.');
            } catch (GdprException $e) {
                $this->assertSame(__('filament-gdpr-requests::filament-gdpr-requests.errors.subject_not_found'), $e->getMessage());
            }
        }

        $this->assertSame('Top of the range', Account::query()->whereKey(self::TOP)->value('name'));
    }

    public function test_the_key_at_the_top_of_the_range_is_found_and_stored_as_written(): void
    {
        $this->accounts();

        $request = app(RequestService::class)->linkSubject($this->accountRequest(), Account::class, '00'.self::TOP, $this->user('dpo'));

        $this->assertSame(self::TOP, $request->subject_id);
    }

    public function test_a_key_past_the_range_on_a_request_erases_nothing(): void
    {
        $this->accounts();
        $dpo = $this->user('dpo');
        $request = app(RequestService::class)->linkSubject($this->accountRequest(), Account::class, self::TOP, $dpo);
        app(RequestService::class)->verifyIdentity($request, $dpo, 'Checked.');
        $request->refresh()->forceFill(['subject_id' => self::OVER])->save();

        $this->assertContains('subject_row_missing', $this->codes(app(ErasureService::class)->plan($request)));
        $this->assertSame('Top of the range', Account::query()->whereKey(self::TOP)->value('name'));
    }

    public function test_the_key_for_the_query_never_clamps(): void
    {
        $this->registry()->forget(Customer::class);
        $root = PersonalData::for(Customer::class)->subject();

        $this->assertSame(PHP_INT_MAX, (new Subject($root, self::TOP))->keyValue());
        $this->assertSame(self::OVER, (new Subject($root, self::OVER))->keyValue());
        $this->assertSame(5, (new Subject($root, '5'))->keyValue());
    }

    // Item 11

    public function test_a_masked_long_number_counts_as_changed(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Customer::query()->whereKey($ada->id)->update(['phone' => '1234567890123456789']);
        $this->registry()->forget(Customer::class);
        PersonalData::for(Customer::class)->label('Customer')->subject(email: 'email')->erase(Erase::anonymize([
            'name' => Rule::fixed('Erased customer'),
            'email' => Rule::uniqueToken('erased-', '@erased.invalid'),
            'phone' => Rule::fixed('1234567890123456780'),
        ]));
        $request = $this->erasureOf($ada);

        $this->assertSame(1, app(ErasureService::class)->plan($request)->group(Customer::class)->changing);

        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));
        $this->execute($request);

        $this->assertSame('1234567890123456780', $ada->fresh()->phone);
        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    /**
     * @return array<string, array{mixed, mixed, bool}>
     */
    public static function values(): array
    {
        return [
            'text and int' => ['5', 5, true],
            'float spelled two ways' => ['1.50', 1.5, true],
            'decimal and int' => ['100.00', 100, true],
            'bool and int' => [1, true, true],
            'false and zero' => [false, 0, true],
            'leading zeros' => ['007', 7, true],
            'negative' => ['-5.0', -5, true],
            '19 digits differ' => ['1234567890123456789', '1234567890123456780', false],
            '19 digits and a float' => ['1234567890123456789', 1.2345678901234567e18, false],
            'plain text' => ['Ada', 'Erased', false],
            'date and midnight' => ['2000-01-01 00:00:00', '2000-01-01', true],
            'date with a time' => ['2000-01-01 10:00:00', '2000-01-01', false],
            'an object' => ['2000-01-01 00:00:00', CarbonImmutable::parse('2000-01-01'), true],
            'json spacing' => ['{"a": 1, "b": 2}', ['b' => 2, 'a' => 1], true],
            'padded char' => ['Erased    ', 'Erased', true],
            'null' => [null, 'x', false],
        ];
    }

    #[DataProvider('values')]
    public function test_what_counts_as_the_same_value(mixed $old, mixed $new, bool $same): void
    {
        $changes = RowRules::changes(Erase::anonymize(['c' => Rule::fixed($new)]), 't', 'id', ['id' => 1, 'c' => $old]);

        $this->assertSame($same, $changes === []);
    }
}
