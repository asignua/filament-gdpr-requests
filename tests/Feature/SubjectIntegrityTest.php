<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Erasure\ErasurePlan;
use Asignua\FilamentGdprRequests\Erasure\Runner;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\PlanBlocked;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestExport;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\ExportService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;
use Workbench\App\Models\Ticket;
use ZipArchive;

/**
 * Who a request is about: the record it is linked to, and that alone. A wrong or doubtful subject blocks the work.
 */
class SubjectIntegrityTest extends TestCase
{
    private function confirmAndRun(GdprRequest $request): void
    {
        app(ErasureService::class)->confirm($request->refresh(), $this->user('dpo', 'Confirmer'));
        app(ErasureService::class)->start($request->refresh(), $this->user('dpo', 'Runner'));
    }

    /**
     * A run of Ada's erasure that stops on a file it cannot remove: the request is `partial`, groups are settled.
     */
    private function partialErasureOf(Customer $customer): GdprRequest
    {
        Document::query()->where('customer_id', $customer->id)->update(['disk' => 'vanished-disk']);
        $request = Shop::request($customer, RequestType::Erasure, $this->user('dpo', 'Confirmer'));

        try {
            $this->confirmAndRun($request);
            $this->fail('The run should stop on the file.');
        } catch (GdprException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(RequestStatus::Partial, $request->refresh()->status);

        return $request;
    }

    /**
     * @return list<string>
     */
    private function codes(ErasurePlan $plan): array
    {
        return array_map(static fn ($blocker): string => $blocker->code, $plan->allBlockers());
    }

    private function verified(GdprRequest $request, Authenticatable $actor): GdprRequest
    {
        return app(RequestService::class)->verifyIdentity($request, $actor, 'Checked by phone.')->refresh();
    }

    private function zipText(GdprRequestExport $export): string
    {
        $path = sys_get_temp_dir().'/t-'.uniqid().'.zip';
        file_put_contents($path, Storage::disk('local')->get((string) $export->path));
        $zip = new ZipArchive;
        $zip->open($path);
        $text = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $text .= (string) $zip->getFromIndex($i)."\n";
        }

        $zip->close();
        unlink($path);

        return $text;
    }

    // Item 1

    public function test_a_partial_erasure_cannot_be_moved_to_another_person(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bruno = Shop::customer('Bruno', 'bruno@customer.example');
        $request = $this->partialErasureOf($ada);

        try {
            app(RequestService::class)->linkSubject($request, Customer::class, $bruno->id, $this->user());
            $this->fail('A partial erasure stays with its person.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $request->refresh();
        $this->assertSame((string) $ada->id, $request->subject_id);
        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertTrue($request->isVerified());
        $this->assertSame('Bruno', $bruno->fresh()->name);
    }

    public function test_steps_settled_for_another_person_do_not_count_for_this_one(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bruno = Shop::customer('Bruno', 'bruno@customer.example');
        $request = $this->partialErasureOf($ada);
        $settled = $request->steps()->get()->filter(fn ($step) => $step->status->isSettled())->count();
        $this->assertGreaterThan(0, $settled);

        // Whatever gets the request onto another person, the database alone cannot be stopped from doing it.
        $request->forceFill([
            'subject_id' => (string) $bruno->id,
            'subject_email' => $bruno->email,
            'status' => RequestStatus::InProgress,
            'erasure_plan' => null,
            'erasure_confirmed_at' => null,
            'erasure_confirmed_by' => null,
        ])->save();
        // The file Ada's run could not remove stays owed; the disk comes back so that this run can finish.
        config(['filesystems.disks.vanished-disk' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/gdpr-vanished-disk']]);
        $this->confirmAndRun($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame('Erased customer', $bruno->fresh()->name, 'Nothing of Bruno was skipped on the strength of Ada\'s steps.');
        $this->assertSame(['Erased', 'Erased'], Order::query()->where('customer_id', $bruno->id)->pluck('shipping_address')->all());
    }

    // Item 2

    public function test_a_request_without_an_email_uses_the_address_of_the_record(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = GdprRequests::submit(RequestType::Erasure, null, 'Ada', subjectModel: Customer::class, subjectId: $ada->id);
        $this->verified(app(RequestService::class)->linkSubject($request, Customer::class, $ada->id, $dpo), $dpo);

        $this->assertSame(1, app(ErasureService::class)->plan($request->refresh())->group(Ticket::class)->matched);

        $this->confirmAndRun($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame(0, Ticket::query()->count(), 'The ticket went with the rest.');
    }

    public function test_the_address_survives_the_root_being_anonymized_first(): void
    {
        $this->registry()->flush();
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        // Nothing relates the ticket to the customer in the schema, so the root group runs first.
        $this->registry()->add(PersonalData::for(Customer::class)->subject(email: 'email')->erase(Erase::anonymize(['email' => Rule::uniqueToken('erased-', '@erased.invalid')])));
        $this->registry()->add(PersonalData::for(Ticket::class)->of(Customer::class)->matchedByEmail('customer_email')->erase(Erase::delete()));

        foreach (['orders', 'invoices', 'documents', 'audit_notes'] as $table) {
            GdprRequests::acknowledge($table, 'Test.');
        }

        $request = GdprRequests::submit(RequestType::Erasure, null, 'Ada', subjectModel: Customer::class, subjectId: $ada->id);
        $this->verified(app(RequestService::class)->linkSubject($request, Customer::class, $ada->id, $dpo), $dpo);

        $this->confirmAndRun($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertStringStartsWith('erased-', (string) $ada->fresh()->email);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_email_groups_block_when_the_record_offers_no_address(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->forget(Customer::class);
        $this->registry()->add(PersonalData::for(Customer::class)->label('Customer')->subject()->erase(Erase::anonymize(['phone' => Rule::null()])));
        $request = GdprRequests::submit(RequestType::Erasure, null, 'Ada', subjectModel: Customer::class, subjectId: $ada->id);
        $this->verified(app(RequestService::class)->linkSubject($request, Customer::class, $ada->id, $dpo), $dpo);

        $this->assertContains('subject_email_unknown', $this->codes(app(ErasureService::class)->plan($request->refresh())));

        $this->expectException(PlanBlocked::class);
        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));
    }

    public function test_a_request_address_that_cannot_be_checked_against_the_record_blocks(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->forget(Customer::class);
        $this->registry()->add(PersonalData::for(Customer::class)->label('Customer')->subject()->erase(Erase::anonymize(['phone' => Rule::null()])));
        $request = Shop::request($ada, RequestType::Erasure, $dpo);

        $this->assertContains('subject_email_unknown', $this->codes(app(ErasureService::class)->plan($request)));
    }

    // Item 3

    public function test_relinking_removes_every_archive_of_the_previous_person(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bruno = Shop::customer('Bruno', 'bruno@customer.example');
        $request = Shop::request($ada, RequestType::Access, $dpo);
        $export = app(ExportService::class)->request($request, $dpo)->refresh();
        $this->assertSame(GdprRequestExport::READY, $export->status);
        $path = (string) $export->path;
        $this->assertTrue(Storage::disk('local')->exists($path));

        app(RequestService::class)->linkSubject($request, Customer::class, $bruno->id, $dpo);

        $export->refresh();
        $this->assertSame(GdprRequestExport::REMOVED, $export->status);
        $this->assertNull($export->path);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_an_export_still_being_built_during_a_relink_does_not_become_ready(): void
    {
        $dpo = $this->user();
        config(['queue.default' => 'null']);
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bruno = Shop::customer('Bruno', 'bruno@customer.example');
        $request = Shop::request($ada, RequestType::Access, $dpo);
        $export = app(ExportService::class)->request($request, $dpo);
        $this->assertSame(GdprRequestExport::PENDING, $export->refresh()->status);

        // The relink lands while the job is halfway through the archive.
        $this->registry()->find(Order::class)->exportUsing(function ($order) use ($request, $bruno, $dpo): array {
            if (GdprRequest::query()->whereKey($request->getKey())->value('subject_id') !== (string) $bruno->id) {
                app(RequestService::class)->linkSubject($request, Customer::class, $bruno->id, $dpo);
            }

            return [];
        });

        app(ExportService::class)->build($export->getKey());

        $export->refresh();
        $this->assertSame(GdprRequestExport::REMOVED, $export->status);
        $this->assertSame([], Storage::disk('local')->allFiles('gdpr-exports'), 'The finished archive of the previous person is gone too.');
    }

    public function test_a_relink_with_an_unknown_key_changes_nothing_and_keeps_the_archives(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);
        $export = app(ExportService::class)->request($request, $dpo)->refresh();

        try {
            app(RequestService::class)->linkSubject($request, Customer::class, 999999, $dpo);
            $this->fail('No such record.');
        } catch (GdprException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(GdprRequestExport::READY, $export->refresh()->status);
    }

    // Item 4

    public function test_a_request_address_that_differs_from_the_record_blocks_the_erasure(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Bruno', 'bruno@customer.example');
        $request = GdprRequests::submit(RequestType::Erasure, 'bruno@customer.example', 'Ada');
        $this->verified(app(RequestService::class)->linkSubject($request, Customer::class, $ada->id, $dpo), $dpo);

        $plan = app(ErasureService::class)->plan($request->refresh());

        $this->assertContains('subject_email_mismatch', $this->codes($plan));
        $this->assertSame(1, Ticket::query()->where('customer_email', 'bruno@customer.example')->count());

        $this->expectException(PlanBlocked::class);
        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));
    }

    public function test_an_address_that_changes_on_the_record_after_confirmation_stops_the_run(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = Shop::request($ada, RequestType::Erasure, $this->user('dpo', 'Confirmer'));
        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));

        Customer::query()->whereKey($ada->id)->update(['email' => 'bruno@customer.example']);

        $this->expectException(PlanBlocked::class);
        app(ErasureService::class)->start($request->refresh(), $this->user('dpo', 'Runner'));
    }

    public function test_the_export_never_holds_the_rows_of_the_address_on_the_request(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Bruno', 'bruno@customer.example');
        $request = GdprRequests::submit(RequestType::Access, 'bruno@customer.example', 'Ada');
        $this->verified(app(RequestService::class)->linkSubject($request, Customer::class, $ada->id, $dpo), $dpo);

        try {
            app(ExportService::class)->request($request->refresh(), $dpo);
            $this->fail('A doubtful subject is not exported.');
        } catch (GdprException $e) {
            $this->assertStringContainsString(__('filament-gdpr-requests::filament-gdpr-requests.blocker.subject_email_mismatch'), $e->getMessage());
        }

        $this->assertSame(0, $request->exports()->count());
    }

    public function test_the_export_uses_the_address_of_the_record_when_the_request_has_none(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Bruno', 'bruno@customer.example');
        $request = GdprRequests::submit(RequestType::Access, null, 'Ada');
        $this->verified(app(RequestService::class)->linkSubject($request, Customer::class, $ada->id, $dpo), $dpo);

        $export = app(ExportService::class)->request($request->refresh(), $dpo)->refresh();
        $text = $this->zipText($export);

        $this->assertStringContainsString('Ada wrote this', $text);
        $this->assertStringNotContainsString('Bruno', $text);
    }

    public function test_a_person_resolves_the_difference_and_must_verify_again(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = GdprRequests::submit(RequestType::Erasure, 'old-address@customer.example', 'Ada');
        $this->verified(app(RequestService::class)->linkSubject($request, Customer::class, $ada->id, $dpo), $dpo);
        $service = app(RequestService::class);

        $service->acceptRecordEmail($request->refresh(), $dpo);

        $request->refresh();
        $this->assertSame('ada@customer.example', $request->subject_email);
        $this->assertSame(Pseudonym::forEmail('ada@customer.example'), $request->email_pseudonym);
        $this->assertFalse($request->isVerified(), 'The check was made against the other address.');
        $this->assertNotContains('subject_email_mismatch', $this->codes(app(ErasureService::class)->plan($request)));
        $this->assertContains('record_email_accepted', $request->events()->pluck('event')->all());
    }

    public function test_the_address_cannot_be_rewritten_once_the_erasure_changed_data(): void
    {
        Shop::customer('Bruno', 'bruno@customer.example');
        $request = $this->partialErasureOf(Shop::customer('Ada', 'ada@customer.example'));

        $this->expectException(InvalidTransition::class);

        app(RequestService::class)->acceptRecordEmail($request, $this->user());
    }

    // Item 5

    public function test_a_key_is_stored_the_way_the_database_stores_it(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = GdprRequests::submit(RequestType::Erasure, $ada->email, 'Ada');

        app(RequestService::class)->linkSubject($request, Customer::class, sprintf('00%d', $ada->id), $dpo);

        $request->refresh();
        $this->assertSame((string) $ada->id, $request->subject_id);
        $this->assertSame(Pseudonym::forSubject(Customer::class, (string) $ada->id), $request->pseudonym);

        $this->verified($request, $dpo);
        $this->confirmAndRun($request);

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
        $this->assertSame('Erased customer', $ada->fresh()->name);
    }

    public function test_a_key_that_is_not_a_number_never_matches_a_numeric_key(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = GdprRequests::submit(RequestType::Erasure, $ada->email, 'Ada');

        $this->expectException(GdprException::class);

        app(RequestService::class)->linkSubject($request, Customer::class, $ada->id.'abc', $this->user());
    }

    public function test_a_request_key_that_matches_no_row_blocks_instead_of_finishing_empty(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = Shop::request($ada, RequestType::Erasure, $this->user());

        foreach ([sprintf('00%d', $ada->id), '999999'] as $key) {
            $request->forceFill(['subject_id' => $key])->save();

            $this->assertContains('subject_row_missing', $this->codes(app(ErasureService::class)->plan($request)), $key);
        }

        $this->assertSame('Ada', $ada->fresh()->name);
    }

    public function test_a_deleted_root_does_not_block_the_resume_of_its_own_run(): void
    {
        $this->registry()->flush();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        OrderItem::query()->delete();
        Order::query()->forceDelete();
        Invoice::query()->delete();
        $this->registry()->add(PersonalData::for(Customer::class)->subject(email: 'email')->erase(Erase::delete()));
        $this->registry()->add(PersonalData::for(Document::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::delete()));
        $this->registry()->add(PersonalData::for(Ticket::class)->of(Customer::class)->matchedByEmail('customer_email')->erase(Erase::delete()));

        foreach (['orders', 'invoices', 'audit_notes'] as $table) {
            GdprRequests::acknowledge($table, 'Test.');
        }

        $request = Shop::request($ada, RequestType::Erasure, $this->user('dpo', 'Confirmer'));
        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));
        $request->refresh()->forceFill(['status' => RequestStatus::Erasing])->save();

        // The root went, its step is settled: the row is gone because this very run removed it.
        app(Runner::class)->runGroup($request, Ticket::class);
        app(Runner::class)->runGroup($request, Document::class);
        app(Runner::class)->runGroup($request, Customer::class);
        $this->assertFalse(Customer::query()->whereKey($ada->id)->exists());

        $this->assertNotContains('subject_row_missing', $this->codes(app(ErasureService::class)->plan($request->refresh())));
    }
}
