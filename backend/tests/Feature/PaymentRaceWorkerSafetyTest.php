<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentReportRaceWorker;
use Tests\TestCase;

require_once __DIR__.'/../Support/alltrueTestDatabase.php';

/** #4254: subprocess configuration must preserve the validated target and signing key. */
class PaymentRaceWorkerSafetyTest extends TestCase
{
    use PaymentReportRaceWorker;

    protected function tearDown(): void
    {
        $this->raceTerminateAllWorkers();
        $this->raceCleanupFiles();
        parent::tearDown();
    }

    public function test_alternate_database_targets_cannot_reach_child(): void
    {
        $db = array_replace(config('database.connections.mysql'), [
            'url' => 'mysql://unused:unused@remote.invalid/AllTrue',
            'unix_socket' => '/tmp/unused-production.sock',
            'read' => ['host' => 'remote.invalid'],
            'write' => ['database' => 'AllTrue'],
        ]);
        $worker = $this->raceSpawn(base_path('tests/Support/payment-race-payload-probe.php'), ['db' => $db]);
        $body = $this->raceFinish($worker)['body'];
        foreach (['url', 'unix_socket', 'read', 'write'] as $alternate) {
            $this->assertNotContains($alternate, $body['db_keys']);
        }
        $this->assertSame('127.0.0.1', $body['host']);
        $this->assertSame($db['database'], $body['database']);
    }

    public function test_child_receives_the_parent_token_signing_key(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        $worker = $this->raceSpawn(base_path('tests/Support/payment-race-payload-probe.php'), []);
        $body = $this->raceFinish($worker)['body'];
        $this->assertSame(hash_hmac('sha256', 'race-probe', config('app.key')), $body['key_proof']);
    }

    public function test_mariadb_uses_the_existing_tx_isolation_fallback(): void
    {
        $connection = \Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('AllTrue_test');
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('selectOne')->once()->with('SELECT @@transaction_isolation AS level')
            ->andThrow(new QueryException('SELECT @@transaction_isolation AS level', [], new \PDOException('Unknown system variable')));
        DB::shouldReceive('selectOne')->once()->with('SELECT @@tx_isolation AS level')
            ->andReturn((object) ['level' => 'REPEATABLE-READ']);
        DB::shouldReceive('statement')->once()->with('SET SESSION innodb_lock_wait_timeout = 5');
        $this->raceAssertMysqlRr();
    }
}
