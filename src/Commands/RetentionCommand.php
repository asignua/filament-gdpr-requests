<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Commands;

use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Retention\RetentionRunner;
use Asignua\FilamentGdprRequests\Support\Environment;
use Illuminate\Console\Command;

class RetentionCommand extends Command
{
    protected $signature = 'gdpr:retention {--apply : Really anonymize / delete. Without it nothing changes.} {--only= : Limit to one map entry (group key or model class)}';

    protected $description = 'Plan (dry run, default) or apply the retention policies declared in the personal data map';

    public function handle(RetentionRunner $runner): int
    {
        try {
            Environment::assertSupported();
            $result = $runner->run((bool) $this->option('apply'), $this->option('only') ?: null);
        } catch (GdprException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($this->option('apply') ? 'APPLIED (rows were changed)' : 'DRY RUN: nothing was changed. Run with --apply to execute.');

        $this->table(
            ['Entry', 'Action', 'Expired rows', 'Would change', 'Changed', 'Status', 'Problems'],
            array_map(static fn (array $row): array => [
                $row['group'], $row['action'], $row['expired'], $row['changing'], $row['changed'], $row['status'], implode('; ', $row['blockers']),
            ], $result['rows']),
        );

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
