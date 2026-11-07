<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Export;

use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\DataMap\Selection;
use Asignua\FilamentGdprRequests\DataMap\SubjectResolver;
use Asignua\FilamentGdprRequests\Erasure\Blocker;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Support\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Builds the export archive of one request: data/*.json for the columns the map declares, files/* for the files
 * it names, manifest.json. Nothing outside the map is read; rows are checked again for an exact match so a sloppy
 * collation cannot add another person's row.
 */
final class ExportBuilder
{
    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly SubjectResolver $subjects,
    ) {}

    /**
     * @return string path of a temporary zip file; the caller moves it and deletes it
     */
    public function build(GdprRequest $request): string
    {
        $model = $request->subject_model;
        $resolved = $this->subjects->resolve($request);

        if ($model === null || $resolved === null) {
            throw GdprException::translated('subject_not_mapped');
        }

        // Rows are found through the linked record; a doubt about who that is stops the export, it is not guessed at.
        if ($resolved->blockers !== []) {
            throw GdprException::translated('export_blocked', ['reason' => $resolved->blockers[0]->message()]);
        }

        $subject = $resolved->subject;
        $schema = new Schema;
        $work = sys_get_temp_dir().'/gdpr-export-'.Str::random(16);
        File::ensureDirectoryExists($work.'/data');
        File::ensureDirectoryExists($work.'/files');

        $zipPath = $work.'.zip';

        try {
            $manifest = ['reference' => $request->reference(), 'generated_at' => now()->toIso8601String(), 'groups' => [], 'missing_files' => []];
            $index = 0;
            $zipFiles = [];

            foreach ($this->registry->forSubject($model) as $entry) {
                if (!$entry->hasExport()) {
                    continue;
                }

                $index++;
                $selection = Selection::forSubject($entry, $subject);
                $this->assertColumns($schema, $selection, $entry->getExportColumns());
                $name = sprintf('data/%02d-%s.json', $index, Str::slug($entry->getLabel()) ?: 'group');
                $handle = $this->open($work.'/'.$name);
                fwrite($handle, '[');
                $rows = 0;

                foreach ($selection->rows() as $row) {
                    $hydrated = (new ($entry->model))->newFromBuilder($row);
                    $record = [];

                    foreach ($entry->getExportColumns() as $column) {
                        $record[$column] = $hydrated->getAttribute($column);
                    }

                    if ($entry->getExportUsing() !== null) {
                        $record = [...$record, ...($entry->getExportUsing())($hydrated)];
                    }

                    fwrite($handle, ($rows === 0 ? "\n" : ",\n").json_encode($record, self::JSON_FLAGS));
                    $rows++;

                    $this->collectFiles($entry->getFiles(), $hydrated, $work, $index, $rows, $zipFiles, $manifest);
                }

                fwrite($handle, $rows === 0 ? ']' : "\n]");
                fclose($handle);

                $loose = $selection->looseRows();

                if ($loose > 0) {
                    $blocker = new Blocker('loose_match', ['table' => $selection->table(), 'column' => (string) ($entry->getMatchColumn() ?? $selection->keyName()), 'rows' => $loose]);

                    throw GdprException::translated('export_blocked', ['reason' => $blocker->message()]);
                }

                $zipFiles[$name] = $work.'/'.$name;
                $manifest['groups'][] = ['label' => $entry->getLabel(), 'rows' => $rows, 'file' => $name];
            }

            file_put_contents($work.'/manifest.json', json_encode($manifest, self::JSON_FLAGS | JSON_PRETTY_PRINT));
            $zipFiles['manifest.json'] = $work.'/manifest.json';

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw GdprException::translated('export_zip_failed');
            }

            foreach ($zipFiles as $entryName => $source) {
                $zip->addFile($source, $entryName);
            }

            if (!$zip->close()) {
                throw GdprException::translated('export_zip_failed');
            }
        } catch (Throwable $e) {
            @unlink($zipPath);

            throw $e;
        } finally {
            File::deleteDirectory($work);
        }

        return $zipPath;
    }

    /**
     * @param array<string, string> $zipFiles
     * @param array<string, mixed>  $manifest
     */
    private function collectFiles(?\Closure $resolver, object $model, string $work, int $group, int $row, array &$zipFiles, array &$manifest): void
    {
        if ($resolver === null) {
            return;
        }

        $n = 0;

        foreach ($resolver($model) as $file) {
            $n++;
            $disk = (string) ($file['disk'] ?? '');
            $path = (string) ($file['path'] ?? '');
            $stream = $disk !== '' && $path !== '' ? $this->openRemote($disk, $path) : null;

            if ($stream === null) {
                $manifest['missing_files'][] = $disk.':'.$path;

                continue;
            }

            // Zip-slip proof: only a sanitized base name, prefixed with counters, ever reaches the archive path.
            $base = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', basename($path)) ?: 'file';
            $name = sprintf('files/%02d/%d-%d-%s', $group, $row, $n, $base);
            File::ensureDirectoryExists(dirname($work.'/'.$name));
            $target = $this->open($work.'/'.$name);
            stream_copy_to_stream($stream, $target);
            fclose($target);
            fclose($stream);
            $zipFiles[$name] = $work.'/'.$name;
        }
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw GdprException::translated('export_zip_failed');
        }

        return $handle;
    }

    /**
     * @return resource|null
     */
    private function openRemote(string $disk, string $path)
    {
        try {
            return Storage::disk($disk)->readStream($path) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $columns
     */
    private function assertColumns(Schema $schema, Selection $selection, array $columns): void
    {
        $existing = $schema->columns($selection->table());

        foreach ($columns as $column) {
            if (!isset($existing[$column])) {
                throw GdprException::translated('export_column_missing', ['table' => $selection->table(), 'column' => $column]);
            }
        }
    }
}
