<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\DataMap\Anonymize;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use DateTimeInterface;

/**
 * Applies the declared rules to one raw row and returns only the columns that really change.
 */
final class RowRules
{
    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed> column => value to store
     */
    public static function changes(Anonymize $erase, string $table, string $keyName, array $row): array
    {
        $changes = [];

        foreach ($erase->rules as $column => $rule) {
            $original = $row[$column] ?? null;
            $new = $rule->apply($original, $table, $column, $row[$keyName] ?? null, $row);

            if (!self::same($original, $new)) {
                $changes[$column] = self::storable($new);
            }
        }

        return $changes;
    }

    /**
     * The columns whose callback would change the row again: the one rule the plugin cannot make repeatable itself.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $changes what changes() answered for this row
     *
     * @return list<string>
     */
    public static function unrepeatable(Anonymize $erase, string $table, string $keyName, array $row, array $changes): array
    {
        $after = [...$row, ...$changes];
        $columns = [];

        foreach ($changes as $column => $value) {
            $rule = $erase->rules[$column];

            if ($rule->kind === Rule::CALLBACK && !self::same($value, $rule->apply($value, $table, $column, $row[$keyName] ?? null, $after))) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Loose comparison between what the driver returned and what a rule produced (1 / true, "5" / 5).
     */
    private static function same(mixed $old, mixed $new): bool
    {
        if ($old === null || $new === null) {
            return $old === $new;
        }

        if (is_bool($new)) {
            $new = (int) $new;
        }

        if (is_bool($old)) {
            $old = (int) $old;
        }

        $new = self::storable($new);

        $old = (string) $old;
        $new = (string) $new;

        return $old === $new
            || self::sameNumber($old, $new)
            || rtrim($old, ' ') === rtrim($new, ' ') // CHAR(n) comes back padded
            || self::sameMoment($old, $new)
            || self::sameJson($old, $new);
    }

    /**
     * Compared as text: past 2^53 a float cannot tell two numbers apart, and a masked card or account number differs from
     * the original only in the digits a float rounds away. `5.0` is `5`, `007` is `7`.
     */
    private static function sameNumber(string $old, string $new): bool
    {
        $canonical = static function (string $number): ?string {
            if (preg_match('/\A([+-]?)0*(\d+?)(?:\.(\d*?)0*)?\z/', $number, $parts) !== 1) {
                return null;
            }

            $digits = $parts[2].(($parts[3] ?? '') === '' ? '' : '.'.$parts[3]);

            return ($parts[1] === '-' && $digits !== '0' ? '-' : '').$digits;
        };

        $left = $canonical($old);

        return $left !== null && $left === $canonical($new);
    }

    /**
     * `2000-01-01` stored in a datetime column comes back with a time.
     */
    private static function sameMoment(string $old, string $new): bool
    {
        $date = '/\A\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}(?::?\d{2})?)?)?\z/';

        return preg_match($date, $old) === 1 && preg_match($date, $new) === 1 && strtotime($old) === strtotime($new);
    }

    /**
     * A json column may hand back other spacing or key order than was written.
     */
    private static function sameJson(string $old, string $new): bool
    {
        $decodedOld = json_decode($old, true);
        $decodedNew = json_decode($new, true);

        return is_array($decodedOld) && is_array($decodedNew) && $decodedOld == $decodedNew; // key order does not count
    }

    private static function storable(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value), is_object($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            default => $value,
        };
    }
}
