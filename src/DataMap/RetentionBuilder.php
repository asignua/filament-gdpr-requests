<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

final class RetentionBuilder
{
    public function __construct(private readonly int $days, private readonly string $from) {}

    public function then(Erase $action): Retention
    {
        return Retention::make($this->days, $this->from, $action);
    }
}
