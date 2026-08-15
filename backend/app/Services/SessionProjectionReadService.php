<?php

namespace App\Services;

use App\Models\StudentClass;
use Carbon\Carbon;

/**
 * Read-only presenter: separates materialized ClassSession rows from projected slots.
 * Does not write to DB or call ClassSessionMaterializationService.
 */
class SessionProjectionReadService
{
    public const KIND_MATERIALIZED = 'materialized';

    public const KIND_PROJECTED = 'projected';

    public function slotKey(string $sessionDate, string $startTime): string
    {
        return substr($sessionDate, 0, 10) . '|' . substr($startTime, 0, 5);
    }

    /**
     * @return array<string, mixed>
     */
    public function materializedSlot(
        int $id,
        int $studentClassId,
        string $sessionDate,
        string $startTime,
        string $endTime,
        string $status
    ): array {
        return [
            'kind' => self::KIND_MATERIALIZED,
            'id' => $id,
            'student_class_id' => $studentClassId,
            'session_date' => substr($sessionDate, 0, 10),
            'start_time' => substr($startTime, 0, 5),
            'end_time' => substr($endTime, 0, 5),
            'status' => $status,
        ];
    }

    /**
     * Projected slots intentionally omit ClassSession id.
     *
     * @return array<string, mixed>
     */
    public function projectedSlot(
        int $studentClassId,
        string $sessionDate,
        string $startTime,
        string $endTime = ''
    ): array {
        return [
            'kind' => self::KIND_PROJECTED,
            'student_class_id' => $studentClassId,
            'session_date' => substr($sessionDate, 0, 10),
            'start_time' => substr($startTime, 0, 5),
            'end_time' => substr($endTime, 0, 5),
            'status' => 'projected',
        ];
    }

    /**
     * @param  iterable<object>  $sessionRows
     * @return list<array<string, mixed>>
     */
    public function collectMaterializedFromRows(
        iterable $sessionRows,
        int $classId,
        ?string $rangeStart = null,
        ?string $rangeEnd = null
    ): array {
        $out = [];
        $keys = [];
        foreach ($sessionRows as $row) {
            if ((int) ($row->StudentClassID ?? 0) !== $classId) {
                continue;
            }
            $status = strtolower((string) ($row->Status ?? ''));
            if ($status === 'cancelled') {
                continue;
            }
            $date = $row->SessionDate ? Carbon::parse($row->SessionDate)->toDateString() : null;
            if (!$date) {
                continue;
            }
            if ($rangeStart && $date < $rangeStart) {
                continue;
            }
            if ($rangeEnd && $date > $rangeEnd) {
                continue;
            }
            $start = substr((string) ($row->StartTime ?? '00:00'), 0, 5);
            $key = $this->slotKey($date, $start);
            if (isset($keys[$key])) {
                continue;
            }
            $keys[$key] = true;
            $out[] = $this->materializedSlot(
                (int) $row->id,
                $classId,
                $date,
                $start,
                substr((string) ($row->EndTime ?? ''), 0, 5),
                (string) ($row->Status ?? 'scheduled')
            );
        }

        usort($out, fn ($a, $b) => [$a['session_date'], $a['start_time']] <=> [$b['session_date'], $b['start_time']]);

        return $out;
    }

    /**
     * @param  list<string>  $effectiveDateList  Display-order dates after contract/quota logic.
     * @param  list<array<string, mixed>>  $materialized
     * @return list<array<string, mixed>>
     */
    public function buildProjectedFromEffectiveDates(
        int $classId,
        array $effectiveDateList,
        array $materialized,
        ?StudentClass $class = null
    ): array {
        $materializedDateSet = [];
        $materializedSlotSet = [];
        foreach ($materialized as $row) {
            $materializedDateSet[$row['session_date']] = true;
            $materializedSlotSet[$this->slotKey($row['session_date'], $row['start_time'])] = true;
        }

        $projected = [];
        foreach ($effectiveDateList as $date) {
            if (isset($materializedDateSet[$date])) {
                continue;
            }
            $times = $this->resolveSlotTimesForCourseDate($class, $date);
            $slot = $this->projectedSlot($classId, $date, $times['start'], $times['end']);
            $key = $this->slotKey($slot['session_date'], $slot['start_time']);
            if (isset($materializedSlotSet[$key])) {
                continue;
            }
            $projected[] = $slot;
        }

        return $projected;
    }

    /**
     * @param  list<array<string, mixed>>  $materialized
     * @param  list<array<string, mixed>>  $projected
     * @return array{materialized: list<array<string, mixed>>, projected: list<array<string, mixed>>}
     */
    public function wrapCourseSplit(array $materialized, array $projected): array
    {
        return [
            'materialized' => $materialized,
            'projected' => $projected,
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $materializedByClass
     * @return array<string, list<array<string, mixed>>>
     */
    public function buildProjectedByClassForMonthlyCourses(
        iterable $classes,
        string $rangeStart,
        string $rangeEnd,
        array $materializedByClass,
        callable $monthlyDatesResolver
    ): array {
        /** @var array<string, list<array<string, mixed>>> $projectedByClass */
        $projectedByClass = [];
        foreach ($classes as $class) {
            $classId = (int) ($class->ID ?? 0);
            if ($classId <= 0 || (string) ($class->ScheduleMode ?? '') !== 'date') {
                continue;
            }
            $classKey = (string) $classId;
            $materialized = array_key_exists($classKey, $materializedByClass)
                ? $materializedByClass[$classKey]
                : [];
            $existingDateSet = [];
            foreach ($materialized as $row) {
                $existingDateSet[$row['session_date']] = true;
            }

            $effectiveDates = $monthlyDatesResolver($class, $rangeStart, $rangeEnd, $existingDateSet);
            $projectedByClass[(string) $classId] = $this->buildProjectedFromEffectiveDates(
                $classId,
                $effectiveDates,
                $materialized,
                $class
            );
        }

        return $projectedByClass;
    }

    /**
     * @return array{start: string, end: string}
     */
    public function resolveSlotTimesForCourseDate(?StudentClass $class, string $date): array
    {
        if (!$class) {
            return ['start' => '00:00', 'end' => ''];
        }

        $dow = (int) Carbon::parse($date)->dayOfWeekIso;
        $pairs = [
            ['week', 'time', 'SessionDuration'],
            ['week1', 'time1', 'duration1'],
            ['week2', 'time2', 'duration2'],
            ['week3', 'time3', 'duration3'],
            ['week4', 'time4', 'duration4'],
            ['week5', 'time5', 'duration5'],
            ['week6', 'time6', 'duration6'],
        ];

        foreach ($pairs as [$weekField, $timeField, $durationField]) {
            if ((int) ($class->{$weekField} ?? 0) !== $dow) {
                continue;
            }
            $start = substr(trim((string) ($class->{$timeField} ?? '')), 0, 5);
            if ($start === '') {
                continue;
            }
            $durationMinutes = (int) ($class->{$durationField} ?? $class->SessionDuration ?? 120);
            if ($durationMinutes <= 0) {
                $durationMinutes = 120;
            }

            return [
                'start' => $start,
                'end' => $this->addMinutesToHm($start, $durationMinutes),
            ];
        }

        $fallbackStart = substr(trim((string) ($class->time ?? '16:00')), 0, 5) ?: '16:00';
        $durationMinutes = (int) ($class->SessionDuration ?? 120);
        if ($durationMinutes <= 0) {
            $durationMinutes = 120;
        }

        return [
            'start' => $fallbackStart,
            'end' => $this->addMinutesToHm($fallbackStart, $durationMinutes),
        ];
    }

    private function addMinutesToHm(string $hm, int $minutes): string
    {
        if (!preg_match('/^\d{2}:\d{2}$/', $hm)) {
            return '';
        }
        [$hh, $mm] = array_map('intval', explode(':', $hm));
        $total = $hh * 60 + $mm + $minutes;
        $endH = intdiv($total, 60) % 24;
        $endM = $total % 60;

        return sprintf('%02d:%02d', $endH, $endM);
    }
}
