<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Database;

use Asignua\FilamentGdprRequests\Exceptions\UnsupportedEnvironment;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Support\Environment;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Ticket;

/**
 * A server whose LOWER knows less than PHP's would miss an address in the preview and again in the check after the run.
 * Such a database is refused up front, with a message that says why.
 */
class CaseFoldingTest extends TestCase
{
    use RunsErasures;

    protected function setUp(): void
    {
        if (!in_array(env('GDPR_TEST_DB'), ['mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Set GDPR_TEST_DB=mariadb|pgsql to run the real-database scenarios.');
        }

        parent::setUp();
        Environment::forgetFolding();
    }

    protected function tearDown(): void
    {
        Environment::forgetFolding();

        parent::tearDown();
    }

    public function test_the_test_database_folds_like_php_and_finds_unicode_addresses(): void
    {
        $this->assertNull(Environment::foldingDifference(DB::connection()));

        $customer = Shop::customer('Someone', 'ΟΔΥΣ-İnci-ÄÉŁЖ@customer.example');
        $plan = app(ErasureService::class)->plan($this->erasureOf($customer));

        $this->assertSame(1, $plan->group(Ticket::class)?->matched);
        $this->assertSame(Customer::class.'#'.$customer->id, $plan->subjectKey);
    }

    public function test_a_postgres_database_with_lc_ctype_c_is_refused(): void
    {
        if (env('GDPR_TEST_DB') !== 'pgsql') {
            $this->markTestSkipped('Postgres only: MariaDB has no ASCII-only LOWER for utf8mb4.');
        }

        // Its own handle: the test's connection sits in a transaction, and CREATE DATABASE refuses to run inside one.
        config(['database.connections.gdpr_admin' => config('database.connections.testing')]);
        $admin = DB::connection('gdpr_admin');
        $exists = $admin->selectOne("SELECT 1 AS found FROM pg_database WHERE datname = 'gdpr_fold_c'") !== null;

        try {
            if (!$exists) {
                $admin->statement("CREATE DATABASE gdpr_fold_c TEMPLATE template0 ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C'");
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('Cannot create a database with LC_CTYPE C here: '.class_basename($e));
        } finally {
            DB::purge('gdpr_admin');
        }

        config(['database.connections.gdpr_fold_c' => ['database' => 'gdpr_fold_c'] + config('database.connections.testing')]);
        $this->assertSame('C', DB::connection('gdpr_fold_c')->selectOne('SELECT datctype FROM pg_database WHERE datname = current_database()')->datctype);

        $difference = Environment::foldingDifference(DB::connection('gdpr_fold_c'));
        $this->assertNotNull($difference);
        $this->assertStringContainsString('ΟΔΥΣ', $difference, 'LOWER under C leaves Greek capitals alone.');

        $default = config('database.default');
        config(['database.default' => 'gdpr_fold_c']);

        try {
            Environment::assertSupported();
            $this->fail('A database that lowercases only ASCII must be refused.');
        } catch (UnsupportedEnvironment $e) {
            $this->assertSame('errors.lowercase_unsupported', $e->translationKey);
            $this->assertSame('pgsql', $e->translationParams['driver']);
            $this->assertStringContainsString('LC_CTYPE', $e->getMessage());
        } finally {
            config(['database.default' => $default]);
            DB::purge('gdpr_fold_c');
        }
    }
}
