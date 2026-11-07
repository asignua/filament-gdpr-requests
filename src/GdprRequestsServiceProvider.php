<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GdprRequestsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-gdpr-requests';

    public function configurePackage(Package $package): void
    {
        // Translations live in resources/lang/<locale>/filament-gdpr-requests.php and are read as
        // `__('filament-gdpr-requests::filament-gdpr-requests.<key>')`. Publish tag: `filament-gdpr-requests-translations`.
        $package->name(static::$name)
            ->hasTranslations()
            ->hasViews();

        // Add a config file only when the plugin really has options: create config/filament-gdpr-requests.php and
        // chain `->hasConfigFile()` here (publish tag `filament-gdpr-requests-config`). Prefer fluent setters on the Plugin.
    }
}
