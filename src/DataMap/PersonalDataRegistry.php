<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use InvalidArgumentException;

/**
 * The data map of the application. Bound as a singleton; entries register themselves from PersonalData::for().
 */
final class PersonalDataRegistry
{
    /** @var array<string, PersonalData> */
    private array $entries = [];

    /** @var array<string, string> table => reason */
    private array $acknowledged = [];

    /**
     * A group is declared once. A second declaration would quietly take the first one's place, and the rows the first
     * one found would be left out of every export and erasure: it is refused. Name the group (`for($model, 'name')`) to
     * map one model twice, or forget() the old entry first to replace it on purpose.
     */
    public function add(PersonalData $entry): void
    {
        $existing = $this->entries[$entry->group] ?? null;

        if ($existing !== null && $existing !== $entry) {
            throw new InvalidArgumentException("The data map already has an entry for [{$entry->group}]. Give the second one its own group: PersonalData::for(\$model, 'name').");
        }

        $this->entries[$entry->group] = $entry;
    }

    /**
     * Removes an entry, so that it can be declared again on purpose.
     */
    public function forget(string $group): void
    {
        unset($this->entries[$group]);
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
