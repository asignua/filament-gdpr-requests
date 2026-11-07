<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Retained = 'retained';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return __('filament-gdpr-requests::filament-gdpr-requests.step.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Done => 'success',
            self::Retained => 'info',
            self::Skipped => 'gray',
            self::Pending => 'warning',
            self::Failed => 'danger',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Done || $this === self::Retained || $this === self::Skipped;
    }
}
