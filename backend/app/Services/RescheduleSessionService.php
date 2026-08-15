<?php

namespace App\Services;

use App\Exceptions\RescheduleSessionException;
use App\Helpers\FeatureFlag;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RescheduleSessionService
{
    public const OCCURRENCE_V2_FLAG = 'schedule-occurrence-v2';

    public function __construct(
        private ClassSessionMaterializationService $materializationService,
        private ScheduleGuardService $scheduleGuardService
    ) {
    }

    /**
     * Move one concrete class occurrence. In atomic mode the schedule anchor,
     * target exception, ClassSession and dependent records are one transaction.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function execute(array $data, int $authUserId = 0): array
    {
        return DB::transaction(function () use ($data, $authUserId): array {
            $classId = (int) $data['student_class_id'];
            $oldDate = $data['old_date'] ?? null;
            $newDate = Carbon::parse((string) $data['new_date'])->toDateString();
            $startTime = $this->normalizeTime($data['start_time'] ?? null);
            $endTime = $this->normalizeTime($data['end_time'] ?? null);
            $oldStartTime = $this->normalizeTime($data['old_start_time'] ?? null);
            $atomic = (bool) ($data['ensure_schedule_exception'] ?? false);

            $studentClass = StudentClass::where('ID', $classId)->lockForUpdate()->first();
            if (!$studentClass) {
                throw new RescheduleSessionException('找不到課程', 404, 'course_not_found');
            }

            $student = Student::where('id', (int) $studentClass->StudentID)->first();
            if (!$student) {
                throw new RescheduleSessionException('找不到課程所屬學生', 422, 'student_not_found');
            }

            if ($atomic && (!$oldDate || !$oldStartTime)) {
                throw new RescheduleSessionException(
                    '調課必須指定原堂次日期與開始時間',
                    422,
                    'original_slot_required'
                );
            }

            $session = $this->findSession($classId, $oldDate, $oldStartTime);
            if ($oldDate && $oldStartTime && !$session) {
                if ($atomic) {
                    $replay = $this->findCommittedReplay(
                        $classId,
                        (string) $oldDate,
                        (string) $oldStartTime,
                        $newDate,
                        $startTime,
                        $endTime
                    );
                    if ($replay) {
                        return $replay;
                    }
                }
                throw new RescheduleSessionException('找不到指定堂次', 422, 'session_not_found');
            }

            if ($session) {
                $startTime = $startTime ?: $this->normalizeTime($session->StartTime);
                $endTime = $endTime ?: $this->normalizeTime($session->EndTime);
            }
            if (!$startTime) {
                $startTime = $this->normalizeTime($studentClass->time1 ?? null) ?: '00:00:00';
            }
            if (!$endTime) {
                $endTime = '00:00:00';
            }

            $anchor = null;
            $target = null;
            if ($atomic) {
                [$anchor, $target] = $this->ensureScheduleException(
                    $studentClass,
                    $student,
                    (string) $oldDate,
                    (string) $oldStartTime,
                    $newDate,
                    $startTime,
                    $endTime,
                    isset($data['teacher_id']) ? (int) $data['teacher_id'] : null,
                    isset($data['subject']) ? (string) $data['subject'] : null
                );
                $this->maybeDualWriteOccurrenceIdentity(
                    $student,
                    $target,
                    (string) $oldDate,
                    (string) $oldStartTime,
                    $newDate,
                    $startTime,
                    $authUserId
                );
            }

            if ($session) {
                $oldStatus = strtolower(trim((string) ($session->Status ?? 'scheduled')));
                $this->cancelAutoMaterializedDuplicateSession(
                    $classId,
                    $newDate,
                    $startTime,
                    (int) $session->id
                );

                $slotConflict = $this->materializationService->findActiveSlotConflict(
                    $classId,
                    $newDate,
                    $startTime,
                    (int) $session->id
                );
                if ($slotConflict) {
                    throw new RescheduleSessionException(
                        '該時段已有課程，無法調課至此時段（請先取消原時段的課或改選其他時段）',
                        422,
                        'slot_occupied'
                    );
                }

                $session->SessionDate = $newDate;
                $session->StartTime = $startTime;
                $session->EndTime = $endTime;
                // R84: ClassSessionObserver::saving() recomputes IsContractException
                // automatically here (SessionDate/StartTime/EndTime are dirty), so
                // force_partial_rebuild / syncFutureScheduledSessionTimes cannot
                // snap the occurrence back to StudentClass week/time.
                $session->save();

                $resetApplied = false;
                if ($this->shouldResetToScheduled($session)) {
                    $resetApplied = $this->resetFutureSession(
                        $session,
                        $studentClass,
                        $authUserId,
                        $oldStatus
                    );
                }

                LearningRecord::where('ClassSessionID', $session->id)->update([
                    'SessionDate' => $session->SessionDate,
                    'StartTime' => $session->StartTime,
                    'EndTime' => $session->EndTime,
                ]);

                $this->syncExistingScheduleChain(
                    $classId,
                    $oldDate,
                    $oldStartTime,
                    $newDate,
                    $session->StartTime,
                    $session->EndTime
                );

                return [
                    'message' => $resetApplied ? '調課完成，未來堂次已重置為未上' : '調課完成',
                    'session_id' => (int) $session->id,
                    'rescheduled_schedule_id' => $anchor ? (int) $anchor->id : null,
                    'scheduled_schedule_id' => $target ? (int) $target->id : null,
                    'reset_to_scheduled' => $resetApplied,
                    'committed' => true,
                ];
            }

            $existing = ClassSession::where('StudentClassID', $classId)
                ->whereDate('SessionDate', $newDate)
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [substr($startTime, 0, 5)])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return [
                    'message' => '該時段已有課堂紀錄',
                    'session_id' => (int) $existing->id,
                    'reset_to_scheduled' => false,
                    'committed' => true,
                ];
            }

            $newSession = $this->materializationService->upsertSlot([
                'StudentClassID' => $classId,
                'SessionDate' => $newDate,
                'StartTime' => $startTime,
                'EndTime' => $endTime,
                'Status' => 'scheduled',
            ])['session'];

            // R84: ClassSessionObserver::updating() only fires for already-persisted
            // rows; this branch just created a brand-new one, so it needs one
            // explicit recompute (same reasoning as StudentClassController::addSession).
            app(ContractScheduleMatcher::class)->applyExceptionFlag($newSession, $studentClass);
            if ($newSession->isDirty('IsContractException')) {
                $newSession->save();
            }

            return [
                'message' => '已建立課堂紀錄',
                'session_id' => (int) $newSession->id,
                'reset_to_scheduled' => false,
                'committed' => true,
            ];
        }, 3);
    }

    private function findSession(int $classId, ?string $oldDate, ?string $oldStartTime): ?ClassSession
    {
        if (!$oldDate) {
            return null;
        }

        $query = ClassSession::where('StudentClassID', $classId)
            ->whereDate('SessionDate', $oldDate)
            ->lockForUpdate();
        if ($oldStartTime) {
            $query->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [substr($oldStartTime, 0, 5)]);
        }

        return $query->orderBy('id')->first();
    }

    /** @return array<string, mixed>|null */
    private function findCommittedReplay(
        int $classId,
        string $oldDate,
        string $oldStartTime,
        string $newDate,
        ?string $newStartTime,
        ?string $newEndTime
    ): ?array {
        if (!$newStartTime) {
            return null;
        }
        $anchor = Schedule::where('student_course_id', $classId)
            ->whereDate('schedule_date', $oldDate)
            ->where('status', 'rescheduled')
            ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [substr($oldStartTime, 0, 5)])
            ->lockForUpdate()
            ->first();
        if (!$anchor) {
            return null;
        }
        $target = Schedule::where('student_course_id', $classId)
            ->where('original_schedule_id', (int) $anchor->id)
            ->where('status', 'scheduled')
            ->whereDate('schedule_date', $newDate)
            ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [substr($newStartTime, 0, 5)])
            ->lockForUpdate()
            ->first();
        if (!$target || ($newEndTime && substr((string) $target->end_time, 0, 5) !== substr($newEndTime, 0, 5))) {
            return null;
        }
        $session = ClassSession::where('StudentClassID', $classId)
            ->whereDate('SessionDate', $newDate)
            ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [substr($newStartTime, 0, 5)])
            ->whereNotIn('Status', ['cancelled', 'voided'])
            ->lockForUpdate()
            ->first();
        if (!$session) {
            return null;
        }

        return [
            'message' => '調課已完成',
            'session_id' => (int) $session->id,
            'rescheduled_schedule_id' => (int) $anchor->id,
            'scheduled_schedule_id' => (int) $target->id,
            'reset_to_scheduled' => false,
            'committed' => true,
            'idempotent_replay' => true,
        ];
    }

    /**
     * Flag default off: production keeps today's chain-only writes.
     * When on: stamp frozen identity on the destination and append one log row.
     * Readers still walk the chain (Phase 4).
     */
    private function maybeDualWriteOccurrenceIdentity(
        Student $student,
        Schedule $target,
        string $oldDate,
        string $oldStartTime,
        string $newDate,
        string $newStartTime,
        int $authUserId
    ): void {
        if (!FeatureFlag::enabled(self::OCCURRENCE_V2_FLAG, (int) $student->CampusID)) {
            return;
        }
        if (!Schema::hasColumn('schedules', 'original_schedule_date')) {
            return;
        }

        $fromTime = substr($oldStartTime, 0, 5);
        $toTime = substr($newStartTime, 0, 5);
        $frozenDate = $target->original_schedule_date
            ? Carbon::parse((string) $target->original_schedule_date)->toDateString()
            : null;
        $frozenTime = $target->original_start_time
            ? substr((string) $target->original_start_time, 0, 5)
            : null;

        if (!$frozenDate || !$frozenTime) {
            $previous = Schedule::where('student_course_id', (int) $target->student_course_id)
                ->whereDate('schedule_date', $oldDate)
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$fromTime])
                ->where('id', '<>', (int) $target->id)
                ->orderByDesc('id')
                ->first();
            if ($previous && $previous->original_schedule_date && $previous->original_start_time) {
                $frozenDate = Carbon::parse((string) $previous->original_schedule_date)->toDateString();
                $frozenTime = substr((string) $previous->original_start_time, 0, 5);
            } else {
                $frozenDate = Carbon::parse($oldDate)->toDateString();
                $frozenTime = $fromTime;
            }
        }

        $target->original_schedule_date = $frozenDate;
        $target->original_start_time = $frozenTime;
        $target->save();

        if (!Schema::hasTable('schedule_change_log')) {
            return;
        }

        ScheduleChangeLog::create([
            'schedule_id' => (int) $target->id,
            'student_course_id' => (int) $target->student_course_id,
            'original_schedule_date' => $frozenDate,
            'original_start_time' => $frozenTime,
            'from_date' => Carbon::parse($oldDate)->toDateString(),
            'from_time' => $fromTime,
            'to_date' => Carbon::parse($newDate)->toDateString(),
            'to_time' => $toTime,
            'actor_id' => $authUserId > 0 ? $authUserId : null,
            'reason' => 'reschedule',
            'created_at' => now(),
        ]);
    }

    /** @return array{0: Schedule, 1: Schedule} */
    private function ensureScheduleException(
        StudentClass $course,
        Student $student,
        string $oldDate,
        string $oldStartTime,
        string $newDate,
        string $newStartTime,
        string $newEndTime,
        ?int $requestedTeacherId,
        ?string $subject
    ): array {
        $classId = (int) $course->ID;
        $branchId = (int) $student->CampusID;
        $contractTeacherId = (int) ($course->TeacherID ?? 0);

        $anchor = Schedule::where('student_course_id', $classId)
            ->whereDate('schedule_date', $oldDate)
            ->where('status', 'rescheduled')
            ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [substr($oldStartTime, 0, 5)])
            ->lockForUpdate()
            ->first();

        if (!$anchor) {
            $anchor = Schedule::create([
                'student_id' => (int) $student->id,
                'teacher_id' => $contractTeacherId ?: null,
                'subject' => $subject,
                'day_of_week' => Carbon::parse($oldDate)->dayOfWeekIso,
                'start_time' => $oldStartTime,
                'end_time' => $this->inferOldEndTime($classId, $oldDate, $oldStartTime, $newEndTime),
                'duration_hours' => $this->durationHours($newStartTime, $newEndTime),
                'class_type' => (string) ($course->ClassType ?: 'one_on_one'),
                'status' => 'rescheduled',
                'type' => 'normal',
                'deduction' => 0,
                'branch_id' => $branchId,
                'student_course_id' => $classId,
                'schedule_date' => $oldDate,
            ]);
        }

        $targets = Schedule::where('student_course_id', $classId)
            ->where('status', 'scheduled')
            ->where('original_schedule_id', (int) $anchor->id)
            ->lockForUpdate()
            ->orderByRaw('CASE WHEN teacher_id <> ? THEN 0 ELSE 1 END', [$contractTeacherId])
            ->orderByDesc('id')
            ->get();
        $target = $targets->first();
        $effectiveTeacherId = ($requestedTeacherId ?? 0) > 0
            ? (int) $requestedTeacherId
            : (int) ($target->teacher_id ?? $contractTeacherId);

        $conflicts = $this->scheduleGuardService->validateScheduleOccurrence([
            'teacher_id' => $effectiveTeacherId,
            'class_type' => (string) ($course->ClassType ?: 'one_on_one'),
            'room_id' => $course->room_id ?: null,
            'branch_id' => $branchId,
            'schedule_date' => $newDate,
            'start_time' => $newStartTime,
            'end_time' => $newEndTime,
            'exclude_student_id' => (int) $student->id,
            'exclude_schedule_id' => $target ? (int) $target->id : null,
        ]);
        if ($conflicts) {
            throw new RescheduleSessionException(
                (string) ($conflicts[0]['message'] ?? '目標時段已有衝突'),
                409,
                'schedule_conflict',
                ['conflicts' => $conflicts]
            );
        }

        $targetData = [
            'student_id' => (int) $student->id,
            'teacher_id' => $effectiveTeacherId ?: null,
            'subject' => $subject ?: $anchor->subject,
            'day_of_week' => Carbon::parse($newDate)->dayOfWeekIso,
            'start_time' => $newStartTime,
            'end_time' => $newEndTime,
            'duration_hours' => $this->durationHours($newStartTime, $newEndTime),
            'class_type' => (string) ($course->ClassType ?: 'one_on_one'),
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $branchId,
            'student_course_id' => $classId,
            'schedule_date' => $newDate,
            'original_schedule_id' => (int) $anchor->id,
        ];
        if ($target) {
            $target->update($targetData);
        } else {
            $target = Schedule::create($targetData);
        }

        if ($targets->count() > 1) {
            Schedule::whereIn('id', $targets->skip(1)->pluck('id')->all())->delete();
        }

        return [$anchor, $target];
    }

    private function inferOldEndTime(
        int $classId,
        string $oldDate,
        string $oldStartTime,
        string $fallback
    ): string {
        $end = ClassSession::where('StudentClassID', $classId)
            ->whereDate('SessionDate', $oldDate)
            ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [substr($oldStartTime, 0, 5)])
            ->value('EndTime');
        return $this->normalizeTime($end) ?: $fallback;
    }

    private function durationHours(string $start, string $end): float
    {
        try {
            $minutes = Carbon::createFromFormat('H:i:s', $start)
                ->diffInMinutes(Carbon::createFromFormat('H:i:s', $end));
            return round($minutes / 60, 2);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function syncExistingScheduleChain(
        int $classId,
        ?string $oldDate,
        ?string $oldStartTime,
        string $newDate,
        ?string $startTime,
        ?string $endTime
    ): void {
        if (!$oldDate) {
            return;
        }

        $anchorQuery = Schedule::where('student_course_id', $classId)
            ->whereDate('schedule_date', $oldDate)
            ->where('status', 'rescheduled')
            ->lockForUpdate();
        if ($oldStartTime) {
            $anchorQuery->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [substr($oldStartTime, 0, 5)]);
        }
        $anchor = $anchorQuery->orderBy('id')->first();
        if (!$anchor) {
            return;
        }

        $contractTeacherId = (int) (DB::table('StudentClass')
            ->where('ID', $classId)
            ->value('TeacherID') ?? 0);
        $rows = Schedule::where('student_course_id', $classId)
            ->where('original_schedule_id', (int) $anchor->id)
            ->where('status', 'scheduled')
            ->lockForUpdate()
            ->orderByRaw('CASE WHEN teacher_id <> ? THEN 0 ELSE 1 END', [$contractTeacherId])
            ->orderBy('id')
            ->get();
        $target = $rows->first();
        if (!$target) {
            return;
        }
        $target->update([
            'schedule_date' => $newDate,
            'day_of_week' => Carbon::parse($newDate)->dayOfWeekIso,
            'start_time' => $startTime ?: $target->start_time,
            'end_time' => $endTime ?: $target->end_time,
        ]);
        if ($rows->count() > 1) {
            Schedule::whereIn('id', $rows->skip(1)->pluck('id')->all())->delete();
        }
    }

    private function cancelAutoMaterializedDuplicateSession(
        int $classId,
        string $newDate,
        ?string $startTime,
        int $movingSessionId
    ): void {
        $startHm = substr((string) ($startTime ?? ''), 0, 5);
        if ($startHm === '') {
            return;
        }
        $duplicate = ClassSession::where('StudentClassID', $classId)
            ->whereDate('SessionDate', $newDate)
            ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$startHm])
            ->where('id', '!=', $movingSessionId)
            ->where('Status', 'scheduled')
            ->where(function ($query): void {
                $query->whereNull('Note')
                    ->orWhere('Note', '')
                    ->orWhere('Note', 'auto-materialized-from-schedule');
            })
            ->lockForUpdate()
            ->first();
        if ($duplicate) {
            $duplicate->Status = 'cancelled';
            $duplicate->Note = trim(
                (string) ($duplicate->Note ?? '') . '; cancelled-duplicate-reschedule-placeholder',
                '; '
            );
            $duplicate->save();
        }
    }

    private function shouldResetToScheduled(ClassSession $session): bool
    {
        try {
            $timezone = config('app.timezone', 'Asia/Taipei');
            $date = Carbon::parse($session->SessionDate)->toDateString();
            $end = $this->normalizeTime($session->EndTime) ?: '23:59:59';
            return Carbon::createFromFormat('Y-m-d H:i:s', "{$date} {$end}", $timezone)
                ->gt(now($timezone));
        } catch (\Throwable) {
            return false;
        }
    }

    private function resetFutureSession(
        ClassSession $session,
        StudentClass $studentClass,
        int $authUserId,
        string $oldStatus
    ): bool {
        $reason = '調課到未來，自動改回未上';
        $activeSignIns = StudentSignIn::where('ClassSessionID', $session->id)->active()->get();
        $hadDeductedSignIn = $activeSignIns->contains(fn ($row) => (bool) ($row->SessionDeducted ?? false));
        foreach ($activeSignIns as $signIn) {
            $signIn->VoidedAt = now();
            $signIn->VoidedByUserID = $authUserId > 0 ? $authUserId : null;
            $signIn->VoidReason = $reason;
            $signIn->save();
        }

        $approvedRecords = LearningRecord::where('ClassSessionID', $session->id)
            ->active()
            ->where('Status', 'approved')
            ->get();
        $approvedCountByTeacher = [];
        $hasDeductedColumn = Schema::hasColumn('LearningRecord', 'SessionDeducted');
        foreach ($approvedRecords as $record) {
            $teacherId = (int) ($record->TeacherID ?? 0);
            if ($teacherId > 0) {
                $approvedCountByTeacher[$teacherId] = ($approvedCountByTeacher[$teacherId] ?? 0) + 1;
            }
            $record->Status = 'pending';
            $record->ApprovedBy = null;
            $record->ApprovedAt = null;
            if ($hasDeductedColumn) {
                $record->SessionDeducted = false;
            }
            $record->save();
        }

        foreach ($approvedCountByTeacher as $teacherId => $count) {
            DB::table('User')->where('id', $teacherId)->update([
                'TeachingSessionCount' => DB::raw(
                    'CASE WHEN TeachingSessionCount >= ' . (int) $count
                    . ' THEN TeachingSessionCount - ' . (int) $count . ' ELSE 0 END'
                ),
            ]);
        }

        $wasAttended = in_array($oldStatus, ['attended', 'completed', 'late', 'absent'], true);
        if ($wasAttended || $hadDeductedSignIn || $approvedRecords->isNotEmpty()) {
            SessionDeductionService::reverseForSession(
                (int) $studentClass->ID,
                (int) $session->id,
                'status_adjust',
                $authUserId > 0 ? $authUserId : null,
                $reason
            );
        }

        $wasScheduled = strtolower(trim((string) $session->Status)) === 'scheduled';
        if (!$wasScheduled) {
            $session->Status = 'scheduled';
            $session->save();
        }
        SessionDeductionService::recomputeCounters((int) $studentClass->ID);

        return !$wasScheduled || $hadDeductedSignIn || $approvedRecords->isNotEmpty();
    }

    private function normalizeTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', trim((string) $value), $matches)) {
            return sprintf('%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0));
        }
        try {
            return Carbon::parse((string) $value)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
