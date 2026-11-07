<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Asignua\FilamentGdprRequests\Exceptions\UnsupportedEnvironment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Exports hold a person's data: they only go to a disk that cannot be reached by URL.
 */
final class PrivateDisk
{
    public static function name(): string
    {
        return (string) config('filament-gdpr-requests.export.disk', 'local');
    }

    public static function resolve(): Filesystem
    {
        $name = self::name();
        $config = config('filesystems.disks.'.$name);

        if (!is_array($config)) {
            throw UnsupportedEnvironment::translated('export_disk_unknown', ['disk' => $name]);
        }

        $root = isset($config['root']) ? realpath((string) $config['root']) : false;
        $public = realpath(public_path());

        $isPublic = ($config['visibility'] ?? 'private') === 'public'
            || ($root !== false && $public !== false && ($root === $public || str_starts_with($root, $public.DIRECTORY_SEPARATOR)));

        if ($isPublic) {
            throw UnsupportedEnvironment::translated('export_disk_public', ['disk' => $name]);
        }

        return Storage::disk($name);
    }
}
