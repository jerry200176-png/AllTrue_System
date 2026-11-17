<?php

namespace App\Services\Billing;

use App\Models\{ClassSession, ExistingCourseCreditAllocationGroup as Group, PaymentReport, StudentClass};
use Carbon\Carbon;

/** The count-course receipt projection, retained before an allocation releases its future reservations. */
final class CountCourseReceiptCalendar
{
    public function read(StudentClass $course, ?PaymentReport $report = null): array
    {
        if ($report && $report->status === 'confirmed' && Group::storageReady()) {
            $groups = Group::where('source_student_class_id', $course->ID)->where('student_id', $course->StudentID)->orderBy('id')->get(['source_before']);
            foreach ($groups as $group) {
                $saved = $group->getAttribute('source_before')['receipt_calendars'][$report->id] ?? null;
                if (is_array($saved)) return $saved;
            }
        }
        $attended = ClassSession::where('StudentClassID', $course->ID)->whereIn('Status', ['attended', 'completed', 'late'])
            ->orderBy('SessionDate')->pluck('SessionDate')->map(fn ($d) => Carbon::parse((string) $d)->format('Y/m/d'))->all();
        $dates = array_map(fn ($d) => ['date' => $d, 'expected' => false], $attended);
        $purchased = (int) $course->SessionCount;
        $needed = $purchased > 0 ? max(0, $purchased - count($attended)) : null;
        if ($needed === null || $needed > 0) {
            $upcoming = ClassSession::where('StudentClassID', $course->ID)->whereIn('Status', ['scheduled', 'rescheduled'])
                ->orderBy('SessionDate')->pluck('SessionDate')->map(fn ($d) => Carbon::parse((string) $d)->format('Y/m/d'))->all();
            foreach ($needed === null ? $upcoming : array_slice($upcoming, 0, $needed) as $d) $dates[] = ['date' => $d, 'expected' => true];
        }
        // #232: dated lessons, rather than stale count-contract metadata, determine the printed period.
        $ordered = collect($dates)->pluck('date')->sort()->values();
        return ['attended_dates' => $attended, 'session_dates' => $dates,
            'period_start' => $ordered->first() ?? ($course->StartDate ? Carbon::parse($course->StartDate)->format('Y/m/d') : null),
            'period_end' => $ordered->last() ?? ($course->EndDate ? Carbon::parse($course->EndDate)->format('Y/m/d') : null)];
    }
}
