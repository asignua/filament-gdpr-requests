<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

/**
 * The whole preview for one request: groups in execution order plus blockers that belong to no single group.
 */
final class ErasurePlan
{
    /**
     * @param list<GroupPlan> $groups   in execution order (rows that reference others first)
     * @param list<Blocker>   $blockers global ones
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $blockers = [],
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
     * Hash of the map's rules in play. A confirmation is only valid while this is unchanged.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode("\n", array_map(
            static fn (GroupPlan $group): string => $group->entry->definitionSignature(),
            $this->groups,
        )));
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
