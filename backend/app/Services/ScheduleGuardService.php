<?php

namespace App\Services;

use App\Models\LearningRecord;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Support\ClassTypeCapacity;
use App\Support\SessionStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleGuardService
{
    public const TEACHER_SLOT_ABSOLUTE_MAX = 3;

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
        // Same-student dual-contract / self occupancy must not block course edit
        // (in-app #311; mirrors validateScheduleOccurrence exclude_student_id).
        $excludeStudentId = isset($payload['exclude_student_id']) && $payload['exclude_student_id']
            ? (int) $payload['exclude_student_id']
            : null;
        $startDate = isset($payload['start_date']) && $payload['start_date'] ? (string) $payload['start_date'] : null;
        $endDate = isset($payload['end_date']) && $payload['end_date'] ? (string) $payload['end_date'] : null;

        $teacherCourses = $this->loadTeacherRecurringCourses(
            $teacherId,
            $branchId,
            $excludeStudentClassId,
            $excludeStudentId
        );
        $conflicts = [];
        $selfOverlaps = [];
        // Lock state of the edited course's sessions, loaded once for all slots.
        $lockedOwn = $excludeStudentClassId ? \App\Services\Scheduling\ContractSessionSchedule::lockedClassSessionIds($excludeStudentClassId) : [];

        foreach ($slots as $slot) {
            $recurringOverlaps = $this->collectRecurringOverlaps($teacherCourses, $slot);
            $concreteOverlaps = $this->collectConcreteRecurringOverlaps(
                $teacherId,
                $branchId,
                $slot,
                $excludeStudentClassId,
                $startDate,
                $endDate,
                $excludeStudentId,
                array_values(array_filter($slots, fn ($s) => (int) ($s['day_of_week'] ?? 0) === (int) ($slot['day_of_week'] ?? 0))),
                $lockedOwn,
                $selfOverlaps
            );
            // Occupancy is per concrete date, not pooled across dates (in-app #347).
            $byDate = [];
            foreach ($concreteOverlaps as $o) {
                $byDate[(string) ($o['schedule_date'] ?? '')][] = $o;
            }
            $teacherConflict = null;
            $roomConflict = null;
            foreach ($byDate ?: [[]] as $dateOverlaps) {
                $overlaps = array_merge($recurringOverlaps, $dateOverlaps);
                $tc = $this->buildTeacherCapacityConflict($classType, $slot, $overlaps);
                if ($tc && (!$teacherConflict || ($tc['current_students'] ?? 0) > ($teacherConflict['current_students'] ?? 0))) {
                    $teacherConflict = $tc;
                }
                $rc = $this->buildRoomCapacityConflict($roomId, $slot, $overlaps);
                if ($rc && (!$roomConflict || ($rc['current_students'] ?? 0) > ($roomConflict['current_students'] ?? 0))) {
                    $roomConflict = $rc;
                }
            }
            if ($teacherConflict) {
                $conflicts[] = $teacherConflict;
            }
            if ($roomConflict) {
                $conflicts[] = $roomConflict;
            }
        }
        // Keyed by date, so slots sharing a weekday report each date once.
        foreach ($selfOverlaps as $d => [$a, $b]) {
            $conflicts[] = [
                'type' => 'self_overlap',
                'schedule_date' => $d,
                'start_time' => substr($b, 0, 5),
                'end_time' => substr($b, 6, 5),
                'message' => sprintf('此課程在 %s 會與自己已鎖定或保留的堂次時間重疊（%s 與 %s），請調整時段或先處理該堂次', $d, $a, $b),
            ];
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
        $excludeStudentId = isset($payload['exclude_student_id']) && $payload['exclude_student_id']
            ? (int) $payload['exclude_student_id']
            : null;
        $excludeCourseId = isset($payload['exclude_course_id']) && $payload['exclude_course_id']
            ? (int) $payload['exclude_course_id']
            : null;

        $entries = $this->buildTeacherDateOccupancyEntries(
            $teacherId,
            $branchId,
            $date,
            $excludeScheduleId,
            $excludeStudentId,
            $excludeCourseId,
            $startTime,
            $endTime
        );
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

    /**
     * Validate many concrete occurrences with one shared payload; every conflict is
     * tagged with the proposed date/time. Single conflict shape for write paths.
     *
     * @param  array<string, mixed>  $base   teacher_id, class_type, room_id, branch_id, exclude_*
     * @param  iterable<array{date: string, start_time: string, end_time: string}>  $slots
     * @param  bool  $dedupe  collapse repeats of the same type/date/time/room (edit paths)
     * @return array<int, array<string, mixed>>
     */
    public function validateOccurrences(array $base, iterable $slots, bool $dedupe = false): array
    {
        $out = [];
        $seen = [];
        foreach ($slots as $slot) {
            $conflicts = $this->validateScheduleOccurrence($base + [
                'schedule_date' => $slot['date'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
            ]);
            $start = substr((string) $slot['start_time'], 0, 5);
            $end = substr((string) $slot['end_time'], 0, 5);
            foreach ($conflicts as $conflict) {
                if ($dedupe) {
                    $key = implode('|', [(string) ($conflict['type'] ?? ''), $slot['date'], $start, $end, (string) ($conflict['room_id'] ?? '')]);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                }
                $out[] = array_merge(['date' => $slot['date'], 'proposed_time' => $start . '-' . $end], $conflict);
            }
        }

        return $out;
    }

    private function capacityForClassType(?string $classType): int
    {
        return ClassTypeCapacity::for($classType);
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
    private function loadTeacherRecurringCourses(
        int $teacherId,
        int $branchId,
        ?int $excludeStudentClassId = null,
        ?int $excludeStudentId = null
    ): array {
        $query = DB::table('StudentClass as sc')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('sc.TeacherID', $teacherId)
            ->where('st.CampusID', $branchId)
            // In-app #373: used-up count courses no longer hold a weekly seat (F8 single rule).
            ->tap(fn ($q) => StudentClass::applyHoldsTemplateSeat($q, 'sc'))
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
        if ($excludeStudentId) {
            $query->where('sc.StudentID', '!=', $excludeStudentId);
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
     * Final same-day layout of one course after ContractSessionSchedule::planSameDayRemap(): moved rows at their target slot, every other
     * live own row (locked, excess, exception, non-scheduled) where it is, plus each slot no row already starts at
     * (the reflow fills it). Capacity counting dedupes the course's own student, so a course overlapping itself
     * must be caught here. Times compare on their H:i prefix.
     *
     * @param  array<int, array{id:int, start:string, end:string}>  $rowsOnDate  live rows of one course on one date
     * @param  array<int, array<string, mixed>>  $moves  ContractSessionSchedule::planSameDayRemap()['moves']
     * @param  array<int, array<string, mixed>>  $daySlots  each with 'start' and 'end'
     * @return array<int, array{0: string, 1: string}> overlapping pairs as 'H:i-H:i'
     */
    public static function planSelfOverlaps(array $rowsOnDate, array $moves, array $daySlots): array
    {
        $hm = fn ($t) => substr((string) $t, 0, 5);
        // The sync skips a move whose target start a staying row still holds (uq_class_session_slot); that row then
        // stays too. ponytail: models the batch outcome, not the one-by-one fallback order.
        do {
            $held = [];
            foreach ($rowsOnDate as $r) {
                if (!isset($moves[(int) $r['id']])) {
                    $held[$hm($r['start'])] = true;
                }
            }
            $before = count($moves);
            $moves = array_filter($moves, fn ($s) => !isset($held[$hm($s['start'])]));
        } while (count($moves) < $before);

        $final = [];
        foreach ($rowsOnDate as $r) {
            $at = $moves[(int) $r['id']] ?? $r;
            $final[] = [$hm($at['start']), $hm($at['end'])];
        }
        $pairs = [];
        foreach ($daySlots as $s) {
            $sameStart = array_filter($final, fn ($f) => $f[0] === $hm($s['start']));
            if (!$sameStart) {
                $final[] = [$hm($s['start']), $hm($s['end'])];
                continue;
            }
            // A staying row holds this start with another duration: when the edit remaps this day, that slot can't be
            // realized (unique start key). Days without moves keep their existing layout untouched.
            foreach ($moves === [] ? [] : $sameStart as $f) {
                if ($f[1] !== $hm($s['end'])) {
                    $pairs[] = [$f[0] . '-' . $f[1], $hm($s['start']) . '-' . $hm($s['end'])];
                }
            }
        }

        foreach ($final as $i => $a) {
            foreach (array_slice($final, $i + 1) as $b) {
                if ($a[0] < $b[1] && $b[0] < $a[1]) {
                    $pairs[] = [$a[0] . '-' . $a[1], $b[0] . '-' . $b[1]];
                }
            }
        }

        return $pairs;
    }

    /**
     * Collect concrete future session/schedule overlaps for a recurring slot.
     * Enforces bounded self-exclusion: a session belonging to $excludeStudentClassId
     * is excluded ONLY if its start_time and end_time match the recurring slot.
     * Overlapping sessions at non-matching times, or sessions from other courses/students,
     * are retained as true conflicts.
     *
     * @param  array<string, mixed>  $slot
     * @return array<int, array<string, mixed>>
     */
    private function collectConcreteRecurringOverlaps(
        int $teacherId,
        int $branchId,
        array $slot,
        ?int $excludeStudentClassId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $excludeStudentId = null,
        array $daySlots = [],
        array $locked = [],
        array &$selfOverlaps = []
    ): array {
        $dow = (int) ($slot['day_of_week'] ?? 0);
        $slotStart = (string) ($slot['start_time'] ?? '');
        $slotEnd = (string) ($slot['end_time'] ?? '');
        if ($dow < 1 || $dow > 7 || $slotStart === '' || $slotEnd === '') {
            return [];
        }

        $today = Carbon::today()->toDateString();
        $horizonStart = ($startDate && $startDate > $today) ? $startDate : $today;
        if ($endDate && $endDate < $horizonStart) {
            return [];
        }

        $scheduleRowsQuery = DB::table('schedules')->where('branch_id', $branchId)->where('teacher_id', $teacherId)->whereDate('schedule_date', '>=', $horizonStart);
        if ($endDate) {
            $scheduleRowsQuery->whereDate('schedule_date', '<=', $endDate);
        }
        $scheduleRows = $scheduleRowsQuery->select(['id', 'student_id', 'schedule_date', 'status', 'start_time', 'end_time', 'class_type', 'student_course_id', 'original_schedule_id'])->get();

        $leaveOrRescheduled = [];
        $scheduledByDate = [];
        foreach ($scheduleRows as $row) {
            $status = (string) ($row->status ?? '');
            $courseId = (int) ($row->student_course_id ?? 0);
            $d = $row->schedule_date ? Carbon::parse((string) $row->schedule_date)->toDateString() : '';
            if ($d && $courseId > 0 && ($status === 'leave' || $status === 'rescheduled')) {
                $leaveOrRescheduled[$courseId . '|' . $d] = true;
            } elseif ($d && $status === 'scheduled') {
                $scheduledByDate[$d][] = $row;
            }
        }

        $staleFilter = app(StaleScheduleExceptionFilter::class);
        $filteredScheduledRows = [];
        foreach ($scheduledByDate as $d => $rowsOnDate) {
            foreach ($staleFilter->rejectStale($rowsOnDate, $d) as $r) {
                $filteredScheduledRows[] = $r;
            }
        }

        $classSessionsQuery = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('sc.TeacherID', $teacherId)->where(fn ($q) => $q->where('sc.Stop', 0)->orWhereNull('sc.Stop'))->where('st.CampusID', $branchId)
            ->whereDate('cs.SessionDate', '>=', $horizonStart)
            ->whereNotIn('cs.Status', SessionStatus::futureReservationExclusionStatuses());
        if ($endDate) {
            $classSessionsQuery->whereDate('cs.SessionDate', '<=', $endDate);
        }
        $classSessions = $classSessionsQuery->select(['cs.id as class_session_id', 'cs.StudentClassID', 'cs.IsContractException', 'cs.SessionDate', 'cs.StartTime', 'cs.EndTime', 'cs.Status', 'sc.StudentID', 'sc.ClassType', 'sc.room_id'])->get();

        $overlaps = [];
        $seenKeys = [];
        // Own rows the edit moves to a new slot (same plan as ContractSessionSchedule::syncFutureScheduledSessionTimes()) never block it;
        // rows the plan leaves in place keep conflicting. Their paired schedules rows move with them.
        $remappedIds = [];
        $movedScheduleKeys = [];
        if ($excludeStudentClassId) {
            $ownByDate = [];
            $ownLiveByDate = [];
            // The edited course's own rows, independent of the teacher filter: an edit that also changes the
            // teacher still moves the course's existing sessions.
            $ownQuery = DB::table('ClassSession as cs')
                ->where('cs.StudentClassID', $excludeStudentClassId)
                ->whereDate('cs.SessionDate', '>=', $horizonStart)
                ->whereNotIn('cs.Status', SessionStatus::futureReservationExclusionStatuses());
            if ($endDate) {
                $ownQuery->whereDate('cs.SessionDate', '<=', $endDate);
            }
            foreach ($ownQuery->get(['cs.id as class_session_id', 'cs.IsContractException', 'cs.SessionDate', 'cs.StartTime', 'cs.EndTime', 'cs.Status']) as $row) {
                $d = substr((string) $row->SessionDate, 0, 10);
                $r = [
                    'id' => (int) $row->class_session_id,
                    'start' => (string) $this->normalizeTime($row->StartTime),
                    'end' => (string) $this->normalizeTime($row->EndTime),
                    'exception' => (bool) $row->IsContractException,
                ];
                if ((int) Carbon::parse($d)->dayOfWeekIso === $dow && $r['start'] !== '' && $r['end'] !== '') {
                    $ownLiveByDate[$d][] = $r;
                }
                // The sync only touches 'scheduled' rows.
                if (strtolower((string) $row->Status) === 'scheduled') {
                    $ownByDate[$d][] = $r;
                }
            }
            $planSlots = array_map(fn ($s) => ['start' => (string) $s['start_time'], 'end' => (string) $s['end_time']], $daySlots ?: [$slot]);
            // Every date with an own live row, including dates whose only rows are non-'scheduled' (e.g. pending leave).
            foreach (array_keys($ownByDate + $ownLiveByDate) as $d) {
                $rows = $ownByDate[$d] ?? [];
                $moves = \App\Services\Scheduling\ContractSessionSchedule::planSameDayRemap($rows, $planSlots, $locked)['moves'];
                foreach ($rows as $r) {
                    if (isset($moves[$r['id']])) {
                        $remappedIds[$r['id']] = true;
                        $movedScheduleKeys[$d . '|' . $r['start']] = true;
                    }
                }
                if (isset($ownLiveByDate[$d]) && ($pairs = self::planSelfOverlaps($ownLiveByDate[$d], $moves, $planSlots))) {
                    $selfOverlaps[$d] = $pairs[0];
                }
            }
        }

        foreach ($classSessions as $row) {
            $sessionDate = substr((string) ($row->SessionDate ?? ''), 0, 10);
            if (!$sessionDate) {
                continue;
            }
            $sessionDow = (int) Carbon::parse($sessionDate)->dayOfWeekIso;
            if ($sessionDow !== $dow) {
                continue;
            }

            $courseId = (int) ($row->StudentClassID ?? 0);
            if ($courseId > 0 && isset($leaveOrRescheduled[$courseId . '|' . $sessionDate])) {
                continue;
            }

            // Same-student dual-contract / other-course occupancy: exclude.
            // Same-course rows keep the bounded time-match exclusion below so
            // partially overlapping exceptions still conflict (adopt-exception).
            if (
                $excludeStudentId
                && (int) ($row->StudentID ?? 0) === $excludeStudentId
                && (!$excludeStudentClassId || $courseId !== $excludeStudentClassId)
            ) {
                continue;
            }

            $start = $this->normalizeTime($row->StartTime ?? null);
            $end = $this->normalizeTime($row->EndTime ?? null);
            if (!$start || !$end) {
                continue;
            }

            if (!$this->timesOverlap($slotStart, $slotEnd, $start, $end)) {
                continue;
            }

            // Bounded self-exclusion:
            if ($excludeStudentClassId && $courseId === $excludeStudentClassId) {
                // Own rows the sync moves to a new slot never block it (in-app #347); rows it leaves in place conflict below.
                if (isset($remappedIds[(int) $row->class_session_id])) {
                    continue;
                }
                // If the session matches the slot being added, it is the course's own
                // exception session being regularized into this recurring slot. Safe to exclude.
                if ($start === $slotStart && $end === $slotEnd) {
                    continue;
                }
                // If start/end do not match, it represents an intra-course overlapping conflict.
            }

            $key = $courseId . '|' . $sessionDate . '|' . $start . '|' . $end;
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;

            $overlaps[] = [
                'source' => 'class_session',
                'source_id' => $courseId,
                'student_id' => (int) ($row->StudentID ?? 0),
                'class_type' => (string) ($row->ClassType ?? 'one_on_one'),
                'room_id' => $row->room_id ? (int) $row->room_id : null,
                'start_time' => $start,
                'end_time' => $end,
                'schedule_date' => $sessionDate,
            ];
        }

        foreach ($filteredScheduledRows as $row) {
            $scheduleDate = substr((string) ($row->schedule_date ?? ''), 0, 10);
            if (!$scheduleDate) {
                continue;
            }
            $scheduleDow = (int) Carbon::parse($scheduleDate)->dayOfWeekIso;
            if ($scheduleDow !== $dow) {
                continue;
            }

            $courseId = (int) ($row->student_course_id ?? 0);
            $start = $this->normalizeTime($row->start_time ?? null);
            $end = $this->normalizeTime($row->end_time ?? null);
            if (!$start || !$end) {
                continue;
            }

            // Same-student other-course (or unlinked) schedule rows: exclude.
            // Same-course keeps bounded time-match exclusion below.
            if (
                $excludeStudentId
                && (int) ($row->student_id ?? 0) === $excludeStudentId
                && (!$excludeStudentClassId || $courseId !== $excludeStudentClassId)
            ) {
                continue;
            }

            if (!$this->timesOverlap($slotStart, $slotEnd, $start, $end)) {
                continue;
            }

            // Bounded self-exclusion for schedules:
            if ($excludeStudentClassId && $courseId === $excludeStudentClassId) {
                if (isset($movedScheduleKeys[$scheduleDate . '|' . $start])) {
                    continue;
                }
                if ($start === $slotStart && $end === $slotEnd) {
                    continue;
                }
            }

            $key = $courseId . '|' . $scheduleDate . '|' . $start . '|' . $end;
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;

            $overlaps[] = [
                'source' => 'schedule',
                'source_id' => (int) ($row->id ?? 0),
                'student_id' => (int) ($row->student_id ?? 0),
                'class_type' => (string) ($row->class_type ?? 'one_on_one'),
                'room_id' => null,
                'start_time' => $start,
                'end_time' => $end,
                'schedule_date' => $scheduleDate,
            ];
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

        // Trial classes are a director-arranged add-on to existing sessions
        // (試聽學生旁聽正式課堂). They bypass both the new-type capacity check
        // and the existing-type capacity check, but we still enforce at most
        // one trial student per teacher slot (FR-002).
        if ($newClassType === 'trial') {
            $trialOverlaps = array_values(array_filter($overlaps, function ($entry) {
                return (string) ($entry['class_type'] ?? '') === 'trial';
            }));
            $existingTrialCount = $this->countDistinctStudents($trialOverlaps);
            if ($existingTrialCount >= 1) {
                return $this->finalizeCapacityConflict([
                    'type' => 'teacher_capacity',
                    'day_of_week' => (int) ($slot['day_of_week'] ?? 0),
                    'start_time' => (string) ($slot['start_time'] ?? ''),
                    'end_time' => (string) ($slot['end_time'] ?? ''),
                    'current_students' => $existingTrialCount,
                    'allowed_students' => 1,
                    'message' => sprintf(
                        '老師此時段已有 %d 位試聽學生，試聽 上限為 1 位學生。',
                        $existingTrialCount
                    ),
                    ...$this->overlapPayload($overlaps),
                ]);
            }
            return null;
        }

        $hasOneOnOne = false;
        foreach ($overlaps as $entry) {
            if ((string) ($entry['class_type'] ?? '') === 'one_on_one') {
                $hasOneOnOne = true;
                break;
            }
        }
        if ($hasOneOnOne) {
            return $this->finalizeCapacityConflict([
                'type' => 'teacher_capacity',
                'day_of_week' => (int) ($slot['day_of_week'] ?? 0),
                'start_time' => (string) ($slot['start_time'] ?? ''),
                'end_time' => (string) ($slot['end_time'] ?? ''),
                'current_students' => $existingCount,
                'allowed_students' => 1,
                'message' => '老師此時段本分校已有一對一課程，無法再加課。',
                ...$this->overlapPayload($overlaps),
            ]);
        }

        if ($existingCount >= self::TEACHER_SLOT_ABSOLUTE_MAX) {
            return $this->finalizeCapacityConflict([
                'type' => 'teacher_capacity',
                'day_of_week' => (int) ($slot['day_of_week'] ?? 0),
                'start_time' => (string) ($slot['start_time'] ?? ''),
                'end_time' => (string) ($slot['end_time'] ?? ''),
                'current_students' => $existingCount,
                'allowed_students' => self::TEACHER_SLOT_ABSOLUTE_MAX,
                'message' => sprintf(
                    '老師此時段本分校已有 %d 位學生，上限為 %d 位學生。',
                    $existingCount,
                    self::TEACHER_SLOT_ABSOLUTE_MAX
                ),
                ...$this->overlapPayload($overlaps),
            ]);
        }

        if ($existingCount >= $newCapacity) {
            return $this->finalizeCapacityConflict([
                'type' => 'teacher_capacity',
                'day_of_week' => (int) ($slot['day_of_week'] ?? 0),
                'start_time' => (string) ($slot['start_time'] ?? ''),
                'end_time' => (string) ($slot['end_time'] ?? ''),
                'current_students' => $existingCount,
                'allowed_students' => $newCapacity,
                'message' => sprintf(
                    '老師此時段本分校已有 %d 位學生，已達%s上限（%d 位）。',
                    $existingCount,
                    $this->classTypeLabel($newClassType),
                    $newCapacity
                ),
                ...$this->overlapPayload($overlaps),
            ]);
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

        if ($currentStudents >= $studentCapacity) {
            return $this->finalizeCapacityConflict([
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
                ...$this->overlapPayload(array_values($sameRoomOverlaps)),
            ]);
        }

        return null;
    }

    /**
     * Make capacity conflicts actionable for directors (in-app #310):
     * bake occupant names into message and attach short resolution steps.
     *
     * @param  array<string, mixed>  $conflict
     * @return array<string, mixed>
     */
    /**
     * Student/subject details for a conflict, hydrated only once a conflict exists
     * (course edits evaluate many future dates; most have no conflict).
     *
     * @param  array<int, array<string, mixed>>  $overlaps
     * @return array{overlap_summary: mixed, overlap_details: mixed}
     */
    private function overlapPayload(array $overlaps): array
    {
        $details = $this->buildOverlapDetails($overlaps);

        return ['overlap_summary' => $this->buildOverlapSummary($details), 'overlap_details' => $details];
    }

    private function finalizeCapacityConflict(array $conflict): array
    {
        $summary = trim((string) ($conflict['overlap_summary'] ?? ''));
        $message = trim((string) ($conflict['message'] ?? ''));
        if ($summary !== '' && $message !== '' && !str_contains($message, $summary)) {
            $conflict['message'] = $message . ' 此時段已有：' . $summary . '。';
        } elseif ($summary !== '' && $message === '') {
            $conflict['message'] = '此時段已有：' . $summary . '。';
        }

        $conflict['suggested_actions'] = [
            '在行事曆切到對應週次，並確認授課老師／分校篩選是否與衝突來源一致',
            '到課程管理搜尋提示中的學生／科目，確認是否為舊合約未結束、代課或調課列',
            '依情況改期、請假、結束舊合約，或改選其他時段後再排',
        ];

        return $conflict;
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

        // #2006：衝突訊息只有姓名時，主任分不出「同一堂」還是同學生的另一筆課程
        // （例如續約舊課程未關閉，跟新課程同時段並存）。補上科目名稱與課程起始日
        // 讓「此時段已有：王品方（理化・7/11期）」講清楚是哪一筆。
        $courseIds = array_values(array_unique(array_filter(array_map(function ($entry) {
            return (int) ($entry['course_id'] ?? $entry['source_id'] ?? 0);
        }, $entries))));

        $courseMetaMap = [];
        if (!empty($courseIds)) {
            $courseMetaMap = DB::table('StudentClass')
                ->whereIn('ID', $courseIds)
                ->select(['ID', 'SubjectID', 'StartDate'])
                ->get()
                ->keyBy('ID')
                ->toArray();
        }

        $subjectIds = array_values(array_unique(array_filter(array_map(
            fn ($meta) => (int) ($meta->SubjectID ?? 0),
            $courseMetaMap
        ))));
        $subjectNameMap = [];
        if (!empty($subjectIds)) {
            $subjectNameMap = DB::table('Subject')
                ->whereIn('id', $subjectIds)
                ->pluck('Subject_Name', 'id')
                ->toArray();
        }

        $details = [];
        $seen = [];
        foreach ($entries as $entry) {
            $studentId = (int) ($entry['student_id'] ?? 0);
            $courseId = (int) ($entry['course_id'] ?? $entry['source_id'] ?? 0);
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

            $meta = $courseMetaMap[$courseId] ?? null;
            $subjectName = '';
            if ($meta) {
                $subjectName = (string) ($subjectNameMap[(int) ($meta->SubjectID ?? 0)] ?? '');
            }
            $coursePeriod = $meta && $meta->StartDate
                ? Carbon::parse((string) $meta->StartDate)->format('n/j') . '期'
                : '';

            $details[] = [
                'source' => (string) ($entry['source'] ?? ''),
                'source_id' => (int) ($entry['source_id'] ?? 0),
                'course_id' => $courseId,
                'student_id' => $studentId,
                'student_name' => $studentId > 0 ? (string) ($studentNameMap[$studentId] ?? '') : '',
                'subject_name' => $subjectName,
                'course_period' => $coursePeriod,
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
            $subjectName = (string) ($row['subject_name'] ?? '');
            $coursePeriod = (string) ($row['course_period'] ?? '');
            $courseLabel = trim($subjectName . ($coursePeriod !== '' ? '・' . $coursePeriod : ''));
            // Human summary only; overlap_details retains the diagnostic source enum.
            $source = (string) ($row['source'] ?? '');
            $sourceLabel = match ($source) {
                'student_class' => '固定課程',
                'class_session' => '課堂紀錄',
                'schedule' => '排課紀錄',
                default => $source,
            };
            $segments[] = sprintf(
                '%s%s(%s-%s,%s)',
                $studentLabel,
                $courseLabel !== '' ? "（{$courseLabel}）" : '',
                (string) ($row['start_time'] ?? ''),
                (string) ($row['end_time'] ?? ''),
                $sourceLabel
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
        ?int $excludeScheduleId = null,
        ?int $excludeStudentId = null,
        ?int $excludeCourseId = null,
        ?string $targetStartTime = null,
        ?string $targetEndTime = null
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
        if ($excludeCourseId && ($targetStartTime === null || $targetEndTime === null)) {
            $scheduleRowsQuery->where('student_course_id', '!=', $excludeCourseId);
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

        // #1296：同 key 的 ClassSession 已全數取消 → scheduled 例外 row 視為 stale，
        // 不可再計入老師佔用（否則代課挑選會出現假衝堂／假已滿）。
        $scheduledRows = app(StaleScheduleExceptionFilter::class)
            ->rejectStale($scheduledRows, $date);

        $classSessions = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('sc.TeacherID', $teacherId)
            ->where('sc.Stop', 0)
            ->where('st.CampusID', $branchId)
            ->whereDate('cs.SessionDate', $date)
            // Leave-type sessions free up the slot, matching the frontend capacity
            // badge (LEAVE_STATUSES) and LearningRecordController's skip set. (#557)
            ->whereNotIn('cs.Status', SessionStatus::futureReservationExclusionStatuses())
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
            if ($excludeStudentId && (int) ($row->StudentID ?? 0) === $excludeStudentId) {
                continue;
            }

            $start = $this->normalizeTime($row->StartTime ?? null);
            $end = $this->normalizeTime($row->EndTime ?? null);
            if (!$start || !$end) {
                continue;
            }

            if ($excludeCourseId && $courseId === $excludeCourseId) {
                if ($targetStartTime === null || $targetEndTime === null || ($start === $targetStartTime && $end === $targetEndTime)) {
                    continue;
                }
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
            if ($excludeStudentId && (int) ($row->student_id ?? 0) === $excludeStudentId) {
                continue;
            }
            if ($excludeCourseId && $courseId === $excludeCourseId) {
                if ($targetStartTime === null || $targetEndTime === null || ($start === $targetStartTime && $end === $targetEndTime)) {
                    continue;
                }
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
                'course_id' => $courseId,
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
