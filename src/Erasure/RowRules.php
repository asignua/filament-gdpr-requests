<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\DataMap\Anonymize;
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

        if (is_array($new) || is_object($new)) {
            $new = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_numeric($old) && is_numeric($new)) {
            return (float) $old === (float) $new;
        }

        return (string) $old === (string) $new;
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
