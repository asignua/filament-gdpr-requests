<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Support;

/**
 * Spellings of Ada's file `docs/Ada.txt` (and near misses that are other files) built from random leads, separators and
 * tails, on the disk itself, a scoped disk and a second disk name over the same root. Seeded: a failure names its
 * spelling.
 */
final class PathSpellings
{
    /**
     * The two extra disks the spellings use, both over the storage of `local`.
     */
    public static function configureDisks(): void
    {
        config([
            'filesystems.disks.local-docs' => ['driver' => 'scoped', 'disk' => 'local', 'prefix' => 'docs'],
            'filesystems.disks.local-again' => ['driver' => 'local', 'root' => rtrim((string) config('filesystems.disks.local.root'), '/').'/'],
        ]);
    }

    /**
     * @return list<array{string, string}> disk and path
     */
    public static function generated(): array
    {
        $bases = [['local', ['docs', 'Ada.txt']], ['local-docs', ['Ada.txt']], ['local-again', ['docs', 'Ada.txt']]];
        $leads = ['', '', '/', './', '//', '/./', 'x/../', './x/../', '\\', '.\\', 'a/b/../../', '../', '/../'];
        $separators = ['/', '/', '//', '///', '/./', '/y/../', '\\', '\\\\', '/.\\', '\\./', '/z/./../'];
        $tails = ['', '', '/', '/.', '//', '\\', '/x/..', '.bak', '/..'];
        $spellings = [
            ['local', 'docs//Ada.txt'], ['local', 'docs/x/../Ada.txt'], ['local', 'docs/./Ada.txt'],
            ['local', 'docs/ada.txt'], ['local', 'docs/x/Ada.txt'], ['local', 'docsAda.txt'], ['local', 'docs/../Ada.txt'],
        ];
        mt_srand(20261109);

        for ($i = 0; $i < 150; $i++) {
            [$disk, $segments] = $bases[$i % 3];
            $path = $leads[mt_rand(0, count($leads) - 1)];

            foreach ($segments as $k => $segment) {
                $path .= ($k > 0 ? $separators[mt_rand(0, count($separators) - 1)] : '').$segment;
            }

            $spellings[] = [$disk, $path.$tails[mt_rand(0, count($tails) - 1)]];
        }

        mt_srand();

        return array_values(array_unique($spellings, SORT_REGULAR));
    }
}
