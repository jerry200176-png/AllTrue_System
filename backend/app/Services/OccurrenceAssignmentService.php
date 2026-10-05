<?php

namespace App\Services;

use App\Helpers\FeatureFlag;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TD-076 Track B: the single writer for "who teaches this occurrence".
 * Callers use it ONLY when enabledFor(campus) is true; flag-off paths stay on today's code.
 *
 * Keeps today's chain shape (one live `scheduled` row whose original_schedule_id points at
 * a `rescheduled` anchor) so chain readers, which are not migrated until PR-C, still work.
 * Never creates a second live row for a slot that already has one.
 */
class OccurrenceAssignmentService
{
    public static function enabledFor(int $campusId): bool
    {
        return FeatureFlag::enabled(RescheduleSessionService::OCCURRENCE_V2_FLAG, $campusId)
            && Schema::hasColumn('schedules', 'original_schedule_date')
            && Schema::hasColumn('schedule_change_log', 'to_teacher_id');
    }

    /**
     * Flag on, OR this slot was written by this service earlier: a flag rollback must still
     * undo/restore rows through the same writer (they may hang off a cross-date anchor).
     */
    public static function handles(ClassSession $session, int $campusId): bool
    {
        return self::enabledFor($campusId)
            || (Schema::hasColumn('schedule_change_log', 'to_teacher_id')
                && ScheduleChangeLog::where('student_course_id', (int) $session->StudentClassID)
                    ->where('reason', 'substitute')
                    ->whereDate('to_date', Carbon::parse((string) $session->SessionDate)->toDateString())
                    ->where('to_time', substr((string) $session->StartTime, 0, 5))
                    ->exists());
    }

    /** A leave occurrence already has its live row; a substitute must not add a second one. */
    public static function onLeave(ClassSession $session): bool
    {
        return strtolower((string) $session->Status) === 'leave'
            || self::at((int) $session->StudentClassID, Carbon::parse((string) $session->SessionDate)->toDateString(), substr((string) $session->StartTime, 0, 5), 'leave')->exists();
    }

    /**
     * @param array{date:string,start:string,end:string}|null $newSlot
     */
    public function assignTeacher(
        ClassSession $session,
        int $newTeacherId,
        ?int $actorId,
        string $reason = 'substitute',
        ?array $newSlot = null
    ): Schedule {
        return DB::transaction(function () use ($session, $newTeacherId, $actorId, $reason, $newSlot): Schedule {
            [$course, $date, $start, $end] = $this->slotOf($session);
            $contractTeacherId = (int) ($course->TeacherID ?? 0);

            $live = $this->findLive((int) $course->ID, $date, $start, $contractTeacherId);
            $anchor = $live && $live->original_schedule_id
                ? Schedule::where('id', (int) $live->original_schedule_id)->lockForUpdate()->first()
                : null;
            $anchor ??= self::at((int) $course->ID, $date, $start, 'rescheduled')->lockForUpdate()->orderByDesc('id')->first();
            $anchor ??= $this->createRow($course, 'rescheduled', $contractTeacherId, $date, $start, $end, null);
            $fromTeacherId = $live ? (int) $live->teacher_id : $contractTeacherId;
            $fromStatus = $live ? (string) $live->status : null;

            $live ??= $this->createRow($course, 'scheduled', $newTeacherId, $date, $start, $end, (int) $anchor->id);
            $live->original_schedule_id = (int) $anchor->id;
            [$frozenDate, $frozenTime] = RescheduleSessionService::freezeOccurrenceIdentity(
                $live,
                Carbon::parse((string) $anchor->schedule_date)->toDateString(),
                (string) $anchor->start_time
            );

            // An anchor on the same slot travels with the live row; a cross-date anchor stays.
            $anchorTravels = $newSlot && $this->sameSlot($anchor, $date, $start);
            $live->teacher_id = $newTeacherId;
            if ($newSlot) {
                $this->moveTo($live, $newSlot);
                if ($anchorTravels) {
                    $this->moveTo($anchor, $newSlot);
                    $anchor->save();
                }
            }
            $live->save();

            $this->log($live, $frozenDate, $frozenTime, $date, $start, $fromTeacherId, $newTeacherId, $fromStatus, $actorId, $reason);

            return $live;
        });
    }

    /**
     * Put the contract teacher back (reason=restore). A row that exists only to carry the
     * substitute (same-slot anchor, not moved from its identity) is deleted, like today's undo;
     * a row that also carries a reschedule keeps its slot and gets the contract teacher.
     *
     * @param array{date:string,start:string,end:string}|null $restoreSlot
     * @return Schedule|null the live row (->exists is false when it was removed); null if none, caller falls back to the legacy cleanup
     */
    public function restoreContractTeacher(ClassSession $session, ?int $actorId, ?array $restoreSlot = null): ?Schedule
    {
        return DB::transaction(function () use ($session, $actorId, $restoreSlot): ?Schedule {
            [$course, $date, $start] = $this->slotOf($session);
            $contractTeacherId = (int) ($course->TeacherID ?? 0);
            $live = $this->findLive((int) $course->ID, $date, $start, $contractTeacherId);
            if (!$live) {
                return null;
            }
            $anchor = $live->original_schedule_id
                ? Schedule::where('id', (int) $live->original_schedule_id)->lockForUpdate()->first()
                : null;
            $fromTeacherId = (int) $live->teacher_id;
            $fromStatus = (string) $live->status;
            $frozenDate = $live->original_schedule_date ? Carbon::parse((string) $live->original_schedule_date)->toDateString() : null;
            $frozenTime = $live->original_start_time ? substr((string) $live->original_start_time, 0, 5) : null;

            $anchorTravels = $restoreSlot && $anchor && $this->sameSlot($anchor, $date, $start);
            $live->teacher_id = $contractTeacherId > 0 ? $contractTeacherId : null;
            if ($restoreSlot) {
                $this->moveTo($live, $restoreSlot);
                if ($anchorTravels) {
                    $this->moveTo($anchor, $restoreSlot);
                    $anchor->save();
                }
            }
            $live->save();

            $liveDate = Carbon::parse((string) $live->schedule_date)->toDateString();
            $liveStart = substr((string) $live->start_time, 0, 5);
            $this->log($live, $frozenDate, $frozenTime, $date, $start, $fromTeacherId, $contractTeacherId ?: null, $fromStatus, $actorId, 'restore');

            $carriesNothing = $anchor && $anchor->status === 'rescheduled'
                && $this->sameSlot($anchor, $liveDate, $liveStart)
                && (!$frozenDate || ($frozenDate === $liveDate && $frozenTime === $liveStart));
            if ($carriesNothing) {
                $live->delete();
                $stillLinked = Schedule::where('original_schedule_id', (int) $anchor->id)->exists();
                if (!$stillLinked) {
                    $anchor->delete();
                }
            }

            return $live;
        });
    }

    /** @return array{0: StudentClass, 1: string, 2: string, 3: string} */
    private function slotOf(ClassSession $session): array
    {
        $course = StudentClass::findOrFail((int) $session->StudentClassID);

        return [
            $course,
            Carbon::parse((string) $session->SessionDate)->toDateString(),
            substr((string) $session->StartTime, 0, 5),
            substr((string) $session->EndTime, 0, 5),
        ];
    }

    /** Rows of one status at a slot; makeup (`extra`) rows are never occurrences. */
    private static function at(int $courseId, string $date, string $start, string $status)
    {
        return Schedule::where('student_course_id', $courseId)->whereDate('schedule_date', $date)->where('status', $status)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '<>', 'extra'))
            ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$start]);
    }

    private function findLive(int $courseId, string $date, string $start, int $contractTeacherId): ?Schedule
    {
        return self::at($courseId, $date, $start, 'scheduled')->lockForUpdate()
            ->orderByRaw('CASE WHEN teacher_id <> ? THEN 0 ELSE 1 END', [$contractTeacherId])
            ->orderByDesc('id')
            ->first();
    }

    private function createRow(StudentClass $course, string $status, int $teacherId,
        string $date, string $start, string $end, ?int $anchorId): Schedule {
        $row = new Schedule([
            'student_id' => (int) $course->StudentID,
            'teacher_id' => $teacherId > 0 ? $teacherId : null,
            'subject' => DB::table('Subject')->where('id', $course->SubjectID)->value('Subject_Name') ?? '',
            'class_type' => (string) ($course->ClassType ?: 'one_on_one'),
            'status' => $status,
            'type' => 'normal',
            'deduction' => $status === 'scheduled' ? 1 : 0,
            'branch_id' => (int) Student::where('id', $course->StudentID)->value('CampusID'),
            'student_course_id' => (int) $course->ID,
            'original_schedule_id' => $anchorId,
        ]);
        $this->moveTo($row, ['date' => $date, 'start' => $start, 'end' => $end]);
        $row->save();

        return $row;
    }

    /** @param array{date:string,start:string,end:string} $slot */
    private function moveTo(Schedule $row, array $slot): void
    {
        $mins = abs(Carbon::parse($slot['start'])->diffInMinutes(Carbon::parse($slot['end'])));
        $row->schedule_date = $slot['date'];
        $row->start_time = $slot['start'];
        $row->end_time = $slot['end'];
        $row->day_of_week = (int) Carbon::parse($slot['date'])->dayOfWeekIso;
        $row->duration_hours = $mins > 0 ? max(0.5, round($mins / 60, 1)) : 2;
    }

    private function sameSlot(Schedule $row, string $date, string $start): bool
    {
        return Carbon::parse((string) $row->schedule_date)->toDateString() === $date
            && substr((string) $row->start_time, 0, 5) === $start;
    }

    private function log(Schedule $live, ?string $frozenDate, ?string $frozenTime, string $fromDate, string $fromTime,
        ?int $fromTeacherId, ?int $toTeacherId, ?string $fromStatus, ?int $actorId, string $reason): void {
        ScheduleChangeLog::create([
            'schedule_id' => (int) $live->id,
            'student_course_id' => (int) $live->student_course_id,
            'original_schedule_date' => $frozenDate,
            'original_start_time' => $frozenTime,
            'from_date' => $fromDate,
            'from_time' => $fromTime,
            'to_date' => Carbon::parse((string) $live->schedule_date)->toDateString(),
            'to_time' => substr((string) $live->start_time, 0, 5),
            'from_teacher_id' => $fromTeacherId ?: null,
            'to_teacher_id' => $toTeacherId ?: null,
            'from_status' => $fromStatus,
            'to_status' => 'scheduled',
            'actor_id' => ($actorId ?? 0) > 0 ? $actorId : null,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
