<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema as SchemaFacade;

/**
 * Reads the live schema (columns, indexes, foreign keys) once per instance. The erasure preview is built from it:
 * a rule that would break NOT NULL, a unique index or a foreign key is found here, not by trying and failing.
 */
final class Schema
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $columns = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $indexes = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $foreignKeys = [];

    /** @var list<string>|null */
    private ?array $tables = null;

    public function __construct(private readonly ?string $connection = null) {}

    private function builder(): Builder
    {
        return SchemaFacade::connection($this->connection);
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->tables ??= array_map(
            static fn (array $table): string => (string) $table['name'],
            $this->builder()->getTables(),
        );
    }

    public function hasTable(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function columns(string $table): array
    {
        if (!isset($this->columns[$table])) {
            $this->columns[$table] = [];

            foreach ($this->builder()->getColumns($table) as $column) {
                $this->columns[$table][(string) $column['name']] = $column;
            }
        }

        return $this->columns[$table];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function indexes(string $table): array
    {
        return $this->indexes[$table] ??= $this->builder()->getIndexes($table);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function foreignKeys(string $table): array
    {
        return $this->foreignKeys[$table] ??= $this->builder()->getForeignKeys($table);
    }

    /**
     * Every foreign key in the database that points at $table.
     *
     * @return list<array{table: string, columns: list<string>, foreign_columns: list<string>, on_delete: string}>
     */
    public function referencing(string $table): array
    {
        $found = [];

        foreach ($this->tables() as $child) {
            foreach ($this->foreignKeys($child) as $key) {
                if (($key['foreign_table'] ?? null) === $table) {
                    $found[] = [
                        'table' => $child,
                        'columns' => $key['columns'],
                        'foreign_columns' => $key['foreign_columns'],
                        'on_delete' => (string) ($key['on_delete'] ?? ''),
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * Unique indexes (the primary key included) that contain $column, as lists of their columns.
     *
     * @return list<list<string>>
     */
    public function uniqueIndexesWith(string $table, string $column): array
    {
        $found = [];

        foreach ($this->indexes($table) as $index) {
            if (($index['unique'] || $index['primary']) && in_array($column, $index['columns'], true)) {
                $found[] = array_values($index['columns']);
            }
        }

        return $found;
    }

    public function isPrimaryKeyColumn(string $table, string $column): bool
    {
        foreach ($this->indexes($table) as $index) {
            if ($index['primary'] && in_array($column, $index['columns'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when $column is part of a foreign key of $table (it points at another table).
     */
    public function isForeignKeyColumn(string $table, string $column): bool
    {
        foreach ($this->foreignKeys($table) as $key) {
            if (in_array($column, $key['columns'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when another table's foreign key points at $table.$column.
     */
    public function isReferencedColumn(string $table, string $column): bool
    {
        foreach ($this->referencing($table) as $key) {
            if (in_array($column, $key['foreign_columns'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Declared length of a text column ("varchar(255)", "character varying(255)"), or null when unbounded/unknown.
     */
    public function textLength(string $table, string $column): ?int
    {
        $type = (string) ($this->columns($table)[$column]['type'] ?? '');

        if (preg_match('/^(?:var)?char|character|nvarchar|nchar/i', $type) === 1 && preg_match('/\((\d+)\)/', $type, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * True for number, boolean, date and similar columns that cannot hold a generated text.
     */
    public function isNonText(string $table, string $column): bool
    {
        $type = strtolower((string) ($this->columns($table)[$column]['type_name'] ?? $this->columns($table)[$column]['type'] ?? ''));

        return preg_match('/int|decimal|numeric|float|double|real|bool|bit|date|time|year|uuid|json/', $type) === 1;
    }
}
