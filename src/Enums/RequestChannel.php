<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Enums;

enum RequestChannel: string
{
    case Email = 'email';
    case Post = 'post';
    case Phone = 'phone';
    case InPerson = 'in_person';
    case Web = 'web';
    case Other = 'other';

    public function label(): string
    {
        return __('filament-gdpr-requests::filament-gdpr-requests.channel.'.$this->value);
    }
}
