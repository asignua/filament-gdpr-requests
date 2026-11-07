<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Erasure\Failures;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\CreateGdprRequest;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ErasurePlanPage;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ListGdprRequests;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ViewGdprRequest;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Support\EventDetails;
use Asignua\FilamentGdprRequests\Support\Lang;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use PDOException;
use RuntimeException;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Order;

class PresentationTest extends TestCase
{
    private function codes(GdprRequest $request): array
    {
        return array_map(static fn ($blocker): string => $blocker->code, app(ErasureService::class)->plan($request)->allBlockers());
    }

    // Item 16

    public function test_a_fixed_null_on_a_not_null_column_blocks_the_preview(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Customer::class)->label('Customer')->subject(email: 'email')->erase(Erase::anonymize(['name' => Rule::fixed(null)])));

        $this->assertContains('not_null', $this->codes(Shop::request($ada, RequestType::Erasure, $this->user())));
    }

    public function test_a_callback_that_returns_null_on_a_not_null_column_blocks_the_preview(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Customer::class)->label('Customer')->subject(email: 'email')->erase(Erase::anonymize(['name' => Rule::using(static fn () => null)])));

        $this->assertContains('not_null', $this->codes(Shop::request($ada, RequestType::Erasure, $this->user())));
    }

    public function test_a_fixed_null_is_treated_as_a_null_everywhere_else(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Order::class)->label('Orders')->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize(['shipping_address' => Rule::fixed(null)])));

        $this->assertNotContains('not_null', $this->codes(Shop::request($ada, RequestType::Erasure, $this->user())));
    }

    public function test_notes_are_refused_once_the_details_are_wiped(): void
    {
        $dpo = $this->user();
        $request = GdprRequests::submit(RequestType::Access, 'a@customer.example', 'Ada');
        $service = app(RequestService::class);
        $service->close($request, $dpo);
        $this->travelTo(now()->addDays(91));
        $this->artisan('gdpr:prune')->assertSuccessful();

        try {
            $service->addNote($request->refresh(), 'Ada phoned again', $dpo);
            $this->fail('Nothing would ever wipe it.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($request->refresh()->notes);
    }

    // Item 17

    public function test_the_readme_map_covers_credentials_and_media_and_warns_about_the_rest(): void
    {
        $readme = (string) file_get_contents(__DIR__.'/../../README.md');

        $this->assertStringContainsString("'password'", $readme);
        $this->assertStringContainsString("'remember_token'", $readme);
        $this->assertStringContainsString('PersonalData::for(Media::class', $readme);
        $this->assertStringContainsString('Anything that is not in the map stays', $readme);
    }

    public function test_the_missing_key_message_says_how_to_fix_it(): void
    {
        foreach (['errors.pseudonym_key_missing', 'blocker.pseudonym_key_missing'] as $key) {
            foreach (['en', 'uk', 'de'] as $locale) {
                app()->setLocale($locale);
                $text = Lang::t($key, ['version' => 1, 'min' => 32]);

                $this->assertStringContainsString('random_bytes(32)', $text, $locale);
                $this->assertStringContainsString('.env', $text, $locale);
                $this->assertStringContainsString('config:clear', $text, $locale);
            }
        }
    }

    public function test_the_panel_says_so_on_the_first_page_when_the_key_is_missing(): void
    {
        $this->user('dpo');
        $message = Lang::t('errors.pseudonym_key_missing', ['version' => 1, 'min' => 32]);

        Livewire::test(ListGdprRequests::class)->assertDontSee('random_bytes');

        config(['filament-gdpr-requests.pseudonym.key' => null]);

        Livewire::test(ListGdprRequests::class)->assertSee($message);
        Livewire::test(CreateGdprRequest::class)->assertSee($message);
    }

    // Item 18

    public function test_the_preview_names_the_person_and_the_record_first(): void
    {
        $ada = Shop::customer('Ada Lovelace', 'ada@customer.example');
        $request = Shop::request($ada, RequestType::Erasure, $this->user('dpo'));

        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->assertSeeInOrder([
                Lang::t('plan.subject', ['name' => 'Ada Lovelace', 'model' => 'Customer', 'id' => (string) $ada->id]),
                'ada@customer.example',
                Lang::t('plan.groups'),
            ]);
    }

    public function test_the_preview_of_a_request_without_a_name_still_says_whose_record_it_is(): void
    {
        $dpo = $this->user('dpo');
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = GdprRequests::submit(RequestType::Erasure, 'ada@customer.example', subjectModel: Customer::class, subjectId: $ada->id);

        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->assertSee(Lang::t('plan.subject', ['name' => Lang::t('plan.subject_unnamed'), 'model' => 'Customer', 'id' => (string) $ada->id]));
        $this->assertNotNull($dpo);
    }

    public function test_every_column_of_the_preview_carries_its_label_for_the_phone_layout(): void
    {
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Erasure, $this->user('dpo'));
        $page = Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid]);

        foreach (['plan.entry', 'plan.action', 'plan.rows', 'plan.trashed', 'plan.details', 'view.status'] as $label) {
            $page->assertSeeHtml('data-label="'.e(Lang::t($label)).'"');
        }

        $page->assertSeeHtml('@media (max-width: 640px)');
    }

    public function test_rules_read_as_sentences_in_the_language_of_the_panel(): void
    {
        foreach (['en', 'uk', 'pl', 'de', 'es', 'fr', 'it', 'nl', 'pt_BR', 'tr'] as $locale) {
            app()->setLocale($locale);

            $this->assertSame(Lang::t('rule.null'), Rule::null()->label(), $locale);
            $this->assertSame(Lang::t('rule.null'), Rule::fixed(null)->label(), $locale);
            $this->assertSame(Lang::t('rule.fixed', ['value' => '"Erased"']), Rule::fixed('Erased')->label(), $locale);
            $this->assertSame(Lang::t('rule.hash'), Rule::hash()->label(), $locale);
            $this->assertSame(Lang::t('rule.token', ['prefix' => 'x-', 'suffix' => '@y']), Rule::uniqueToken('x-', '@y')->label(), $locale);
            $this->assertSame(Lang::t('rule.callback'), Rule::using(static fn () => 1)->label(), $locale);
            $this->assertSame(Lang::t('rule.callback_unique'), Rule::using(static fn () => 1, unique: true)->label(), $locale);
        }
    }

    public function test_the_fingerprint_does_not_depend_on_the_language_of_the_page(): void
    {
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Erasure, $this->user('dpo'));
        $english = app(ErasureService::class)->plan($request)->fingerprint();

        app()->setLocale('uk');

        $this->assertSame($english, app(ErasureService::class)->plan($request)->fingerprint());
    }

    public function test_a_preview_shows_the_translated_rule_not_its_technical_form(): void
    {
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Erasure, $this->user('dpo'));
        app()->setLocale('uk');

        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->assertSee(Lang::t('rule.null'))
            ->assertDontSee('→ NULL');
    }

    public function test_stored_errors_are_rendered_in_the_language_of_the_reader(): void
    {
        $stored = Failures::encode(GdprException::translated('files_not_removed', ['count' => 2]));

        foreach (['en', 'uk', 'tr'] as $locale) {
            app()->setLocale($locale);

            $this->assertSame(Lang::t('errors.files_not_removed', ['count' => 2]), Failures::render($stored));
        }

        $database = Failures::encode(new QueryException('testing', 'update x set y = ?', ['ada@customer.example'], tap(new PDOException('boom ada@customer.example'), function (PDOException $e): void {
            $e->errorInfo = ['23000', 1062, 'duplicate'];
        })));
        $this->assertStringNotContainsString('ada@customer.example', $database);
        $this->assertSame(Lang::t('report.database_error', ['state' => '23000', 'code' => '1062']), Failures::render($database));

        $other = Failures::encode(new RuntimeException('secret ada@customer.example'));
        $this->assertStringNotContainsString('ada@customer.example', $other);
        $this->assertSame(Lang::t('report.other_error', ['class' => 'RuntimeException']), Failures::render($other));

        $this->assertSame('An older plain message', Failures::render('An older plain message'));
        $this->assertNull(Failures::render(null));
    }

    public function test_a_failed_step_shows_its_error_in_the_current_language(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Document::query()->where('customer_id', $ada->id)->update(['disk' => 'vanished-disk']);
        $confirmer = $this->user('dpo', 'Confirmer');
        $request = Shop::request($ada, RequestType::Erasure, $confirmer);
        app(ErasureService::class)->confirm($request, $confirmer);

        try {
            app(ErasureService::class)->start($request->refresh(), $this->user('dpo', 'Runner'));
        } catch (GdprException) {
            $this->addToAssertionCount(1);
        }

        app()->setLocale('uk');
        $step = $request->steps()->where('group_key', Document::class)->first();

        $this->assertSame(Lang::t('errors.files_not_removed', ['count' => 1]), $step->errorMessage());
        Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])->assertSee(Lang::t('errors.files_not_removed', ['count' => 1]));
    }

    public function test_the_action_log_reads_as_text_not_as_json(): void
    {
        $text = EventDetails::format([
            'group' => Order::class,
            'action' => 'anonymize',
            'matched' => 2,
            'changed' => 1,
            'fingerprint' => str_repeat('a', 64),
            'resumed' => true,
            'four_eyes' => false,
            'open' => [Order::class, Customer::class],
            'counts' => [Order::class => 2],
            'export' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'nothing' => null,
        ]);

        $this->assertStringNotContainsString('{', (string) $text);
        $this->assertStringNotContainsString('"', (string) $text);
        $this->assertStringNotContainsString('aaaaaaaa', (string) $text, 'The fingerprint means nothing to a reader.');
        $this->assertStringContainsString(Lang::t('context.group').': Orders', (string) $text);
        $this->assertStringContainsString(Lang::t('context.action').': '.Lang::t('action.anonymize'), (string) $text);
        $this->assertStringContainsString(Lang::t('context.resumed').': '.Lang::t('context.yes'), (string) $text);
        $this->assertStringContainsString(Lang::t('context.four_eyes').': '.Lang::t('context.no'), (string) $text);
        $this->assertStringContainsString('Orders, Customer', (string) $text);
        $this->assertStringContainsString('Orders: 2', (string) $text);
        $this->assertNull(EventDetails::format(null));
        $this->assertNull(EventDetails::format(['fingerprint' => 'x']));
    }

    public function test_every_context_key_in_use_has_a_label(): void
    {
        foreach (['type', 'channel', 'model', 'assignee', 'months', 'due', 'counts', 'resumed', 'four_eyes', 'group_count', 'open', 'archives', 'export', 'bytes', 'group', 'action', 'matched', 'changed', 'reason', 'groups'] as $key) {
            $this->assertNotSame('filament-gdpr-requests::filament-gdpr-requests.context.'.$key, Lang::t('context.'.$key));
        }
    }

    public function test_the_log_on_the_view_page_has_no_raw_json_and_its_lists_are_labelled(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $confirmer = $this->user('dpo', 'Confirmer');
        $request = Shop::request($ada, RequestType::Erasure, $confirmer);
        app(ErasureService::class)->confirm($request, $confirmer);
        app(ErasureService::class)->start($request->refresh(), $this->user('dpo', 'Runner'));
        app()->setLocale('uk');

        Livewire::test(ViewGdprRequest::class, ['record' => $request->ulid])
            ->assertSee(Lang::t('context.matched'))
            ->assertDontSee('&quot;matched&quot;', false)
            ->assertDontSee('"matched"', false)
            ->assertDontSee('Steps')
            ->assertDontSee('Events')
            ->assertSee(Lang::t('view.steps'));
    }
}
