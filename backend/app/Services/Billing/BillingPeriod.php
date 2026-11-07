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

    /** Dates of a cycle that is not yet scheduled, from the course's weekly slots, ascending. */
    public static function scheduledDates(StudentClass $course, string $start, string $end): array
    {
        $slots = ContractSessionSchedule::resolveScheduleSlotsForRebuild($course);
        $built = $slots ? ContractSessionSchedule::buildSessionsFromWeeklySchedule(
            (int) $course->getKey(), $start, $end, $slots, max(30, (int) ($course->getAttribute('SessionDuration') ?? 120))) : [];

        return collect($built)->map(fn ($s) => substr((string) $s['SessionDate'], 0, 10))->unique()->sort()->values()->all();
    }

    /**
     * Label for a cycle about to be billed. A month this student+subject already carries a live bill for moves on to
     * the next lesson month of the cycle, else the cycle start month, else (all taken) the first lesson month.
     */
    public static function forCycle(StudentClass $course, string $start, string $end): string
    {
        $dates = self::scheduledDates($course, $start, $end);
        $first = self::pick($dates, $start); // the rule itself: first lesson month, else the start month
        $months = array_values(array_unique([$first, ...array_map(fn ($d) => substr($d, 0, 7), $dates)]));
        // every lesson month already billed (e.g. by a catch-up): fall back to the cycle start month, today's label, before sharing a month
        $candidates = array_values(array_unique([...$months, substr($start, 0, 7)]));
        $taken = DB::table('Invoice as inv')->join('StudentClass as sc', 'sc.ID', '=', 'inv.StudentClassID')
            ->where('sc.StudentID', $course->getAttribute('StudentID'))->where('sc.SubjectID', $course->getAttribute('SubjectID'))
            ->whereIn('inv.billing_period', $candidates)
            ->where(fn ($q) => $q->whereNull('inv.Status')->orWhere('inv.Status', '<>', 'void'))->pluck('inv.billing_period')->all();
        foreach ($candidates as $month) {
            if (!in_array($month, $taken, true)) {
                return $month;
            }
        }

        return $months[0]; // all taken: share the first lesson month, as catch-up plus renewal bills already do
    }

    /**
     * Of $dates (Y-m-d lessons of this student+subject), those a live bill already covers: one of its dated items owned by one of this student+subject's
     * contracts holds the date (MonthlyBillingService::serviceRangeForCourse ownership, but a lone item only while it has no owner; item ranges are not merged, so a gap stays uncovered), or, when the bill has no dated item at all, its billing_period month does. The label alone never covers a
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
        $spans = [];   // individual owned item ranges: a gap between two items stays uncovered
        $labels = [];  // months of bills with no dated item
        foreach ($invoices as $inv) {
            $bounded = $inv->items->filter(fn ($i) => $i->PeriodStart && $i->PeriodEnd);
            $found = false;
            foreach ($mine as $classId) {
                // MonthlyBillingService::serviceRangeForCourse ownership: items linked to the contract, else the lone unowned item
                $linked = $bounded->filter(fn ($i) => (int) ($i->StudentClassID ?? 0) === $classId);
                foreach ($linked->isNotEmpty() ? $linked : ($inv->items->count() === 1
                    ? $bounded->filter(fn ($i) => !(int) ($i->StudentClassID ?? 0)) // a lone item counts only while unowned: another subject's never covers
                    : collect()) as $i) {
                    $spans[] = [substr((string) $i->PeriodStart, 0, 10), substr((string) $i->PeriodEnd, 0, 10)];
                    $found = true;
                }
            }
            if (!$found && $bounded->isEmpty() && in_array((int) $inv->StudentClassID, $mine, true) && $inv->billing_period) {
                $labels[(string) $inv->billing_period] = true;
            }
        }

        return array_values(array_filter($dates, fn (string $d): bool => isset($labels[substr($d, 0, 7)])
            || collect($spans)->contains(fn ($r) => $r[0] <= $d && $d <= $r[1])));
    }
}
