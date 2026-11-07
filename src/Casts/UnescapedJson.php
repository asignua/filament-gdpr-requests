<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * JSON without \uXXXX escaping: the stock `array` cast writes Cyrillic and accents as escapes, which makes the
 * stored log unreadable in a dump or a diff. Reads both forms.
 *
 * @implements CastsAttributes<array<mixed>|null, array<mixed>|null>
 */
class UnescapedJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
