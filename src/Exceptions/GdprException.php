<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Exceptions;

use RuntimeException;

/**
 * Base of every refusal the plugin raises on purpose; the message is safe to show to staff.
 */
class GdprException extends RuntimeException
{
    /** The translation behind the message: stored instead of the text, so a report reads in its reader's language. */
    public ?string $translationKey = null;

    /** @var array<string, scalar> */
    public array $translationParams = [];

    /**
     * @param array<string, scalar> $replace
     */
    public static function translated(string $key, array $replace = []): static
    {
        // @phpstan-ignore new.static (every subclass keeps the (string $message) constructor except PlanBlocked, which has its own factory path)
        $exception = new static(__('filament-gdpr-requests::filament-gdpr-requests.errors.'.$key, $replace));
        $exception->translationKey = 'errors.'.$key;
        $exception->translationParams = $replace;

        return $exception;
    }
}
