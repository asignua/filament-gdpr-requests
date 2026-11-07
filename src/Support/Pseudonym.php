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
    public const int MIN_KEY_BYTES = 32;

    /**
     * The message that says the key is missing and how to set it, or null when all is well.
     */
    public static function setupProblem(): ?string
    {
        return self::isConfigured() ? null : Lang::t('errors.pseudonym_key_missing', ['version' => self::currentVersion(), 'min' => self::MIN_KEY_BYTES]);
    }

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

    public static function isConfigured(?int $version = null): bool
    {
        try {
            self::key($version);

            return true;
        } catch (MissingPseudonymKey) {
            return false;
        }
    }

    /**
     * Trim and lowercase, nothing cleverer: no plus-address stripping, no dot folding. Trim means every white space and
     * invisible character that a paste leaves at the ends (see Sql::TRIMMED), and the SQL side strips the same set.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $edges = '['.Sql::characterClass().']+';
        $email = mb_strtolower(preg_replace('/\A'.$edges.'|'.$edges.'\z/u', '', $email) ?? trim($email));

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

    /**
     * What a confirmation is bound to. It holds the subject's key, so it is keyed like every other pseudonym: an
     * unkeyed hash would give the id back to anyone who tries the small range of ids against it.
     */
    public static function forPlan(string $description, ?int $version = null): string
    {
        return self::hmac('plan', $description, $version);
    }

    private static function hmac(string $purpose, string $value, ?int $version): string
    {
        return hash_hmac('sha256', $purpose."\0".$value, self::key($version));
    }
}
