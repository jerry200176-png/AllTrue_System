<?php

namespace App\Services\Billing;

use App\Models\StudentClass;
use App\Services\Scheduling\ContractSessionSchedule;
use Illuminate\Support\Facades\DB;

/**
 * The one rule for the month label (Invoice.billing_period) of a monthly cycle (in-app #514). Mature billing
 * (Stripe, Chargebee) labels an invoice by its service period: the month of the cycle's first lesson, else the
 * cycle start month. A cycle 04-29 ~ 05-27 whose lessons are all in May is 2026-05. Callers must not derive the
 * month from the cycle start themselves.
 */
final class BillingPeriod
{
    /** @param iterable<string> $lessonDates Y-m-d dates inside the cycle, any order */
    public static function pick(iterable $lessonDates, string $cycleStart): string
    {
        $first = null;
        foreach ($lessonDates as $date) {
            $date = substr((string) $date, 0, 10);
            if ($date !== '' && ($first === null || $date < $first)) {
                $first = $date;
            }
        }

        return substr($first ?? $cycleStart, 0, 7);
    }

    /** Distinct lesson months of a cycle that is not yet scheduled, from the course's weekly slots, ascending. */
    public static function scheduledMonths(StudentClass $course, string $start, string $end): array
    {
        $slots = ContractSessionSchedule::resolveScheduleSlotsForRebuild($course);
        $built = $slots ? ContractSessionSchedule::buildSessionsFromWeeklySchedule(
            (int) $course->getKey(), $start, $end, $slots, max(30, (int) ($course->getAttribute('SessionDuration') ?? 120))) : [];

        return collect($built)->map(fn ($s) => substr((string) $s['SessionDate'], 0, 7))->unique()->sort()->values()->all();
    }

    /**
     * Label for a cycle about to be billed. A month this student+subject already carries a live bill for moves on to
     * the next lesson month of the cycle, so no month is billed twice.
     */
    public static function forCycle(StudentClass $course, string $start, string $end): string
    {
        $months = self::scheduledMonths($course, $start, $end) ?: [substr($start, 0, 7)];
        $taken = DB::table('Invoice as inv')->join('StudentClass as sc', 'sc.ID', '=', 'inv.StudentClassID')
            ->where('sc.StudentID', $course->getAttribute('StudentID'))->where('sc.SubjectID', $course->getAttribute('SubjectID'))
            ->whereIn('inv.billing_period', $months)
            ->where(fn ($q) => $q->whereNull('inv.Status')->orWhere('inv.Status', '<>', 'void'))->pluck('inv.billing_period')->all();
        foreach ($months as $month) {
            if (!in_array($month, $taken, true)) {
                return $month;
            }
        }

        return $months[0];
    }
}
