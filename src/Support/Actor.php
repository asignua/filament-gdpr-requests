<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;

final class Actor
{
    /**
     * The signed-in staff member of the current panel; an anonymous call never reaches a service.
     */
    public static function require(): Authenticatable
    {
        $user = Filament::auth()->user() ?? auth()->user();

        if (!$user instanceof Authenticatable) {
            throw NotPermitted::translated('not_permitted', ['ability' => 'sign-in']);
        }

        return $user;
    }
}
