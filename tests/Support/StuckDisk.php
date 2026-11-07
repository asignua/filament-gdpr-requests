<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * A disk whose deletes fail quietly, the way a read-only mount or a refused S3 call does: `delete()` answers false.
 */
final class StuckDisk
{
    public static function install(string $name): void
    {
        config(['filesystems.disks.'.$name => ['driver' => 'local', 'root' => sys_get_temp_dir().'/'.$name]]);
        $fake = Storage::fake($name);

        Storage::set($name, new class($fake->getDriver(), $fake->getAdapter(), ['root' => sys_get_temp_dir()]) extends FilesystemAdapter
        {
            public function delete($paths): bool
            {
                return false;
            }
        });
    }

    public static function release(string $name): void
    {
        $stuck = Storage::disk($name);
        $free = new FilesystemAdapter($stuck->getDriver(), $stuck->getAdapter(), ['root' => sys_get_temp_dir()]);

        Storage::set($name, $free);
    }
}
