<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Exceptions;

use RuntimeException;

/**
 * Base of every refusal the plugin raises on purpose; the message is safe to show to staff.
 */
class GdprException extends RuntimeException
{
    /**
     * @param array<string, scalar> $replace
     */
    public static function translated(string $key, array $replace = []): static
    {
        // @phpstan-ignore new.static (every subclass keeps the (string $message) constructor except PlanBlocked, which has its own factory path)
        return new static(__('filament-gdpr-requests::filament-gdpr-requests.errors.'.$key, $replace));
    }
}
