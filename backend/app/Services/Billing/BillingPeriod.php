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

    /**
     * Of $dates (Y-m-d lessons of this student+subject), those a live bill already covers: the service range of its item(s) owned by
     * one of this student+subject's contracts holds the date (MonthlyBillingService::serviceRangeForCourse, the single-unowned-item
     * fallback included), or, when the bill has no dated item at all, its billing_period month does. The label alone never covers a
     * date outside a bill's own range, and another subject's item on a shared bill never covers this subject's lesson.
     *
     * @param list<string> $dates @return list<string>
     */
    public static function datesCoveredByBills(\Illuminate\Database\Eloquent\Model $course, array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        $mine = StudentClass::query()->where('StudentID', $course->getAttribute('StudentID'))->where('SubjectID', $course->getAttribute('SubjectID'))
            ->pluck('ID')->map(fn ($id) => (int) $id)->all();
        $invoices = \App\Models\Invoice::query()->notVoided()->with('items')
            ->where(fn ($q) => $q->whereIn('StudentClassID', $mine)->orWhereHas('items', fn ($i) => $i->whereIn('StudentClassID', $mine)))->get();
        $billing = app(\App\Services\MonthlyBillingService::class);
        $spans = [];   // [from, to]
        $labels = [];  // months of bills with no dated item
        foreach ($invoices as $inv) {
            $found = false;
            foreach ($mine as $classId) {
                [$from, $to] = $billing->serviceRangeForCourse($inv, $classId);
                if ($from !== null && $to !== null) {
                    $spans[] = [$from, $to];
                    $found = true;
                }
            }
            if (!$found && !$inv->items->contains(fn ($i) => $i->PeriodStart && $i->PeriodEnd) && in_array((int) $inv->StudentClassID, $mine, true) && $inv->billing_period) {
                $labels[(string) $inv->billing_period] = true;
            }
        }

        return array_values(array_filter($dates, fn (string $d): bool => isset($labels[substr($d, 0, 7)])
            || collect($spans)->contains(fn ($r) => $r[0] <= $d && $d <= $r[1])));
    }
}
