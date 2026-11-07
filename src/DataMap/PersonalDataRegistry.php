<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

/**
 * The data map of the application. Bound as a singleton; entries register themselves from PersonalData::for().
 */
final class PersonalDataRegistry
{
    /** @var array<string, PersonalData> */
    private array $entries = [];

    /** @var array<string, string> table => reason */
    private array $acknowledged = [];

    public function add(PersonalData $entry): void
    {
        $this->entries[$entry->group] = $entry;
    }

    /**
     * @return array<string, PersonalData>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function find(string $group): ?PersonalData
    {
        return $this->entries[$group] ?? null;
    }

    /**
     * @return list<PersonalData> the subject entries (the models a request can be about)
     */
    public function subjectRoots(): array
    {
        return array_values(array_filter($this->entries, static fn (PersonalData $entry): bool => $entry->isSubject()));
    }

    public function rootFor(string $model): ?PersonalData
    {
        foreach ($this->subjectRoots() as $entry) {
            if ($entry->model === $model) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<PersonalData> the root first, then every entry that declares `of($subjectModel)`
     */
    public function forSubject(string $model): array
    {
        $root = $this->rootFor($model);
        $list = $root === null ? [] : [$root];

        foreach ($this->entries as $entry) {
            // An entry that names no subject model at all is included everywhere on purpose: it can never run, so it
            // shows up as a blocker in every preview instead of silently doing nothing.
            if (!$entry->isSubject() && ($entry->subjectModel() === $model || $entry->subjectModel() === null)) {
                $list[] = $entry;
            }
        }

        return $list;
    }

    /**
     * Declares that a table which references a mapped table holds no personal data of the subject (or that the
     * data in it is handled elsewhere). Without this, such a table blocks the erasure preview.
     */
    public function acknowledge(string $table, string $reason): void
    {
        $this->acknowledged[$table] = $reason;
    }

    /**
     * @return array<string, string>
     */
    public function acknowledged(): array
    {
        return $this->acknowledged;
    }

    public function flush(): void
    {
        $this->entries = [];
        $this->acknowledged = [];
    }
}
