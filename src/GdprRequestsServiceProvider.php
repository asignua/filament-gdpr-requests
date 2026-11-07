<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests;

use Asignua\FilamentGdprRequests\Commands\PruneCommand;
use Asignua\FilamentGdprRequests\Commands\RemindCommand;
use Asignua\FilamentGdprRequests\Commands\RetentionCommand;
use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\Http\ExportDownloadController;
use Asignua\FilamentGdprRequests\Support\Gates;
use Illuminate\Support\Facades\Route;
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
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasMigration('create_gdpr_requests_tables')
            ->hasCommands([RetentionCommand::class, PruneCommand::class, RemindCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PersonalDataRegistry::class);
    }

    public function packageBooted(): void
    {
        // Deny by default; the host defines the four abilities in its own AuthServiceProvider / Gate::define().
        Gates::registerDefaults();

        // Not part of the panel's route group on purpose: the link is signed and bound to one signed-in user, and the
        // controller authenticates against the panel named in the signature.
        Route::middleware(['web'])
            ->get('gdpr-requests/exports/{export}', ExportDownloadController::class)
            ->name('filament-gdpr-requests.export.download');
    }
}
