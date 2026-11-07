<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Asignua\FilamentGdprRequests\Exceptions\UnsupportedEnvironment;
use Asignua\FilamentGdprRequests\GdprRequestsPlugin;
use Filament\Facades\Filament;

final class Environment
{
    /**
     * v1 keeps requests global, so a panel with tenancy that carries this plugin is refused: every service and
     * command calls this first and fails closed instead of mixing tenants.
     */
    public static function tenancyActive(): bool
    {
        foreach (Filament::getPanels() as $panel) {
            if ($panel->hasPlugin(GdprRequestsPlugin::ID) && $panel->hasTenancy()) {
                return true;
            }
        }

        return false;
    }

    public static function assertSupported(): void
    {
        if (self::tenancyActive()) {
            throw UnsupportedEnvironment::translated('tenancy_unsupported');
        }
    }
}
