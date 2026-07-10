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
        // #1078 evaluation-integrity guard: create missing pending LearningRecords for
        // past attended sessions so teachers can always submit 評量 (idempotent; previously
        // only created lazily via ensure-past, leaving 1,274 sessions without a record).
        $schedule->command('learning-records:backfill-missing')->dailyAt('03:50');
        // #1080 bug-termination gate: re-run every recurring bug's reproduction query so a
        // FIXED bug that regresses is caught automatically (non-zero exit) and open
        // divergences (#1062/#957/#964) are measured nightly — "production state vs bug state".
        $schedule->command('bugs:verify-reproductions')->dailyAt('04:00');
        // AI-native ops (docs/ROADMAP_AI_NATIVE.md phase 0): read-only daily business
        // digest — revenue at risk, retention risk, data-quality anomalies, forward
        // coverage — logged each morning so business health is quantified, not discovered.
        $schedule->command('ops:business-digest')->dailyAt('04:10');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
    }
}
