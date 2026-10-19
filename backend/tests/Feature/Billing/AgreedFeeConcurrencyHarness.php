<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\AgreedFeeCorrection;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Support\AgreedFeeFixture;
use Tests\Support\AgreedFeeTestSchema;
use Tests\Support\PaymentReportRaceWorker;
use Tests\TestCase;

/** Real InnoDB REPEATABLE READ, separate PHP processes/PDO connections, deterministic lock barriers. */
abstract class AgreedFeeConcurrencyHarness extends TestCase
{
    use AgreedFeeFixture { fixture as protected; feePayload as protected; reportFor as protected; receiptFor as protected; }
    use PaymentReportRaceWorker;
    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.agreed_fee_correction_enabled' => true]);
        AgreedFeeTestSchema::assertTestConnection();
        $this->raceAssertMysqlRr();
        $this->artisan('migrate:fresh', ['--force' => true]);
        // Ephemeral AllTrue_test extension columns only — S1 does not ship prod ALTER.
        AgreedFeeTestSchema::ensureExtensionColumns();
        $this->assertSame('REPEATABLE-READ', alltrue_race_isolation_level());
    }
    protected function tearDown(): void
    {
        $this->raceTerminateAllWorkers();
        $this->raceCleanupFiles();
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    /**
     * Committed AllTrue_test schema lifecycle for mid-case DDL (RENAME/DROP/ADD).
     * No RefreshDatabase outer wrap — workers use separate PDOs and must see commits.
     * Call only when transactionLevel is already 0 (this harness never opens an outer txn).
     */
    protected function rebuildCommittedTestSchema(): void
    {
        AgreedFeeTestSchema::assertTestConnection();
        $level = DB::connection()->transactionLevel();
        if ($level !== 0) {
            throw new \RuntimeException(
                'Concurrency harness has no outer Testing txn; expected level 0 before schema rebuild, got '.$level
            );
        }
        $this->artisan('migrate:fresh', ['--force' => true]);
        AgreedFeeTestSchema::ensureExtensionColumns();
        RefreshDatabaseState::$migrated = false;
    }
    protected function file(): string { return $this->raceFile(); }
    protected function actor(array $f): array { return ['id' => (int) $f['user']->id, 'role' => 'director', 'campus_ids' => [1], 'student_id' => (int) $f['student']->id]; }
    protected function preview(array $f, ?array $body = null): array
    {
        $body ??= $this->feePayload($f['invoice']);
        return $body + ['preview_token' => app(AgreedFeeCorrection::class)->run($f['course']->ID, $body, $this->actor($f))['preview_token']];
    }
    protected function spawn(array $f, string $op, array $input, ?int $id = null, ?array $pause = null): array
    {
        $connectionFile = $this->file();
        $worker = $this->raceSpawn(base_path('tests/Support/agreed-fee-worker.php'), [
            'actor' => $this->actor($f), 'course_id' => $f['course']->ID,
            'op' => $op, 'input' => $input, 'id' => $id, 'pause' => $pause, 'connection_file' => $connectionFile,
            // Child process must receive the fixture allowlists (config is not inherited).
            'allowlist_campus_ids' => array_map('intval', (array) config('billing.agreed_fee_correction_campus_ids', [])),
            'allowlist_course_ids' => array_map('intval', (array) config('billing.agreed_fee_correction_course_ids', [])),
        ]);
        return $worker + ['connection_file' => $connectionFile];
    }
    protected function awaitBlocked(array $worker, string $table = 'StudentClass'): void
    {
        $this->await($worker['connection_file']);
        $id = (int) file_get_contents($worker['connection_file']);
        $this->assertGreaterThan(0, $id);
        $deadline = microtime(true) + 3;
        do {
            // Without PROCESS, MySQL/MariaDB still expose this account's own threads.
            // The winner holds this fixture's row; require its contender's exact
            // locking read to be pending on the server, not merely a PHP marker.
            $row = DB::selectOne('SELECT COMMAND, TIME, INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$id]);
            $sql = strtolower((string) ($row->INFO ?? ''));
            if (($row->COMMAND ?? null) === 'Execute' || ($row->COMMAND ?? null) === 'Query') {
                if ((int) $row->TIME >= 1 && str_contains($sql, 'from `'.strtolower($table).'`')
                    && str_contains($sql, 'for update')) return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('contender must reach the held-row locking query before releasing the winner');
    }
    protected function await(string $file): void
    {
        $this->raceAwait($file);
    }
    protected function finish(array $worker): array
    {
        return $this->raceFinish($worker);
    }
    protected function pause(string $match): array { return $this->racePause($match); }

}
