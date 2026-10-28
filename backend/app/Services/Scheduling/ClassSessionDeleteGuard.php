<?php

namespace App\Services\Scheduling;

use Illuminate\Support\Facades\DB;

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
