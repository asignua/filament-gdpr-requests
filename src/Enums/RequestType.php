<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Enums;

enum RequestType: string
{
    case Access = 'access';
    case Erasure = 'erasure';
    case Rectification = 'rectification';
    case Restriction = 'restriction';
    case Portability = 'portability';
    case Objection = 'objection';

    public function label(): string
    {
        return __('filament-gdpr-requests::filament-gdpr-requests.type.'.$this->value);
    }

    /**
     * Only these two produce a copy of the data.
     */
    public function allowsExport(): bool
    {
        return $this === self::Access || $this === self::Portability;
    }

    public function allowsErasure(): bool
    {
        return $this === self::Erasure;
    }
}
