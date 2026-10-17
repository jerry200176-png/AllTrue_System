<?php

namespace Tests\Feature\Billing;

use App\Models\{Invoice, Payment, PaymentReport};
use App\Services\Billing\AgreedFeeCorrection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Support\AgreedFeeFixture;
use Tests\Support\AgreedFeeTestSchema;
use Tests\TestCase;

/** Real InnoDB REPEATABLE READ, separate PHP processes/PDO connections, deterministic lock barriers. */
abstract class AgreedFeeConcurrencyHarness extends TestCase
{
    use AgreedFeeFixture { fixture as protected; feePayload as protected; reportFor as protected; receiptFor as protected; }
    private array $files = [];
    protected function setUp(): void
    {
        parent::setUp(); config(['billing.agreed_fee_correction_enabled' => true, 'app.key' => str_repeat('t', 32)]);
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('AllTrue_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true]);
        // Ephemeral AllTrue_test extension columns only — S1 does not ship prod ALTER.
        AgreedFeeTestSchema::ensureExtensionColumns();
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
    }
    protected function tearDown(): void { foreach ($this->files as $p) @unlink($p); RefreshDatabaseState::$migrated = false; parent::tearDown(); }
    protected function file(): string { $p = '/tmp/fee-'.Str::uuid(); $this->files[] = $p; return $p; }
    protected function actor(array $f): array { return ['id' => (int) $f['user']->id, 'role' => 'director', 'campus_ids' => [1], 'student_id' => (int) $f['student']->id]; }
    protected function preview(array $f, ?array $body = null): array
    {
        $body ??= $this->feePayload($f['invoice']);
        return $body + ['preview_token' => app(AgreedFeeCorrection::class)->run($f['course']->ID, $body, $this->actor($f))['preview_token']];
    }
    protected function spawn(array $f, string $op, array $input, ?int $id = null, ?array $pause = null): array
    {
        $started = $this->file();
        $process = proc_open([PHP_BINARY, base_path('tests/Support/agreed-fee-worker.php')], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes, base_path());
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode(['db' => config('database.connections.mysql'), 'actor' => $this->actor($f),
            'course_id' => $f['course']->ID, 'op' => $op, 'input' => $input, 'id' => $id, 'pause' => $pause, 'started' => $started]));
        fclose($pipes[0]);
        $this->await($started);
        return [$process, $pipes];
    }
    protected function await(string $file): void
    {
        $deadline = microtime(true)+15;
        while (!file_exists($file) && microtime(true)<$deadline) usleep(5000);
        $this->assertFileExists($file, 'worker reached deterministic barrier');
    }
    protected function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $this->assertSame(0, proc_close($process), $err);
        $result = json_decode($out, true); $this->assertIsArray($result, $out.$err); return $result;
    }
    protected function pause(string $match): array { return ['match' => $match, 'ready' => $this->file(), 'release' => $this->file()]; }

}
