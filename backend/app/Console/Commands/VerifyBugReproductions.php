<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\StudentClass;
use App\Services\Billing\InvoiceAmountParityCensus;
use App\Services\Billing\PaidFlagInvoiceMismatch;
use App\Services\Scheduling\ScheduleTeacherParityCensus;
use App\Support\AppClockSql;
use App\Support\AttendanceStatus;
use App\Support\ContractPeriod;
use App\Support\SchedulerEvidence;
use App\Support\SessionStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * Read-only: only SELECT. Scheduled nightly (Kernel) — feeds #904 recurrence report.
 * `screen_amount_vs_resolver` (#4991 item 2) is counted by InvoiceAmountParityCensus instead of one COUNT(*); its JSON row
 * carries `unevaluated`, `census_error` and a `detail` (per campus, per type, named exclusions, truncated flag).
 * `lesson_teacher_disagreement` (#4991 item 7) is counted the same way by ScheduleTeacherParityCensus.
 */
class VerifyBugReproductions extends Command
{
    protected $signature = 'bugs:verify-reproductions {--json : Emit JSON instead of a table}';

    protected $description = 'Run every recurring bug reproduction query against production; fail if a FIXED bug regresses (#1080).';

    /**
     * @return list<array{key:string,label:string,issue:string,enforced:bool,sql?:string,census?:class-string}>
     */
    private function conditions(): array
    {
        $fillableStatuses = implode(",", array_map(
            static fn (string $status): string => "'" . addslashes($status) . "'",
            AttendanceStatus::requiresLogSessionStatuses()
        ));
        $quoted = static fn (array $values): string => implode(',', array_map(
            static fn (string $v): string => "'" . addslashes($v) . "'",
            $values
        ));
        $freeingStatuses = $quoted(SessionStatus::futureReservationExclusionStatuses());
        $usageLocks = $quoted(StudentClass::USAGE_LOCK_CLOSURES);
        return [
            [
                'key' => 'attended_session_without_learning_record',
                'label' => 'Attended sessions with no LearningRecord (teacher cannot submit 評量)',
                'issue' => '#1078/#188', 'enforced' => true,
                'sql' => "SELECT COUNT(*) AS c FROM ClassSession cs
                          JOIN StudentClass sc ON sc.ID = cs.StudentClassID
                          WHERE LOWER(cs.Status) IN ({$fillableStatuses})
                            AND CONCAT(cs.SessionDate, ' ', COALESCE(cs.StartTime, '00:00:00')) <= CAST(:app_now AS DATETIME)
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
                                            WHERE cs.StudentClassID = sc.ID AND cs.SessionDate >= CAST(:app_today AS DATE)
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
                'key' => 'shared_pool_member_generation_stopped',
                'label' => 'Running shared-pool members taught in the last 21 days with no upcoming lesson while the pool ledger still has lessons',
                'issue' => '#5151/#4991', 'enforced' => false,
                // The pool ledger is the authority for what is left (SharedPackagePlanningService); the member's own counter is not read.
                // Counts both a generator that stopped early and a sibling course holding every lesson the pool has left.
                'sql' => "SELECT COUNT(*) AS c FROM StudentClass sc
                          JOIN course_packages p ON p.id = sc.PackageID
                          WHERE (sc.Stop = 0 OR sc.Stop IS NULL) AND sc.ScheduleMode = 'count'
                            AND COALESCE(sc.scheduling_policy, 'auto_recurrence') <> 'manual_occurrence'
                            AND p.billing_mode = 'count'
                            AND (SELECT COUNT(*) FROM StudentClass m
                                 WHERE m.PackageID = p.id AND (m.Stop = 0 OR m.Stop IS NULL)) > 1
                            AND CAST(p.total_sessions AS SIGNED) + COALESCE((SELECT SUM(l.delta) FROM package_session_ledger l
                                                                             WHERE l.package_id = p.id), 0) > 0
                            AND EXISTS (SELECT 1 FROM ClassSession cs
                                        WHERE cs.StudentClassID = sc.ID
                                          AND cs.SessionDate >= DATE_SUB(CAST(:app_today AS DATE), INTERVAL 21 DAY) AND cs.SessionDate < CAST(:app_today AS DATE)
                                          AND LOWER(cs.Status) NOT IN ('cancelled','voided'))
                            AND NOT EXISTS (SELECT 1 FROM ClassSession cs
                                            WHERE cs.StudentClassID = sc.ID AND cs.SessionDate >= CAST(:app_today AS DATE)
                                              AND LOWER(cs.Status) NOT IN ('cancelled','voided'))",
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
                // Payment rows are the truth: Invoice::netPaidSql(). A bill names the course on its header or on a line
                // (InvoiceItem.StudentClassID). Package members are skipped (their package owns the paid flag).
                // The predicate lives in PaidFlagInvoiceMismatch; the read-only row dump (production-case-dump case
                // `paid_flag_vs_invoice_rows`, encrypted artifact, IDs and booleans only) names the rows this counts (#5187).
                // No `sql`: PaidFlagInvoiceMismatch::run() leaves out side-B contracts the resolver holds for review.
                'census' => PaidFlagInvoiceMismatch::class,
            ],
            [
                // No `sql`: counted by InvoiceAmountParityCensus. Reported first; enforce after 14 nights at 0 (#4991 item 2).
                'key' => 'screen_amount_vs_resolver',
                'label' => 'Live bills whose header, line items and the amount the screens show (resolver) do not agree',
                'issue' => '#4991/#3521', 'enforced' => false,
                'census' => InvoiceAmountParityCensus::class,
            ],
            [
                // No `sql`: counted by ScheduleTeacherParityCensus. Reported first; enforce after 14 nights at 0 (#4991 item 7).
                'key' => 'lesson_teacher_disagreement',
                'label' => 'Taught lessons (last 35 days) whose evaluation author is not the teacher the schedule resolver names',
                'issue' => '#4991/#4901', 'enforced' => false,
                'census' => ScheduleTeacherParityCensus::class,
            ],
            [
                // Observe-only (never enforced): allowed by Founder decision #5198 option A; ids per campus via sessions:census-before-contract-start.
                'key' => 'lesson_before_contract_start',
                'label' => 'Lessons dated before their contract StartDate (allowed; shown as 合約期間外, in-app #536)',
                'issue' => '#5198', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM ClassSession cs JOIN StudentClass sc ON sc.ID = cs.StudentClassID
                          WHERE " . ContractPeriod::SQL_BEFORE_START . " AND LOWER(COALESCE(cs.Status, '')) NOT IN ('cancelled', 'voided')",
            ],
            [
                // #4991 line 3: ScheduleGuardService counts every live lesson of a running (Stop = 0) course as taken,
                // but a usage-locked course (StudentClass::isUsageSettlementLocked) can never teach it, so the slot is
                // blocked for nobody. Legacy closures outside the lock (settled, completed, settled_pending) are not counted.
                // Reported first; enforce after 14 nights at 0.
                'key' => 'seat_held_by_finished_contract',
                'label' => 'Upcoming lessons of a usage-locked running course that still block the teacher, room and student slot',
                'issue' => '#4991', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM ClassSession cs JOIN StudentClass sc ON sc.ID = cs.StudentClassID
                          WHERE (sc.Stop = 0 OR sc.Stop IS NULL)
                            AND (sc.settlement_locked_at IS NOT NULL OR sc.closed_reason IN ({$usageLocks}))
                            AND cs.SessionDate >= CAST(:app_today AS DATE)
                            AND LOWER(COALESCE(cs.Status, '')) NOT IN ({$freeingStatuses})",
            ],
            [
                // Founder 2026-11-28 (monthly billing: "charged twice or missed?"): candidates only, a person decides.
                // One student + subject + month on two live positive bills. A catch-up bill beside the month's own bill
                // is counted too; that is the point of looking. Observe-only: never enforced.
                'key' => 'monthly_period_billed_twice',
                'label' => 'Monthly contracts: one student, subject and billing month on more than one live bill (possible double charge)',
                'issue' => '#5708', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM (
                            SELECT sc.StudentID, sc.SubjectID, i.billing_period
                            FROM Invoice i JOIN StudentClass sc ON sc.ID = i.StudentClassID
                            WHERE sc.ScheduleMode = 'date' AND i.StudentID = sc.StudentID
                              AND i.billing_period IS NOT NULL AND i.TotalAmount > 0
                              AND (i.Status IS NULL OR i.Status NOT IN ('void', 'cancelled'))
                            GROUP BY sc.StudentID, sc.SubjectID, i.billing_period HAVING COUNT(*) > 1) t",
            ],
            [
                // A finished month (from 2026-08, when bills started to carry their month) in which a priced monthly
                // contract taught a lesson but has no live bill labelled with that month. A bill labelled with the
                // neighbouring month that covers the lesson by date is counted too (label mismatch). Observe-only.
                'key' => 'monthly_lessons_without_bill',
                'label' => 'Monthly contracts: finished months with a taught lesson and no live bill for that month (possible missed charge)',
                'issue' => '#5708', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM (
                            SELECT cs.StudentClassID, DATE_FORMAT(cs.SessionDate, '%Y-%m') AS ym
                            FROM ClassSession cs JOIN StudentClass sc ON sc.ID = cs.StudentClassID
                            WHERE sc.ScheduleMode = 'date' AND COALESCE(sc.PackageID, 0) = 0
                              AND LOWER(TRIM(COALESCE(sc.ClassType, ''))) <> 'tutoring'
                              AND (COALESCE(sc.Charge, 0) > 0 OR COALESCE(sc.Rate, 0) > 0)
                              AND COALESCE(sc.closed_reason, '') NOT IN ('waived', 'refunded')
                              AND LOWER(COALESCE(cs.Status, '')) IN ('attended', 'completed', 'late')
                              AND cs.SessionDate >= '2026-08-01'
                              AND cs.SessionDate < DATE_FORMAT(CAST(:app_today AS DATE), '%Y-%m-01')
                              AND NOT EXISTS (SELECT 1 FROM Invoice i WHERE i.StudentClassID = cs.StudentClassID
                                AND (i.Status IS NULL OR i.Status NOT IN ('void', 'cancelled'))
                                AND i.billing_period = DATE_FORMAT(cs.SessionDate, '%Y-%m'))
                            GROUP BY cs.StudentClassID, DATE_FORMAT(cs.SessionDate, '%Y-%m')) t",
            ],
        ];
    }

    /**
     * The census must never take the other conditions down with it: any failure (database error included) makes
     * this one condition INCOMPLETE with `census_error` = 1. Only the exception class goes to the server log.
     *
     * @return array<string,mixed>
     */
    private function census(string $class): array
    {
        try {
            return ['census_error' => 0] + app($class)->run();
        } catch (\Throwable $e) {
            Log::warning($class === InvoiceAmountParityCensus::class ? 'amount_parity.census_error' : 'reproduction.census_error', ['error' => $e::class, 'census' => class_basename($class)]);

            return ['census_error' => 1, 'mismatch_count' => 0, 'unevaluated' => 0, 'truncated' => false];
        }
    }

    public function handle(): int
    {
        $results = [];
        $regressed = 0;

        $conditions = $this->conditions();
        // One list of condition keys (SchedulerEvidence): the failed-run evidence reader accepts exactly this set, so
        // a condition added here without registering it there must not ship.
        if (array_column($conditions, 'key') !== SchedulerEvidence::BUG_CONDITION_CODES) {
            throw new \LogicException('bugs:verify-reproductions conditions differ from SchedulerEvidence::BUG_CONDITION_CODES');
        }

        foreach ($conditions as $cond) {
            $detail = isset($cond['sql']) ? null : $this->census($cond['census']);
            $count = $detail === null ? (int) (DB::selectOne(...AppClockSql::bind($cond['sql']))->c ?? 0) : $detail['mismatch_count'];
            // Known-baseline allowance (config/reproduction_baselines.php): an enforced condition fails only above it;
            // a listed null means the baseline is not measured yet (reported, never failing).
            $baseline = config('reproduction_baselines.' . $cond['key'], 0);
            $pending = $cond['enforced'] && $baseline === null;
            $isRegression = $cond['enforced'] && !$pending && $count > (int) $baseline;
            // An enforced census that could not run proves nothing: fail it instead of reporting 0.
            $isRegression = $isRegression || ($cond['enforced'] && ($detail['census_error'] ?? 0) > 0);
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
                    ? ($isRegression ? 'REGRESSED' : ($count === 0 ? 'FIXED-OK' : ($pending ? 'BASELINE-PENDING' : 'WITHIN-BASELINE')))
                    // A census cut short by a bound, or with a bill it could not evaluate, proves nothing about those bills.
                    : ($count > 0 ? 'OPEN' : (($detail['truncated'] ?? false) || ($detail['unevaluated'] ?? 0) > 0 || ($detail['census_error'] ?? 0) > 0 ? 'INCOMPLETE' : 'OPEN-CLEARED')),
                'label' => $cond['label'],
            ] + ($detail === null ? [] : ['unevaluated' => $detail['unevaluated'], 'census_error' => $detail['census_error'], 'detail' => $detail]);
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
