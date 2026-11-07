<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Illuminate\Support\Facades\Storage;
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
}
