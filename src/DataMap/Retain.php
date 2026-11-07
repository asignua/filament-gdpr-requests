<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use InvalidArgumentException;

final class Retain extends Erase
{
    public function __construct(public readonly string $basis, public readonly int $days, public readonly string $from)
    {
        if (trim($basis) === '' || $days < 1 || trim($from) === '') {
            throw new InvalidArgumentException('Erase::retain() needs a legal basis, a period in days and the column the period counts from.');
        }
    }

    public function kind(): string
    {
        return self::RETAIN;
    }
}
