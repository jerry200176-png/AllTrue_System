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
 *
 * Exit code reflects execution_healthy only. Reconciliation residuals are
 * reported separately and must not be conflated with "job did not run".
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
                'execution_healthy' => false,
                'healthy' => false,
                'error' => 'scheduler_evidence_unreadable',
                'error_class' => 'evidence_unreadable',
            ], JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $databaseChecksAt = CarbonImmutable::now(SchedulerEvidence::TIMEZONE);
        $dbChecks = $this->databaseChecks($date, $summary, $databaseChecksAt);
        $reconciliation = $this->reconciliationBaseline($dbChecks, $summary);
        $summary['database_checks'] = $dbChecks;
        $summary['database_checks_as_of'] = $databaseChecksAt->toIso8601String();
        $summary['reconciliation_baseline'] = $reconciliation;
        $summary['failure_routing'] = $this->failureRouting($summary, $reconciliation);

        // Do NOT fold residuals into execution healthy.
        $summary['healthy'] = (bool) $summary['execution_healthy'];

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $summary['execution_healthy'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,int>  $dbChecks
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function reconciliationBaseline(array $dbChecks, array $summary): array
    {
        $stranded = $summary['jobs']['sessions-audit-stranded']['observed_result'] ?? null;
        $bugs = $summary['jobs']['bugs-verify-reproductions']['observed_result'] ?? null;
        $reconcile = $summary['jobs']['reconcile-nightly']['observed_result'] ?? null;

        $duplicateSessionCount = 0;
        $crossScDup = 0;
        if (is_array($bugs) && isset($bugs['conditions']) && is_array($bugs['conditions'])) {
            foreach ($bugs['conditions'] as $condition) {
                $key = $condition['key'] ?? '';
                $count = (int) ($condition['count'] ?? 0);
                if ($key === 'duplicate_session_same_slot') {
                    $duplicateSessionCount = $count;
                }
                if ($key === 'cross_sc_duplicate_attended_slot') {
                    $crossScDup = $count;
                }
            }
        }

        $billingDivergence = is_array($reconcile) ? (int) ($reconcile['mismatch_count'] ?? 0) : null;
        $orphanSessions = is_array($stranded) ? (int) ($stranded['stranded_sessions'] ?? 0) : null;
        $orphanCourses = is_array($stranded) ? (int) ($stranded['stranded_courses'] ?? 0) : null;

        $residualScore = (int) ($dbChecks['student_orphans_mdt_at_or_before_nightly'] ?? 0)
            + (int) ($dbChecks['past_attended_sessions_without_learning_record'] ?? 0)
            + (int) ($dbChecks['active_leave_intervals_missing_sign_out'] ?? 0)
            + (int) ($dbChecks['teacher_orphans_remaining'] ?? 0);

        $status = 'green';
        if ($residualScore > 0 || $duplicateSessionCount > 0 || $crossScDup > 0 || ($billingDivergence ?? 0) > 0) {
            $status = 'amber';
        }
        if (($dbChecks['student_orphans_mdt_at_or_before_nightly'] ?? 0) > 50
            || ($orphanSessions ?? 0) > 2000) {
            $status = 'red';
        }

        return [
            'status' => $status,
            'duplicate_session_count' => $duplicateSessionCount,
            'cross_sc_duplicate_attended_slot' => $crossScDup,
            'orphan_session_count' => $orphanSessions,
            'orphan_course_count' => $orphanCourses,
            'unresolved_billing_divergence_count' => $billingDivergence,
            'failed_nightly_residual_score' => $residualScore,
            'stale_reconciliation_age_seconds' => $summary['sli']['evidence_freshness_seconds'] ?? null,
            'database_checks' => $dbChecks,
            'note' => 'Baseline only — no production data repair applied',
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $reconciliation
     * @return list<array<string,string>>
     */
    private function failureRouting(array $summary, array $reconciliation): array
    {
        $routes = [];
        foreach ($summary['jobs'] as $jobKey => $job) {
            $status = $job['run_status'] ?? $job['status'] ?? 'unknown';
            if (in_array($status, SchedulerEvidence::EXECUTION_OK_STATUSES, true) || $status === 'scheduled') {
                if (($job['zero_work'] ?? false) === true) {
                    $routes[] = [
                        'job_key' => $jobKey,
                        'status' => $status,
                        'owner' => 'no_incident',
                        'reason' => 'expected_zero_work',
                    ];
                }
                continue;
            }
            $owner = match ($status) {
                'no_run', 'stale', 'timed_out' => 'devops',
                'failed', 'partial', 'unknown' => 'engineering',
                default => 'engineering',
            };
            $routes[] = [
                'job_key' => $jobKey,
                'status' => $status,
                'owner' => $owner,
                'reason' => 'execution_' . $status,
            ];
        }
        if (($reconciliation['status'] ?? 'green') !== 'green') {
            $routes[] = [
                'job_key' => 'reconciliation_baseline',
                'status' => (string) $reconciliation['status'],
                'owner' => 'data_repair_queue',
                'reason' => 'residuals_present_no_auto_apply',
            ];
        }

        return $routes;
    }

    /**
     * @param  array<string,mixed>  $schedulerSummary
     * @return array<string,int>
     */
    private function databaseChecks(
        string $date,
        array $schedulerSummary,
        ?CarbonImmutable $databaseChecksAt = null
    ): array
    {
        $databaseChecksAt ??= CarbonImmutable::now(SchedulerEvidence::TIMEZONE);
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
        $okStatuses = array_merge(SchedulerEvidence::EXECUTION_OK_STATUSES, ['verified']);
        if (in_array($studentCloseJob['status'] ?? null, $okStatuses, true)
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
            'student_orphans_mdt_at_or_before_nightly' => $studentOrphansBeforeNightly,
            'student_orphans_mdt_after_nightly' => $studentOrphansAfterNightly,
            'student_orphans_unclassified' => $studentOrphansUnclassified,
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
                  AND CONCAT(cs.SessionDate, ' ', COALESCE(cs.StartTime, '00:00:00')) <= ?
                  AND NOT EXISTS (
                    SELECT 1 FROM LearningRecord lr
                    WHERE lr.ClassSessionID = cs.id AND lr.VoidedAt IS NULL
                  )
                SQL,
                [$databaseChecksAt->toDateTimeString()]
            )->c ?? 0),
        ];
    }
}
