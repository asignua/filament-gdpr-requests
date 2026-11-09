<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\WhitespacePathNormalizer;
use Throwable;

final class Files
{
    /**
     * Removes a file and says whether it is really gone. A disk that refuses answers false instead of throwing, and
     * one that is not configured throws: both mean the personal data is still there.
     */
    public static function remove(string $disk, string $path): bool
    {
        try {
            $storage = Storage::disk($disk);

            return !$storage->exists($path) || $storage->delete($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One file, however it is spelled: `/docs/a.txt`, `docs//a.txt` and `docs/a.txt` on the same disk are one file, and
     * so are two disk names over the same storage (a scoped disk is its parent plus a prefix). Two rows that name the
     * same identity name the same file.
     */
    public static function identity(string $disk, string $path): string
    {
        [$storage, $path] = self::resolve($disk, $path, 0);

        return $storage."\0".self::normalize($path);
    }

    /**
     * The spellings a row may use for this file, for an indexed lookup: the normalised path with a leading `/`, `./` or
     * backslashes, on this disk and on every configured disk over the same storage (the path inside a scoped disk drops
     * its prefix). A rarer spelling (`a//b`, `x/../a`) is not among them; PHP compares whatever the lookup finds.
     *
     * @return list<string>
     */
    public static function spellings(string $disk, string $path): array
    {
        [$storage, $full] = self::resolve($disk, $path, 0);
        $full = self::normalize($full);
        $inside = [self::normalize($path), $full];
        $disks = config('filesystems.disks');

        foreach (is_array($disks) ? array_keys($disks) : [] as $name) {
            [$other, $prefix] = self::resolve((string) $name, '', 0);
            $prefix = self::normalize($prefix);

            if ($other !== $storage) {
                continue;
            }

            if ($prefix === '') {
                $inside[] = $full;
            } elseif (str_starts_with($full, $prefix.'/')) {
                $inside[] = substr($full, strlen($prefix) + 1);
            }
        }

        $spellings = [$path];

        foreach (array_unique($inside) as $one) {
            array_push($spellings, $one, '/'.$one, './'.$one, str_replace('/', '\\', $one));
        }

        return array_values(array_unique($spellings));
    }

    /**
     * The path as Flysystem itself reads it. One it refuses (control characters, `..` above the root) is kept as it is:
     * the disk refuses it as well, so it cannot be the file another spelling reaches.
     */
    public static function normalize(string $path): string
    {
        try {
            return (new WhitespacePathNormalizer)->normalizePath($path);
        } catch (Throwable) {
            return $path;
        }
    }

    /**
     * @return array{string, string} the storage behind the disk and the path inside it
     */
    private static function resolve(string $disk, string $path, int $depth): array
    {
        $config = config('filesystems.disks.'.$disk);

        if (!is_array($config) || $depth > 8) {
            return ['disk:'.$disk, $path];
        }

        $prefix = is_string($config['prefix'] ?? null) ? trim($config['prefix'], '/\\') : '';
        $path = $prefix === '' ? $path : $prefix.'/'.$path;

        if (($config['driver'] ?? null) === 'scoped' && is_string($config['disk'] ?? null)) {
            return self::resolve($config['disk'], $path, $depth + 1);
        }

        $where = [];

        foreach (['driver', 'root', 'bucket', 'container', 'endpoint', 'host'] as $option) {
            $value = $config[$option] ?? null;

            if (is_scalar($value) && $value !== '') {
                $where[$option] = $option === 'root' ? rtrim((string) $value, '/\\') : (string) $value;
            }
        }

        // A disk whose config names no storage at all stays itself.
        return [$where === [] || array_keys($where) === ['driver'] ? 'disk:'.$disk : (string) json_encode($where, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $path];
    }
}
