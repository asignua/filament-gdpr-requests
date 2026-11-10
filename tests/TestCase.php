<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests;

use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\GdprRequestsServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Workbench\App\Models\User;
use Workbench\App\Providers\AdminPanelProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        GdprRequests::reset();
        Filament::setCurrentPanel('admin');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            GdprRequestsServiceProvider::class,
            WorkbenchServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('filament-gdpr-requests.pseudonym.key', 'test-only-pseudonym-key-0123456789abcdef');
        $app['config']->set('cache.default', 'array');

        // Real-database runs (MariaDB, Postgres) are opted into from the environment.
        $driver = env('GDPR_TEST_DB');

        if (is_string($driver) && $driver !== '') {
            $app['config']->set('database.connections.testing', [
                'driver' => $driver,
                'host' => env('GDPR_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('GDPR_TEST_DB_PORT'),
                'database' => env('GDPR_TEST_DB_NAME', 'gdpr_test'),
                'username' => env('GDPR_TEST_DB_USER', 'gdpr'),
                'password' => env('GDPR_TEST_DB_PASSWORD', 'gdpr'),
                'charset' => $driver === 'mariadb' ? 'utf8mb4' : 'utf8',
                'collation' => $driver === 'mariadb' ? 'utf8mb4_unicode_ci' : null,
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    protected function user(string $role = 'dpo', ?string $name = null): User
    {
        static $n = 0;
        $n++;

        $user = User::query()->create([
            'name' => $name ?? 'Staff '.$n,
            'email' => 'staff'.$n.'@example.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
        $this->actingAs($user);

        return $user;
    }

    protected function registry(): PersonalDataRegistry
    {
        return app(PersonalDataRegistry::class);
    }

    /**
     * PHPUnit 13.2 перейменував expectExceptionMessage() на expectExceptionMessageIsOrContains().
     * Laravel 12-гілка CI (testbench 10) тримає PHPUnit на 13.1, де нової назви ще немає.
     */
    protected function expectExceptionMessageContaining(string $message): void
    {
        $method = method_exists($this, 'expectExceptionMessageIsOrContains') ? 'expectExceptionMessageIsOrContains' : 'expectExceptionMessage';
        $this->{$method}($message);
    }
}
