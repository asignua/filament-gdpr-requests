<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Asignua\FilamentGdprRequests\Support\Sql;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The rows one map entry selects: for one subject, or (for retention) by an age constraint.
 *
 * Global scopes are always removed: a tenant, "active" or soft-delete scope must never hide a row from an
 * erasure or an export, not even inside a subquery a closure of the map builds (see unscoped()). For column / e-mail
 * matches every row is checked again in PHP (exact comparison), because a case-insensitive or padded collation would
 * otherwise let `= 'abc'` also select 'ABC' - a different person.
 */
final class Selection
{
    private readonly Model $prototype;

    /** What the last rows() kept and dropped. */
    private int $accepted = 0;

    private int $rejected = 0;

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
        // A model the closure makes for the first time boots while the scopes are off, and booting registers its scopes
        // (soft deletes, say) again: the subquery it built then carries them. That model is booted now, so the query is
        // built once more, with its scopes out of the way. Whether a model was booted before is not the closure's to know:
        // a fresh queue worker and a warm web process must find the same rows.
        for ($attempt = 1; ; $attempt++) {
            [$query, $booted] = self::unscoped(fn (): Builder => $this->build());

            if (!$booted || $attempt >= 3) {
                return $query;
            }
        }
    }

    /**
     * @return Builder<Model>
     */
    private function build(): Builder
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
     * are dropped, and counted: see looseRows().
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(int $chunk = 200): Generator
    {
        $key = $this->keyName();
        $this->accepted = 0;
        $this->rejected = 0;

        // Reading by key needs the key order alone: an ORDER BY of the closure's own (latest(), say) would make every
        // chunk after the first start from the wrong row, and the rows in between would never be seen.
        foreach ($this->query()->reorder()->toBase()->select($this->table().'.*')->lazyById($chunk, $this->table().'.'.$key, $key) as $row) {
            $row = (array) $row;

            if ($this->accepts($row)) {
                $this->accepted++;

                yield $row;
            } else {
                $this->rejected++;
            }
        }
    }

    /**
     * Every selected row read in one go, without chunks: the check after a run counts with this, so that it does not
     * share a blind spot of the chunked read it is checking.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function freshRows(): Generator
    {
        foreach ($this->query()->reorder()->toBase()->select($this->table().'.*')->cursor() as $row) {
            $row = (array) $row;

            if ($this->accepts($row)) {
                yield $row;
            }
        }
    }

    /**
     * What makes the selection unsafe to read by key in chunks, or null. A limit or an offset applies to every chunk
     * instead of the whole; DISTINCT, GROUP BY, HAVING and UNION change what a "row" is, so a chunk boundary can split
     * or repeat one. Such a selection could leave rows behind while every count agrees with itself.
     */
    public function unchunkable(): ?string
    {
        $base = $this->query()->toBase();

        return match (true) {
            $base->limit !== null || $base->unionLimit !== null => 'limit',
            $base->offset !== null || $base->unionOffset !== null => 'offset',
            $base->distinct !== false => 'distinct',
            ($base->groups ?? []) !== [] || ($base->havings ?? []) !== [] => 'group by',
            ($base->unions ?? []) !== [] => 'union',
            default => null,
        };
    }

    /**
     * Rows of the last complete rows() that nearly hold the subject's key: the SQL took them and the exact comparison did
     * not, or (where the driver's `=` is strict, so the SQL never saw them) only case or padding keeps them apart. Whether
     * such a row is this person's is not for the plugin to guess, and neither erasing nor skipping it is safe.
     */
    public function looseRows(): int
    {
        $subject = $this->subject;

        if ($subject === null || $this->entry->getMatchKind() !== PersonalData::MATCH_COLUMN || !is_string($subject->keyValue())) {
            return $this->rejected;
        }

        $query = $this->entry->model::query()->withoutGlobalScopes();
        Sql::whereNormalizedEquals($query, $this->table().'.'.$this->entry->getMatchColumn(), (string) Pseudonym::normalizeEmail($subject->id));

        return max($this->rejected, $query->count() - $this->accepted);
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
                && Pseudonym::normalizeEmail((string) ($row[(string) $this->entry->getMatchColumn()] ?? '')) === $subject->email,
            default => true,
        };
    }

    /**
     * @param Builder<Model> $query
     */
    private function constrainToSubject(Builder $query, Subject $subject): void
    {
        $table = $this->table();

        if ($subject->overflows()) {
            $query->whereRaw('1 = 0');

            return;
        }

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
     * Runs the closures of the map with every global scope switched off, so that a builder they make for themselves (a
     * subquery through another model, say) does not bring back the soft-delete or tenant scope this query has just shed.
     * Scopes attach when a builder is made, so the subquery stays unscoped after the closure returns. Says whether a model
     * registered scopes meanwhile (it was booted inside): what was built then may carry them, see query().
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return array{T, bool}
     */
    private static function unscoped(Closure $callback): array
    {
        $scopes = Model::getAllGlobalScopes();
        Model::setAllGlobalScopes([]);
        $registered = [];

        try {
            $result = $callback();
            $registered = Model::getAllGlobalScopes();

            return [$result, $registered !== []];
        } finally {
            Model::setAllGlobalScopes($scopes + ($registered === [] ? Model::getAllGlobalScopes() : $registered));
        }
    }

    /**
     * Does finding these rows touch $table? Asked of a closure, which the plugin cannot look inside: the SQL it builds
     * and any query it runs itself are collected without reaching the database.
     */
    public function reads(string $table): bool
    {
        $connection = $this->connection();
        $built = '';

        try {
            $queries = $connection->pretend(function () use (&$built): void {
                $built = $this->query()->toBase()->toSql();
            });
        } catch (Throwable) {
            return false; // a closure that cannot run has its own blocker in the preview
        }

        $text = implode("\n", [$built, ...array_column($queries, 'query')]);

        return preg_match('/(?<![\w$])'.preg_quote($connection->getTablePrefix().$table, '/').'(?![\w$])/i', $text) === 1;
    }

    /**
     * Used to read one connection consistently with the request tables.
     */
    public function connection(): \Illuminate\Database\Connection
    {
        return DB::connection($this->connectionName());
    }
}
