<?php

namespace App\Services;

use App\Helpers\FeatureFlag;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Support\SessionStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TD-076 Track B: the single writer for "who teaches this occurrence"; callers gate on enabledFor(campus).
 * Keeps today's chain shape (live `scheduled` row -> `rescheduled` anchor) so unmigrated readers still work,
 * and never creates a second live row for a slot that already has one.
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
     * Flag on, or this occurrence was written by this service earlier (a flag rollback must still undo through it).
     *
     * Lineage key (#3590 item 1): the occurrence identity (course, original date, original time) of the live row at
     * the session's slot, plus its `schedule_change_log` rows (by schedule id or identity). A legacy reschedule after a
     * flag rollback moves the slot but keeps the stamped identity, so the writer's rows are still found.
     */
    public static function handles(ClassSession $session, int $campusId): bool
    {
        if (self::enabledFor($campusId)) {
            return true;
        }
        if (!Schema::hasColumn('schedule_change_log', 'to_teacher_id')) {
            return false;
        }
        $course = (int) $session->StudentClassID;
        $date = Carbon::parse((string) $session->SessionDate)->toDateString();
        $start = substr((string) $session->StartTime, 0, 5);
        $logs = fn () => ScheduleChangeLog::where('student_course_id', $course)->where('reason', 'substitute');

        if ($logs()->whereDate('to_date', $date)->where('to_time', $start)->exists()) {
            return true;
        }
        $stamped = Schedule::query()->where('student_course_id', $course)->whereDate('schedule_date', $date)
            ->whereIn('status', ['scheduled', 'leave'])->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$start])
            ->whereNotNull('original_schedule_date')->whereNotNull('original_start_time')
            ->get(['id', 'original_schedule_date', 'original_start_time']);
        foreach ($stamped as $row) {
            $key = fn ($q) => $q->where('schedule_id', (int) $row->id)->orWhere(fn ($i) => $i
                ->whereDate('original_schedule_date', Carbon::parse((string) $row->original_schedule_date)->toDateString())
                ->where('original_start_time', substr((string) $row->original_start_time, 0, 5)));
            if ($logs()->where($key)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** A leave occurrence already has its live row; a substitute must not add a second one. */
    public static function onLeave(ClassSession $session): bool
    {
        return SessionStatus::isLeaveLike((string) $session->Status)
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
            $this->lockOccurrence($session);
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
            } else {
                // Pure substitution: keep the live row's end/duration in step with the session, like the flag-off path.
                $this->moveTo($live, ['date' => $date, 'start' => $start, 'end' => $end]);
            }
            $live->save();

            $this->log($live, $frozenDate, $frozenTime, $date, $start, $fromTeacherId, $newTeacherId, $fromStatus, $actorId, $reason);

            return $live;
        });
    }

    /** reason=pin (or pin_conflict when evidence disagreed): freeze who taught a past occurrence; a leave occurrence already has its live row, so null. */
    public function pinTaughtTeacher(ClassSession $session, int $teacherId, ?int $actorId, string $reason = 'pin'): ?Schedule
    {
        return self::onLeave($session) ? null : $this->assignTeacher($session, $teacherId, $actorId, $reason);
    }

    /**
     * reason=restore. A substitute-only row (same-slot anchor, identity unmoved) is deleted like today's undo;
     * a row that also carries a reschedule keeps its slot with the contract teacher.
     *
     * @param array{date:string,start:string,end:string}|null $restoreSlot
     * @return Schedule|null the live row (->exists false if removed); null if none: caller uses the legacy cleanup
     */
    public function restoreContractTeacher(ClassSession $session, ?int $actorId, ?array $restoreSlot = null): ?Schedule
    {
        return DB::transaction(function () use ($session, $actorId, $restoreSlot): ?Schedule {
            $this->lockOccurrence($session);
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

    /** Serialize writers on one occurrence: no unique index yet, so two first writes must not both create a chain. */
    private function lockOccurrence(ClassSession $session): void
    {
        ClassSession::query()->where('id', (int) $session->id)->lockForUpdate()->first();
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
