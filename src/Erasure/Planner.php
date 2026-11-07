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
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Asignua\FilamentGdprRequests\Support\Schema;
use Carbon\CarbonImmutable;
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
    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly SubjectResolver $subjects,
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

        $groups = $this->order($groups, $schema);

        return new ErasurePlan($groups, [...$global, ...$this->unmappedReferences($groups, $schema)], $model.'#'.$request->subject_id, $subject->email, $keyVersion);
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

        if ($action instanceof Delete) {
            foreach ($this->unmappedReferences([$plan], $schema) as $blocker) {
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

        if ($erase !== null && $erase->removesFiles() && $entry->getFiles() === null) {
            $plan->addBlocker('files_resolver_missing');
        }

        if ($plan->isBlocked()) {
            return $plan;
        }

        // Scan: exact counts, soft-deleted rows included. Anonymization also counts the rows it would really change.
        $deletedAt = $selection->deletedAtColumn();
        $until = null;
        $missingDate = false;

        try {
            foreach ($selection->rows((int) config('filament-gdpr-requests.erasure.chunk', 200)) as $row) {
                $plan->matched++;

                if ($deletedAt !== null && ($row[$deletedAt] ?? null) !== null) {
                    $plan->trashed++;
                }

                if ($erase instanceof Anonymize && RowRules::changes($erase, $table, $key, $row) !== []) {
                    $plan->changing++;
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

        match (true) {
            $erase instanceof Anonymize => $this->checkAnonymize($plan, $erase, $schema, $selection),
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

    private function checkAnonymize(GroupPlan $plan, Anonymize $erase, Schema $schema, Selection $selection): void
    {
        $table = $selection->table();
        $columns = $schema->columns($table);
        $key = $selection->keyName();
        $entry = $plan->entry;

        foreach ($erase->rules as $column => $rule) {
            $plan->rules[] = $column.' → '.$rule->describe();

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

            if ($rule->kind === Rule::NULL && !(bool) ($columns[$column]['nullable'] ?? true)) {
                $plan->addBlocker('not_null', $params);
            }

            if ($rule->kind !== Rule::NULL && $schema->isForeignKeyColumn($table, $column)) {
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

        if ($indexes === [] || $rule->kind === Rule::NULL) {
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
     * Tables that reference a mapped table but are in neither the map nor the acknowledged list.
     *
     * @param list<GroupPlan> $groups
     *
     * @return list<Blocker>
     */
    private function unmappedReferences(array $groups, Schema $schema): array
    {
        $mapped = [];

        foreach ($this->registry->all() as $entry) {
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
     * Rows that reference others go first, so a parent is never removed while a child still points at it.
     *
     * @param list<GroupPlan> $groups
     *
     * @return list<GroupPlan>
     */
    private function order(array $groups, Schema $schema): array
    {
        $byTable = [];

        foreach ($groups as $group) {
            $byTable[$group->table()][] = $group;
        }

        $dependsOn = [];

        foreach ($groups as $group) {
            $table = $group->table();
            $dependsOn[$group->key()] = [];

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
