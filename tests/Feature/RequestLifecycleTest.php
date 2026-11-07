<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\MissingPseudonymKey;
use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Notifications\RequesterNotification;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use Workbench\App\Models\Customer;

class RequestLifecycleTest extends TestCase
{
    public function test_submit_always_creates_an_unverified_request_with_a_one_month_deadline(): void
    {
        $this->travelTo('2026-01-31 09:00:00');

        $request = GdprRequests::submit(RequestType::Erasure, ' Ada@Customer.Example ', 'Ada', 'delete me');

        $this->assertSame(RequestStatus::New, $request->status);
        $this->assertFalse($request->isVerified());
        $this->assertSame('ada@customer.example', $request->subject_email);
        $this->assertSame('2026-02-28 23:59:59', $request->due_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($request->email_pseudonym);
        $this->assertSame('received', $request->events()->first()->event);
    }

    public function test_submit_cannot_start_a_verified_request_even_with_a_known_subject(): void
    {
        $customer = Shop::customer('Ada', 'ada@customer.example');

        $request = GdprRequests::submit(RequestType::Access, 'ada@customer.example', 'Ada', null, subjectModel: Customer::class, subjectId: $customer->id);

        $this->assertTrue($request->hasSubject());
        $this->assertFalse($request->isVerified());
        $this->assertNotNull($request->pseudonym);
    }

    public function test_without_the_pseudonym_key_a_request_with_a_subject_is_refused(): void
    {
        $customer = Shop::customer('Ada', 'ada@customer.example');
        config(['filament-gdpr-requests.pseudonym.key' => null]);

        $this->expectException(MissingPseudonymKey::class);

        GdprRequests::submit(RequestType::Access, 'ada@customer.example', subjectModel: Customer::class, subjectId: $customer->id);
    }

    public function test_verification_needs_a_gate_a_note_and_a_linked_record(): void
    {
        $customer = Shop::customer('Ada', 'ada@customer.example');
        $service = app(RequestService::class);
        $request = GdprRequests::submit(RequestType::Access, 'ada@customer.example');
        $dpo = $this->user('dpo');

        try {
            $service->verifyIdentity($request, $dpo, 'ok');
            $this->fail('Not linked yet.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $service->linkSubject($request, Customer::class, $customer->id, $dpo);

        try {
            $service->verifyIdentity($request, $dpo, '   ');
            $this->fail('A note is required.');
        } catch (GdprException) {
            $this->addToAssertionCount(1);
        }

        $nobody = $this->user('none');
        $this->expectException(NotPermitted::class);
        $service->verifyIdentity($request, $nobody, 'I am not allowed to');
    }

    public function test_relinking_the_record_drops_the_verification(): void
    {
        $dpo = $this->user();
        $a = Shop::customer('Ada', 'ada@customer.example');
        $b = Shop::customer('Bruno', 'bruno@customer.example');
        $request = Shop::request($a, RequestType::Access, $dpo);

        $this->assertTrue($request->isVerified());

        app(RequestService::class)->linkSubject($request, Customer::class, $b->id, $dpo);

        $this->assertFalse($request->refresh()->isVerified());
        $this->assertSame((string) $b->id, $request->subject_id);
    }

    public function test_an_email_lookup_is_exact_and_refuses_ambiguity(): void
    {
        Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Adam', 'ada@customer.example.org');
        $service = app(RequestService::class);

        $this->assertNotNull($service->findSubjectByEmail(Customer::class, '  ADA@customer.example '));
        $this->assertNull($service->findSubjectByEmail(Customer::class, 'ad@customer.example'), 'No fuzzy matching.');
        $this->assertNull($service->findSubjectByEmail(Customer::class, 'ada@customer'), 'No prefix matching.');
    }

    public function test_the_deadline_can_be_extended_once_with_a_reason_and_within_the_limit(): void
    {
        $this->travelTo('2026-01-31 09:00:00');
        $dpo = $this->user();
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example');
        $service = app(RequestService::class);

        foreach ([[0, 'r'], [3, 'r'], [1, ' ']] as [$months, $reason]) {
            try {
                $service->extend($request, $months, $reason, $dpo);
                $this->fail('Should be refused.');
            } catch (GdprException) {
                $this->addToAssertionCount(1);
            }
        }

        $service->extend($request, 2, 'Complex request', $dpo);

        $this->assertSame('2026-04-30', $request->refresh()->due_at->toDateString());
        $this->assertSame('Complex request', $request->extension_reason);

        $this->expectException(InvalidTransition::class);
        $service->extend($request, 1, 'again', $dpo);
    }

    public function test_an_overdue_request_cannot_be_extended(): void
    {
        $dpo = $this->user();
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example');
        $this->travelTo(now()->addMonths(2));

        $this->assertTrue($request->refresh()->isOverdue());
        $this->expectException(InvalidTransition::class);

        app(RequestService::class)->extend($request, 1, 'too late', $dpo);
    }

    public function test_closing_detaches_the_subject_and_keeps_only_the_pseudonym(): void
    {
        $dpo = $this->user();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = Shop::request($ada, RequestType::Rectification, $dpo);
        $pseudonym = $request->pseudonym;
        $service = app(RequestService::class);
        $service->complete($request, $dpo);
        $service->close($request->refresh(), $dpo);

        $request->refresh();
        $this->assertSame(RequestStatus::Closed, $request->status);
        $this->assertNull($request->subject_id);
        $this->assertNull($request->subject_model);
        $this->assertSame($pseudonym, $request->pseudonym);
        $this->assertSame(Pseudonym::forSubject(Customer::class, (string) $ada->id), $request->pseudonym);
    }

    public function test_an_erasure_request_cannot_be_marked_fulfilled_by_hand(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Erasure, $dpo);

        $this->expectException(InvalidTransition::class);

        app(RequestService::class)->complete($request, $dpo);
    }

    public function test_the_requester_gets_stage_mails_only_when_enabled(): void
    {
        Notification::fake();
        GdprRequests::submit(RequestType::Access, 'a@customer.example');
        Notification::assertNothingSent();

        config(['filament-gdpr-requests.requester_mail.enabled' => true]);
        GdprRequests::submit(RequestType::Access, 'b@customer.example');

        Notification::assertSentOnDemand(RequesterNotification::class);
    }

    public function test_the_log_holds_structure_not_the_requesters_details(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada Lovelace', 'ada@customer.example'), RequestType::Access, $dpo);

        $dump = json_encode($request->events()->get()->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Ada', $dump);
        $this->assertStringNotContainsString('customer.example', $dump);
        $this->assertStringNotContainsString('registered address', $dump);
    }

    public function test_json_columns_are_written_without_unicode_escaping(): void
    {
        $request = GdprRequest::query()->create([
            'type' => 'access', 'status' => 'new', 'channel' => 'email', 'received_at' => now(), 'due_at' => now(),
            'erasure_plan' => ['label' => 'Замовлення', 'path' => 'a/b'],
        ]);

        $raw = (string) \DB::table('gdpr_requests')->where('id', $request->id)->value('erasure_plan');

        $this->assertStringContainsString('Замовлення', $raw);
        $this->assertStringContainsString('a/b', $raw);
        $this->assertSame(['label' => 'Замовлення', 'path' => 'a/b'], $request->refresh()->erasure_plan);
    }
}
