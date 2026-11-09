<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Benchmark;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ErasurePlanPage;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Closure;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The preview, the confirmation and the run against a documents table of a million rows (one other customer's), with
 * the time and the number of queries of each printed. Opt in with GDPR_BENCH=1 (GDPR_BENCH_ROWS to change the size):
 *
 *   GDPR_BENCH=1 GDPR_TEST_DB=pgsql … vendor/bin/phpunit tests/Benchmark
 */
class LargeTableBenchmark extends TestCase
{
    protected function setUp(): void
    {
        if (!(bool) env('GDPR_BENCH')) {
            $this->markTestSkipped('Set GDPR_BENCH=1 to run the benchmark.');
        }

        parent::setUp();
    }

    public function test_preview_confirm_and_run_with_a_million_rows_elsewhere(): void
    {
        $this->bench('bulk/', 'canonical');
    }

    /**
     * Every stored path starts with `/`, so every row is not in its normal form: the non-canonical pre-filter alone
     * would hand all of them to PHP.
     */
    public function test_preview_confirm_and_run_with_a_million_leading_slash_rows_elsewhere(): void
    {
        $this->bench('/bulk/', 'leading slash');
    }

    private function bench(string $prefix, string $label): void
    {
        $rows = (int) env('GDPR_BENCH_ROWS', 1_000_000);
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $many = Shop::customer('Many', 'many@customer.example');
        $seeded = $this->seedDocuments($many->id, $rows, $prefix);
        $request = Shop::request($ada, RequestType::Erasure, $this->user('dpo', 'Confirmer'));
        $results = ['seed' => $seeded];

        $results['preview'] = $this->measure(static fn () => Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])->assertSee('Documents'));
        $results['confirm'] = $this->measure(static fn () => Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->callAction('confirm', ['reference' => $request->reference()])->assertHasNoActionErrors());
        $this->assertSame(RequestStatus::ErasureConfirmed, $request->refresh()->status);

        $this->actingAs($this->user('dpo', 'Runner'));
        $results['run'] = $this->measure(static fn () => Livewire::test(ErasurePlanPage::class, ['record' => $request->ulid])
            ->callAction('run', ['reference' => $request->reference()])->assertHasNoActionErrors());

        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);

        fwrite(STDERR, sprintf("\n[bench %s, %s %s rows]\n", DB::getDriverName(), number_format($rows, 0, '.', ' '), $label));

        foreach ($results as $phase => [$seconds, $queries]) {
            fwrite(STDERR, sprintf("  %-8s %7.2f s %7d queries\n", $phase, $seconds, $queries));
        }
    }

    /**
     * @return array{float, int}
     */
    private function seedDocuments(int $customerId, int $rows, string $prefix): array
    {
        return $this->measure(static function () use ($customerId, $rows, $prefix): void {
            $columns = 'INSERT INTO documents (customer_id, title, disk, path, created_at, updated_at) ';

            match (DB::getDriverName()) {
                'pgsql' => DB::insert($columns."SELECT ?, 'Bulk', 'local', CAST(? AS TEXT) || g || '.txt', now(), now() FROM generate_series(1, ?) g", [$customerId, $prefix, $rows]),
                'mysql', 'mariadb' => DB::insert($columns."SELECT ?, 'Bulk', 'local', CONCAT(?, seq, '.txt'), NOW(), NOW() FROM seq_1_to_{$rows}", [$customerId, $prefix]),
                default => DB::insert('WITH RECURSIVE g(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM g WHERE n < ?) '.$columns."SELECT ?, 'Bulk', 'local', ? || n || '.txt', datetime('now'), datetime('now') FROM g", [$rows, $customerId, $prefix]),
            };

            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ANALYZE documents');
            }
        });
    }

    /**
     * @return array{float, int}
     */
    private function measure(Closure $callback): array
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $start = hrtime(true);
        $callback();
        $seconds = (hrtime(true) - $start) / 1e9;
        DB::flushQueryLog();
        $counted = $queries;
        $queries = PHP_INT_MIN; // later listeners of this measure stop counting

        return [$seconds, $counted];
    }
}
