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

        $found = array_flip([...$this->exact(), ...$this->nonCanonical()]);
        $missed = array_values(array_diff_key($expected, $found));

        $this->assertGreaterThan(50, count($expected));
        $this->assertSame([], $missed, 'Spellings of the file neither query finds.');
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
        return $this->ids(static fn (Builder $query) => Sql::whereNonCanonicalPath($query, 'gdpr_paths.path'));
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
