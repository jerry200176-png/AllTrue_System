<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleGuardService
{
    /** @var array<string, int> */
    private array $classCapacityMap = [
        'one_on_one' => 1,
        'one_on_two' => 2,
        'one_on_three' => 3,
        'tutoring' => 4,
        'trial' => 1,
    ];

    /** @var array<int, object|null> */
    private array $roomCache = [];

    /**
     * Validate recurring course slots against existing teacher/room load.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function validateRecurringCourse(array $payload): array
    {
        $teacherId = (int) ($payload['teacher_id'] ?? 0);
        $branchId = (int) ($payload['branch_id'] ?? 0);
        if ($teacherId <= 0 || $branchId <= 0) {
            return [];
        }

        $slots = $this->normalizeSlots($payload['slots'] ?? []);
        if (empty($slots)) {
            return [];
        }

        $classType = (string) ($payload['class_type'] ?? 'one_on_one');
        $roomId = isset($payload['room_id']) && $payload['room_id'] ? (int) $payload['room_id'] : null;
        $excludeStudentClassId = isset($payload['exclude_student_class_id']) && $payload['exclude_student_class_id']
            ? (int) $payload['exclude_student_class_id']
            : null;

        $teacherCourses = $this->loadTeacherRecurringCourses($teacherId, $branchId, $excludeStudentClassId);
        $conflicts = [];

        foreach ($slots as $slot) {
            $overlaps = $this->collectRecurringOverlaps($teacherCourses, $slot);

            $teacherConflict = $this->buildTeacherCapacityConflict($classType, $slot, $overlaps);
            if ($teacherConflict) {
                $conflicts[] = $teacherConflict;
            }

            $roomConflict = $this->buildRoomCapacityConflict($roomId, $slot, $overlaps);
            if ($roomConflict) {
                $conflicts[] = $roomConflict;
            }
        }

        return $conflicts;
    }

    /**
     * Validate one concrete schedule occurrence (e.g. rescheduled target slot).
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function validateScheduleOccurrence(array $payload): array
    {
        $teacherId = (int) ($payload['teacher_id'] ?? 0);
        $branchId = (int) ($payload['branch_id'] ?? 0);
        if ($teacherId <= 0 || $branchId <= 0) {
            return [];
        }

        $scheduleDate = $payload['schedule_date'] ?? null;
        if (!$scheduleDate) {
            return [];
        }

        $startTime = $this->normalizeTime($payload['start_time'] ?? null);
        $endTime = $this->normalizeTime($payload['end_time'] ?? null);
        if (!$startTime || !$endTime) {
            return [];
        }

        $date = Carbon::parse((string) $scheduleDate)->toDateString();
        $dayOfWeek = (int) Carbon::parse($date)->dayOfWeekIso;
        $slot = [
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'schedule_date' => $date,
        ];

        $classType = (string) ($payload['class_type'] ?? 'one_on_one');
        $roomId = isset($payload['room_id']) && $payload['room_id'] ? (int) $payload['room_id'] : null;
        $excludeScheduleId = isset($payload['exclude_schedule_id']) && $payload['exclude_schedule_id']
            ? (int) $payload['exclude_schedule_id']
            : null;

        $entries = $this->buildTeacherDateOccupancyEntries($teacherId, $branchId, $date, $excludeScheduleId);
        $overlaps = array_values(array_filter($entries, function ($entry) use ($startTime, $endTime) {
            return $this->timesOverlap($startTime, $endTime, (string) $entry['start_time'], (string) $entry['end_time']);
        }));

        $conflicts = [];

        $teacherConflict = $this->buildTeacherCapacityConflict($classType, $slot, $overlaps);
        if ($teacherConflict) {
            $conflicts[] = $teacherConflict;
        }

        $roomConflict = $this->buildRoomCapacityConflict($roomId, $slot, $overlaps);
        if ($roomConflict) {
            $conflicts[] = $roomConflict;
        }

        return $conflicts;
    }

    private function capacityForClassType(?string $classType): int
    {
        $key = (string) ($classType ?: 'one_on_one');
        return $this->classCapacityMap[$key] ?? 1;
    }

    private function classTypeLabel(?string $classType): string
    {
        return match ((string) $classType) {
            'one_on_one' => '一對一',
            'one_on_two' => '一對二',
            'one_on_three' => '一對三',
            'tutoring' => '輔導',
            'trial' => '試聽',
            default => (string) ($classType ?: '課程'),
        };
    }

    /**
     * @param  array<int, mixed>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSlots(array $slots): array
    {
        $normalized = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $dow = (int) ($slot['day_of_week'] ?? 0);
            if ($dow === 0) {
                $dow = 7;
            }
            if ($dow < 1 || $dow > 7) {
                continue;
            }

            $start = $this->normalizeTime($slot['start_time'] ?? null);
            $end = $this->normalizeTime($slot['end_time'] ?? null);
            if (!$start || !$end) {
                continue;
            }

            $normalized[] = [
                'day_of_week' => $dow,
                'start_time' => $start,
                'end_time' => $end,
            ];
        }

        return $normalized;
    }

    private function normalizeTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $matches)) {
            $h = max(0, min(23, (int) $matches[1]));
            $m = max(0, min(59, (int) $matches[2]));
            return sprintf('%02d:%02d', $h, $m);
        }

        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function addMinutes(string $time, int $minutes): string
    {
        return Carbon::createFromFormat('H:i', $time)->addMinutes($minutes)->format('H:i');
    }

    /**
     * @return array<int, object>
     */
    private function loadTeacherRecurringCourses(int $teacherId, int $branchId, ?int $excludeStudentClassId = null): array
    {
        $query = DB::table('StudentClass as sc')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('sc.TeacherID', $teacherId)
            ->where('sc.Stop', 0)
            ->where('st.CampusID', $branchId)
            ->select([
                'sc.ID',
                'sc.StudentID',
                'sc.ClassType',
                'sc.room_id',
                'sc.SessionDuration',
                'sc.StartDate',
                'sc.EndDate',
                'sc.week',
                'sc.week1',
                'sc.week2',
                'sc.week3',
                'sc.week4',
                'sc.week5',
                'sc.week6',
                'sc.time',
                'sc.time1',
                'sc.time2',
                'sc.time3',
                'sc.time4',
                'sc.time5',
                'sc.time6',
            ]);

        if ($excludeStudentClassId) {
            $query->where('sc.ID', '!=', $excludeStudentClassId);
        }

        return $query->get()->all();
    }

    /**
     * @param  array<int, object>  $teacherCourses
     * @param  array<string, mixed>  $slot
     * @return array<int, array<string, mixed>>
     */
    private function collectRecurringOverlaps(array $teacherCourses, array $slot): array
    {
        $overlaps = [];
        foreach ($teacherCourses as $course) {
            foreach ($this->extractCourseSlots($course) as $existingSlot) {
                if ((int) $existingSlot['day_of_week'] !== (int) $slot['day_of_week']) {
                    continue;
                }

                if (!$this->timesOverlap(
                    (string) $slot['start_time'],
                    (string) $slot['end_time'],
                    (string) $existingSlot['start_time'],
                    (string) $existingSlot['end_time']
                )) {
                    continue;
                }

                $overlaps[] = [
                    'source' => 'student_class',
                    'source_id' => (int) ($course->ID ?? 0),
                    'student_id' => (int) ($course->StudentID ?? 0),
                    'class_type' => (string) ($course->ClassType ?? 'one_on_one'),
                    'room_id' => $course->room_id ? (int) $course->room_id : null,
                    'start_time' => (string) $existingSlot['start_time'],
                    'end_time' => (string) $existingSlot['end_time'],
                ];
            }
        }

        return $overlaps;
    }

    /**
     * @param  object  $course
     * @return array<int, array<string, mixed>>
     */
    private function extractCourseSlots(object $course): array
    {
        $durationMinutes = max(30, (int) ($course->SessionDuration ?? 120));
        $weekFields = ['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'];
        $timeFields = ['time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6'];

        $slots = [];
        $seen = [];

        foreach ($weekFields as $idx => $weekField) {
            $dow = (int) ($course->{$weekField} ?? 0);
            if ($dow < 1 || $dow > 7) {
                continue;
            }

            $start = $this->normalizeTime($course->{$timeFields[$idx]} ?? null);
            if (!$start) {
                continue;
            }

            $end = $this->addMinutes($start, $durationMinutes);
            $key = $dow . '|' . $start . '|' . $end;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $slots[] = [
                'day_of_week' => $dow,
                'start_time' => $start,
                'end_time' => $end,
            ];
        }

        return $slots;
    }

    /**
     * @param  array<int, array<string, mixed>>  $overlaps
     * @return array<string, mixed>|null
     */
    private function buildTeacherCapacityConflict(string $newClassType, array $slot, array $overlaps): ?array
    {
        $existingCount = $this->countDistinctStudents($overlaps);
        $newCapacity = $this->capacityForClassType($newClassType);
        $overlapDetails = $this->buildOverlapDetails($overlaps);
        $overlapSummary = $this->buildOverlapSummary($overlapDetails);

        if ($existingCount >= $newCapacity) {
            return [
                'type' => 'teacher_capacity',
                'day_of_week' => (int) ($slot['day_of_week'] ?? 0),
                'start_time' => (string) ($slot['start_time'] ?? ''),
                'end_time' => (string) ($slot['end_time'] ?? ''),
                'current_students' => $existingCount,
                'allowed_students' => $newCapacity,
                'message' => sprintf(
                    '老師此時段已有 %d 位學生，%s 上限為 %d 位學生。',
                    $existingCount,
                    $this->classTypeLabel($newClassType),
                    $newCapacity
                ),
                'overlap_summary' => $overlapSummary,
                'overlap_details' => $overlapDetails,
            ];
        }

        foreach ($overlaps as $entry) {
            $existingType = (string) ($entry['class_type'] ?? 'one_on_one');
            $existingCapacity = $this->capacityForClassType($existingType);
            if ($existingCount >= $existingCapacity) {
                return [
                    'type' => 'teacher_capacity',
                    'day_of_week' => (int) ($slot['day_of_week'] ?? 0),
                    'start_time' => (string) ($slot['start_time'] ?? ''),
                    'end_time' => (string) ($slot['end_time'] ?? ''),
                    'current_students' => $existingCount,
                    'allowed_students' => $existingCapacity,
                    'message' => sprintf(
                        '老師此時段已有 %d 位學生，既有%s課型上限為 %d 位學生。',
                        $existingCount,
                        $this->classTypeLabel($existingType),
                        $existingCapacity
                    ),
                    'overlap_summary' => $overlapSummary,
                    'overlap_details' => $overlapDetails,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $overlaps
     * @return array<string, mixed>|null
     */
    private function buildRoomCapacityConflict(?int $roomId, array $slot, array $overlaps): ?array
    {
        if (!$roomId) {
            return null;
        }

        $room = $this->loadRoom($roomId);
        if (!$room) {
            return null;
        }

        $totalCapacity = max(0, (int) ($room->capacity ?? 0));
        $studentCapacity = max(0, $totalCapacity - 1); // Reserve one seat for teacher.
        $sameRoomOverlaps = array_filter($overlaps, function ($entry) use ($roomId) {
            return (int) ($entry['room_id'] ?? 0) === $roomId;
        });
        $currentStudents = $this->countDistinctStudents(array_values($sameRoomOverlaps));
        $overlapDetails = $this->buildOverlapDetails(array_values($sameRoomOverlaps));
        $overlapSummary = $this->buildOverlapSummary($overlapDetails);

        if ($currentStudents >= $studentCapacity) {
            return [
                'type' => 'room_capacity',
                'room_id' => $roomId,
                'room_name' => (string) ($room->name ?? ('#' . $roomId)),
                'day_of_week' => (int) ($slot['day_of_week'] ?? 0),
                'start_time' => (string) ($slot['start_time'] ?? ''),
                'end_time' => (string) ($slot['end_time'] ?? ''),
                'current_students' => $currentStudents,
                'allowed_students' => $studentCapacity,
                'message' => sprintf(
                    '教室 %s 容量為 %d（含老師），此時段最多可安排 %d 位學生。',
                    (string) ($room->name ?? ('#' . $roomId)),
                    $totalCapacity,
                    $studentCapacity
                ),
                'overlap_summary' => $overlapSummary,
                'overlap_details' => $overlapDetails,
            ];
        }

        return null;
    }

    private function loadRoom(int $roomId): ?object
    {
        if (!array_key_exists($roomId, $this->roomCache)) {
            $this->roomCache[$roomId] = DB::table('rooms')
                ->where('id', $roomId)
                ->select(['id', 'name', 'capacity'])
                ->first();
        }

        return $this->roomCache[$roomId];
    }

    private function timesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        $s1 = $this->minutesOfDay($startA);
        $e1 = $this->minutesOfDay($endA);
        $s2 = $this->minutesOfDay($startB);
        $e2 = $this->minutesOfDay($endB);

        return $s1 < $e2 && $e1 > $s2;
    }

    private function minutesOfDay(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function countDistinctStudents(array $entries): int
    {
        $studentIds = [];
        $anonymousEntries = [];

        foreach ($entries as $entry) {
            $studentId = (int) ($entry['student_id'] ?? 0);
            if ($studentId > 0) {
                $studentIds[$studentId] = true;
                continue;
            }

            $anonymousKey = implode('|', [
                (string) ($entry['source'] ?? ''),
                (string) ($entry['source_id'] ?? ''),
                (string) ($entry['start_time'] ?? ''),
                (string) ($entry['end_time'] ?? ''),
            ]);
            $anonymousEntries[$anonymousKey] = true;
        }

        return count($studentIds) + count($anonymousEntries);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function buildOverlapDetails(array $entries): array
    {
        if (empty($entries)) {
            return [];
        }

        $studentIds = array_values(array_unique(array_filter(array_map(function ($entry) {
            return (int) ($entry['student_id'] ?? 0);
        }, $entries))));

        $studentNameMap = [];
        if (!empty($studentIds)) {
            $studentNameMap = DB::table('Student')
                ->whereIn('id', $studentIds)
                ->pluck('name', 'id')
                ->toArray();
        }

        $details = [];
        $seen = [];
        foreach ($entries as $entry) {
            $studentId = (int) ($entry['student_id'] ?? 0);
            $key = implode('|', [
                (string) ($entry['source'] ?? ''),
                (string) ($entry['source_id'] ?? ''),
                (string) ($entry['start_time'] ?? ''),
                (string) ($entry['end_time'] ?? ''),
                (string) $studentId,
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $details[] = [
                'source' => (string) ($entry['source'] ?? ''),
                'source_id' => (int) ($entry['source_id'] ?? 0),
                'student_id' => $studentId,
                'student_name' => $studentId > 0 ? (string) ($studentNameMap[$studentId] ?? '') : '',
                'class_type' => (string) ($entry['class_type'] ?? ''),
                'room_id' => !empty($entry['room_id']) ? (int) $entry['room_id'] : null,
                'start_time' => (string) ($entry['start_time'] ?? ''),
                'end_time' => (string) ($entry['end_time'] ?? ''),
            ];
        }

        return $details;
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private function buildOverlapSummary(array $details): string
    {
        if (empty($details)) {
            return '';
        }

        $segments = [];
        $max = min(5, count($details));
        for ($i = 0; $i < $max; $i++) {
            $row = $details[$i];
            $name = (string) ($row['student_name'] ?? '');
            $sid = (int) ($row['student_id'] ?? 0);
            $studentLabel = $name !== '' ? $name : ($sid > 0 ? ('#' . $sid) : '未綁定學生');
            $segments[] = sprintf(
                '%s(%s-%s,%s)',
                $studentLabel,
                (string) ($row['start_time'] ?? ''),
                (string) ($row['end_time'] ?? ''),
                (string) ($row['source'] ?? '')
            );
        }

        if (count($details) > $max) {
            $segments[] = sprintf('...其餘 %d 筆', count($details) - $max);
        }

        return implode('；', $segments);
    }

    /**
     * Build occupancy entries for a teacher on one concrete date.
     * Data source order:
     * 1) ClassSession (actual scheduled sessions)
     * 2) schedules.status=scheduled (rescheduled-to / extra sessions not yet synced)
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTeacherDateOccupancyEntries(
        int $teacherId,
        int $branchId,
        string $date,
        ?int $excludeScheduleId = null
    ): array {
        $scheduleRowsQuery = DB::table('schedules')
            ->where('branch_id', $branchId)
            ->where('teacher_id', $teacherId)
            ->whereDate('schedule_date', $date)
            ->select([
                'id',
                'student_id',
                'status',
                'start_time',
                'end_time',
                'class_type',
                'student_course_id',
                'original_schedule_id',
            ]);

        if ($excludeScheduleId) {
            $scheduleRowsQuery->where('id', '!=', $excludeScheduleId);
        }
        $scheduleRows = $scheduleRowsQuery->get();

        $leaveOrRescheduled = [];
        $scheduledRows = [];
        foreach ($scheduleRows as $row) {
            $status = (string) ($row->status ?? '');
            $courseId = (int) ($row->student_course_id ?? 0);
            if (($status === 'leave' || $status === 'rescheduled') && $courseId > 0) {
                $leaveOrRescheduled[$courseId] = true;
                continue;
            }
            if ($status === 'scheduled') {
                $scheduledRows[] = $row;
            }
        }

        $classSessions = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('sc.TeacherID', $teacherId)
            ->where('sc.Stop', 0)
            ->where('st.CampusID', $branchId)
            ->whereDate('cs.SessionDate', $date)
            ->whereNotIn('cs.Status', ['cancelled'])
            ->select([
                'cs.StudentClassID',
                'cs.StartTime',
                'cs.EndTime',
                'sc.StudentID',
                'sc.ClassType',
                'sc.room_id',
            ])
            ->get();

        $entries = [];
        $existingKeys = [];
        $classSessionCourseIds = [];
        foreach ($classSessions as $row) {
            $courseId = (int) ($row->StudentClassID ?? 0);
            if ($courseId > 0 && isset($leaveOrRescheduled[$courseId])) {
                continue;
            }

            $start = $this->normalizeTime($row->StartTime ?? null);
            $end = $this->normalizeTime($row->EndTime ?? null);
            if (!$start || !$end) {
                continue;
            }

            $entries[] = [
                'source' => 'class_session',
                'source_id' => $courseId,
                'student_id' => (int) ($row->StudentID ?? 0),
                'class_type' => (string) ($row->ClassType ?? 'one_on_one'),
                'room_id' => $row->room_id ? (int) $row->room_id : null,
                'start_time' => $start,
                'end_time' => $end,
            ];
            $existingKeys[$courseId . '|' . $start . '|' . $end] = true;
            if ($courseId > 0) {
                $classSessionCourseIds[$courseId] = true;
            }
        }

        $courseMeta = [];
        $courseIds = array_values(array_unique(array_filter(array_map(function ($row) {
            return (int) ($row->student_course_id ?? 0);
        }, $scheduledRows))));
        if (!empty($courseIds)) {
            $metaRows = DB::table('StudentClass')
                ->whereIn('ID', $courseIds)
                ->select(['ID', 'ClassType', 'room_id'])
                ->get();
            foreach ($metaRows as $meta) {
                $courseMeta[(int) $meta->ID] = $meta;
            }
        }

        foreach ($scheduledRows as $row) {
            $start = $this->normalizeTime($row->start_time ?? null);
            $end = $this->normalizeTime($row->end_time ?? null);
            if (!$start || !$end) {
                continue;
            }

            $courseId = (int) ($row->student_course_id ?? 0);
            if ($courseId > 0 && isset($leaveOrRescheduled[$courseId])) {
                continue;
            }
            // If this course already has a concrete ClassSession on the date,
            // trust ClassSession as the source of truth and ignore stale schedule overrides.
            if ($courseId > 0 && isset($classSessionCourseIds[$courseId])) {
                continue;
            }
            $meta = $courseId > 0 ? ($courseMeta[$courseId] ?? null) : null;
            $classType = (string) ($row->class_type ?: ($meta->ClassType ?? 'one_on_one'));
            $roomId = $meta?->room_id ? (int) $meta->room_id : null;
            $key = $courseId . '|' . $start . '|' . $end;
            if ($courseId > 0 && isset($existingKeys[$key])) {
                continue;
            }

            $entries[] = [
                'source' => 'schedule',
                'source_id' => (int) ($row->id ?? 0),
                'student_id' => (int) ($row->student_id ?? 0),
                'class_type' => $classType,
                'room_id' => $roomId,
                'start_time' => $start,
                'end_time' => $end,
            ];
        }

        return $entries;
    }
}
