<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Closure;

/**
 * What to do with ONE column when a row is anonymized. There is no default: a column without a rule is not touched.
 *
 * Every rule is repeatable on a row it has already changed (the same input gives the same output, or the rule
 * recognises its own result), so a rerun after a crash does no damage.
 */
final class Rule
{
    public const string NULL = 'null';

    public const string FIXED = 'fixed';

    public const string HASH = 'hash';

    public const string TOKEN = 'token';

    public const string CALLBACK = 'callback';

    /** Marker that lets `hash` recognise a value it already produced. */
    public const string HASH_PREFIX = 'h1$';

    private function __construct(
        public readonly string $kind,
        public readonly mixed $value = null,
        public readonly int $length = 0,
        public readonly string $prefix = '',
        public readonly string $suffix = '',
        public readonly ?Closure $callback = null,
        public readonly bool $unique = false,
    ) {}

    /**
     * Set the column to NULL. The preview blocks it when the column is NOT NULL.
     */
    public static function null(): self
    {
        return new self(self::NULL);
    }

    /**
     * Set the column to one constant. On a unique column it is blocked unless exactly one row is touched.
     */
    public static function fixed(mixed $value): self
    {
        return new self(self::FIXED, $value);
    }

    /**
     * A keyed one-way digest of the old value (HMAC with GDPR_PSEUDONYM_KEY), `h1$` + $length hex characters.
     */
    public static function hash(int $length = 32): self
    {
        return new self(self::HASH, length: $length);
    }

    /**
     * prefix + a token derived from table, column and row key: unique per row, the same on every rerun.
     */
    public static function uniqueToken(string $prefix = 'erased-', string $suffix = '', int $length = 16): self
    {
        return new self(self::TOKEN, length: $length, prefix: $prefix, suffix: $suffix);
    }

    /**
     * Your own rule: fn (mixed $original, array $row): mixed. It must be repeatable (calling it on its own
     * output changes nothing). On a unique column pass $unique: true to assert that results never collide.
     *
     * @param Closure(mixed, array<string, mixed>): mixed $callback
     */
    public static function using(Closure $callback, bool $unique = false): self
    {
        return new self(self::CALLBACK, callback: $callback, unique: $unique);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function apply(mixed $original, string $table, string $column, mixed $key, array $row): mixed
    {
        return match ($this->kind) {
            self::NULL => null,
            self::FIXED => $this->value,
            self::HASH => $this->hashed($original, $table, $column),
            self::TOKEN => $this->prefix.Pseudonym::digest($table.'.'.$column, (string) $key, $this->length).$this->suffix,
            default => $this->callback === null ? $original : ($this->callback)($original, $row),
        };
    }

    /**
     * The longest string this rule can write, or null when it cannot be known (callback) or is not a string.
     */
    public function maxLength(): ?int
    {
        return match ($this->kind) {
            self::FIXED => is_string($this->value) ? mb_strlen($this->value) : null,
            self::HASH => strlen(self::HASH_PREFIX) + max(8, min(64, $this->length)),
            self::TOKEN => strlen($this->prefix) + max(8, min(64, $this->length)) + strlen($this->suffix),
            default => null,
        };
    }

    /**
     * True when the rule writes a string derived from the old value or the row (not a constant, not null).
     */
    public function producesText(): bool
    {
        return $this->kind === self::HASH || $this->kind === self::TOKEN;
    }

    public function describe(): string
    {
        return match ($this->kind) {
            self::NULL => 'NULL',
            self::FIXED => 'fixed '.json_encode($this->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::HASH => 'hash',
            self::TOKEN => 'token '.$this->prefix.'…'.$this->suffix,
            default => 'callback'.($this->unique ? ' (unique)' : ''),
        };
    }

    private function hashed(mixed $original, string $table, string $column): mixed
    {
        if ($original === null) {
            return null;
        }

        $text = (string) $original;

        if (str_starts_with($text, self::HASH_PREFIX)) {
            return $text;
        }

        return self::HASH_PREFIX.Pseudonym::digest($table.'.'.$column, $text, $this->length);
    }
}
