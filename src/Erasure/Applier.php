<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Erasure;

use Asignua\FilamentGdprRequests\DataMap\Anonymize;
use Asignua\FilamentGdprRequests\DataMap\Delete;
use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\Selection;
use Illuminate\Support\Facades\DB;

/**
 * Executes anonymize / delete on a selection. It writes with the base query builder, so no model event fires
 * (an observer must not send mail or recreate data mid-erasure), updated_at is not touched (an unannounced
 * column does not change) and soft-deleted rows are really removed by `delete`.
 */
final class Applier
{
    /**
     * @return array{matched: int, changed: int, trashed: int, files: list<array{disk: string, path: string}>}
     */
    public function apply(Selection $selection, Erase $strategy, int $chunk = 200): array
    {
        $result = ['matched' => 0, 'changed' => 0, 'trashed' => 0, 'files' => []];
        $table = $selection->table();
        $key = $selection->keyName();
        $deletedAt = $selection->deletedAtColumn();
        $connection = DB::connection($selection->connectionName());
        $ids = [];

        foreach ($selection->rows($chunk) as $row) {
            $result['matched']++;

            if ($deletedAt !== null && ($row[$deletedAt] ?? null) !== null) {
                $result['trashed']++;
            }

            if ($strategy->removesFiles()) {
                array_push($result['files'], ...$this->filesOf($selection, $row));
            }

            if ($strategy instanceof Anonymize) {
                $changes = RowRules::changes($strategy, $table, $key, $row);

                if ($changes !== []) {
                    $connection->table($table)->where($key, $row[$key])->update($changes);
                    $result['changed']++;
                }
            } elseif ($strategy instanceof Delete) {
                $ids[] = $row[$key];

                if (count($ids) >= $chunk) {
                    $result['changed'] += $connection->table($table)->whereIn($key, $ids)->delete();
                    $ids = [];
                }
            }
        }

        if ($ids !== []) {
            $result['changed'] += $connection->table($table)->whereIn($key, $ids)->delete();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{disk: string, path: string}>
     */
    public function filesOf(Selection $selection, array $row): array
    {
        $resolver = $selection->entry->getFiles();

        if ($resolver === null) {
            return [];
        }

        // Postgres hands a bytea over as a stream: the resolver gets the bytes it holds, not `Resource id #7`.
        $row = array_map(static fn (mixed $value): mixed => is_resource($value) ? (string) stream_get_contents($value, -1, 0) : $value, $row);
        $model = (new ($selection->entry->model))->newFromBuilder($row);
        $files = [];

        foreach ($resolver($model) as $file) {
            if (isset($file['disk'], $file['path']) && $file['path'] !== '') {
                $files[] = ['disk' => (string) $file['disk'], 'path' => (string) $file['path']];
            }
        }

        return $files;
    }
}
