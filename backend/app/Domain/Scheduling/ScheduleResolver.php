<?php

namespace App\Domain\Scheduling;

use App\Models\ClassSession;
use App\Models\Schedule;
use Carbon\Carbon;

/**
 * Read-only mirror of schedule occurrence resolution.
 *
 * Mirrors (partial): ClassSession rows + schedules exceptions for a course/date range.
 * Does NOT replicate full calendarOccurrenceMerge.js or ScheduleGuardService merge —
 * those are documented in shadow-domain-validation.md as known gaps.
 *
 * Legacy references:
 * - StudentClassController::sessionDates()
 * - ScheduleGuardService::buildTeacherDateOccupancyEntries()
 */
class ScheduleResolver
{
    /**
     * Load materialized ClassSession occurrences for a course in a date range.
     *
     * @return list<ScheduleOccurrence>
     */
    public function materializedSessions(int $courseId, string $rangeStart, string $rangeEnd): array
    {
        if ($courseId <= 0) {
            return [];
        }

        $rows = ClassSession::query()
            ->where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '>=', $rangeStart)
            ->whereDate('SessionDate', '<=', $rangeEnd)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get(['id', 'SessionDate', 'StartTime', 'Status']);

        $out = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->SessionDate)->toDateString();
            $out[] = new ScheduleOccurrence(
                courseId: $courseId,
                date: $date,
                startTime: substr((string) $row->StartTime, 0, 5),
                source: 'class_session',
                status: (string) ($row->Status ?? 'scheduled'),
                classSessionId: (int) $row->id,
            );
        }

        return $out;
    }

    /**
     * Load schedules-table exceptions for a course in a date range.
     *
     * @return list<ScheduleOccurrence>
     */
    public function scheduleExceptions(int $courseId, string $rangeStart, string $rangeEnd): array
    {
        if ($courseId <= 0) {
            return [];
        }

        $rows = Schedule::query()
            ->where('student_course_id', $courseId)
            ->whereDate('schedule_date', '>=', $rangeStart)
            ->whereDate('schedule_date', '<=', $rangeEnd)
            ->orderBy('schedule_date')
            ->orderBy('start_time')
            ->get(['id', 'schedule_date', 'start_time', 'status']);

        $out = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->schedule_date)->toDateString();
            $out[] = new ScheduleOccurrence(
                courseId: $courseId,
                date: $date,
                startTime: substr((string) $row->start_time, 0, 5),
                source: 'schedules',
                status: (string) ($row->status ?? ''),
                scheduleId: (int) $row->id,
            );
        }

        return $out;
    }

    /**
     * Mirror of StudentClassController::computeEffectiveSessionDates (count mode projection).
     * Legacy: StudentClassController.php L935–955
     *
     * @param  list<int>  $daysOfWeek  ISO 1=Mon … 7=Sun
     * @param  array<string, true>  $leaveSet
     * @param  array<string, true>  $scheduledSet
     * @return list<string>  Y-m-d dates
     */
    public function computeEffectiveSessionDates(
        string $startDate,
        int $n,
        array $daysOfWeek,
        array $leaveSet,
        array $scheduledSet
    ): array {
        $list = [];
        $d = Carbon::parse($startDate . ' 12:00:00');
        $end = $d->copy()->addYears(2);
        while ($d <= $end && count($list) < $n) {
            $ymd = $d->toDateString();
            $dow = $d->dayOfWeekIso;
            $isRegular = in_array($dow, $daysOfWeek, true);
            $isLeave = isset($leaveSet[$ymd]);
            $isScheduledExtra = isset($scheduledSet[$ymd]);

            if ($isRegular && !$isLeave) {
                $list[] = $ymd;
            } elseif ($isScheduledExtra && !$isRegular) {
                $list[] = $ymd;
            }
            $d->addDay();
        }

        return array_slice($list, 0, $n);
    }

    /**
     * Mirror of StudentClassController::buildSessionsFromWeeklySchedule weekday loop.
     * Weekday accepts ISO 1-7 or legacy JS 0-6 (0=Sunday → 7); compared against
     * dayOfWeekIso. Historical dayOfWeek (0=Sun) compare dropped ISO-Sunday slots
     * (fixed with GitHub #1096).
     *
     * @param  list<array{weekday: int, time: string}>  $slots
     * @return list<array{SessionDate: string, StartTime: string}>
     */
    public function buildSessionsFromWeeklyScheduleLegacy(
        string $startDate,
        string $endDate,
        array $slots
    ): array {
        $out = [];
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            foreach ($slots as $slot) {
                $slotIso = (int) $slot['weekday'] === 0 ? 7 : (int) $slot['weekday'];
                if ((int) $date->dayOfWeekIso === $slotIso) {
                    $out[] = [
                        'SessionDate' => $date->toDateString(),
                        'StartTime' => (string) ($slot['time'] ?? ''),
                    ];
                }
            }
        }

        return $out;
    }
}
