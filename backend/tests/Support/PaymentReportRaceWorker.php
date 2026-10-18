<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared two-process spawn/await/finish for payment-report race workers.
 * Keeps VoidRace + ConfirmRr fixtures from duplicating process plumbing.
 */
trait PaymentReportRaceWorker
{
    /** @var list<string> */
    private array $raceFiles = [];

    private function raceFile(): string
    {
        $p = '/tmp/pr-race-'.Str::uuid();
        $this->raceFiles[] = $p;

        return $p;
    }

    private function racePause(string $match): array
    {
        return ['match' => $match, 'ready' => $this->raceFile(), 'release' => $this->raceFile()];
    }

    private function raceCleanupFiles(): void
    {
        foreach ($this->raceFiles as $p) {
            @unlink($p);
        }
        $this->raceFiles = [];
    }

    private function raceAwait(string $file): void
    {
        $deadline = microtime(true) + 15;
        while (!file_exists($file) && microtime(true) < $deadline) {
            usleep(5000);
        }
        $this->assertFileExists($file, 'worker reached deterministic barrier');
    }

    /** @param array<string, mixed> $payload */
    private function raceSpawn(string $script, array $payload): array
    {
        $started = $this->raceFile();
        $payload['started'] = $started;
        $payload['db'] = $payload['db'] ?? config('database.connections.mysql');
        $process = proc_open(
            [PHP_BINARY, $script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path()
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $this->raceAwait($started);

        return [$process, $pipes];
    }

    private function raceFinish(array $worker): array
    {
        [$process, $pipes] = $worker;
        stream_set_timeout($pipes[1], 20);
        stream_set_timeout($pipes[2], 20);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $this->assertSame(0, $code, $err."\n".$out);
        $result = json_decode($out, true);
        $this->assertIsArray($result, $out.$err);
        if (($result['status'] ?? null) === 500) {
            $this->fail('worker returned HTTP 500 (deadlock/lock-wait not success): '.json_encode($result));
        }

        return $result;
    }

    private function raceAssertMysqlRr(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        require_once base_path('tests/Support/alltrueTestDatabase.php');
        $dbName = DB::connection()->getDatabaseName();
        $this->assertTrue(
            alltrue_is_allowed_test_database($dbName),
            'race workers require CI AllTrue_test or phpunit-isolated AllTrue_test_*; got '.$dbName
        );
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
    }
}
