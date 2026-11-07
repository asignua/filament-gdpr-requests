<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages;

use Asignua\FilamentGdprRequests\Resources\Concerns\WarnsAboutSetup;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGdprRequests extends ListRecords
{
    use WarnsAboutSetup;

    protected static string $resource = GdprRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
