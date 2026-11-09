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
     * LIKE patterns (escape `!`, compared byte by byte) that every stored path the normaliser would change matches: a
     * backslash, a leading, trailing or doubled `/`, and a `.` or `..` segment (`./`, `../`, `/./`, `/../`, a final `/.`
     * or `/..`). A few canonical paths match too (a name that starts with a dot); PHP compares whatever is found. A
     * path that matches none is its own normal form, so the exact spellings below find it. One the normaliser refuses
     * (control characters, `..` above the root) stays as it is and is found by its exact spelling.
     */
    public const array NON_CANONICAL = ['%\\%', '/%', '%/', '%//%', '.%', '%/.%'];

    /**
     * The canonical spellings a row may use for this file, for an exact indexed lookup: the path as given and its
     * normalised form on this disk and on every configured disk over the same storage (the path inside a scoped disk
     * drops its prefix). Every other spelling is non-canonical and found through NON_CANONICAL.
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

        return array_values(array_unique([$path, ...$inside]));
    }

    /**
     * The last segment of the path in its normal form, which every spelling of the file holds as it is (the normaliser
     * only turns `\` into `/` and drops empty, `.` and `..` segments); an empty string when there is none to rely on (a
     * path that normalises to the root, or one the normaliser refuses).
     */
    public static function lastSegment(string $path): string
    {
        $segments = preg_split('#[/\\\\]#', self::normalize($path), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = (string) end($segments);

        return in_array($last, ['.', '..'], true) ? '' : $last;
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
