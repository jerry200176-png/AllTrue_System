<?php

namespace App\Services\Scheduling;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #4455 (class C): which lessons a delete path must never remove.
 *
 * Neither ledger has a foreign key on class_session_id. Deleting a ClassSession leaves its
 * session_deduction_ledger / package_session_ledger rows pointing at nothing, and a rebuild that
 * re-creates the date gets a fresh, undebited row, so the course and package balances no longer
 * match the lessons on the calendar. The ledgers are append-only (Stripe balance transactions,
 * TigerBeetle): a referenced lesson never disappears, it is corrected by a reversal.
 *
 * A lesson is protected when
 *  - its course ledger net (deduct - reverse) is not zero, or
 *  - its package ledger net (SUM(delta)) is not zero, or
 *  - its status was marked by a person (ContractSessionSchedule::ATTENDANCE_MARKED_STATUSES, the list
 *    the slot-only course edit already refuses to rebuild over).
 *
 * `completed` alone is not protected: the start-date rebuild itself writes it for past dates without a
 * debit, so protecting it would make a never-taught backdated course impossible to re-date. A
 * `completed` lesson that was debited is protected by its ledger net.
 */
final class ClassSessionDeleteGuard
{
    /**
     * Sessions whose course or package ledger net is not zero.
     *
     * @param  array<int|string>  $sessionIds
     * @return array<int, true> class_session_id => true
     */
    public static function ledgerBearingSessionIds(array $sessionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $sessionIds), fn (int $id) => $id > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $course = DB::table('session_deduction_ledger')
                ->whereIn('class_session_id', $chunk)
                ->groupBy('class_session_id')
                ->havingRaw("SUM(CASE WHEN event_type = 'deduct' THEN 1 WHEN event_type = 'reverse' THEN -1 ELSE 0 END) <> 0")
                ->pluck('class_session_id');
            $package = DB::table('package_session_ledger')
                ->whereIn('class_session_id', $chunk)
                ->groupBy('class_session_id')
                ->havingRaw('SUM(delta) <> 0')
                ->pluck('class_session_id');
            foreach ($course->merge($package) as $id) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }

    /**
     * Every column that points at a lesson (schedule_audit_logs aside: it records every row, and keeps the delete).
     * `parent_session_id` columns hold a parent's login session, not a lesson, so they are not listed.
     */
    private const REFERENCES = [
        'StudentSingIn' => ['ClassSessionID', 'class_session_id'], 'LearningRecord' => ['ClassSessionID'],
        'class_session_reassignments' => ['class_session_id'], 'exception_workflows' => ['class_session_id'],
        'exception_workflow_candidates' => ['class_session_id'],
        'learning_record_feedbacks' => ['class_session_id'], 'learning_record_teacher_comments' => ['class_session_id'],
        'package_session_ledger' => ['class_session_id'], 'session_deduction_ledger' => ['class_session_id'],
        'payroll_runs' => ['class_session_id'], 'payroll_run_lines' => ['class_session_id'], 'schedule_discrepancies' => ['class_session_id'],
        'session_charge_backfill_log' => ['class_session_id'], 'session_corrections' => ['session_id', 'replaced_by_session_id'],
        'session_coverages' => ['class_session_id'], 'session_entitlement_transfers' => ['class_session_id'],
        'truefit_diagnoses' => ['class_session_id'], 'truefit_lesson_preps' => ['class_session_id'],
        'truefit_mastery_evidence' => ['class_session_id'], 'truefit_observations' => ['class_session_id'], 'truefit_remediations' => ['class_session_id'],
    ];

    /**
     * Lessons any other row points at, whatever its value: a sign-in, a ledger row (even netted to zero), a
     * substitute, a note, a payroll line. Only a lesson with none of these never happened anywhere (#4734).
     *
     * @param  array<int|string>  $sessionIds
     * @return array<int, true> class_session_id => true
     */
    public static function referencedSessionIds(array $sessionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $sessionIds), fn (int $id) => $id > 0)));
        $out = [];
        foreach (self::REFERENCES as $table => $columns) {
            foreach ($columns as $column) {
                if ($ids === [] || !Schema::hasColumn($table, $column)) {
                    continue;
                }
                foreach (array_chunk($ids, 500) as $chunk) {
                    foreach (DB::table($table)->whereIn($column, $chunk)->distinct()->pluck($column) as $id) {
                        $out[(int) $id] = true;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Lessons whose course has a dated `schedules` row on that day: a substitute (ClassSessionController::substitute writes
     * the substitute and the original teacher's rows by course and date, not by lesson id), a move or a make-up.
     *
     * @param  iterable<\App\Models\ClassSession>  $sessions
     * @return array<int, true> class_session_id => true
     */
    public static function datedScheduleSessionIds(iterable $sessions): array
    {
        $byDay = [];
        foreach ($sessions as $s) {
            $byDay[(int) $s->StudentClassID . '|' . substr((string) $s->SessionDate, 0, 10)][] = (int) $s->id;
        }
        if ($byDay === []) {
            return [];
        }
        $courses = array_values(array_unique(array_map(fn (string $k) => (int) explode('|', $k)[0], array_keys($byDay))));
        $out = [];
        foreach (array_chunk($courses, 500) as $chunk) {
            foreach (DB::table('schedules')->whereIn('student_course_id', $chunk)->whereNotNull('schedule_date')
                ->get(['student_course_id', 'schedule_date']) as $row) {
                foreach ($byDay[(int) $row->student_course_id . '|' . substr((string) $row->schedule_date, 0, 10)] ?? [] as $id) {
                    $out[$id] = true;
                }
            }
        }

        return $out;
    }

    /**
     * The lessons of one course that must be kept: ledger-bearing or attendance-marked.
     *
     * @return array<int, true> class_session_id => true
     */
    public static function protectedSessionIds(int $studentClassId): array
    {
        if ($studentClassId <= 0) {
            return [];
        }
        $rows = DB::table('ClassSession')->where('StudentClassID', $studentClassId)->get(['id', 'Status']);
        $out = self::ledgerBearingSessionIds($rows->pluck('id')->all());
        foreach ($rows as $row) {
            if (in_array(strtolower((string) $row->Status), ContractSessionSchedule::ATTENDANCE_MARKED_STATUSES, true)) {
                $out[(int) $row->id] = true;
            }
        }

        return $out;
    }

    /**
     * A course edit must not delete-and-rebuild a course's lessons when any of them is protected or the
     * course has the sign-in / approved-record history hasImmutableSessionHistory() already guards.
     */
    public static function courseHasProtectedHistory(int $studentClassId): bool
    {
        return ContractSessionSchedule::hasImmutableSessionHistory($studentClassId)
            || self::protectedSessionIds($studentClassId) !== [];
    }
}
