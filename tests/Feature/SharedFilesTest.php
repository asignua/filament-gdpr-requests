<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Erasure\Blocker;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Support\Files;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Models\Document;

/**
 * A file is removed only when no row that stays still names it.
 */
class SharedFilesTest extends TestCase
{
    use RunsErasures;

    public function test_a_file_another_row_still_names_is_not_deleted(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bob = Shop::customer('Bob', 'bob@customer.example');
        Document::query()->where('customer_id', $bob->id)->update(['path' => 'docs/Ada.txt']);

        $plan = app(ErasureService::class)->plan($this->erasureOf($ada));

        $shared = array_values(array_filter($plan->allBlockers(), static fn (Blocker $blocker): bool => $blocker->code === 'file_shared'));
        $this->assertCount(1, $shared);
        // Tables, not people: nothing in the message says whose row it is.
        $this->assertSame(['table' => 'documents', 'others' => 'documents'], $shared[0]->params);
    }

    public function test_a_file_shared_after_the_confirmation_stays_on_the_disk(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bob = Shop::customer('Bob', 'bob@customer.example');
        $request = $this->confirmed($ada);
        Document::query()->where('customer_id', $bob->id)->update(['path' => 'docs/Ada.txt']);

        try {
            $this->execute($request);
        } catch (\Throwable) {
            // the blocked group fails the job; the request says so
        }

        $this->assertTrue(Storage::disk('local')->exists('docs/Ada.txt'));
        $this->assertSame(1, Document::query()->where('customer_id', $bob->id)->count());
        $this->assertNotSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function otherSpellings(): array
    {
        return [
            'leading slash' => ['local', '/docs/Ada.txt'],
            'dot segment and double slash' => ['local', './docs//Ada.txt'],
            'backslashes' => ['local', 'docs\\Ada.txt'],
            'a scoped disk over the same storage' => ['local-docs', 'Ada.txt'],
            'another disk name over the same root' => ['local-again', 'docs/Ada.txt'],
        ];
    }

    #[DataProvider('otherSpellings')]
    public function test_a_file_another_row_names_in_another_spelling_is_still_shared(string $disk, string $path): void
    {
        config([
            'filesystems.disks.local-docs' => ['driver' => 'scoped', 'disk' => 'local', 'prefix' => 'docs'],
            'filesystems.disks.local-again' => ['driver' => 'local', 'root' => rtrim((string) config('filesystems.disks.local.root'), '/').'/'],
        ]);
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bob = Shop::customer('Bob', 'bob@customer.example');
        Document::query()->where('customer_id', $bob->id)->update(['disk' => $disk, 'path' => $path]);

        $plan = app(ErasureService::class)->plan($this->erasureOf($ada));

        $this->assertContains('file_shared', array_map(static fn (Blocker $blocker): string => $blocker->code, $plan->allBlockers()));
    }

    public function test_a_file_named_in_another_spelling_after_the_confirmation_stays_on_the_disk(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bob = Shop::customer('Bob', 'bob@customer.example');
        $request = $this->confirmed($ada);
        Document::query()->where('customer_id', $bob->id)->update(['path' => '/docs/Ada.txt']);

        try {
            $this->execute($request);
        } catch (\Throwable) {
            // the blocked group fails the job; the request says so
        }

        $this->assertTrue(Storage::disk('local')->exists('docs/Ada.txt'), "Bob's file is not deleted.");
        $this->assertNotSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    public function test_different_files_keep_different_identities(): void
    {
        $this->assertNotSame(Files::identity('local', 'docs/Ada.txt'), Files::identity('local', 'docs/ada.txt'));
        $this->assertNotSame(Files::identity('local', 'docs/Ada.txt'), Files::identity('s3-unknown', 'docs/Ada.txt'));
        $this->assertSame(Files::identity('local', 'docs/Ada.txt'), Files::identity('local', 'docs/sub/../Ada.txt'));
    }
}
