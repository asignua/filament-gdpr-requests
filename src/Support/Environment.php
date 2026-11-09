<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Asignua\FilamentGdprRequests\Exceptions\UnsupportedEnvironment;
use Asignua\FilamentGdprRequests\GdprRequestsPlugin;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class Environment
{
    /**
     * Capitals of the scripts an address may be written in, with the two whose lowercase PHP and SQL spell differently
     * (İ, a word-final Σ). A server whose LOWER leaves any of them alone would miss such an address in the preview and
     * again in the check after the run, and the request would read `fulfilled` with the rows still there.
     */
    public const string FOLDING_PROBE = 'ÄÖÜÉÈÑÇÅØÆŁŻŚĆŃŐŰŠŽČŘĞÐÞ ΣΑΒΓΔΩΣ ДЖЯЇЄҐЁЩ ԱԲԳ İNCI ΟΔΥΣ';

    /** @var array<string, string|null> connection => the difference found, null when the server folds like PHP */
    private static array $folding = [];

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

        $connection = DB::connection();
        $difference = self::foldingDifference($connection);

        if ($difference !== null) {
            throw UnsupportedEnvironment::translated('lowercase_unsupported', [
                'driver' => $connection->getDriverName(),
                'server' => $difference,
                'php' => self::expectedFolding($connection),
            ]);
        }
    }

    /**
     * What the server makes of the probe when it differs from PHP, or null. Asked once per connection and process: the
     * answer belongs to the database (Postgres' LC_CTYPE, MariaDB's utf8mb4 collation), not to the request.
     */
    public static function foldingDifference(Connection $connection): ?string
    {
        if ($connection->getDriverName() === 'sqlite') {
            return null; // PHP itself answers there
        }

        $id = $connection->getName().'|'.$connection->getDatabaseName();

        if (array_key_exists($id, self::$folding)) {
            return self::$folding[$id];
        }

        [$sql, $bindings] = Sql::normalized($connection, '?');

        try {
            $row = (array) $connection->selectOne('SELECT '.$sql.' AS folded', [self::FOLDING_PROBE, ...$bindings]);
            $server = (string) ($row['folded'] ?? '');
        } catch (Throwable) {
            return null; // a server that cannot answer fails on the real query, with its own error
        }

        return self::$folding[$id] = $server === self::expectedFolding($connection) ? null : $server;
    }

    public static function forgetFolding(): void
    {
        self::$folding = [];
    }

    private static function expectedFolding(Connection $connection): string
    {
        return Sql::fold((string) Pseudonym::normalizeEmail(self::FOLDING_PROBE), $connection);
    }
}
