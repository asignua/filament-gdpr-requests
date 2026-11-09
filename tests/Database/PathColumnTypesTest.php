<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Database;

use Asignua\FilamentGdprRequests\Erasure\Blocker;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Support\Files;
use Asignua\FilamentGdprRequests\Support\Sql;
use Asignua\FilamentGdprRequests\Tests\Support\PathSpellings;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use Workbench\App\Models\Document;

/**
 * The shared-file check asks a declared path column for the canonical spellings and for every row not in its normal
 * form. Whatever the column's type, character set or collation, no spelling of the file may slip past both: a file
 * another row still names would be deleted.
 */
class PathColumnTypesTest extends TestCase
{
    use RunsErasures;

    /**
     * @return array<string, array{string, string}>
     */
    public static function columnTypes(): array
    {
        return [
            'mariadb utf8mb4' => ['mariadb', 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'],
            'mariadb utf8mb3' => ['mariadb', 'VARCHAR(255) CHARACTER SET utf8mb3'],
            'mariadb latin1' => ['mariadb', 'VARCHAR(255) CHARACTER SET latin1'],
            'mariadb ascii' => ['mariadb', 'VARCHAR(255) CHARACTER SET ascii'],
            'mariadb gbk' => ['mariadb', 'VARCHAR(255) CHARACTER SET gbk'],
            'mariadb big5' => ['mariadb', 'VARCHAR(255) CHARACTER SET big5'],
            'mariadb sjis' => ['mariadb', 'VARCHAR(255) CHARACTER SET sjis'],
            'mariadb utf16' => ['mariadb', 'VARCHAR(255) CHARACTER SET utf16'],
            'mariadb ucs2' => ['mariadb', 'VARCHAR(255) CHARACTER SET ucs2'],
            'mariadb utf32' => ['mariadb', 'VARCHAR(255) CHARACTER SET utf32'],
            'mariadb varbinary' => ['mariadb', 'VARBINARY(255)'],
            'mariadb blob' => ['mariadb', 'BLOB'],
            'mariadb binary' => ['mariadb', 'BINARY(64)'],
            'pgsql text' => ['pgsql', 'TEXT'],
            'pgsql C' => ['pgsql', 'TEXT COLLATE "C"'],
            'pgsql ICU' => ['pgsql', 'TEXT COLLATE "und-x-icu"'],
            'pgsql ICU nondeterministic' => ['pgsql', 'TEXT COLLATE gdpr_ci'],
            'pgsql citext' => ['pgsql', 'CITEXT'],
            'pgsql bytea' => ['pgsql', 'BYTEA'],
            'pgsql bytea domain' => ['pgsql', 'gdpr_bytes'],
            'sqlite text' => ['sqlite', 'TEXT'],
        ];
    }

    #[DataProvider('columnTypes')]
    public function test_every_spelling_of_the_file_is_found_in_the_column(string $driver, string $type): void
    {
        $this->givenPathColumn($driver, $type);
        PathSpellings::configureDisks();
        $target = Files::identity('local', 'docs/Ada.txt');
        $expected = [];

        foreach (PathSpellings::generated() as $i => [$disk, $path]) {
            $this->insert($i + 1, $disk, $path, $type);

            if (Files::identity($disk, $path) === $target) {
                $expected[$i + 1] = $disk.':'.$path;
            }
        }

        // Rows of other files, every one not in its normal form: the segment keeps them out of PHP.
        $others = ['/bulk/1.txt', '//docs/Bob.txt', 'docs\\Ad.txt/../Bob.txt', '/docs/ada.txt'];

        foreach ($others as $k => $path) {
            $this->insert(10_000 + $k, 'local', $path, $type);
        }

        $found = array_flip([...$this->exact(), ...$this->nonCanonical()]);
        $missed = array_values(array_diff_key($expected, $found));

        $this->assertGreaterThan(50, count($expected));
        $this->assertSame([], $missed, 'Spellings of the file neither query finds.');

        if (!Sql::comparesPathsAsText(DB::connection(), 'gdpr_paths', 'path')) {
            // A bytea, or a NUL-padded BINARY: the column is read whole and PHP compares every row.
            $this->assertContains($type, ['BYTEA', 'gdpr_bytes', 'BINARY(64)']);
            $this->assertSame(count(PathSpellings::generated()) + count($others), count($found));
        } elseif ($driver !== 'sqlite') {
            // SQLite's LIKE ignores ASCII case and lets `ada.txt` through; PHP tells it apart. Elsewhere none passes.
            $this->assertSame([], array_values(array_intersect_key(array_flip(array_map(static fn (int $k): int => 10_000 + $k, array_keys($others))), $found)));
        }
    }

    #[DataProvider('columnTypes')]
    public function test_the_segment_is_matched_literally(string $driver, string $type): void
    {
        $this->givenPathColumn($driver, $type);
        $rows = [1 => '/docs/50%_a!b.txt', 2 => '/docs/50x_a!b.txt', 3 => '/docs/50%ya!b.txt', 4 => '/docs/50%_ab.txt', 5 => 'docs\\50%_a!b.txt'];

        foreach ($rows as $id => $path) {
            $this->insert($id, 'local', $path, $type);
        }

        $segment = Files::lastSegment('docs/50%_a!b.txt');
        $found = $this->ids(static fn (Builder $query) => Sql::whereNonCanonicalPath($query, 'gdpr_paths.path', [$segment]));
        sort($found);

        $this->assertSame('50%_a!b.txt', $segment);

        if (Sql::comparesPathsAsText(DB::connection(), 'gdpr_paths', 'path')) {
            $this->assertSame([1, 5], $found, '% and _ in the name match only themselves.');
        } else {
            // A column whose text form is not its bytes is read whole.
            $this->assertSame([1, 2, 3, 4, 5], $found);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function byteaSpellings(): array
    {
        return [
            'leading slash' => ['/docs/Ada.txt'],
            'trailing dot' => ['docs/Ada.txt/.'],
            'trailing parent' => ['docs/Ada.txt/x/..'],
            'dot segment' => ['docs/./Ada.txt'],
            'as it is' => ['docs/Ada.txt'],
        ];
    }

    /**
     * The whole way through on a bytea path column: the rows are found, and their streams are read as the bytes.
     */
    #[DataProvider('byteaSpellings')]
    public function test_a_file_another_row_names_in_a_bytea_column_is_shared(string $path): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgsql only.');
        }

        DB::statement("ALTER TABLE documents ALTER COLUMN path TYPE BYTEA USING convert_to(path, 'UTF8')");
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $bob = Shop::customer('Bob', 'bob@customer.example');
        Document::query()->where('customer_id', $bob->id)->update(['path' => $path]);

        $codes = array_map(static fn (Blocker $blocker): string => $blocker->code, app(ErasureService::class)->plan($this->erasureOf($ada))->allBlockers());

        $this->assertContains('file_shared', $codes, "Bob's {$path} names Ada's file.");
    }

    public function test_the_last_segment_survives_every_spelling(): void
    {
        PathSpellings::configureDisks();
        $target = Files::identity('local', 'docs/Ada.txt');

        foreach (PathSpellings::generated() as [$disk, $path]) {
            if (Files::identity($disk, $path) === $target) {
                $this->assertStringContainsString('Ada.txt', $path);
                $this->assertSame('Ada.txt', Files::lastSegment($path), $disk.':'.$path);
            }
        }

        $this->assertSame('', Files::lastSegment('/'));
        $this->assertSame('', Files::lastSegment('../..'));
        $this->assertSame('b', Files::lastSegment('a\\b'));
    }

    private function givenPathColumn(string $driver, string $type): void
    {
        if (DB::getDriverName() !== $driver) {
            $this->markTestSkipped($driver.' only.');
        }

        if (str_contains($type, 'gdpr_ci')) {
            DB::statement("CREATE COLLATION IF NOT EXISTS gdpr_ci (provider = icu, locale = 'und-u-ks-level2', deterministic = false)");
        }

        if ($type === 'gdpr_bytes') {
            DB::statement('DO $$ BEGIN CREATE DOMAIN gdpr_bytes AS BYTEA; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        }

        if ($type === 'CITEXT') {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
            } catch (Throwable $e) {
                $this->markTestSkipped('citext is not available here: '.class_basename($e));
            }
        }

        // A temporary table: MariaDB commits the test's transaction on any other DDL.
        DB::statement('CREATE TEMPORARY TABLE gdpr_paths (id INTEGER PRIMARY KEY, disk VARCHAR(64), path '.$type.')');
    }

    /**
     * Postgres reads a string bound for a bytea as its escape format, where a backslash starts an escape: the bytes go in
     * as hex. MariaDB's BINARY pads with NUL; the row holds exactly what the application wrote only in the others.
     */
    private function insert(int $id, string $disk, string $path, string $type): void
    {
        if (in_array($type, ['BYTEA', 'gdpr_bytes'], true)) {
            DB::insert("INSERT INTO gdpr_paths (id, disk, path) VALUES (?, ?, decode(?, 'hex'))", [$id, $disk, bin2hex($path)]);

            return;
        }

        DB::table('gdpr_paths')->insert(['id' => $id, 'disk' => $disk, 'path' => $path]);
    }

    /**
     * @return list<int>
     */
    private function exact(): array
    {
        $spellings = Files::spellings('local', 'docs/Ada.txt');

        return $this->ids(static fn (Builder $query) => $query->whereIn('gdpr_paths.path', $spellings));
    }

    /**
     * @return list<int>
     */
    private function nonCanonical(): array
    {
        $segments = [Files::lastSegment('docs/Ada.txt')];

        return $this->ids(static fn (Builder $query) => Sql::whereNonCanonicalPath($query, 'gdpr_paths.path', $segments));
    }

    /**
     * @param callable(Builder<Model>): mixed $constrain
     *
     * @return list<int>
     */
    private function ids(callable $constrain): array
    {
        $model = new class extends Model
        {
            protected $table = 'gdpr_paths';

            public $timestamps = false;
        };
        $query = $model->newQuery();
        $constrain($query);

        return array_map(intval(...), $query->pluck('id')->all());
    }
}
