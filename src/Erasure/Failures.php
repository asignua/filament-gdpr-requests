<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Text that is safe to keep in a report. A database error message carries the SQL with its bound values (the very
 * personal data being erased), so for those only the SQLSTATE and the driver code are stored.
 */
final class Failures
{
    public static function describe(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $info = $e->errorInfo;

            return 'Database error (SQLSTATE '.($info[0] ?? '?').', driver code '.($info[1] ?? '?').')';
        }

        if ($e instanceof GdprException) {
            return mb_substr($e->getMessage(), 0, 400);
        }

        return class_basename($e);
    }
}
