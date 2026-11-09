<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Database;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Enums\StepStatus;
use Asignua\FilamentGdprRequests\Erasure\Runner;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Support\Schema as GdprSchema;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

/**
 * Runs only against a real MariaDB / Postgres (GDPR_TEST_DB). Nothing is wrapped in a transaction here: the
 * scenarios need committed data, a failure that really rolls back, and several processes at once.
 */
class RealDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array(env('GDPR_TEST_DB'), ['mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Set GDPR_TEST_DB=mariadb|pgsql to run the real-database scenarios.');
        }

        parent::setUp();
    }

    /**
     * No wrapping transaction: children must see the data.
     */
    public function beginDatabaseTransaction(): void {}

    protected function tearDown(): void
    {
        if (in_array(env('GDPR_TEST_DB'), ['mariadb', 'pgsql'], true)) {
            $this->emptyAllTables();
        }

        parent::tearDown();
    }

    /**
     * The committed rows must not leak into the next test; the schema (and the migrations table) stay.
     */
    private function emptyAllTables(): void
    {
        // Without the schema argument getTables() lists every database the user can see, not this one.
        $tables = array_values(array_filter(
            array_map(static fn (array $table): string => (string) $table['name'], Schema::getTables(Schema::getCurrentSchemaListing())),
            static fn (string $name): bool => $name !== 'migrations',
        ));

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE '.implode(', ', array_map(static fn (string $t): string => '"'.$t.'"', $tables)).' RESTART IDENTITY CASCADE');

            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($tables as $table) {
            DB::statement('TRUNCATE TABLE `'.$table.'`');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function confirmedErasure(Customer $customer): GdprRequest
    {
        $confirmer = $this->user('dpo', 'Confirmer');
        $request = Shop::request($customer, RequestType::Erasure, $confirmer);
        app(ErasureService::class)->confirm($request, $confirmer);

        return $request->refresh();
    }

    public function test_a_real_unique_violation_in_the_middle_rolls_the_group_back_and_a_rerun_completes_it(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $state = new \ArrayObject(['collide' => true]);
        // A callback vouching for uniqueness (so the preview lets it through) that really collides on the second row.
        $this->registry()->forget(Order::class);
        $this->registry()->add(PersonalData::for(Order::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize([
            'number' => Rule::using(static fn ($original, array $row) => $state['collide'] ? 'same' : 'erased-'.$row['id'], unique: true),
            'shipping_address' => Rule::fixed('Erased'),
        ])));
        $request = $this->confirmedErasure($ada);

        try {
            app(ErasureService::class)->start($request, $this->user('dpo', 'Runner'));
            $this->fail('The unique violation should surface.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $request->refresh();
        $this->assertSame(RequestStatus::Partial, $request->status);
        $this->assertSame(['Ada street 1', 'Ada street 2'], Order::query()->where('customer_id', $ada->id)->orderBy('id')->pluck('shipping_address')->all(), 'The failed group rolled back as a whole, including the row before the collision.');
        $failed = $request->steps()->where('group_key', Order::class)->first();
        $this->assertSame(StepStatus::Failed, $failed->status);
        $this->assertStringStartsWith('Database error (SQLSTATE', (string) $failed->errorMessage());
        $this->assertStringNotContainsString('same', (string) $failed->error, 'The offending value is not stored in the report.');

        $state['collide'] = false;
        app(ErasureService::class)->start($request->refresh(), $this->user('dpo', 'Runner'));

        $request->refresh();
        $this->assertSame(RequestStatus::Fulfilled, $request->status);
        $this->assertSame(['Erased', 'Erased'], Order::query()->where('customer_id', $ada->id)->pluck('shipping_address')->all());
        $this->assertSame(2, $request->steps()->where('group_key', Order::class)->value('attempts'));
    }

    public function test_two_workers_on_the_same_group_take_turns_and_the_work_happens_once(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $log = tempnam(sys_get_temp_dir(), 'gdpr-calls');
        $this->registry()->forget(Order::class);
        $this->registry()->add(PersonalData::for(Order::class)->of(Customer::class)->matchedBy('customer_id')->erase(Erase::anonymize([
            'shipping_address' => Rule::using(static function ($original) use ($log) {
                file_put_contents($log, getmypid()."\n", FILE_APPEND);
                usleep(300_000);

                return 'Erased';
            }),
        ])));
        $request = $this->confirmedErasure($ada);
        $request->forceFill(['status' => RequestStatus::Erasing, 'erasure_heartbeat_at' => now()])->save();
        file_put_contents($log, ''); // the confirmation preview evaluated the rules in this process too

        $results = $this->inParallel(2, function () use ($request): string {
            app(Runner::class)->runGroup($request, Order::class);

            return 'ok';
        });

        $this->assertSame(['ok', 'ok'], $results, 'Both calls end quietly: the second finds the step settled.');
        $pids = array_unique(array_filter(explode("\n", (string) file_get_contents($log))));
        unlink($log);
        $this->assertCount(1, $pids, 'Only one process ever evaluated the rules: the other waited on the request lock.');
        $this->assertSame(1, $request->steps()->where('group_key', Order::class)->count());
        $this->assertSame(1, (int) $request->steps()->where('group_key', Order::class)->value('attempts'));
        $this->assertSame(2, (int) $request->steps()->where('group_key', Order::class)->value('changed'));
    }

    public function test_two_simultaneous_starts_of_one_request_claim_it_once(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $request = $this->confirmedErasure($ada);
        $runner = $this->user('dpo', 'Runner');

        $results = $this->inParallel(2, function () use ($request, $runner): string {
            try {
                app(ErasureService::class)->start($request->refresh(), $runner);

                return 'started';
            } catch (\Throwable $e) {
                return class_basename($e);
            }
        });

        sort($results);
        $this->assertSame('started', $results[1] === 'started' ? $results[1] : $results[0], 'Exactly one caller started the run: '.json_encode($results));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'started')), json_encode($results));
        $request->refresh();
        $this->assertSame(RequestStatus::Fulfilled, $request->status);
        $this->assertSame(1, $request->events()->where('event', 'erasure_started')->count());
        $this->assertSame(0, (int) $request->steps()->where('attempts', '!=', 1)->count());
        $this->assertSame('Erased customer', Customer::query()->find($ada->id)->name);
    }

    public function test_the_schema_reader_stays_inside_the_current_database(): void
    {
        $foreign = env('GDPR_TEST_FOREIGN_DB');

        if (!is_string($foreign) || $foreign === '') {
            $this->markTestSkipped('Set GDPR_TEST_FOREIGN_DB to a neighbouring database (MariaDB) or schema (Postgres) holding customers, foreign_only and foreign_child.');
        }

        $mysql = DB::getDriverName() !== 'pgsql';
        $present = DB::table('information_schema.tables')->where('table_schema', $foreign)->pluck('table_name')->all();
        $this->assertEqualsCanonicalizing(['customers', 'foreign_only', 'foreign_child'], $present, 'The neighbour must really be there.');

        $schema = new GdprSchema;

        $this->assertFalse($schema->hasTable('foreign_only'), 'A table of another database is not ours.');
        $this->assertFalse($schema->hasTable('foreign_child'));
        $this->assertTrue($schema->hasTable('customers'));
        $this->assertSame(count($schema->tables()), count(array_unique($schema->tables())), 'A name shared with the neighbour appears once.');
        $this->assertNotContains('foreign_child', array_column($schema->referencing('customers'), 'table'), 'A foreign key of the neighbour does not point at our customers.');

        $ours = array_column($schema->referencing('customers'), 'table');
        $this->assertContains('orders', $ours);

        // A table of ours that points at the NEIGHBOUR's customers does not reference our customers either.
        $qualified = $mysql ? '`'.$foreign.'`.`customers`' : '"'.$foreign.'"."customers"';
        DB::statement('create table cross_ref (id bigint primary key, customer_id '.($mysql ? 'bigint unsigned' : 'bigint').' references '.$qualified.' (id), foreign key (customer_id) references '.$qualified.' (id))');

        try {
            $this->assertNotContains('cross_ref', array_column((new GdprSchema)->referencing('customers'), 'table'));
        } finally {
            DB::statement('drop table cross_ref');
        }

        // The erasure preview on top of it: nothing of the neighbour reaches a plan, and the sweep leaves it alone.
        $request = $this->confirmedErasure(Shop::customer('Ada', 'ada@customer.example'));
        $plan = app(ErasureService::class)->plan($request);
        $this->assertSame([], array_map(static fn ($blocker): string => $blocker->message(), $plan->allBlockers()));

        $this->emptyAllTables();
        $this->assertSame('sentinel', DB::table($foreign.'.customers')->value('marker'));
    }

    /**
     * Forks $n children that each run $work with their own connection and report a string back.
     *
     * @return list<string>
     */
    private function inParallel(int $n, \Closure $work): array
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required.');
        }

        DB::disconnect();
        $dir = sys_get_temp_dir().'/gdpr-par-'.uniqid();
        mkdir($dir);
        $pids = [];

        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                try {
                    $out = $work();
                } catch (\Throwable $e) {
                    $out = 'EXC '.get_class($e).': '.$e->getMessage();
                }

                file_put_contents($dir.'/'.$i, $out);
                // End the child without running PHPUnit's shutdown handlers.
                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        for ($i = 0; $i < $n; $i++) {
            $results[] = (string) @file_get_contents($dir.'/'.$i);
            @unlink($dir.'/'.$i);
        }

        @rmdir($dir);
        DB::reconnect();

        return $results;
    }
}
