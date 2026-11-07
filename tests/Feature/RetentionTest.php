<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Retention;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Models\GdprRun;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Ticket;

class RetentionTest extends TestCase
{
    public function test_the_default_run_is_a_dry_run_that_changes_nothing_and_is_logged(): void
    {
        Shop::customer('Ada', 'ada@customer.example');
        Invoice::query()->update(['issued_at' => now()->subDays(4000)]);

        $this->artisan('gdpr:retention')->assertSuccessful();

        $this->assertSame(2, Invoice::query()->where('billed_to', 'Ada')->count());
        $run = GdprRun::query()->where('command', 'retention')->latest('id')->first();
        $this->assertTrue($run->dry_run);
        $this->assertSame(2, $run->summary['entries'][0]['expired']);
        $this->assertSame(0, $run->summary['entries'][0]['changed']);
    }

    public function test_apply_changes_only_rows_past_the_term_and_is_repeatable(): void
    {
        Shop::customer('Ada', 'ada@customer.example');
        $old = Invoice::query()->where('number', 'INV-Ada-1')->first();
        $old->update(['issued_at' => now()->subDays(4000)]);

        $this->artisan('gdpr:retention', ['--apply' => true])->assertSuccessful();

        $hashed = Invoice::query()->find($old->id)->billed_to;
        $this->assertStringStartsWith('h1$', $hashed);
        $this->assertSame('Ada', Invoice::query()->where('number', 'INV-Ada-2')->value('billed_to'), 'A row inside its term is untouched.');

        $this->artisan('gdpr:retention', ['--apply' => true])->assertSuccessful();
        $this->assertSame($hashed, Invoice::query()->find($old->id)->billed_to, 'A rerun does not hash the hash.');
        $run = GdprRun::query()->where('command', 'retention')->latest('id')->first();
        $this->assertFalse($run->dry_run);
        $this->assertSame(0, $run->summary['entries'][0]['changed']);
    }

    public function test_a_row_inside_its_retain_term_is_never_in_the_plan_even_if_the_policy_is_shorter(): void
    {
        Shop::customer('Ada', 'ada@customer.example');
        // The policy says 1 day, but the legal retention (3650 days) still holds the invoice.
        $this->registry()->add(
            PersonalData::for(Invoice::class)->of(Customer::class)->matchedBy('customer_id')
                ->erase(Erase::retain('Law', days: 3650, from: 'issued_at'))
                ->retention(Retention::after(days: 1, from: 'issued_at')->then(Erase::delete())),
        );

        $this->artisan('gdpr:retention', ['--apply' => true])->assertSuccessful();

        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_a_retain_without_a_policy_only_lists_expired_rows(): void
    {
        Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Invoice::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::retain('Law', days: 5, from: 'issued_at')));
        Invoice::query()->update(['issued_at' => now()->subDays(10)]);

        $this->artisan('gdpr:retention', ['--apply' => true])->assertSuccessful();

        $this->assertSame(2, Invoice::query()->count());
        $entry = GdprRun::query()->latest('id')->first()->summary['entries'][0];
        $this->assertSame('info', $entry['status']);
        $this->assertSame(2, $entry['expired']);
    }

    public function test_a_blocked_policy_is_skipped_and_fails_the_apply(): void
    {
        Shop::customer('Ada', 'ada@customer.example');
        // Deleting customers older than a day while orders still reference them: blocked, nothing deleted.
        $this->registry()->add(PersonalData::for(Customer::class)->subject()->erase(Erase::anonymize(['phone' => Rule::null()]))
            ->retention(Retention::after(days: 1, from: 'created_at')->then(Erase::delete())));
        Customer::query()->update(['created_at' => now()->subDays(5)]);

        $this->artisan('gdpr:retention', ['--apply' => true])->assertFailed();

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_apply_is_never_implied_by_config(): void
    {
        config(['filament-gdpr-requests.retention' => ['apply' => true], 'filament-gdpr-requests.apply' => true]);
        Shop::customer('Ada', 'ada@customer.example');
        Invoice::query()->update(['issued_at' => now()->subDays(4000)]);

        $this->artisan('gdpr:retention')->assertSuccessful();

        $this->assertSame(2, Invoice::query()->where('billed_to', 'Ada')->count());
    }

    public function test_deleting_by_policy_is_applied_with_the_flag_and_respects_the_filter(): void
    {
        $this->registry()->flush();
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Customer::class)->subject(email: 'email')->erase(Erase::anonymize(['phone' => Rule::null()])));
        $this->registry()->add(
            PersonalData::for(Ticket::class)->of(Customer::class)->matchedByEmail('customer_email')
                ->retention(Retention::after(days: 30, from: 'created_at')->then(Erase::delete())),
        );
        Ticket::query()->create(['customer_email' => 'x@customer.example', 'subject' => 'old', 'body' => 'b', 'created_at' => now()->subDays(60)]);

        $this->artisan('gdpr:retention', ['--apply' => true, '--only' => Ticket::class])->assertSuccessful();

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame($ada->email, Customer::query()->first()->email);
    }

    public function test_it_refuses_on_a_panel_with_tenancy(): void
    {
        \Filament\Facades\Filament::getPanel('admin')->tenant(Customer::class);

        $this->artisan('gdpr:retention')->assertFailed();
        $this->artisan('gdpr:prune')->assertFailed();
        $this->artisan('gdpr:remind')->assertFailed();
    }
}
