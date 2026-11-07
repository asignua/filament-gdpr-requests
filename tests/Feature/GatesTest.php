<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Support\Gates;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;

class GatesTest extends TestCase
{
    public function test_every_ability_is_denied_until_the_host_defines_it(): void
    {
        // A pristine gate, as in an application whose host never defined the abilities.
        $this->app->singleton(GateContract::class, fn ($app) => new AccessGate($app, fn () => auth()->user()));
        Gate::clearResolvedInstance(GateContract::class);

        Gates::registerDefaults();
        $user = $this->user('dpo');

        foreach (Gates::ALL as $ability) {
            $this->assertFalse(Gates::allows($ability, $user), $ability.' must be denied by default.');
        }
    }

    public function test_a_guest_never_passes(): void
    {
        foreach (Gates::ALL as $ability) {
            $this->assertFalse(Gates::allows($ability, null));
        }
    }

    public function test_the_host_definition_wins_over_the_default(): void
    {
        $manager = $this->user('manager');
        $dpo = $this->user('dpo');

        $this->assertTrue(Gates::allows(Gates::MANAGE, $manager));
        $this->assertFalse(Gates::allows(Gates::EXPORT, $manager));
        $this->assertFalse(Gates::allows(Gates::ERASE, $manager));
        $this->assertTrue(Gates::allows(Gates::ERASE, $dpo));
    }
}
