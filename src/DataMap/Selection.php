<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use Asignua\FilamentGdprRequests\Support\Sql;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * The rows one map entry selects: for one subject, or (for retention) by an age constraint.
 *
 * Global scopes are always removed: a tenant, "active" or soft-delete scope must never hide a row from an
 * erasure or an export. For column / e-mail matches every row is checked again in PHP (exact comparison), because
 * a case-insensitive or padded collation would otherwise let `= 'abc'` also select 'ABC' - a different person.
 */
final class Selection
{
    private readonly Model $prototype;

    /**
     * @param Closure(Builder<Model>): void|null $constraint extra narrowing, used by retention
     */
    private function __construct(
        public readonly PersonalData $entry,
        public readonly ?Subject $subject,
        private readonly ?Closure $constraint = null,
    ) {
        $this->prototype = new ($entry->model);
    }

    public static function forSubject(PersonalData $entry, Subject $subject): self
    {
        return new self($entry, $subject);
    }

    /**
     * @param Closure(Builder<Model>): void $constraint
     */
    public static function constrained(PersonalData $entry, Closure $constraint): self
    {
        return new self($entry, null, $constraint);
    }

    public function table(): string
    {
        return $this->prototype->getTable();
    }

    public function keyName(): string
    {
        return $this->prototype->getKeyName();
    }

    public function connectionName(): ?string
    {
        return $this->prototype->getConnectionName();
    }

    public function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->prototype), true);
    }

    public function deletedAtColumn(): ?string
    {
        return $this->usesSoftDeletes() && method_exists($this->prototype, 'getDeletedAtColumn') ? (string) $this->prototype->getDeletedAtColumn() : null;
    }

    /**
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        $query = $this->entry->model::query()->withoutGlobalScopes();

        if ($this->subject !== null) {
            $this->constrainToSubject($query, $this->subject);
        }

        if ($this->constraint !== null) {
            ($this->constraint)($query);
        }

        return $query;
    }

    /**
     * Every selected row as a raw array, ordered by key and read in chunks. Rows that fail the exact comparison
     * are dropped.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(int $chunk = 200): Generator
    {
        $key = $this->keyName();

        foreach ($this->query()->toBase()->select($this->table().'.*')->lazyById($chunk, $this->table().'.'.$key, $key) as $row) {
            $row = (array) $row;

            if ($this->accepts($row)) {
                yield $row;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function accepts(array $row): bool
    {
        $subject = $this->subject;

        if ($subject === null) {
            return true;
        }

        return match ($this->entry->getMatchKind()) {
            PersonalData::MATCH_SUBJECT => (string) ($row[$this->keyName()] ?? '') === $subject->id,
            PersonalData::MATCH_COLUMN => (string) ($row[(string) $this->entry->getMatchColumn()] ?? '') === $subject->id,
            PersonalData::MATCH_EMAIL => $subject->email !== null
                && mb_strtolower(trim((string) ($row[(string) $this->entry->getMatchColumn()] ?? ''))) === $subject->email,
            default => true,
        };
    }

    /**
     * @param Builder<Model> $query
     */
    private function constrainToSubject(Builder $query, Subject $subject): void
    {
        $table = $this->table();

        switch ($this->entry->getMatchKind()) {
            case PersonalData::MATCH_SUBJECT:
                $query->where($table.'.'.$this->keyName(), $subject->keyValue());

                break;
            case PersonalData::MATCH_COLUMN:
                $query->where($table.'.'.(string) $this->entry->getMatchColumn(), $subject->keyValue());

                break;
            case PersonalData::MATCH_EMAIL:
                if ($subject->email === null) {
                    $query->whereRaw('1 = 0');

                    break;
                }

                Sql::whereNormalizedEquals($query, $table.'.'.$this->entry->getMatchColumn(), $subject->email);

                break;
            default:
                $callback = $this->entry->getMatchClosure();

                if ($callback === null) {
                    $query->whereRaw('1 = 0');

                    break;
                }

                $callback($query, $subject);
        }
    }

    /**
     * Used to read one connection consistently with the request tables.
     */
    public function connection(): \Illuminate\Database\Connection
    {
        return DB::connection($this->connectionName());
    }
}
