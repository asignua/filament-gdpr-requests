<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

/**
 * Something the preview wants the developer to know that does not stop the run: a check that had to read a whole table,
 * for one. Rendered from translations by code.
 */
final readonly class Notice
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
        $key = 'filament-gdpr-requests::filament-gdpr-requests.plan.notice_'.$this->code;
        $text = __($key, $this->params);

        return $text === $key ? $this->code.' '.json_encode($this->params) : $text;
    }
}
