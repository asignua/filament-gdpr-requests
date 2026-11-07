<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

/**
 * A reason the erasure preview refuses to let the run start. Rendered from translations by code.
 */
final readonly class Blocker
{
    /**
     * @param array<string, scalar> $params
     */
    public function __construct(
        public string $code,
        public array $params = [],
        public ?string $group = null,
    ) {}

    public function message(): string
    {
        $key = 'filament-gdpr-requests::filament-gdpr-requests.blocker.'.$this->code;
        $text = __($key, $this->params);

        return $text === $key ? $this->code.' '.json_encode($this->params) : $text;
    }

    /**
     * @return array{code: string, params: array<string, scalar>, group: string|null}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'params' => $this->params, 'group' => $this->group];
    }
}
