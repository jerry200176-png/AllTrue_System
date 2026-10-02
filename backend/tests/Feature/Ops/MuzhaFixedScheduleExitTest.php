<?php

namespace Tests\Feature\Ops;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class MuzhaFixedScheduleExitTest extends TestCase
{
    public function test_preflight_rejection_exits_nonzero_without_success_payload(): void
    {
        $backend = dirname(__DIR__, 3);
        $process = new Process(
            [PHP_BINARY, $backend . '/scripts/ops/muzha_fixed_schedule_20260930.php'],
            $backend,
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'MUZHA_MODE' => 'invalid',
                'MUZHA_CONFIRM' => 'invalid',
            ],
        );

        $process->run();

        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Invalid case mode or confirmation', $process->getErrorOutput());
        self::assertSame('', trim($process->getOutput()));
    }

    public function test_legacy_apply_is_retired_before_database_access(): void
    {
        $backend = dirname(__DIR__, 3);
        $process = new Process(
            [PHP_BINARY, $backend . '/scripts/ops/muzha_fixed_schedule_20260930.php'],
            $backend,
            [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
                'MUZHA_MODE' => 'apply', 'MUZHA_CONFIRM' => 'APPROVE_MUZHA_FIXED_SCHEDULE_20260930',
            ],
        );
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Legacy apply is retired', $process->getErrorOutput());
        self::assertSame('', trim($process->getOutput()));
    }
}
