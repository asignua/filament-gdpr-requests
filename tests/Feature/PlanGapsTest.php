<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Retention;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\PlanBlocked;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestExport;
use Asignua\FilamentGdprRequests\Models\GdprRun;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\ExportService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Workbench\App\Models\AuditNote;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Member;
use Workbench\App\Models\MemberNote;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

/**
 * Three ways a preview said less than the data did.
 */
class PlanGapsTest extends TestCase
{
    use RunsErasures;

    private const string KEY = '01jd8qkz3v5w7x9y1b3c5d7f9g';

    // Item 4

    public function test_a_table_mapped_only_for_another_subject_is_still_outside_this_map(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(User::class)->subject(email: 'email');
        // audit_notes belongs to a map, but not to the customer's; nothing here would touch those rows.
        $this->registry()->flush();
        PersonalData::for(Customer::class)->subject(email: 'email')->erase(Erase::anonymize(['phone' => Rule::null()]));
        PersonalData::for(User::class)->subject(email: 'email');
        PersonalData::for(AuditNote::class)->of(User::class)->matchedBy('customer_id')->erase(Erase::delete());

        foreach (['orders', 'documents', 'invoices'] as $table) {
            GdprRequests::acknowledge($table, 'Not part of this test.');
        }

        $plan = app(ErasureService::class)->plan($this->erasureOf($ada));

        $this->assertContains('unmapped_reference', $this->codes($plan));
    }

    public function test_an_acknowledged_table_or_one_of_the_same_subject_does_not_block(): void
    {
        $plan = app(ErasureService::class)->plan($this->erasureOf(Shop::customer('Ada', 'ada@customer.example')));

        $this->assertNotContains('unmapped_reference', $this->codes($plan));
    }

    public function test_retention_looks_at_the_map_of_the_subject_too(): void
    {
        Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->flush();
        PersonalData::for(Customer::class)->subject();
        PersonalData::for(User::class)->subject();
        PersonalData::for(\Workbench\App\Models\OrderItem::class)->of(User::class)->matchedBy('order_id');
        PersonalData::for(Order::class)->of(Customer::class)->matchedBy('customer_id')
            ->retention(Retention::after(days: 1, from: 'created_at')->then(Erase::delete()));
        Order::query()->update(['created_at' => now()->subDays(5)]);

        $this->artisan('gdpr:retention');

        $codes = GdprRun::query()->where('command', 'retention')->latest('id')->first()->summary['entries'][0]['blockers'];
        $this->assertNotEmpty(array_filter($codes, static fn (string $text): bool => str_contains($text, 'order_items') && str_contains($text, 'data map')));
    }

    // Item 6

    public function test_deleting_a_row_that_names_a_file_without_removing_the_file_blocks(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Document::class)->of(Customer::class)->matchedBy('customer_id')
            ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]])
            ->erase(Erase::delete());

        $this->assertContains('files_left_behind', $this->codes(app(ErasureService::class)->plan($this->erasureOf($ada))));
    }

    public function test_removing_the_files_with_the_rows_passes(): void
    {
        $plan = app(ErasureService::class)->plan($this->erasureOf(Shop::customer('Ada', 'ada@customer.example')));

        $this->assertNotContains('files_left_behind', $this->codes($plan));
    }

    public function test_a_row_that_stays_keeps_naming_its_file(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        PersonalData::for(Document::class)->of(Customer::class)->matchedBy('customer_id')
            ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]])
            ->erase(Erase::anonymize(['title' => Rule::fixed('Erased')]));

        $this->assertNotContains('files_left_behind', $this->codes(app(ErasureService::class)->plan($this->erasureOf($ada))));
    }

    // Item 7

    private function member(RequestType $type): GdprRequest
    {
        $this->registry()->flush();
        PersonalData::for(Member::class)->subject()->export(['id', 'name'])->erase(Erase::anonymize(['name' => Rule::fixed('Erased')]));
        PersonalData::for(MemberNote::class)->label('Notes')->of(Member::class)->matchedBy('member_id')->export(['id', 'body'])->erase(Erase::delete());
        Member::query()->create(['id' => self::KEY, 'name' => 'Ada']);
        $dpo = $this->user('dpo');
        $request = GdprRequests::submit($type, null, 'Ada', 'please');
        app(RequestService::class)->linkSubject($request, Member::class, self::KEY, $dpo);
        app(RequestService::class)->verifyIdentity($request, $dpo, 'Checked.');

        return $request->refresh();
    }

    public function test_a_row_that_differs_from_the_key_only_in_case_blocks(): void
    {
        $request = $this->member(RequestType::Erasure);
        MemberNote::query()->create(['member_id' => self::KEY, 'body' => 'Exact']);
        MemberNote::query()->create(['member_id' => strtoupper(self::KEY), 'body' => 'Same person, or another one?']);

        $plan = app(ErasureService::class)->plan($request);

        $this->assertContains('loose_match', $this->codes($plan));
        $blocker = $plan->group(MemberNote::class)->blockers[0];
        $this->assertSame(1, $blocker->params['rows']);

        $this->expectException(PlanBlocked::class);

        try {
            app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));
        } finally {
            $this->assertSame(2, MemberNote::query()->count());
        }
    }

    public function test_the_export_refuses_a_row_it_cannot_place(): void
    {
        $request = $this->member(RequestType::Access);
        MemberNote::query()->create(['member_id' => strtoupper(self::KEY), 'body' => 'Whose is this?']);

        try {
            app(ExportService::class)->request($request, $this->user('dpo'));
            $this->fail('The archive must not be built around a row nobody can place.');
        } catch (GdprException $e) {
            $this->assertStringContainsString('member_notes', $e->getMessage());
        }

        $this->assertSame(0, GdprRequestExport::query()->where('status', GdprRequestExport::READY)->count());
    }

    public function test_rows_with_the_exact_key_pass(): void
    {
        $request = $this->member(RequestType::Erasure);
        MemberNote::query()->create(['member_id' => self::KEY, 'body' => 'Exact']);

        $this->assertSame([], $this->codes(app(ErasureService::class)->plan($request)));
    }
}
