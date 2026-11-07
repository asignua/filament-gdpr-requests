<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Support\Lang;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Text that is safe to keep in a report. A database error message carries the SQL with its bound values (the very
 * personal data being erased), so for those only the SQLSTATE and the driver code are kept.
 *
 * What is stored is a translation key with its parameters, not a sentence: a queue worker writes it in the
 * application's language, and the person reading the report may use another.
 */
final class Failures
{
    /**
     * The form to store.
     */
    public static function encode(Throwable $e): string
    {
        return self::store(...self::parts($e));
    }

    /**
     * @param array<string, scalar> $params
     */
    public static function store(string $key, array $params = []): string
    {
        return json_encode(['key' => $key, 'params' => $params], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * A stored failure in the current language. Text written by an older version is shown as it is.
     */
    public static function render(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        $decoded = json_decode($stored, true);

        if (!is_array($decoded) || !is_string($decoded['key'] ?? null)) {
            return $stored;
        }

        /** @var array<string, scalar> $params */
        $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];

        return mb_substr(Lang::t($decoded['key'], $params), 0, 400);
    }

    /**
     * The same failure as text, right now.
     */
    public static function describe(Throwable $e): string
    {
        return (string) self::render(self::encode($e));
    }

    /**
     * @return array{0: string, 1: array<string, scalar>}
     */
    private static function parts(Throwable $e): array
    {
        if ($e instanceof QueryException) {
            $info = $e->errorInfo;

            return ['report.database_error', ['state' => (string) ($info[0] ?? '?'), 'code' => (string) ($info[1] ?? '?')]];
        }

        if ($e instanceof GdprException && $e->translationKey !== null) {
            return [$e->translationKey, $e->translationParams];
        }

        return ['report.other_error', ['class' => class_basename($e)]];
    }
}
