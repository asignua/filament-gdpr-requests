<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\DataMap\Anonymize;
use Asignua\FilamentGdprRequests\DataMap\Delete;
use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\DataMap\Retain;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\DataMap\Selection;
use Asignua\FilamentGdprRequests\DataMap\SubjectResolver;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Support\Environment;
use Asignua\FilamentGdprRequests\Support\Files;
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Asignua\FilamentGdprRequests\Support\Schema;
use Asignua\FilamentGdprRequests\Support\Sql;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds the preview: for every entry of the map, how many rows (soft-deleted ones included), what will happen to
 * them and, above all, what would go wrong. A rule that breaks NOT NULL, a unique index or a foreign key, and a
 * table outside the map that references a mapped one, are found from the live schema and BLOCK the run.
 */
final class Planner
{
    /** Paths asked for in one indexed lookup. */
    private const int LOOKUP_CHUNK = 500;

    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly SubjectResolver $subjects,
        private readonly Applier $applier,
    ) {}

    /**
     * @param string|null $only restrict the (costly) row scan to one group; blockers and order stay complete
     */
    public function forRequest(GdprRequest $request, ?string $only = null): ErasurePlan
    {
        Environment::assertSupported();

        $global = [];

        // The key the request's pseudonyms were made with: a rotation must not strand a confirmed erasure.
        $keyVersion = $request->pseudonym_key_version;

        if (!Pseudonym::isConfigured() || !Pseudonym::isConfigured($keyVersion)) {
            $global[] = new Blocker('pseudonym_key_missing');
        }

        $model = $request->subject_model;
        $resolved = $this->subjects->resolve($request);

        if ($model === null || $resolved === null) {
            $global[] = new Blocker('subject_not_mapped');

            return new ErasurePlan([], $global);
        }

        $subject = $resolved->subject;
        $global = [...$global, ...$resolved->blockers];
        $entries = $this->registry->forSubject($model);
        $schema = new Schema;

        $selections = [];

        foreach ($entries as $entry) {
            $selections[$entry->group] = Selection::forSubject($entry, $subject);
        }

        $groups = [];

        foreach ($entries as $entry) {
            $selection = $selections[$entry->group];
            $plan = $only === null || $only === $entry->group
                ? $this->inspect($entry, $selection, $entry->getErase(), $schema, $selections, true)
                : new GroupPlan($entry, $selection, $entry->getErase()?->kind() ?? 'none');

            $groups[] = $plan;
        }

        $this->checkRetainOverlap($groups);
        $this->checkSharedFiles($groups, $only);
        $readers = $this->closureReaders($groups);
        $groups = $this->order($groups, $schema, $readers);
        $this->checkClosureOrder($groups, $readers);

        return new ErasurePlan($groups, [...$global, ...$this->unmappedReferences($groups, $schema, $entries)], $model.'#'.$request->subject_id, $subject->email, $keyVersion);
    }

    /**
     * One entry against a constrained selection (the retention path). The entry's own strategy is not used: the
     * retention policy names its own action.
     */
    public function forRetention(PersonalData $entry, Selection $selection, Erase $action): GroupPlan
    {
        Environment::assertSupported();

        $schema = new Schema;
        $plan = $this->inspect($entry, $selection, $action, $schema, [$entry->group => $selection], false);

        $this->checkSharedFiles([$plan]);

        if ($action instanceof Delete) {
            $subject = $entry->subjectModel();

            foreach ($this->unmappedReferences([$plan], $schema, $subject === null ? [$entry] : $this->registry->forSubject($subject)) as $blocker) {
                $plan->blockers[] = $blocker;
            }
        }

        return $plan;
    }

    /**
     * @param array<string, Selection> $selections every selection of this run, to know what is deleted together
     */
    private function inspect(PersonalData $entry, Selection $selection, ?Erase $erase, Schema $schema, array $selections, bool $subjectMode): GroupPlan
    {
        $plan = new GroupPlan($entry, $selection, $erase?->kind() ?? 'none');

        foreach ($entry->problems() as $problem) {
            $plan->addBlocker($problem);
        }

        if ($plan->isBlocked()) {
            return $plan;
        }

        $table = $selection->table();

        if (!$schema->hasTable($table)) {
            $plan->addBlocker('table_missing', ['table' => $table]);

            return $plan;
        }

        if (($selection->connectionName() ?? DB::getDefaultConnection()) !== DB::getDefaultConnection()) {
            $plan->addBlocker('other_connection', ['table' => $table]);

            return $plan;
        }

        $columns = $schema->columns($table);
        $key = $selection->keyName();

        $primary = array_filter($schema->indexes($table), static fn (array $index): bool => (bool) $index['primary']);
        $primaryColumns = $primary === [] ? [] : array_values(reset($primary)['columns']);

        if ($primaryColumns !== [$key] || !isset($columns[$key])) {
            $plan->addBlocker('key_unsupported', ['table' => $table]);

            return $plan;
        }

        foreach (array_filter([$entry->getMatchColumn(), $entry->getEmailColumn()]) as $column) {
            if (!isset($columns[$column])) {
                $plan->addBlocker('column_missing', ['table' => $table, 'column' => $column]);
            }
        }

        foreach ($entry->getFileColumns() as $column) {
            if (!isset($columns[$column])) {
                $plan->addBlocker('column_missing', ['table' => $table, 'column' => $column]);
            }
        }

        if ($erase !== null && $erase->removesFiles() && $entry->getFiles() === null) {
            $plan->addBlocker('files_resolver_missing');
        }

        // The row is the only thing that knows where its file is: once it is gone the file can never be found again.
        if ($erase instanceof Delete && !$erase->removesFiles() && $entry->getFiles() !== null) {
            $plan->addBlocker('files_left_behind', ['table' => $table]);
        }

        if ($plan->isBlocked()) {
            return $plan;
        }

        // Scan: exact counts, soft-deleted rows included. Anonymization also counts the rows it would really change.
        $deletedAt = $selection->deletedAtColumn();
        $until = null;
        $missingDate = false;
        /** @var array<string, true> $nulled columns a rule really wrote NULL into, callbacks included */
        $nulled = [];
        /** @var array<string, true> $unrepeatable columns whose callback changes its own output */
        $unrepeatable = [];
        // A row that stays but stops naming its file loses the file as surely as a deleted row does.
        $watchFiles = $erase instanceof Anonymize && !$erase->removesFiles() && $entry->getFiles() !== null;
        $orphans = 0;

        try {
            $unchunkable = $selection->unchunkable();

            if ($unchunkable !== null) {
                $plan->addBlocker('selection_unchunkable', ['table' => $table, 'clause' => $unchunkable]);

                return $plan;
            }

            foreach ($selection->rows((int) config('filament-gdpr-requests.erasure.chunk', 200)) as $row) {
                $plan->matched++;

                if ($deletedAt !== null && ($row[$deletedAt] ?? null) !== null) {
                    $plan->trashed++;
                }

                if ($erase instanceof Anonymize) {
                    $changes = RowRules::changes($erase, $table, $key, $row);
                    $plan->changing += $changes === [] ? 0 : 1;
                    $nulled += array_fill_keys(array_keys(array_filter($changes, static fn (mixed $value): bool => $value === null)), true);
                    $unrepeatable += array_fill_keys(RowRules::unrepeatable($erase, $table, $key, $row, $changes), true);

                    if ($watchFiles && $changes !== []) {
                        $after = array_map(static fn (array $file): string => Files::identity($file['disk'], $file['path']), $this->applier->filesOf($selection, array_replace($row, $changes)));

                        foreach ($this->applier->filesOf($selection, $row) as $file) {
                            $orphans += in_array(Files::identity($file['disk'], $file['path']), $after, true) ? 0 : 1;
                        }
                    }
                }

                if ($erase instanceof Retain) {
                    $from = $row[$erase->from] ?? null;

                    if ($from === null || !isset($columns[$erase->from])) {
                        $missingDate = true;
                    } else {
                        $end = CarbonImmutable::parse((string) $from)->addDays($erase->days);
                        $until = $until === null || $end->greaterThan($until) ? $end : $until;
                    }
                }
            }
        } catch (Throwable $e) {
            // A rule that throws while only previewing must stop the run, not crash the page. Only the class is
            // kept: a message may quote the very values being erased.
            $plan->addBlocker('rule_error', ['table' => $table, 'error' => class_basename($e)]);

            return $plan;
        }

        if ($orphans > 0) {
            $plan->addBlocker('files_orphaned', ['table' => $table, 'files' => $orphans]);
        }

        $loose = $selection->looseRows();

        if ($loose > 0) {
            $plan->addBlocker('loose_match', ['table' => $table, 'column' => (string) ($entry->getMatchColumn() ?? $key), 'rows' => $loose]);
        }

        // The last look asks the rules whether a row is done: one that never settles would read as left over for ever.
        foreach (array_keys($unrepeatable) as $column) {
            $plan->addBlocker('rule_not_repeatable', ['table' => $table, 'column' => $column]);
        }

        match (true) {
            $erase instanceof Anonymize => $this->checkAnonymize($plan, $erase, $schema, $selection, $nulled),
            $erase instanceof Delete => $this->checkDelete($plan, $schema, $selection, $selections),
            $erase instanceof Retain => $this->describeRetain($plan, $erase, $until, $missingDate, isset($columns[$erase->from])),
            default => null,
        };

        return $plan;
    }

    private function describeRetain(GroupPlan $plan, Retain $erase, ?CarbonImmutable $until, bool $missingDate, bool $columnExists): void
    {
        $plan->basis = $erase->basis;
        $plan->retainedUntil = $until?->toDateString();

        if (!$columnExists) {
            $plan->addBlocker('column_missing', ['table' => $plan->table(), 'column' => $erase->from]);
        } elseif ($missingDate) {
            $plan->addBlocker('retain_date_missing', ['table' => $plan->table(), 'column' => $erase->from]);
        }
    }

    /**
     * @param array<string, true> $nulled
     */
    private function checkAnonymize(GroupPlan $plan, Anonymize $erase, Schema $schema, Selection $selection, array $nulled): void
    {
        $table = $selection->table();
        $columns = $schema->columns($table);
        $key = $selection->keyName();
        $entry = $plan->entry;

        foreach ($erase->rules as $column => $rule) {
            $plan->rules[] = $column.' → '.$rule->label();

            if (!isset($columns[$column])) {
                $plan->addBlocker('column_missing', ['table' => $table, 'column' => $column]);

                continue;
            }

            $params = ['table' => $table, 'column' => $column];

            if ($column === $key || $schema->isPrimaryKeyColumn($table, $column)) {
                $plan->addBlocker('rule_on_key', $params);
            }

            if ($column === $entry->getMatchColumn() || (!$entry->isSubject() && $column === $entry->getEmailColumn())) {
                $plan->addBlocker('rule_on_match_column', $params);
            }

            if (($rule->writesNull() || isset($nulled[$column])) && !(bool) ($columns[$column]['nullable'] ?? true)) {
                $plan->addBlocker('not_null', $params);
            }

            if (!$rule->writesNull() && $schema->isForeignKeyColumn($table, $column)) {
                $plan->addBlocker('foreign_key_column', $params);
            }

            if ($schema->isReferencedColumn($table, $column)) {
                $plan->addBlocker('referenced_column', $params);
            }

            $this->checkText($plan, $rule, $schema, $table, $column);
            $this->checkUnique($plan, $erase, $rule, $schema, $selection, $column);
        }
    }

    private function checkText(GroupPlan $plan, Rule $rule, Schema $schema, string $table, string $column): void
    {
        $params = ['table' => $table, 'column' => $column];

        if ($rule->producesText() && $schema->isNonText($table, $column)) {
            $plan->addBlocker('type_mismatch', $params);

            return;
        }

        $max = $rule->maxLength();
        $length = $schema->textLength($table, $column);

        if ($max !== null && $length !== null && $max > $length) {
            $plan->addBlocker('too_long', [...$params, 'max' => $length]);
        }
    }

    private function checkUnique(GroupPlan $plan, Anonymize $erase, Rule $rule, Schema $schema, Selection $selection, string $column): void
    {
        $table = $selection->table();
        $indexes = $schema->uniqueIndexesWith($table, $column);

        if ($indexes === [] || $rule->writesNull()) {
            return;
        }

        $params = ['table' => $table, 'column' => $column];

        if ($rule->kind === Rule::CALLBACK && !$rule->unique) {
            $plan->addBlocker('unique_callback', $params);

            return;
        }

        if ($rule->kind !== Rule::FIXED) {
            return;
        }

        foreach ($indexes as $indexColumns) {
            $others = array_diff($indexColumns, [$column]);
            $spread = false;

            foreach ($others as $other) {
                $otherRule = $erase->rules[$other] ?? null;
                $spread = $spread || ($otherRule !== null && ($otherRule->producesText() || ($otherRule->kind === Rule::CALLBACK && $otherRule->unique)));
            }

            if ($plan->matched > 1 && !$spread) {
                $plan->addBlocker('unique_fixed', [...$params, 'rows' => $plan->matched]);

                return;
            }

            // A constant that another, untouched row already holds would collide as well.
            if ($others === [] && $rule->value !== null) {
                $key = $selection->keyName();
                $taken = DB::table($table)
                    ->where($column, $rule->value)
                    ->whereNotIn($key, $selection->query()->toBase()->select($table.'.'.$key))
                    ->exists();

                if ($taken) {
                    $plan->addBlocker('unique_conflict', $params);

                    return;
                }
            }
        }
    }

    /**
     * @param array<string, Selection> $selections
     */
    private function checkDelete(GroupPlan $plan, Schema $schema, Selection $selection, array $selections): void
    {
        $table = $selection->table();

        foreach ($schema->referencing($table) as $reference) {
            $child = $reference['table'];

            $covering = array_values(array_filter(
                $selections,
                static fn (Selection $other): bool => $other->table() === $child && $other->entry->getErase() instanceof Delete,
            ));

            $count = $this->countReferencing($selection, $reference, $covering);

            if ($count > 0) {
                $plan->addBlocker('still_referenced', [
                    'table' => $table,
                    'child' => $child,
                    'columns' => implode(', ', $reference['columns']),
                    'rows' => $count,
                ]);
            }
        }
    }

    /**
     * Rows of the child table that point at rows being deleted and are NOT themselves deleted by this run.
     *
     * @param array{table: string, columns: list<string>, foreign_columns: list<string>, on_delete: string} $reference
     * @param list<Selection>                                                                               $covering  deletions of the child table in this run
     */
    private function countReferencing(Selection $parent, array $reference, array $covering): int
    {
        $child = $reference['table'];
        $parentTable = $parent->table();
        $parentKey = $parent->keyName();
        $parentRows = $parent->query()->toBase()->select($parentTable.'.'.$parentKey);

        try {
            $query = DB::table($child)->whereExists(function (Builder $exists) use ($reference, $parentTable, $parentKey, $parentRows, $child): void {
                $exists->from($parentTable.' as gdpr_parent')->selectRaw('1');

                foreach ($reference['columns'] as $i => $column) {
                    $exists->whereColumn('gdpr_parent.'.$reference['foreign_columns'][$i], $child.'.'.$column);
                }

                $exists->whereIn('gdpr_parent.'.$parentKey, $parentRows);
            });

            foreach ($covering as $selection) {
                $childKey = $selection->keyName();
                $query->whereNotIn($child.'.'.$childKey, $selection->query()->toBase()->select($selection->table().'.'.$childKey));
            }

            return $query->count();
        } catch (Throwable) {
            // If the check itself cannot run, assume the worst: the run must not start on a guess.
            return PHP_INT_MAX;
        }
    }

    /**
     * `retain` promises the row stays whole, and the preview and the report say so. A row that another group of the same
     * run anonymizes or deletes would be reported as kept while it is gone: the erasing group is blocked.
     *
     * @param list<GroupPlan> $groups
     */
    private function checkRetainOverlap(array $groups): void
    {
        foreach ($groups as $kept) {
            if ($kept->action !== Erase::RETAIN || $kept->isBlocked()) {
                continue;
            }

            foreach ($groups as $other) {
                if ($other === $kept || !$other->erases() || $other->isBlocked() || $other->table() !== $kept->table()) {
                    continue;
                }

                $table = $kept->table();
                $key = $kept->selection->keyName();

                try {
                    $rows = $kept->selection->query()->toBase()
                        ->whereIn($table.'.'.$key, $other->selection->query()->toBase()->select($table.'.'.$key))
                        ->count();
                } catch (Throwable) {
                    $rows = PHP_INT_MAX; // the run must not start on a guess
                }

                if ($rows > 0) {
                    $other->addBlocker('retain_overlap', ['table' => $table, 'kept' => $kept->label(), 'rows' => $rows === PHP_INT_MAX ? '?' : $rows]);
                }
            }
        }
    }

    /**
     * A file is removed only when every row that names it goes with it. Rows of the whole map are asked (through each
     * entry's files() resolver), not only this person's: a path two records share must not lose the other record's file.
     * An entry that declares the columns its files are found by (or a lookup) is asked through the index for the rows
     * that may name these paths, and PHP decides on those; one that declares neither is read whole, and the plan says so.
     * The blocker names tables, never whose row it is.
     *
     * @param list<GroupPlan> $groups
     * @param string|null     $only   a group job: only this group's files are checked, the others have their own job
     */
    private function checkSharedFiles(array $groups, ?string $only = null): void
    {
        /** @var array<string, true> $removed rows whose files this run removes, as "table\0key" */
        $removed = [];
        /** @var array<string, array<string, true>> $files group => files it removes, by identity */
        $files = [];
        /** @var array<string, array{disk: string, path: string}> $named identity => one spelling of it */
        $named = [];

        foreach ($groups as $group) {
            $erase = $group->entry->getErase();

            if ($erase === null || !$erase->removesFiles() || $group->entry->getFiles() === null || $group->isBlocked()) {
                continue;
            }

            $key = $group->selection->keyName();
            $checked = $only === null || $only === $group->key();

            try {
                foreach ($group->selection->rows() as $row) {
                    $removed[$group->table()."\0".$row[$key]] = true;

                    if (!$checked) {
                        continue;
                    }

                    foreach ($this->applier->filesOf($group->selection, $row) as $file) {
                        $id = Files::identity($file['disk'], $file['path']);
                        $files[$group->key()][$id] = true;
                        $named[$id] = $file;
                    }
                }
            } catch (Throwable) {
                continue; // the scan of the group failed the same way and is a blocker already
            }
        }

        if ($files === []) {
            return;
        }

        /** @var array<string, array<string, true>> $namedBy file => tables of rows that stay and still name it */
        $namedBy = [];
        /** @var array<string, true> $scanned tables read whole for want of declared file columns */
        $scanned = [];

        foreach ($this->registry->all() as $entry) {
            if ($entry->getFiles() === null) {
                continue;
            }

            $selections = $this->fileCandidates($entry, array_values($named));
            $table = (new ($entry->model))->getTable();

            if ($selections === null) {
                $scanned[$table] = true;
                $selections = [Selection::constrained($entry, static function (): void {})];
            }

            try {
                foreach ($selections as $selection) {
                    $key = $selection->keyName();

                    foreach ($selection->rows() as $row) {
                        if (isset($removed[$table."\0".$row[$key]])) {
                            continue;
                        }

                        foreach ($this->applier->filesOf($selection, $row) as $file) {
                            $id = Files::identity($file['disk'], $file['path']);

                            if (isset($named[$id])) {
                                $namedBy[$id][$table] = true;
                            }
                        }
                    }
                }
            } catch (Throwable) {
                // A table that cannot be read cannot vouch that none of its rows names the file.
                foreach (array_keys($named) as $id) {
                    $namedBy[$id][$table] = true;
                }
            }
        }

        ksort($scanned);

        foreach ($groups as $group) {
            if (!isset($files[$group->key()])) {
                continue;
            }

            $tables = [];

            foreach (array_keys($files[$group->key()]) as $id) {
                $tables += $namedBy[$id] ?? [];
            }

            if ($tables !== []) {
                ksort($tables);
                $group->addBlocker('file_shared', ['table' => $group->table(), 'others' => implode(', ', array_keys($tables))]);
            }

            if ($scanned !== []) {
                $group->addNotice('files_full_scan', ['table' => $group->table(), 'tables' => implode(', ', array_keys($scanned))]);
            }
        }
    }

    /**
     * The rows of an entry that may name one of these files, found through its declared file columns (every usual
     * spelling of the path, on every disk over the same storage) or its own lookup; null when it declares neither.
     *
     * @param list<array{disk: string, path: string}> $files
     *
     * @return list<Selection>|null
     */
    private function fileCandidates(PersonalData $entry, array $files): ?array
    {
        $columns = $entry->getFileColumns();
        $lookup = $entry->getFileLookup();

        if ($columns === [] && $lookup === null) {
            return null;
        }

        $table = (new ($entry->model))->getTable();
        $selections = [];

        if ($columns !== []) {
            $spellings = array_values(array_unique(array_merge(...array_map(static fn (array $file): array => Files::spellings($file['disk'], $file['path']), $files))));

            foreach (array_chunk($spellings, self::LOOKUP_CHUNK) as $chunk) {
                $selections[] = Selection::constrained($entry, static function (EloquentBuilder $query) use ($columns, $chunk, $table): void {
                    $query->where(static function (EloquentBuilder $where) use ($columns, $chunk, $table): void {
                        foreach ($columns as $column) {
                            $where->orWhereIn($table.'.'.$column, $chunk);
                        }
                    });
                });
            }

            // Plus every row whose path is not in its normal form (`docs//a`, `x/../a`, `/a`) and that holds the last
            // segment of one of the files: no list of spellings covers those, so they are read and PHP normalises them.
            // Without the segment a table where every path starts with `/` would be read whole. A file with no segment
            // to rely on drops the narrowing.
            $segments = array_values(array_unique(array_map(static fn (array $file): string => Files::lastSegment($file['path']), $files)));
            $chunks = in_array('', $segments, true) ? [[]] : array_chunk($segments, self::LOOKUP_CHUNK);

            $connection = (new ($entry->model))->getConnection();

            foreach ($columns as $column) {
                // A column read whole (Sql::comparesPathsAsText) is read once, not once per chunk.
                foreach (Sql::comparesPathsAsText($connection, $table, $column) ? $chunks : [[]] as $chunk) {
                    $selections[] = Selection::constrained($entry, static fn (EloquentBuilder $query) => Sql::whereNonCanonicalPath($query, $table.'.'.$column, $chunk));
                }
            }
        }

        if ($lookup !== null) {
            $paths = array_values(array_unique(array_map(static fn (array $file): string => Files::normalize($file['path']), $files)));

            foreach (array_chunk($paths, self::LOOKUP_CHUNK) as $chunk) {
                $selections[] = Selection::constrained($entry, static fn (EloquentBuilder $query) => $lookup($query, $chunk));
            }
        }

        return $selections;
    }

    /**
     * Tables that reference a mapped table but are in neither this subject's map nor the acknowledged list. A table
     * mapped for another subject is outside this map: nothing in this run would touch its rows.
     *
     * @param list<GroupPlan>    $groups
     * @param list<PersonalData> $entries the map of the subject in question
     *
     * @return list<Blocker>
     */
    private function unmappedReferences(array $groups, Schema $schema, array $entries): array
    {
        $mapped = [];

        foreach ($entries as $entry) {
            $mapped[(new ($entry->model))->getTable()] = true;
        }

        $acknowledged = $this->registry->acknowledged();
        $seen = [];
        $blockers = [];

        foreach ($groups as $group) {
            $table = $group->table();

            if (!$schema->hasTable($table)) {
                continue;
            }

            foreach ($schema->referencing($table) as $reference) {
                $child = $reference['table'];
                $id = $child.'>'.$table;

                if (isset($mapped[$child]) || isset($acknowledged[$child]) || isset($seen[$id]) || str_starts_with($child, 'gdpr_request')) {
                    continue;
                }

                $seen[$id] = true;
                $blockers[] = new Blocker('unmapped_reference', [
                    'child' => $child,
                    'columns' => implode(', ', $reference['columns']),
                    'table' => $table,
                ]);
            }
        }

        return $blockers;
    }

    /**
     * A closure can only be trusted to find its rows while the tables it reads are untouched, so it runs before any
     * group that changes them.
     *
     * @param list<GroupPlan> $groups
     *
     * @return array<string, list<string>> group => closure groups that read its table
     */
    private function closureReaders(array $groups): array
    {
        $readers = [];

        foreach ($groups as $reader) {
            if ($reader->entry->getMatchKind() !== PersonalData::MATCH_CLOSURE) {
                continue;
            }

            foreach ($groups as $target) {
                // Another group, not another table: a group that deletes from the closure's own table changes what it reads.
                if ($target !== $reader && $target->erases() && $reader->selection->reads($target->table())) {
                    $readers[$target->key()][] = $reader->key();
                }
            }
        }

        return $readers;
    }

    /**
     * Foreign keys can want the opposite order. Then nothing can make the closure right, and a person has to look.
     *
     * @param list<GroupPlan>             $groups  in execution order
     * @param array<string, list<string>> $readers
     */
    private function checkClosureOrder(array $groups, array $readers): void
    {
        $position = array_flip(array_map(static fn (GroupPlan $group): string => $group->key(), $groups));

        foreach ($groups as $reader) {
            foreach ($groups as $target) {
                if (in_array($reader->key(), $readers[$target->key()] ?? [], true) && $position[$reader->key()] > $position[$target->key()]) {
                    $reader->addBlocker('closure_order', ['table' => $reader->table(), 'other' => $target->table()]);
                }
            }
        }
    }

    /**
     * Rows that reference others go first, so a parent is never removed while a child still points at it.
     *
     * @param list<GroupPlan>             $groups
     * @param array<string, list<string>> $readers group => closure groups that read its table
     *
     * @return list<GroupPlan>
     */
    private function order(array $groups, Schema $schema, array $readers): array
    {
        $byTable = [];

        foreach ($groups as $group) {
            $byTable[$group->table()][] = $group;
        }

        $dependsOn = [];

        foreach ($groups as $group) {
            $table = $group->table();
            $dependsOn[$group->key()] = array_fill_keys($readers[$group->key()] ?? [], true);

            if (!$schema->hasTable($table)) {
                continue;
            }

            // $group must run AFTER every group whose table references $table (the children).
            foreach ($schema->referencing($table) as $reference) {
                if ($reference['table'] === $table) {
                    continue;
                }

                foreach ($byTable[$reference['table']] ?? [] as $child) {
                    $dependsOn[$group->key()][$child->key()] = true;
                }
            }
        }

        $ordered = [];
        $remaining = $groups;

        while ($remaining !== []) {
            $progressed = false;

            foreach ($remaining as $i => $group) {
                $waiting = array_filter(
                    array_keys($dependsOn[$group->key()]),
                    static fn (string $dependency): bool => !in_array($dependency, array_map(static fn (GroupPlan $done): string => $done->key(), $ordered), true),
                );

                if ($waiting === []) {
                    $ordered[] = $group;
                    unset($remaining[$i]);
                    $progressed = true;
                }
            }

            if (!$progressed) {
                // A cycle between tables: keep the declared order for what is left.
                array_push($ordered, ...array_values($remaining));

                break;
            }
        }

        return $ordered;
    }
}
