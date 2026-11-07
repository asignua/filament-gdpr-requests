<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;

final class Sql
{
    /**
     * `LOWER(TRIM(column)) = value`: the exact comparison used for e-mail addresses. It is equality after the
     * plugin's own normalisation, never LIKE. The identifier is quoted by the grammar and the value is a binding.
     *
     * @param Builder<Model> $query
     */
    public static function whereNormalizedEquals(Builder $query, string $qualifiedColumn, string $normalizedValue): void
    {
        $column = $query->getQuery()->getGrammar()->wrap($qualifiedColumn);

        $query->whereRaw(new Expression('LOWER(TRIM('.$column.')) = ?'), [$normalizedValue]); // @phpstan-ignore argument.type
    }
}
