<?php

namespace App\Console\Commands;

use App\Models\PendingSwipe;
use App\Models\StudentSignIn;
use App\Models\TeacherSignIn;
use App\Support\SchedulerEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Emits only aggregate scheduler evidence. It is intentionally safe to run
 * from Pi Health: no writes and no student, teacher, RFID, or course IDs.
 */
class SchedulerEvidenceSummary extends Command
{
    protected $signature = 'scheduler:evidence-summary {--date= : Local Asia/Taipei date (YYYY-MM-DD)}';

    protected $description = 'Summarize PII-free per-job scheduler execution evidence for production health checks.';

    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: CarbonImmutable::now(SchedulerEvidence::TIMEZONE)->toDateString());
        try {
            $summary = SchedulerEvidence::summarize($date);
        } catch (\Throwable $exception) {
            $this->error(json_encode([
                'date' => $date,
                'timezone' => SchedulerEvidence::TIMEZONE,
                'healthy' => false,
                'error' => 'scheduler_evidence_unreadable',
            ], JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $summary['database_checks'] = $this->databaseChecks($date, $summary);
        $summary['healthy'] = $summary['healthy'] && array_sum($summary['database_checks']) === 0;

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $summary['healthy'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $schedulerSummary
     * @return array<string,int>
     */
    private function databaseChecks(string $date, array $schedulerSummary): array
    {
        $today = CarbonImmutable::parse($date, SchedulerEvidence::TIMEZONE)->startOfDay();

        $studentOrphans = StudentSignIn::query()
            ->whereNull('SignOutDT')
            ->whereNull('VoidedAt')
            ->where('SignInDT', '<', $today->toDateTimeString());

        $studentOrphansRemaining = (clone $studentOrphans)->count();
        $studentOrphansBeforeNightly = 0;
        $studentOrphansAfterNightly = 0;
        $studentOrphansUnclassified = $studentOrphansRemaining;

        $studentCloseJob = $schedulerSummary['jobs']['student-signin-close-orphans'] ?? [];
        $nightlyExecution = null;
        if (($studentCloseJob['status'] ?? null) === 'verified'
            && is_string($studentCloseJob['latest_execution'] ?? null)) {
            try {
                $nightlyExecution = CarbonImmutable::parse(
                    $studentCloseJob['latest_execution'],
                    SchedulerEvidence::TIMEZONE
                )->setTimezone(SchedulerEvidence::TIMEZONE);
            } catch (\Throwable) {
                $nightlyExecution = null;
            }
        }

        if ($nightlyExecution !== null) {
            $nightlyExecutionAt = $nightlyExecution->toDateTimeString();
            $studentOrphansBeforeNightly = (clone $studentOrphans)
                ->whereNotNull('MDT')
                ->where('MDT', '<=', $nightlyExecutionAt)
                ->count();
            $studentOrphansAfterNightly = (clone $studentOrphans)
                ->whereNotNull('MDT')
                ->where('MDT', '>', $nightlyExecutionAt)
                ->count();
            $studentOrphansUnclassified = (clone $studentOrphans)
                ->whereNull('MDT')
                ->count();
        }

        return [
            'student_orphans_remaining' => $studentOrphansRemaining,
            // PII-free classification of rows that survived the verified 02:30 close.
            'student_orphans_mdt_at_or_before_nightly' => $studentOrphansBeforeNightly,
            'student_orphans_mdt_after_nightly' => $studentOrphansAfterNightly,
            'student_orphans_unclassified' => $studentOrphansUnclassified,
            // Immediate invariant check: leave is an attendance placeholder, not
            // an open presence interval. Count every date so same-day regressions
            // are visible before the next 02:30 repair cycle.
            'active_leave_intervals_missing_sign_out' => StudentSignIn::query()
                ->whereNull('SignOutDT')
                ->whereNull('VoidedAt')
                ->whereRaw("LOWER(COALESCE(Status, '')) = 'leave'")
                ->count(),
            'teacher_orphans_remaining' => TeacherSignIn::query()
                ->whereNull('SignOutDT')
                ->where('SignInDT', '<', $today->toDateTimeString())
                ->count(),
            'expired_pending_swipes_remaining' => PendingSwipe::query()
                ->where('created_at', '<', $today->subDays(30)->toDateTimeString())
                ->count(),
            'past_attended_sessions_without_learning_record' => (int) (DB::selectOne(<<<'SQL'
                SELECT COUNT(*) AS c
                FROM ClassSession cs
                JOIN StudentClass sc ON sc.ID = cs.StudentClassID
                WHERE LOWER(cs.Status) IN ('attended','late','absent')
                  AND CONCAT(cs.SessionDate, ' ', COALESCE(cs.StartTime, '00:00:00')) <= NOW()
                  AND NOT EXISTS (
                    SELECT 1 FROM LearningRecord lr
                    WHERE lr.ClassSessionID = cs.id AND lr.VoidedAt IS NULL
                  )
                SQL
            )->c ?? 0),
        ];
    }
}
