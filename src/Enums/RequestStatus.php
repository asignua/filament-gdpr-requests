<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Enums;

enum RequestStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case ErasureConfirmed = 'erasure_confirmed';
    case Erasing = 'erasing';

    /**
     * Some groups were erased, some failed. Never shown as success; only continued forward.
     */
    case Partial = 'partial';

    case Fulfilled = 'fulfilled';
    case Refused = 'refused';
    case Closed = 'closed';

    public function label(): string
    {
        return __('filament-gdpr-requests::filament-gdpr-requests.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::InProgress => 'primary',
            self::ErasureConfirmed, self::Erasing => 'warning',
            self::Partial => 'danger',
            self::Fulfilled => 'success',
            self::Refused, self::Closed => 'gray',
        };
    }

    /**
     * Statuses in which the deadline still runs.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::New, self::InProgress, self::ErasureConfirmed, self::Erasing, self::Partial => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isOpen())),
        );
    }
}
