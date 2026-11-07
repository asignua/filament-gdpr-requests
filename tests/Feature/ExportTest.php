<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Asignua\FilamentGdprRequests\Exceptions\UnsupportedEnvironment;
use Asignua\FilamentGdprRequests\Models\GdprRequestExport;
use Asignua\FilamentGdprRequests\Services\ExportService;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;
use Workbench\App\Models\Ticket;
use ZipArchive;

class ExportTest extends TestCase
{
    private function ready(Customer $customer, ?string $type = null): GdprRequestExport
    {
        $dpo = $this->user('dpo', 'Exporter');
        $request = Shop::request($customer, RequestType::from($type ?? 'access'), $dpo);

        return app(ExportService::class)->request($request, $dpo)->refresh();
    }

    /**
     * @return array<string, string>
     */
    private function unzip(GdprRequestExport $export): array
    {
        $zip = new ZipArchive;
        $path = sys_get_temp_dir().'/t-'.uniqid().'.zip';
        file_put_contents($path, Storage::disk('local')->get($export->path));
        $zip->open($path);
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($path);

        return $files;
    }

    public function test_the_archive_has_exactly_the_declared_columns_of_this_subject_only(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Bruno', 'bruno@customer.example');
        Order::query()->where('number', 'Ada-1')->first()->delete();

        $export = $this->ready($ada);
        $files = $this->unzip($export);
        $all = implode("\n", $files);

        $this->assertSame('ready', $export->status);
        $this->assertStringContainsString('Ada street 1', $all, 'A soft-deleted order is exported too.');
        $this->assertStringNotContainsString('Bruno', $all);
        $this->assertStringNotContainsString('bruno@customer.example', $all);

        $customerFile = collect($files)->first(fn ($content, $name) => str_contains($name, 'customer.json'));
        $row = json_decode($customerFile, true)[0];
        $this->assertSame(['id', 'name', 'email', 'phone', 'birthday', 'created_at'], array_keys($row));
        $this->assertArrayNotHasKey('updated_at', $row);

        $this->assertArrayHasKey('manifest.json', $files);
        $this->assertTrue(collect(array_keys($files))->contains(fn ($n) => str_starts_with($n, 'files/') && str_ends_with($n, 'Ada.txt')));
        $this->assertStringContainsString('secret of Ada', implode('', array_filter($files, fn ($n) => str_starts_with($n, 'files/'), ARRAY_FILTER_USE_KEY)));
    }

    public function test_the_archive_lies_on_a_private_disk_under_an_unguessable_name(): void
    {
        $export = $this->ready(Shop::customer('Ada', 'ada@customer.example'));

        $this->assertMatchesRegularExpression('#^gdpr-exports/[0-9a-z]{26}/[A-Za-z0-9]{40}\.zip$#', $export->path);
        $this->assertNotNull($export->expires_at);
        $this->assertSame(30, (int) round(now()->diffInDays($export->expires_at, true)));
    }

    public function test_cyrillic_is_not_escaped_in_the_archive(): void
    {
        $customer = Shop::customer('Олена', 'olena@customer.example');
        $export = $this->ready($customer);

        $this->assertStringContainsString('Олена', implode("\n", $this->unzip($export)));
    }

    public function test_export_needs_a_verified_identity_the_gate_and_the_right_type(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $dpo = $this->user('dpo');
        $service = app(ExportService::class);

        $unverified = Shop::request($ada, RequestType::Access, $dpo, verify: false);

        try {
            $service->request($unverified, $dpo);
            $this->fail('Unverified.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $erasure = Shop::request($ada, RequestType::Erasure, $dpo);

        try {
            $service->request($erasure, $dpo);
            $this->fail('Wrong type.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $verified = Shop::request($ada, RequestType::Portability, $dpo);
        $this->expectException(NotPermitted::class);
        $service->request($verified, $this->user('manager'));
    }

    public function test_a_public_export_disk_is_refused(): void
    {
        config(['filesystems.disks.local.visibility' => 'public']);
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);

        $this->expectException(UnsupportedEnvironment::class);

        app(ExportService::class)->request($request, $dpo);
    }

    public function test_the_signed_link_downloads_only_for_the_user_it_was_issued_to(): void
    {
        $dpo = $this->user('dpo', 'Owner');
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);
        $export = app(ExportService::class)->request($request, $dpo)->refresh();
        $url = app(ExportService::class)->link($export, $dpo, 'admin');

        $response = $this->actingAs($dpo)->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(1, $export->refresh()->downloads);
        $this->assertContains('export_downloaded', $request->events()->pluck('event')->all());

        // The link leaks to a colleague: useless for them.
        $other = $this->user('dpo', 'Colleague');
        $this->actingAs($other)->get($url)->assertForbidden();

        // The holder loses the export right after the link was issued: the gate is checked again on download.
        $dpo->update(['role' => 'manager']);
        $this->actingAs($dpo->refresh())->get($url)->assertForbidden();
        $dpo->update(['role' => 'dpo']);

        // A guest.
        auth()->logout();
        $this->get($url)->assertForbidden();
    }

    public function test_a_tampered_or_unsigned_link_is_refused(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);
        $export = app(ExportService::class)->request($request, $dpo)->refresh();
        $url = app(ExportService::class)->link($export, $dpo, 'admin');

        $this->actingAs($dpo)->get(route('filament-gdpr-requests.export.download', ['export' => $export->ulid, 'panel' => 'admin', 'uid' => $dpo->id]))->assertForbidden();
        $this->actingAs($dpo)->get(str_replace('uid='.$dpo->id, 'uid=999', $url))->assertForbidden();
    }

    public function test_the_link_expires(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);
        $export = app(ExportService::class)->request($request, $dpo)->refresh();
        $url = app(ExportService::class)->link($export, $dpo, 'admin');

        $this->travelTo(now()->addMinutes(16));

        $this->actingAs($dpo)->get($url)->assertForbidden();
    }

    public function test_an_expired_archive_is_not_served_and_prune_removes_it(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);
        $export = app(ExportService::class)->request($request, $dpo)->refresh();
        $path = $export->path;
        $url = app(ExportService::class)->link($export, $dpo, 'admin');

        $this->travelTo(now()->addDays(31));
        $url2 = $url;
        $this->actingAs($dpo)->get($url2)->assertForbidden(); // link itself is long expired
        $this->assertFalse($export->refresh()->isDownloadable());

        $this->artisan('gdpr:prune')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame('removed', $export->refresh()->status);
    }

    public function test_closing_the_request_removes_its_archives(): void
    {
        $dpo = $this->user();
        $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);
        $export = app(ExportService::class)->request($request, $dpo)->refresh();
        $path = $export->path;

        app(\Asignua\FilamentGdprRequests\Services\RequestService::class)->complete($request, $dpo);
        app(\Asignua\FilamentGdprRequests\Services\RequestService::class)->close($request->refresh(), $dpo);

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame('removed', $export->refresh()->status);
    }

    public function test_an_undeclared_column_is_a_loud_error_not_a_silent_gap(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->add(PersonalData::for(Ticket::class)->of(Customer::class)->matchedByEmail('customer_email')->export(['id', 'no_such_column'])->erase(Erase::delete()));
        $dpo = $this->user();
        $request = Shop::request($ada, RequestType::Access, $dpo);

        try {
            app(ExportService::class)->request($request, $dpo);
            $this->fail('The job should fail.');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $export = $request->exports()->first();
        $this->assertSame('failed', $export->status);
        $this->assertNull($export->path);
    }
}
