<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\Support\Lang;

/**
 * What the look at the data after a run found: what the plan said would go and is still there, and whatever stopped the
 * look from being sure. Group keys and counts only, so it is safe to keep on the request and in the log.
 */
final readonly class Findings
{
    /**
     * @param array<string, int>    $remaining   group => rows the plan said would go
     * @param array<string, int>    $files       group => files that should be gone
     * @param array<string, string> $mismatch    group => "planned / handled"
     * @param list<Blocker>         $blockers
     * @param bool                  $planChanged the rules or the person differ from what was confirmed
     */
    public function __construct(
        public array $remaining = [],
        public array $files = [],
        public array $mismatch = [],
        public array $blockers = [],
        public bool $planChanged = false,
    ) {}

    public function isClean(): bool
    {
        return $this->remaining === [] && $this->files === [] && $this->mismatch === [] && $this->blockers === [] && !$this->planChanged;
    }

    /**
     * @return array{remaining: array<string, int>, files: array<string, int>, mismatch: array<string, string>, blockers: list<array{code: string, params: array<string, scalar>, group: string|null}>, plan_changed: bool}
     */
    public function toArray(): array
    {
        return [
            'remaining' => $this->remaining,
            'files' => $this->files,
            'mismatch' => $this->mismatch,
            'blockers' => array_map(static fn (Blocker $blocker): array => $blocker->toArray(), $this->blockers),
            'plan_changed' => $this->planChanged,
        ];
    }

    /**
     * The same as the log wants it: a blocker's parameters may name a record, its code does not.
     *
     * @return array<string, mixed>
     */
    public function forLog(): array
    {
        return array_filter([
            'remaining' => $this->remaining,
            'files_left' => $this->files,
            'mismatch' => $this->mismatch,
            'blockers' => array_map(static fn (Blocker $blocker): string => $blocker->code, $this->blockers),
            'plan_changed' => $this->planChanged ?: null,
        ], static fn (mixed $value): bool => $value !== [] && $value !== null);
    }

    /**
     * @param array<string, mixed> $stored what toArray() left on the request
     */
    public static function fromArray(array $stored): self
    {
        /** @var list<array{code: string, params: array<string, scalar>, group: string|null}> $blockers */
        $blockers = $stored['blockers'] ?? [];

        return new self(
            $stored['remaining'] ?? [],
            $stored['files'] ?? [],
            $stored['mismatch'] ?? [],
            array_map(static fn (array $blocker): Blocker => new Blocker($blocker['code'], $blocker['params'], $blocker['group']), $blockers),
            (bool) ($stored['plan_changed'] ?? false),
        );
    }

    /**
     * The list for the person who has to act on it.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->remaining as $group => $rows) {
            $lines[] = Lang::t('plan.finding_remaining', ['group' => self::label($group), 'rows' => $rows]);
        }

        foreach ($this->files as $group => $files) {
            $lines[] = Lang::t('plan.finding_files', ['group' => self::label($group), 'files' => $files]);
        }

        foreach ($this->mismatch as $group => $figures) {
            $lines[] = Lang::t('plan.finding_mismatch', ['group' => self::label($group), 'figures' => $figures]);
        }

        if ($this->planChanged) {
            $lines[] = Lang::t('plan.finding_plan_changed');
        }

        foreach ($this->blockers as $blocker) {
            $lines[] = $blocker->message();
        }

        return $lines;
    }

    private static function label(string $group): string
    {
        return app(PersonalDataRegistry::class)->find($group)?->getLabel() ?? class_basename($group);
    }
}
