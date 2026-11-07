<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Asignua\FilamentGdprRequests\Exceptions\MissingPseudonymKey;

/**
 * HMAC pseudonyms with the plugin's own key. APP_KEY is deliberately never a fallback: rotating the app key
 * must not silently change (or expose) the pseudonyms, and a missing key must be loud.
 */
final class Pseudonym
{
    private const int MIN_KEY_BYTES = 32;

    public static function currentVersion(): int
    {
        return max(1, (int) config('filament-gdpr-requests.pseudonym.key_version', 1));
    }

    public static function key(?int $version = null): string
    {
        $version ??= self::currentVersion();
        $key = $version === self::currentVersion()
            ? config('filament-gdpr-requests.pseudonym.key')
            : (config('filament-gdpr-requests.pseudonym.previous_keys', [])[$version] ?? null);

        if (!is_string($key) || strlen($key) < self::MIN_KEY_BYTES) {
            throw MissingPseudonymKey::translated('pseudonym_key_missing', ['version' => $version, 'min' => self::MIN_KEY_BYTES]);
        }

        return $key;
    }

    public static function isConfigured(): bool
    {
        try {
            self::key();

            return true;
        } catch (MissingPseudonymKey) {
            return false;
        }
    }

    /**
     * Trim and lowercase, nothing cleverer: no plus-address stripping, no dot folding.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    public static function forSubject(string $model, string $id, ?int $version = null): string
    {
        return self::hmac('subject', $model."\0".$id, $version);
    }

    public static function forEmail(string $email, ?int $version = null): string
    {
        return self::hmac('email', (string) self::normalizeEmail($email), $version);
    }

    /**
     * A keyed, truncated digest for anonymization rules; unlike the pseudonyms it never leaves the data map.
     */
    public static function digest(string $purpose, string $value, int $length, ?int $version = null): string
    {
        return substr(self::hmac('rule:'.$purpose, $value, $version), 0, max(8, min(64, $length)));
    }

    private static function hmac(string $purpose, string $value, ?int $version): string
    {
        return hash_hmac('sha256', $purpose."\0".$value, self::key($version));
    }
}
