<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

/**
 * What happens to the rows of one map entry on erasure: anonymize, delete or retain.
 * Nothing is inferred; an entry without a strategy is left untouched (and the preview says so).
 */
abstract class Erase
{
    public const string ANONYMIZE = 'anonymize';

    public const string DELETE = 'delete';

    public const string RETAIN = 'retain';

    /**
     * @param array<string, Rule> $rules column => rule; a column without a rule is not changed
     * @param bool                $files also remove the files the entry's files() resolver names
     */
    public static function anonymize(array $rules, bool $files = false): Anonymize
    {
        return new Anonymize($rules, $files);
    }

    /**
     * Physical removal, soft-deleted rows included. There is no copy and no undo.
     */
    public static function delete(bool $files = false): Delete
    {
        return new Delete($files);
    }

    /**
     * Keep the rows whole, on a stated legal basis, for $days counted from the date in column $from.
     * After that the rows only show up in the `gdpr:retention` plan; nothing changes by itself.
     */
    public static function retain(string $basis, int $days, string $from = 'created_at'): Retain
    {
        return new Retain($basis, $days, $from);
    }

    abstract public function kind(): string;

    public function removesFiles(): bool
    {
        return false;
    }
}
