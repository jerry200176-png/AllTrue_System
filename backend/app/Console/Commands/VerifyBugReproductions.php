<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Support\AttendanceStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bug-termination gate (#1080).
 *
 * The in-app bug "infinite loop" recurs because the same divergence generators stay live
 * while individual user-cases are patched. This command turns every recurring bug into a
 * machine-checkable **reproduction query** and runs them against production:
 *
 *   - `enforced` conditions are bugs already verified FIXED (count must stay 0). If one
 *     regresses (count > 0) the command exits non-zero — the auto-reopen / alert signal,
 *     so "PR merged" can never again be mistaken for "verified absent in production".
 *   - non-enforced conditions are KNOWN-OPEN divergences (owner-gated fixes #1062/#957/#964);
 *     reported with their tracking issue so "production state vs bug state" is measurable nightly.
 *
 * Read-only: only SELECT COUNT(*). Scheduled nightly (Kernel) — feeds #904 recurrence report.
 */
class VerifyBugReproductions extends Command
{
    protected $signature = 'bugs:verify-reproductions {--json : Emit JSON instead of a table}';

    protected $description = 'Run every recurring bug reproduction query against production; fail if a FIXED bug regresses (#1080).';

    /**
     * @return list<array{key:string,label:string,issue:string,enforced:bool,sql:string}>
     */
    private function conditions(): array
    {
        $fillableStatuses = implode(",", array_map(
            static fn (string $status): string => "'" . addslashes($status) . "'",
            AttendanceStatus::requiresLogSessionStatuses()
        ));
        return [
            [
                'key' => 'attended_session_without_learning_record',
                'label' => 'Attended sessions with no LearningRecord (teacher cannot submit 評量)',
                'issue' => '#1078/#188', 'enforced' => true,
                'sql' => "SELECT COUNT(*) AS c FROM ClassSession cs
                          JOIN StudentClass sc ON sc.ID = cs.StudentClassID
                          WHERE LOWER(cs.Status) IN ({$fillableStatuses})
                            AND CONCAT(cs.SessionDate, ' ', COALESCE(cs.StartTime, '00:00:00')) <= NOW()
                            AND NOT EXISTS (SELECT 1 FROM LearningRecord lr
                                            WHERE lr.ClassSessionID = cs.id AND lr.VoidedAt IS NULL)",
            ],
            [
                'key' => 'leave_session_with_live_learning_record',
                'label' => 'Leave sessions carrying a live LearningRecord (wrongly in 待審核評量)',
                'issue' => '#170', 'enforced' => true,
                'sql' => "SELECT COUNT(*) AS c FROM ClassSession cs
                          WHERE LOWER(cs.Status) IN ('leave','leave_adjusted')
                            AND EXISTS (SELECT 1 FROM LearningRecord lr
                                        WHERE lr.ClassSessionID = cs.id AND lr.VoidedAt IS NULL
                                          AND LOWER(lr.Status) <> 'approved'
                                          )",
            ],
            [
                'key' => 'stranded_prepaid_course',
                'label' => 'Active count-mode course, remaining>0, no upcoming session (stranded)',
                'issue' => '#1062', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM StudentClass sc
                          WHERE (sc.Stop = 0 OR sc.Stop IS NULL) AND sc.ScheduleMode = 'count'
                            AND sc.RemainingSessions > 0
                            AND NOT EXISTS (SELECT 1 FROM ClassSession cs
                                            WHERE cs.StudentClassID = sc.ID AND cs.SessionDate >= CURDATE()
                                              AND LOWER(cs.Status) NOT IN ('cancelled','voided'))",
            ],
            [
                'key' => 'duplicate_session_same_slot',
                'label' => 'Duplicate non-cancelled ClassSession for same (course,date,start)',
                'issue' => '#957/#173', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM (
                            SELECT 1 FROM ClassSession cs WHERE LOWER(cs.Status) NOT IN ('cancelled','voided')
                            GROUP BY cs.StudentClassID, cs.SessionDate, SUBSTRING(cs.StartTime, 1, 5)
                            HAVING COUNT(*) > 1) d",
            ],
            [
                'key' => 'cross_sc_duplicate_attended_slot',
                'label' => 'Same student attended/completed twice in one slot across two contracts (cross-SC)',
                'issue' => '#1130/#173', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM (
                            SELECT 1 FROM ClassSession cs
                            JOIN StudentClass sc ON sc.ID = cs.StudentClassID
                            WHERE LOWER(cs.Status) IN ('attended','completed')
                            GROUP BY sc.StudentID, cs.SessionDate, SUBSTRING(cs.StartTime, 1, 5)
                            HAVING COUNT(*) > 1 AND COUNT(DISTINCT cs.StudentClassID) > 1) d",
            ],
            [
                'key' => 'remaining_sessions_diverges_from_derived',
                'label' => 'Stored RemainingSessions <> SessionCount - UsedSessions (count truth)',
                'issue' => '#964/#172', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM StudentClass sc
                          WHERE sc.ScheduleMode = 'count'
                            AND sc.SessionCount IS NOT NULL AND sc.RemainingSessions IS NOT NULL
                            AND sc.UsedSessions IS NOT NULL
                            AND sc.RemainingSessions <> (sc.SessionCount - sc.UsedSessions)",
            ],
            [
                'key' => 'package_over_debit',
                'label' => 'Shared packages whose ledger used > taught + absent lessons (#4455 over-debit class)',
                'issue' => '#4659/#4455', 'enforced' => true,
                // Same lesson count as scripts/probes/inapp-package-ledger-probe.php (group types debit one slot, others one per lesson).
                'sql' => "SELECT COUNT(*) AS c FROM (
                            SELECT l.package_id, -SUM(l.delta) AS used FROM package_session_ledger l GROUP BY l.package_id HAVING -SUM(l.delta) > 0) u
                          JOIN course_packages p ON p.id = u.package_id
                          WHERE u.used > (
                            SELECT COUNT(DISTINCT CASE WHEN p.class_type IN ('one_on_two','one_on_three','one_on_many','tutoring')
                                                       THEN CONCAT(cs.SessionDate, ' ', SUBSTRING(cs.StartTime, 1, 5)) ELSE CONCAT('s', cs.id) END)
                            FROM StudentClass sc JOIN ClassSession cs ON cs.StudentClassID = sc.ID
                            WHERE sc.PackageID = u.package_id
                              AND LOWER(cs.Status) IN ('attended','completed','late','absent'))",
            ],
            [
                'key' => 'session_debited_twice',
                'label' => 'Same session debited more than once on one package ledger or one course ledger',
                'issue' => '#4659/#4455', 'enforced' => true,
                'sql' => "SELECT (SELECT COUNT(*) FROM (
                                    SELECT 1 FROM package_session_ledger WHERE class_session_id IS NOT NULL
                                    GROUP BY package_id, class_session_id HAVING SUM(delta) < -1) a)
                               + (SELECT COUNT(*) FROM (
                                    SELECT 1 FROM session_deduction_ledger WHERE class_session_id IS NOT NULL
                                    GROUP BY student_class_id, class_session_id
                                    HAVING SUM(CASE event_type WHEN 'deduct' THEN 1 WHEN 'reverse' THEN -1 ELSE 0 END) > 1) b) AS c",
            ],
            [
                'key' => 'unpaid_charge_vs_rate_count',
                'label' => 'Unpaid count-mode contracts whose Charge is >5x off Rate x count (4050/4051 class, #4138)',
                'issue' => '#4659/#4138', 'enforced' => true,
                // Rate x count as in production-case-dump `charge_vs_rate_count` (hour-rated: Rate x hours); package members excluded.
                'sql' => "SELECT COUNT(*) AS c FROM (
                            SELECT sc.Charge AS charge, sc.Rate * (CASE WHEN LOWER(TRIM(COALESCE(sc.rate_unit, 'session'))) = 'hour'
                                   THEN COALESCE(NULLIF(sc.TotalHours, 0), ROUND(sc.SessionCount * GREATEST(30, COALESCE(sc.SessionDuration, 120)) / 60))
                                   ELSE sc.SessionCount END) AS rate_count
                            FROM StudentClass sc
                            WHERE sc.ScheduleMode = 'count' AND (sc.Stop = 0 OR sc.Stop IS NULL) AND COALESCE(sc.Paid, 0) = 0
                              AND LOWER(TRIM(COALESCE(sc.ClassType, ''))) <> 'tutoring'
                              AND sc.PackageID IS NULL AND sc.Rate > 0 AND sc.SessionCount > 0) t
                          WHERE COALESCE(t.charge, 0) * 5 < t.rate_count OR COALESCE(t.charge, 0) > t.rate_count * 5",
            ],
            [
                'key' => 'paid_flag_vs_invoice',
                'label' => 'Active contracts where Paid=1 beside an open positive invoice, or Paid=0 with every positive invoice fully paid (G-009)',
                'issue' => '#4659/G-009', 'enforced' => true,
                // Payment rows are the truth: Invoice::netPaidSql(). Package members are skipped (their package owns the paid flag).
                'sql' => "SELECT COUNT(*) AS c FROM StudentClass sc
                          WHERE (sc.Stop = 0 OR sc.Stop IS NULL) AND sc.PackageID IS NULL AND (
                            (sc.Paid = 1 AND EXISTS (SELECT 1 FROM Invoice i WHERE i.StudentClassID = sc.ID AND COALESCE(i.Status, '') <> 'void'
                                  AND i.TotalAmount > 0 AND i.TotalAmount > " . Invoice::netPaidSql('i') . "))
                            OR (COALESCE(sc.Paid, 0) = 0
                                AND EXISTS (SELECT 1 FROM Invoice i WHERE i.StudentClassID = sc.ID AND COALESCE(i.Status, '') <> 'void' AND i.TotalAmount > 0)
                                AND NOT EXISTS (SELECT 1 FROM Invoice i WHERE i.StudentClassID = sc.ID AND COALESCE(i.Status, '') <> 'void'
                                  AND i.TotalAmount > 0 AND i.TotalAmount > " . Invoice::netPaidSql('i') . ")))",
            ],
        ];
    }

    public function handle(): int
    {
        $results = [];
        $regressed = 0;

        foreach ($this->conditions() as $cond) {
            $count = (int) (DB::selectOne($cond['sql'])->c ?? 0);
            // Known-baseline allowance (config/reproduction_baselines.php): an enforced condition fails only above it;
            // a listed null means the baseline is not measured yet (reported, never failing).
            $baseline = config('reproduction_baselines.' . $cond['key'], 0);
            $pending = $cond['enforced'] && $baseline === null;
            $isRegression = $cond['enforced'] && !$pending && $count > (int) $baseline;
            if ($isRegression) {
                $regressed++;
            }
            $results[] = [
                'key' => $cond['key'],
                'issue' => $cond['issue'],
                'enforced' => $cond['enforced'],
                'count' => $count,
                'baseline' => $baseline,
                'state' => $cond['enforced']
                    ? ($count === 0 ? 'FIXED-OK' : ($pending ? 'BASELINE-PENDING' : ($isRegression ? 'REGRESSED' : 'WITHIN-BASELINE')))
                    : ($count === 0 ? 'OPEN-CLEARED' : 'OPEN'),
                'label' => $cond['label'],
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'as_of' => now()->toIso8601String(),
                'regressed' => $regressed,
                'conditions' => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['condition', 'issue', 'enforced', 'count', 'state'],
                array_map(fn ($r) => [$r['key'], $r['issue'], $r['enforced'] ? 'yes' : 'no', $r['count'], $r['state']], $results)
            );
            if ($regressed > 0) {
                $this->error(sprintf('%d FIXED bug(s) regressed in production — investigate + reopen.', $regressed));
            } else {
                $this->info('No FIXED bug has regressed. Open divergences are tracked by their issue.');
            }
        }

        // Non-zero exit when a verified-fixed bug reappears: the auto-reopen / alert signal.
        return $regressed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
