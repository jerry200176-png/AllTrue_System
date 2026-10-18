<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared two-process spawn/await/finish for payment-report race workers.
 * Keeps VoidRace + ConfirmRr fixtures from duplicating process plumbing.
 *
 * finish() never blocks forever on proc_close: stdout/stderr are drained with
 * non-blocking polls under a hard deadline; overtime workers are SIGTERM/SIGKILL'd
 * and every live worker is terminated from tearDown/finally.
 */
trait PaymentReportRaceWorker
{
    private const RACE_WORKER_DEADLINE_SECONDS = 20;

    /** @var list<string> */
    private array $raceFiles = [];

    /** @var list<array{process:resource,pipes:array<int,resource>,id:string}> */
    private array $raceLiveWorkers = [];

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

    /** @param array<string, mixed> $payload @return array{process:resource,pipes:array<int,resource>,id:string} */
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
        unset($pipes[0]);
        $this->raceAwait($started);

        $worker = [
            'process' => $process,
            'pipes' => $pipes,
            'id' => (string) Str::uuid(),
        ];
        $this->raceLiveWorkers[] = $worker;

        return $worker;
    }

    /** @param array{process:resource,pipes:array<int,resource>,id:string} $worker */
    private function raceFinish(array $worker): array
    {
        try {
            return $this->raceDrainWorker($worker);
        } finally {
            $this->raceForgetWorker($worker);
        }
    }

    /** @param array{process:resource,pipes:array<int,resource>,id:string} $worker */
    private function raceDrainWorker(array $worker): array
    {
        $process = $worker['process'];
        $pipes = $worker['pipes'];
        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                stream_set_blocking($pipes[$fd], false);
            }
        }

        $out = '';
        $err = '';
        $deadline = microtime(true) + self::RACE_WORKER_DEADLINE_SECONDS;
        $exitCode = null;

        while (microtime(true) < $deadline) {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $chunk = stream_get_contents($pipes[1]);
                if ($chunk !== false && $chunk !== '') {
                    $out .= $chunk;
                }
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $chunk = stream_get_contents($pipes[2]);
                if ($chunk !== false && $chunk !== '') {
                    $err .= $chunk;
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                // Final drain after exit.
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    $chunk = stream_get_contents($pipes[1]);
                    if ($chunk !== false && $chunk !== '') {
                        $out .= $chunk;
                    }
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    $chunk = stream_get_contents($pipes[2]);
                    if ($chunk !== false && $chunk !== '') {
                        $err .= $chunk;
                    }
                }
                $exitCode = $status['exitcode'];
                break;
            }
            usleep(10000);
        }

        if ($exitCode === null) {
            $this->raceTerminateProcess($process);
            // Brief poll for exit after kill — still bounded.
            $killDeadline = microtime(true) + 2;
            while (microtime(true) < $killDeadline) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                usleep(10000);
            }
            $this->raceClosePipes($pipes);
            // Avoid indefinite block: only proc_close after terminate+short wait.
            @proc_close($process);
            $this->fail(
                'race worker exceeded '.self::RACE_WORKER_DEADLINE_SECONDS
                .'s deadline and was terminated; stdout='.$out.' stderr='.$err
            );
        }

        $this->raceClosePipes($pipes);
        // Process already exited — proc_close should return immediately with the code.
        $code = proc_close($process);
        if ($code === -1 && $exitCode !== null && $exitCode !== -1) {
            $code = $exitCode;
        }
        $this->assertSame(0, $code, $err."\n".$out);
        $result = json_decode($out, true);
        $this->assertIsArray($result, $out.$err);
        if (($result['status'] ?? null) === 500) {
            $this->fail('worker returned HTTP 500 (deadlock/lock-wait not success): '.json_encode($result));
        }

        return $result;
    }

    /** @param resource $process */
    private function raceTerminateProcess($process): void
    {
        if (!is_resource($process)) {
            return;
        }
        $status = @proc_get_status($process);
        if (is_array($status) && ($status['running'] ?? false)) {
            @proc_terminate($process, 15);
            usleep(100000);
            $status = @proc_get_status($process);
            if (is_array($status) && ($status['running'] ?? false)) {
                @proc_terminate($process, 9);
            }
        }
    }

    /** @param array<int,resource> $pipes */
    private function raceClosePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /** @param array{process:resource,pipes:array<int,resource>,id:string} $worker */
    private function raceForgetWorker(array $worker): void
    {
        $this->raceLiveWorkers = array_values(array_filter(
            $this->raceLiveWorkers,
            static fn (array $w): bool => ($w['id'] ?? '') !== ($worker['id'] ?? '')
        ));
    }

    /** tearDown / failure path: kill every worker still tracked. */
    private function raceTerminateAllWorkers(): void
    {
        foreach ($this->raceLiveWorkers as $worker) {
            $this->raceTerminateProcess($worker['process']);
            $this->raceClosePipes($worker['pipes']);
            if (is_resource($worker['process'])) {
                @proc_close($worker['process']);
            }
        }
        $this->raceLiveWorkers = [];
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
