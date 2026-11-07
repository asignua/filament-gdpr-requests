<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Four gates, all denied until the host defines them. Services check them again themselves, so a
 * custom page or a console script cannot reach an irreversible step around the UI.
 */
final class Gates
{
    public const string VIEW = 'gdpr.view';

    public const string MANAGE = 'gdpr.manage';

    public const string EXPORT = 'gdpr.export';

    public const string ERASE = 'gdpr.erase';

    public const array ALL = [self::VIEW, self::MANAGE, self::EXPORT, self::ERASE];

    /**
     * Defines the deny-by-default gate for every ability the host has not defined yet.
     */
    public static function registerDefaults(): void
    {
        foreach (self::ALL as $ability) {
            if (!Gate::has($ability)) {
                Gate::define($ability, static fn (): bool => false);
            }
        }
    }

    public static function allows(string $ability, ?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows($ability);
    }

    public static function authorize(string $ability, ?Authenticatable $user): void
    {
        if (!self::allows($ability, $user)) {
            throw NotPermitted::translated('not_permitted', ['ability' => $ability]);
        }
    }
}
