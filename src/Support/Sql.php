<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * The inside of a regular expression class holding TRIMMED.
     */
    public static function characterClass(): string
    {
        return implode('', array_map(static fn (int $code): string => sprintf('\x{%X}', $code), self::TRIMMED));
    }

    /**
     * The normalised form of the column equals the value: never LIKE. The identifier is quoted by the grammar and the
     * values are bindings. Used for addresses, and for keys when looking for rows that hold a near miss of one.
     *
     * @param Builder<Model> $query
     */
    public static function whereNormalizedEquals(Builder $query, string $qualifiedColumn, string $normalizedValue): void
    {
        $base = $query->getQuery();
        $column = $base->getGrammar()->wrap($qualifiedColumn);
        $connection = $base->getConnection();

        match ($connection->getDriverName()) {
            'sqlite' => $query->whereRaw(self::sqliteFunction($connection).'('.$column.') = ?', [$normalizedValue]),
            // Postgres text cannot hold NUL, not even as an argument.
            'pgsql' => $query->whereRaw('LOWER(BTRIM(CAST('.$column.' AS TEXT), ?)) = ?', [self::characters(), $normalizedValue]),
            // CONVERT first: on a latin1 column the expression would otherwise run without Unicode.
            'mysql', 'mariadb' => $query->whereRaw("LOWER(REGEXP_REPLACE(CONVERT({$column} USING utf8mb4), ?, '')) = ?", ['\A['.self::characterClass().']+|['.self::characterClass().']+\z', $normalizedValue]),
            default => $query->whereRaw('LOWER(TRIM('.$column.')) = ?', [$normalizedValue]),
        };
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
