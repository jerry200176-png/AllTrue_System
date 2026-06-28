<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('rfid:prune-pending')->dailyAt('03:00');
        $schedule->command('learning-records:drift-check --fix')->dailyAt('03:20');
        // tuition:send-reminders 目前不自動執行，需手動呼叫或按需開啟
        // $schedule->command('tuition:send-reminders')->dailyAt('08:00');
        $schedule->command('reconcile:nightly')->dailyAt('02:00');
        // TD-008: 補上跨日孤兒 StudentSignIn.SignOutDT（在 nightly reconcile 之後）
        $schedule->command('student-signin:close-orphans')->dailyAt('02:30');
        $schedule->command('teacher-signin:close-orphans')->dailyAt('00:05');
        // #1062 revenue-integrity guard: surface prepaid courses with remaining
        // sessions but no upcoming class so stranded paid sessions never go
        // unnoticed (read-only; logs to the scheduler output).
        $schedule->command('sessions:audit-stranded')->dailyAt('03:40');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
    }
}
