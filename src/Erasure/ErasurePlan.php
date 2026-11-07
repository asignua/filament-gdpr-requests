<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\Support\Pseudonym;

/**
 * The whole preview for one request: groups in execution order plus blockers that belong to no single group.
 */
final class ErasurePlan
{
    /**
     * @param list<GroupPlan> $groups       in execution order (rows that reference others first)
     * @param list<Blocker>   $blockers     global ones
     * @param string|null     $subjectEmail the address rows are matched on: the linked record's
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $blockers = [],
        public readonly string $subjectKey = '',
        public readonly ?string $subjectEmail = null,
        public readonly ?int $keyVersion = null,
    ) {}

    /**
     * @return list<Blocker>
     */
    public function allBlockers(): array
    {
        $all = $this->blockers;

        foreach ($this->groups as $group) {
            array_push($all, ...$group->blockers);
        }

        return $all;
    }

    public function isBlocked(): bool
    {
        return $this->allBlockers() !== [];
    }

    public function group(string $key): ?GroupPlan
    {
        foreach ($this->groups as $group) {
            if ($group->key() === $key) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Keyed hash of the map's rules in play and of WHO is being erased. A confirmation is only valid while this is
     * unchanged, so neither a changed rule nor a re-linked subject can ride on an old confirmation.
     */
    public function fingerprint(): string
    {
        return Pseudonym::forPlan($this->subjectKey."\n".implode("\n", array_map(
            static fn (GroupPlan $group): string => $group->entry->definitionSignature(),
            $this->groups,
        )), $this->keyVersion);
    }

    /**
     * @return array<string, int> group => matched rows, the ceiling a confirmation allows
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->groups as $group) {
            $counts[$group->key()] = $group->matched;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'fingerprint' => $this->fingerprint(),
            'counts' => $this->counts(),
            'groups' => array_map(static fn (GroupPlan $group): array => $group->toArray(), $this->groups),
        ];
    }
}
