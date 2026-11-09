<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Database;

use Asignua\FilamentGdprRequests\Support\Files;
use Asignua\FilamentGdprRequests\Support\Sql;
use Asignua\FilamentGdprRequests\Tests\Support\PathSpellings;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The shared-file check asks a declared path column for the canonical spellings and for every row not in its normal
 * form. Whatever the column's type, character set or collation, no spelling of the file may slip past both: a file
 * another row still names would be deleted.
 */
class PathColumnTypesTest extends TestCase
{
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
            'pgsql text' => ['pgsql', 'TEXT'],
            'pgsql C' => ['pgsql', 'TEXT COLLATE "C"'],
            'pgsql ICU' => ['pgsql', 'TEXT COLLATE "und-x-icu"'],
            'pgsql ICU nondeterministic' => ['pgsql', 'TEXT COLLATE gdpr_ci'],
            'pgsql citext' => ['pgsql', 'CITEXT'],
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
            DB::table('gdpr_paths')->insert(['id' => $i + 1, 'disk' => $disk, 'path' => $path]);

            if (Files::identity($disk, $path) === $target) {
                $expected[$i + 1] = $disk.':'.$path;
            }
        }

        // Rows of other files, every one not in its normal form: the segment keeps them out of PHP.
        $others = ['/bulk/1.txt', '//docs/Bob.txt', 'docs\\Ad.txt/../Bob.txt', '/docs/ada.txt'];

        foreach ($others as $k => $path) {
            DB::table('gdpr_paths')->insert(['id' => 10_000 + $k, 'disk' => 'local', 'path' => $path]);
        }

        $found = array_flip([...$this->exact(), ...$this->nonCanonical()]);
        $missed = array_values(array_diff_key($expected, $found));

        $this->assertGreaterThan(50, count($expected));
        $this->assertSame([], $missed, 'Spellings of the file neither query finds.');

        if ($driver !== 'sqlite') {
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
            DB::table('gdpr_paths')->insert(['id' => $id, 'disk' => 'local', 'path' => $path]);
        }

        $segment = Files::lastSegment('docs/50%_a!b.txt');
        $found = $this->ids(static fn (Builder $query) => Sql::whereNonCanonicalPath($query, 'gdpr_paths.path', [$segment]));
        sort($found);

        $this->assertSame('50%_a!b.txt', $segment);
        $this->assertSame([1, 5], $found, '% and _ in the name match only themselves.');
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
