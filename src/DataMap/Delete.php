<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

final class Delete extends Erase
{
    public function __construct(private readonly bool $files = false) {}

    public function kind(): string
    {
        return self::DELETE;
    }

    public function removesFiles(): bool
    {
        return $this->files;
    }
}
