<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\GdprRequestsPlugin;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Filament\Facades\Filament;

class SmokeTest extends TestCase
{
    public function test_the_panel_boots(): void
    {
        $this->assertSame('admin', Filament::getCurrentPanel()?->getId());
    }

    public function test_the_plugin_is_registered_on_the_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasPlugin('asignua-filament-gdpr-requests'));
        $this->assertInstanceOf(GdprRequestsPlugin::class, $panel->getPlugin('asignua-filament-gdpr-requests'));
    }

    public function test_the_translations_are_loaded(): void
    {
        $this->assertSame('Sample', __('filament-gdpr-requests::filament-gdpr-requests.sample'));
    }
}
