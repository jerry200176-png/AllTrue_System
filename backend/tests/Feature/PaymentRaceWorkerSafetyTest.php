<?php

namespace Tests\Feature;

use Tests\Support\PaymentReportRaceWorker;
use Tests\TestCase;

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
        config(['app.key' => 'synthetic-parent-signing-key-4254']);
        $worker = $this->raceSpawn(base_path('tests/Support/payment-race-payload-probe.php'), []);
        $body = $this->raceFinish($worker)['body'];
        $this->assertSame(hash_hmac('sha256', 'race-probe', config('app.key')), $body['key_proof']);
    }
}
