<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

final class Lang
{
    /**
     * @param array<string, scalar> $replace
     */
    public static function t(string $key, array $replace = []): string
    {
        return (string) __('filament-gdpr-requests::filament-gdpr-requests.'.$key, $replace);
    }
}
