<?php

namespace App\Console;

use App\Support\SchedulerEvidence;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // R68: schedule:list proves only registration. Every production task keeps
        // a private per-run output file plus PII-free completion evidence that
        // Pi Health can verify after the overnight cycle.
        SchedulerEvidence::ensureDirectories();

        $this->scheduleObservedCommand($schedule, 'teacher-signin-close-orphans');
        $this->scheduleObservedCommand($schedule, 'reconcile-nightly');
        $this->scheduleObservedCommand($schedule, 'student-signin-close-orphans');
        $this->scheduleObservedCommand($schedule, 'rfid-prune-pending');
        $this->scheduleObservedCommand($schedule, 'learning-records-drift-check');
        // tuition:send-reminders 目前不自動執行，需手動呼叫或按需開啟
        // $schedule->command('tuition:send-reminders')->dailyAt('08:00');
        // #1062 revenue-integrity guard: surface prepaid courses with remaining
        // sessions but no upcoming class so stranded paid sessions never go
        // unnoticed (read-only; logs to the scheduler output).
        $this->scheduleObservedCommand($schedule, 'sessions-audit-stranded');
        // #1078 evaluation-integrity guard: create missing pending LearningRecords for
        // past attended sessions so teachers can always submit 評量 (idempotent; previously
        // only created lazily via ensure-past, leaving 1,274 sessions without a record).
        // #1080 bug-termination gate: re-run every recurring bug's reproduction query so a
        // FIXED bug that regresses is caught automatically (non-zero exit) and open
        // divergences (#1062/#957/#964) are measured nightly — "production state vs bug state".
        // #1062 Track A durable closure: forward-generate the next sessions for ACTIVE
        // prepaid (count-mode) courses with a confirmed weekly cadence, so active students'
        // prepaid sessions never strand off the calendar again. Idempotent, confirmed-cadence
        // only, 4-week capped, rollbackable (Note-tagged scheduled rows). Dormant courses are
        // excluded (director decision, #1152). Runs after the stranded audit (03:40).
        $this->scheduleObservedCommand($schedule, 'sessions-generate-forward');
        $this->scheduleObservedCommand($schedule, 'learning-records-backfill-missing');
        $this->scheduleObservedCommand($schedule, 'bugs-verify-reproductions');
        // AI-native ops (docs/POLICY_AI_NATIVE_ROADMAP.md phase 0): read-only daily business
        // digest — revenue at risk, retention risk, data-quality anomalies, forward
        // coverage — logged each morning so business health is quantified, not discovered.
        $this->scheduleObservedCommand($schedule, 'ops-business-digest');
    }

    private function scheduleObservedCommand(Schedule $schedule, string $job): void
    {
        $definition = SchedulerEvidence::jobs()[$job];
        $schedule->command($definition['command'])
            ->dailyAt($definition['time'])
            ->timezone(SchedulerEvidence::TIMEZONE)
            ->sendOutputTo(SchedulerEvidence::outputPath($job))
            ->onSuccess(static function () use ($job): void {
                SchedulerEvidence::recordCompletion($job, 'success');
            })
            ->onFailure(static function () use ($job): void {
                try {
                    SchedulerEvidence::recordCompletion($job, 'failure');
                } catch (\Throwable $exception) {
                    Log::critical('scheduler_evidence_write_failed', ['job' => $job, 'error' => $exception->getMessage()]);
                }
            });
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
    }
}
