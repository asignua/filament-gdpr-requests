<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use InvalidArgumentException;

final class Anonymize extends Erase
{
    /** @var array<string, Rule> */
    public readonly array $rules;

    /**
     * @param array<mixed> $rules checked here, because plain PHP callers pass whatever they like
     */
    public function __construct(array $rules, private readonly bool $files = false)
    {
        if ($rules === []) {
            throw new InvalidArgumentException('Erase::anonymize() needs at least one rule: nothing is anonymized by default.');
        }

        $checked = [];

        foreach ($rules as $column => $rule) {
            if (!is_string($column) || !$rule instanceof Rule) {
                throw new InvalidArgumentException('Erase::anonymize() takes [column => Rule].');
            }

            $checked[$column] = $rule;
        }

        $this->rules = $checked;
    }

    public function kind(): string
    {
        return self::ANONYMIZE;
    }

    public function removesFiles(): bool
    {
        return $this->files;
    }
}
