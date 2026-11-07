<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Selection;

/**
 * What the preview says about ONE map entry: how many rows, which action, what blocks it.
 */
final class GroupPlan
{
    /** @var list<Blocker> */
    public array $blockers = [];

    public int $matched = 0;

    public int $trashed = 0;

    /** Rows an anonymization would really change (rows already anonymized do not count). */
    public int $changing = 0;

    /** @var list<string> */
    public array $rules = [];

    public ?string $basis = null;

    public ?string $retainedUntil = null;

    public function __construct(
        public readonly PersonalData $entry,
        public readonly Selection $selection,
        /** none | anonymize | delete | retain */
        public string $action,
    ) {}

    public function key(): string
    {
        return $this->entry->group;
    }

    public function label(): string
    {
        return $this->entry->getLabel();
    }

    public function table(): string
    {
        return $this->selection->table();
    }

    /**
     * @param array<string, scalar> $params
     */
    public function addBlocker(string $code, array $params = []): void
    {
        $this->blockers[] = new Blocker($code, $params, $this->key());
    }

    public function isBlocked(): bool
    {
        return $this->blockers !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'table' => $this->table(),
            'action' => $this->action,
            'matched' => $this->matched,
            'trashed' => $this->trashed,
            'changing' => $this->changing,
            'rules' => $this->rules,
            'basis' => $this->basis,
            'retained_until' => $this->retainedUntil,
            'blockers' => array_map(static fn (Blocker $blocker): array => $blocker->toArray(), $this->blockers),
        ];
    }
}
