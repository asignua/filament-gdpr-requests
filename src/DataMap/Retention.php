<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use InvalidArgumentException;

/**
 * A retention policy of one map entry, applied by `gdpr:retention --apply` (dry run by default, never from config).
 *
 *     ->retention(Retention::after(days: 730, from: 'last_login_at')->then(Erase::delete()))
 */
final class Retention
{
    private function __construct(
        public readonly int $days,
        public readonly string $from,
        public readonly Erase $then,
    ) {}

    public static function after(int $days, string $from = 'created_at'): RetentionBuilder
    {
        if ($days < 1) {
            throw new InvalidArgumentException('A retention period must be at least one day.');
        }

        return new RetentionBuilder($days, $from);
    }

    /**
     * @internal use Retention::after(...)->then(...)
     */
    public static function make(int $days, string $from, Erase $then): self
    {
        if ($then instanceof Retain) {
            throw new InvalidArgumentException('A retention policy anonymizes or deletes; to keep rows use Erase::retain() on the entry.');
        }

        return new self($days, $from, $then);
    }
}
