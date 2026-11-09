<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Throwable;

/**
 * The address comparison in the database. SQL's TRIM strips spaces and nothing else, and SQLite's LOWER knows only
 * ASCII, so each driver is told exactly what Pseudonym::normalizeEmail() does: a row PHP would accept must not be
 * missed by the query in front of it.
 */
final class Sql
{
    /**
     * The characters stripped from both ends of an address, as code points: the ASCII controls and spaces, NEL, no-break
     * space, the Unicode spaces, the line separators, zero-width space and BOM.
     *
     * @var list<int>
     */
    public const array TRIMMED = [
        0x00, 0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20, 0x85, 0xA0, 0x1680,
        0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005, 0x2006, 0x2007, 0x2008, 0x2009, 0x200A, 0x200B,
        0x2028, 0x2029, 0x202F, 0x205F, 0x3000, 0xFEFF,
    ];

    private const string SQLITE_FUNCTION = 'gdpr_normalize';

    /** U+0130, whose lowercase in PHP is two code points. */
    private const string DOTTED_I = "\u{130}";

    private const string DOTTED_I_LOWER = "i\u{307}";

    /** PHP lowercases a word-final capital sigma to ς, the server always to σ. */
    private const string SIGMA_FINAL = "\u{3C2}";

    private const string SIGMA = "\u{3C3}";

    /**
     * The inside of a regular expression class holding TRIMMED.
     */
    public static function characterClass(): string
    {
        return implode('', array_map(static fn (int $code): string => sprintf('\x{%X}', $code), self::TRIMMED));
    }

    /**
     * The normalised form of the column equals the value: never LIKE. The identifier is quoted by the grammar and the
     * values are bindings. Used for addresses, and for keys when looking for rows that hold a near miss of one. On a
     * server it may also find rows PHP's normalisation tells apart (see the folding below): compare the rows in PHP.
     *
     * @param Builder<Model> $query
     */
    public static function whereNormalizedEquals(Builder $query, string $qualifiedColumn, string $normalizedValue): void
    {
        $column = $query->getQuery()->getGrammar()->wrap($qualifiedColumn);
        [$sql, $bindings] = self::normalized($query->getModel()->getConnection(), $column);

        $query->whereRaw(new Expression($sql.' = ?'), [...$bindings, self::fold($normalizedValue, $query->getModel()->getConnection())]); // @phpstan-ignore argument.type
    }

    /**
     * Rows whose path column is not in its normal form (Files::NON_CANONICAL), compared by code point: a collation
     * would only slow the scan down (MariaDB's utf8mb4 LIKE is about three times slower) or refuse LIKE outright (a
     * nondeterministic one on Postgres). On MariaDB the column is converted to utf8mb4 first: its raw bytes are not the
     * pattern's in utf16, ucs2 or utf32 (every pattern missed), nor the backslash in sjis.
     *
     * With segments, only the rows that also hold one of them (the last segment of each file looked for, Files::
     * lastSegment()) under the same expression: normalising keeps a path's last segment as it is, so a spelling of the
     * file always contains it, and a table where every path starts with `/` is not read whole.
     *
     * A column whose text form is not its bytes (comparesPathsAsText) gets no constraint at all: every row is read and
     * PHP compares them.
     *
     * @param Builder<Model> $query
     * @param list<string>   $segments
     */
    public static function whereNonCanonicalPath(Builder $query, string $qualifiedColumn, array $segments = []): void
    {
        $connection = $query->getModel()->getConnection();
        $dot = strrpos($qualifiedColumn, '.');

        if ($dot !== false && !self::comparesPathsAsText($connection, substr($qualifiedColumn, 0, $dot), substr($qualifiedColumn, $dot + 1))) {
            return;
        }

        $column = $query->getQuery()->getGrammar()->wrap($qualifiedColumn);
        $like = match ($connection->getDriverName()) {
            'pgsql' => 'CAST('.$column.' AS TEXT) COLLATE "C" LIKE ?',
            'mysql', 'mariadb' => 'CONVERT('.$column.' USING utf8mb4) COLLATE utf8mb4_bin LIKE ?',
            default => $column.' LIKE ?',
        };
        $any = static function (Builder $where, array $patterns) use ($like): void {
            foreach ($patterns as $pattern) {
                $where->orWhereRaw($like." ESCAPE '!'", [$pattern]); // @phpstan-ignore argument.type (the column is wrapped by the grammar)
            }
        };

        // The segment first: it rules out nearly every row, and MariaDB then skips the six patterns behind it.
        if ($segments !== []) {
            $query->where(static fn (Builder $where) => $any($where, array_map(static fn (string $segment): string => '%'.self::escapeLike($segment).'%', $segments)));
        }

        $query->where(static fn (Builder $where) => $any($where, Files::NON_CANONICAL));
    }

    /**
     * Whether a LIKE on the column's text form sees the path the application wrote. On Postgres only a string type does
     * (a domain carries its base type's category): a bytea casts to its output format, `\x2f646f6373…` in hex, where no
     * segment and no pattern of a path is ever found, and an escape-format output turns every non-ASCII byte into an
     * octal escape. MariaDB's CONVERT reads the bytes of a BLOB or VARBINARY as they are, but a BINARY(n) holds the
     * path padded with NUL to its width, which neither the exact lookup nor a trailing pattern sees through. SQLite
     * compares the bytes as stored. A column that cannot be looked up counts as not: reading it whole is slow, missing a
     * row deletes a file another row names.
     */
    public static function comparesPathsAsText(Connection $connection, string $table, string $column): bool
    {
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            try {
                // SHOW COLUMNS, not information_schema: it also sees a temporary table.
                foreach ($connection->select('SHOW COLUMNS FROM '.$connection->getQueryGrammar()->wrapTable($table)) as $field) {
                    if (is_object($field) && ($field->Field ?? null) === $column) {
                        return !str_starts_with(strtolower((string) ($field->Type ?? '')), 'binary');
                    }
                }
            } catch (Throwable) {
                return false;
            }

            return false;
        }

        if ($connection->getDriverName() !== 'pgsql') {
            return true;
        }

        try {
            $category = $connection->selectOne(
                'SELECT t.typcategory AS category FROM pg_attribute a JOIN pg_type t ON t.oid = a.atttypid'
                .' WHERE a.attrelid = CAST(? AS regclass) AND a.attname = ? AND a.attnum > 0 AND NOT a.attisdropped',
                [$connection->getQueryGrammar()->wrapTable($table), $column],
            );
        } catch (Throwable) {
            return false;
        }

        return is_object($category) && ($category->category ?? null) === 'S';
    }

    /**
     * The value as a literal inside a LIKE pattern with ESCAPE '!': `%` and `_` match themselves, and so does `!`. The
     * backslash is no escape there, on any of the drivers.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * The normalised form of an SQL expression (a wrapped column, or `?` for a bound probe) on this connection, with the
     * bindings it needs before any of the caller's.
     *
     * @return array{string, list<string>}
     */
    public static function normalized(Connection $connection, string $expression): array
    {
        // The server's LOWER maps one letter to one letter; PHP's mb_strtolower does not for two of them (İ becomes i plus
        // a combining dot, a final Σ becomes ς). Both sides are folded the same way here, so the query finds a superset of
        // what PHP accepts; every caller then compares in PHP exactly.
        return match ($connection->getDriverName()) {
            'sqlite' => [self::sqliteFunction($connection).'('.$expression.')', []],
            // Postgres text cannot hold NUL, not even as an argument. LOWER folds by the collation of its input, and a
            // column may carry its own (`COLLATE "C"` folds ASCII only): COLLATE "default" makes it the database
            // default, the one Environment's probe checked.
            'pgsql' => [
                'REPLACE(LOWER(REPLACE(BTRIM(CAST('.$expression.' AS TEXT) COLLATE "default", ?), ?, ?)), ?, ?)',
                [self::characters(), self::DOTTED_I, self::DOTTED_I_LOWER, self::SIGMA_FINAL, self::SIGMA],
            ],
            // CONVERT first: on a latin1 column the expression would otherwise run without Unicode. Its result takes the
            // default collation of utf8mb4 whatever the column's, so LOWER folds a column the way it folds the probe.
            'mysql', 'mariadb' => [
                "REPLACE(LOWER(REPLACE(REGEXP_REPLACE(CONVERT({$expression} USING utf8mb4), ?, ''), ?, ?)), ?, ?)",
                ['\A['.self::characterClass().']+|['.self::characterClass().']+\z', self::DOTTED_I, self::DOTTED_I_LOWER, self::SIGMA_FINAL, self::SIGMA],
            ],
            default => ['LOWER(TRIM('.$expression.'))', []],
        };
    }

    /**
     * The value as the server-side expression leaves an address PHP has normalised: SQLite answers with PHP itself.
     */
    public static function fold(string $normalizedValue, Connection $connection): string
    {
        return in_array($connection->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)
            ? str_replace(self::SIGMA_FINAL, self::SIGMA, $normalizedValue)
            : $normalizedValue;
    }

    private static function characters(): string
    {
        return implode('', array_map(mb_chr(...), array_values(array_filter(self::TRIMMED, static fn (int $code): bool => $code !== 0))));
    }

    /**
     * PHP itself answers on SQLite. The function lives on the PDO handle, so it is declared wherever a query may go.
     */
    private static function sqliteFunction(Connection $connection): string
    {
        foreach ([$connection->getPdo(), $connection->getReadPdo()] as $pdo) {
            $normalize = static fn (mixed $value): ?string => is_scalar($value) ? Pseudonym::normalizeEmail((string) $value) : null;

            // Pdo\Sqlite (PHP 8.4) has the first; the plain handle of PHP 8.3 only the second.
            if (method_exists($pdo, 'createFunction')) {
                $pdo->createFunction(self::SQLITE_FUNCTION, $normalize, 1);
            } else {
                $pdo->sqliteCreateFunction(self::SQLITE_FUNCTION, $normalize, 1);
            }
        }

        return self::SQLITE_FUNCTION;
    }
}
