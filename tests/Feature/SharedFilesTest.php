<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Erasure\Blocker;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Support\Files;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Models\Customer;
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
     * @return array<string, array{string, string, bool}>
     */
    public static function otherSpellings(): array
    {
        return [
            'leading slash' => ['local', '/docs/Ada.txt', false],
            'dot segment and double slash, read whole' => ['local', './docs//Ada.txt', true],
            'backslashes' => ['local', 'docs\\Ada.txt', false],
            'leading slash, read whole' => ['local', '/docs/Ada.txt', true],
            'a scoped disk over the same storage' => ['local-docs', 'Ada.txt', false],
            'another disk name over the same root' => ['local-again', 'docs/Ada.txt', false],
        ];
    }

    #[DataProvider('otherSpellings')]
    public function test_a_file_another_row_names_in_another_spelling_is_still_shared(string $disk, string $path, bool $readWhole): void
    {
        if ($readWhole) {
            // Without declared file columns every row is read, and any spelling Flysystem accepts is the same file.
            $this->registry()->forget(Document::class);
            PersonalData::for(Document::class)->label('Documents')->of(Customer::class)->matchedBy('customer_id')
                ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]])
                ->erase(Erase::delete(files: true));
        }

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

    /**
     * @return array<string, array{string}>
     */
    public static function spellingsAfterTheConfirmation(): array
    {
        return [
            'leading slash' => ['/docs/Ada.txt'],
            'double slash' => ['docs//Ada.txt'],
            'parent segment' => ['docs/x/../Ada.txt'],
            'dot segment' => ['docs/./Ada.txt'],
        ];
    }

    #[DataProvider('spellingsAfterTheConfirmation')]
    public function test_a_file_named_in_another_spelling_after_the_confirmation_stays_on_the_disk(string $path): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bob = Shop::customer('Bob', 'bob@customer.example');
        $request = $this->confirmed($ada);
        Document::query()->where('customer_id', $bob->id)->update(['path' => $path]);

        try {
            $this->execute($request);
        } catch (\Throwable) {
            // the blocked group fails the job; the request says so
        }

        $this->assertTrue(Storage::disk('local')->exists('docs/Ada.txt'), "Bob's file is not deleted.");
        $this->assertNotSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    /**
     * Spellings of Ada's file (and near misses that are other files) built from random leads, separators and tails, on
     * the disk itself, a scoped disk and a second disk name over the same root. Seeded: a failure names its spelling.
     *
     * @return list<array{string, string}>
     */
    private static function generatedSpellings(): array
    {
        $bases = [['local', ['docs', 'Ada.txt']], ['local-docs', ['Ada.txt']], ['local-again', ['docs', 'Ada.txt']]];
        $leads = ['', '', '/', './', '//', '/./', 'x/../', './x/../', '\\', '.\\', 'a/b/../../', '../', '/../'];
        $separators = ['/', '/', '//', '///', '/./', '/y/../', '\\', '\\\\', '/.\\', '\\./', '/z/./../'];
        $tails = ['', '', '/', '/.', '//', '\\', '/x/..', '.bak', '/..'];
        $spellings = [
            ['local', 'docs//Ada.txt'], ['local', 'docs/x/../Ada.txt'], ['local', 'docs/./Ada.txt'],
            ['local', 'docs/ada.txt'], ['local', 'docs/x/Ada.txt'], ['local', 'docsAda.txt'], ['local', 'docs/../Ada.txt'],
        ];
        mt_srand(20261109);

        for ($i = 0; $i < 150; $i++) {
            [$disk, $segments] = $bases[$i % 3];
            $path = $leads[mt_rand(0, count($leads) - 1)];

            foreach ($segments as $k => $segment) {
                $path .= ($k > 0 ? $separators[mt_rand(0, count($separators) - 1)] : '').$segment;
            }

            $spellings[] = [$disk, $path.$tails[mt_rand(0, count($tails) - 1)]];
        }

        mt_srand();

        return array_values(array_unique($spellings, SORT_REGULAR));
    }

    public function test_declared_file_columns_catch_every_spelling_the_full_scan_catches(): void
    {
        config([
            'filesystems.disks.local-docs' => ['driver' => 'scoped', 'disk' => 'local', 'prefix' => 'docs'],
            'filesystems.disks.local-again' => ['driver' => 'local', 'root' => rtrim((string) config('filesystems.disks.local.root'), '/').'/'],
        ]);
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bob = Shop::customer('Bob', 'bob@customer.example');
        $request = $this->erasureOf($ada);
        $target = Files::identity('local', 'docs/Ada.txt');
        $differences = [];
        $same = 0;

        foreach (self::generatedSpellings() as [$disk, $path]) {
            Document::query()->where('customer_id', $bob->id)->update(['disk' => $disk, 'path' => $path]);
            $indexed = in_array('file_shared', $this->codes(app(ErasureService::class)->plan($request)), true);
            $this->declareDocuments(columns: false);
            $full = in_array('file_shared', $this->codes(app(ErasureService::class)->plan($request)), true);
            $this->declareDocuments(columns: true);

            $this->assertSame(Files::identity($disk, $path) === $target, $full, "The full scan decides by identity: {$disk}:{$path}");
            $same += $full ? 1 : 0;

            if ($indexed !== $full) {
                $differences[] = $disk.':'.$path.($full ? ' (missed)' : ' (extra)');
            }
        }

        $this->assertSame([], $differences);
        $this->assertGreaterThan(50, $same, 'Most generated spellings name the same file.');
    }

    private function declareDocuments(bool $columns): void
    {
        $this->registry()->forget(Document::class);
        PersonalData::for(Document::class)->label('Documents')->of(Customer::class)->matchedBy('customer_id')
            ->export(['id', 'title', 'created_at'])
            ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]], columns: $columns ? ['path'] : [])
            ->erase(Erase::delete(files: true));
    }

    public function test_different_files_keep_different_identities(): void
    {
        $this->assertNotSame(Files::identity('local', 'docs/Ada.txt'), Files::identity('local', 'docs/ada.txt'));
        $this->assertNotSame(Files::identity('local', 'docs/Ada.txt'), Files::identity('s3-unknown', 'docs/Ada.txt'));
        $this->assertSame(Files::identity('local', 'docs/Ada.txt'), Files::identity('local', 'docs/sub/../Ada.txt'));
    }

    public function test_declared_file_columns_are_asked_through_an_index_and_the_others_are_read_whole_with_a_notice(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Shop::customer('Bob', 'bob@customer.example');
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $plan = app(ErasureService::class)->plan($this->erasureOf($ada));

        $this->assertSame([], $plan->allNotices());
        $this->assertNotEmpty(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"documents"."path" in') || str_contains($sql, '`documents`.`path` in')));

        $this->registry()->forget(Document::class);
        PersonalData::for(Document::class)->label('Documents')->of(Customer::class)->matchedBy('customer_id')
            ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]])
            ->erase(Erase::delete(files: true));

        $notices = app(ErasureService::class)->plan($this->erasureOf($ada))->allNotices();

        $this->assertCount(1, $notices);
        $this->assertSame('files_full_scan', $notices[0]->code);
        $this->assertSame(['table' => 'documents', 'tables' => 'documents'], $notices[0]->params);
        $this->assertStringContainsString('documents', $notices[0]->message());
    }

    public function test_a_lookup_closure_narrows_the_rows_and_php_still_decides(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bob = Shop::customer('Bob', 'bob@customer.example');
        Document::query()->where('customer_id', $bob->id)->update(['path' => '/docs/Ada.txt']);
        $asked = [];
        $this->registry()->forget(Document::class);
        PersonalData::for(Document::class)->label('Documents')->of(Customer::class)->matchedBy('customer_id')
            ->files(
                static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]],
                lookup: static function ($query, array $paths) use (&$asked): void {
                    $asked = $paths;
                    $query->where(static function ($where) use ($paths): void {
                        foreach ($paths as $path) {
                            $where->orWhere('path', 'like', '%'.basename($path));
                        }
                    });
                },
            )
            ->erase(Erase::delete(files: true));

        $plan = app(ErasureService::class)->plan($this->erasureOf($ada));

        $this->assertSame(['docs/Ada.txt'], $asked);
        $this->assertContains('file_shared', array_map(static fn (Blocker $blocker): string => $blocker->code, $plan->allBlockers()));
        $this->assertSame([], $plan->allNotices());
    }
}
