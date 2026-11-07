<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests;

use Asignua\FilamentGdprRequests\Resources\GdprRequestResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * GDPR / data subject request register for Filament panels.
 *
 *     ->plugin(GdprRequestsPlugin::make()->navigationGroup('Compliance'))
 *
 * Panels with tenancy are not supported in v1: the resource and the services refuse to work there.
 */
class GdprRequestsPlugin implements Plugin
{
    public const string ID = 'asignua-filament-gdpr-requests';

    protected ?string $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected string $navigationIcon = 'heroicon-o-shield-check';

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function navigationIcon(string $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function getNavigationIcon(): string
    {
        return $this->navigationIcon;
    }

    public function register(Panel $panel): void
    {
        // The resource is always registered (->tenant() may come after ->plugin() in the panel provider); it
        // closes itself at runtime on a panel with tenancy, see GdprRequestResource::canAccess().
        $panel->resources([GdprRequestResource::class]);
    }

    public function boot(Panel $panel): void {}
}
