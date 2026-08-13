<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\ScheduleAuditLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative writer for the flexible/逐堂手動排課 flow.
 *
 * Billing remains on StudentClass.ScheduleMode=count and RemainingSessions is
 * still consumption-derived. A future scheduled ClassSession reserves one
 * unit for booking purposes, but attendance remains the only deduction path.
 */
class ManualSessionBookingService
{
    public const POLICY = 'manual_occurrence';
    // Keep reservation capacity aligned with ScheduleGuardService and the
    // attendance/quota rules: an excused occurrence is not a live reservation.
    private const EXCLUDED_SESSION_STATUSES = ['cancelled', 'voided', 'leave', 'leave_adjusted', 'excused'];

    public function __construct(private ScheduleGuardService $scheduleGuardService) {}

    /**
     * @return array<string, mixed>
     */
    public function check(StudentClass $course, string $sessionDate, string $startTime, ?int $branchId = null): array
    {
        $date = Carbon::parse($sessionDate)->toDateString();
        $start = Carbon::createFromFormat('H:i', substr($startTime, 0, 5));
        $startHm = $start->format('H:i');
        $duration = $this->durationForDate($course, $date);
        $end = $start->copy()->addMinutes($duration);
        $packageId = (int) $course->getAttribute('PackageID');
        $studentId = (int) $course->getAttribute('StudentID');

        $package = null;
        if ($packageId > 0) {
            $package = CoursePackage::query()
                ->where('id', $packageId)
                ->where('student_id', $studentId)
                ->first();
        }

        $base = [
            'can_add' => false,
            'conflict_type' => 'none',
            'error_code' => null,
            'message' => '可建立下一堂',
            'session_date' => $date,
            'start_time' => $startHm,
            'end_time' => $end->format('H:i'),
            'duration_minutes' => $duration,
            'reserved_sessions' => 0,
            'remaining_sessions' => $package
                ? max(0, (int) $package->computeRemainingFromLedger())
                : max(0, (int) ($course->RemainingSessions ?? 0)),
            'available_sessions' => 0,
            'existing_session_id' => null,
            'suggested_actions' => [],
        ];

        if ($packageId > 0 && !$package) {
            return $this->blocked($base, 'package_not_found', '找不到共用方案，請重新整理後再試');
        }

        // Validate the course contract before idempotency. A stale matching row
        // must not let an auto-recurrence, stopped, or package course use this API.
        if (!in_array((string) ($course->ScheduleMode ?? 'count'), ['count'], true)) {
            return $this->blocked($base, 'not_count_course', 'Manual occurrence scheduling requires a session course');
        }
        if (!in_array((string) ($course->scheduling_policy ?? 'auto_recurrence'), [self::POLICY], true)) {
            return $this->blocked($base, 'manual_policy_required', 'Course is not configured for manual occurrence scheduling');
        }
        if ((int) ($course->Stop ?? 0) === 1) {
            return $this->blocked($base, 'course_stopped', 'Course is stopped');
        }
        if ((int) ($course->SessionCount ?? 0) <= 0) {
            return $this->blocked($base, 'session_count_missing', 'Course has no purchased session count');
        }

        $existing = ClassSession::query()
            ->where('StudentClassID', (int) $course->ID)
            ->whereDate('SessionDate', $date)
            ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$startHm])
            ->whereNotIn('Status', ['cancelled', 'voided'])
            ->orderBy('id')
            ->first();
        if ($existing) {
            return array_merge($base, [
                'can_add' => true,
                'message' => '已存在相同堂次，重送不會建立重複資料',
                'existing_session_id' => (int) $existing->id,
                'session' => $existing,
            ]);
        }

        if (!in_array((string) ($course->ScheduleMode ?? 'count'), ['count'], true)) {
            return $this->blocked($base, 'not_count_course', '逐堂手動排課目前只適用堂數制課程');
        }
        if (!in_array((string) ($course->scheduling_policy ?? 'auto_recurrence'), [self::POLICY], true)) {
            return $this->blocked($base, 'manual_policy_required', '請先將課程切換為逐堂手動排課');
        }
        if ((int) ($course->Stop ?? 0) === 1) {
            return $this->blocked($base, 'course_stopped', '課程已停用，不能新增堂次');
        }
        if ((int) ($course->SessionCount ?? 0) <= 0) {
            return $this->blocked($base, 'session_count_missing', '課程尚未設定購買堂數');
        }

        $now = Carbon::now();
        if ($date < $now->toDateString() || ($date === $now->toDateString() && $end->lte($now))) {
            return $this->blocked($base, 'past_session', '只能新增尚未結束的日期與時間');
        }

        $startDate = $course->StartDate ? Carbon::parse($course->StartDate)->toDateString() : null;
        $endDate = $course->EndDate ? Carbon::parse($course->EndDate)->toDateString() : null;
        if ($startDate && $date < $startDate) {
            return $this->blocked($base, 'before_course_start', '堂次日期不可早於課程開始日');
        }
        if ($endDate && $date > $endDate) {
            return $this->blocked($base, 'after_course_end', '堂次日期不可超過課程到期日');
        }

        $reserved = $this->reservedSessionCount($course, $now->toDateString());
        $remaining = (int) $base['remaining_sessions'];
        $available = max(0, $remaining - $reserved);
        $base['reserved_sessions'] = $reserved;
        $base['available_sessions'] = $available;
        if ($available <= 0) {
            return $this->blocked($base, 'reservation_limit', '剩餘堂數已被既有未來排課占用，請先完成、取消或調整既有堂次');
        }

        $studentRows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', (int) $course->StudentID)
            ->whereDate('cs.SessionDate', $date)
            ->whereNotIn('cs.Status', self::EXCLUDED_SESSION_STATUSES)
            ->where(function ($query) {
                $query->where('sc.Stop', 0)->orWhereNull('sc.Stop');
            })
            ->select(['cs.id', 'cs.StudentClassID', 'cs.StartTime', 'cs.EndTime'])
            ->get();
        foreach ($studentRows as $row) {
            if ($this->timesOverlap($startHm, $end->format('H:i'), (string) $row->StartTime, (string) $row->EndTime)) {
                return $this->blocked($base, 'student_conflict', '學生在同一時間已有另一堂課，請改選其他時段');
            }
        }

        $studentBranch = (int) (Student::where('id', (int) $course->StudentID)->value('CampusID') ?? 0);
        if ($branchId !== null && $branchId > 0 && $studentBranch > 0 && $branchId !== $studentBranch) {
            return $this->blocked($base, 'cross_branch', '課程分校與預約分校不同，無法建立手動堂次');
        }
        $branch = $studentBranch;
        if ((int) $course->TeacherID <= 0 || $branch <= 0) {
            return $this->blocked($base, 'course_configuration_missing', '課程缺少老師或分校設定，暫時不能排課');
        }
        if (!UserCampus::where('UserID', (int) $course->TeacherID)
            ->where('CampusID', $branch)->exists()) {
            return $this->blocked($base, 'cross_branch', '老師未被指派至課程分校，無法建立手動堂次');
        }

        $scheduleConflicts = $this->scheduleGuardService->validateScheduleOccurrence([
            'teacher_id' => (int) $course->TeacherID,
            'class_type' => (string) ($course->ClassType ?: 'one_on_one'),
            'room_id' => $course->room_id ? (int) $course->room_id : null,
            'branch_id' => $branch,
            'schedule_date' => $date,
            'start_time' => $startHm,
            'end_time' => $end->format('H:i'),
        ]);
        if (!empty($scheduleConflicts)) {
            $first = $scheduleConflicts[0];
            return array_merge($this->blocked($base, 'schedule_conflict', '老師或教室在這個時段已有安排，請改選其他時段'), [
                'conflicts' => $scheduleConflicts,
                'conflict_detail' => $first,
            ]);
        }

        $base['can_add'] = true;
        $base['message'] = '可建立下一堂';
        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        StudentClass $course,
        string $sessionDate,
        string $startTime,
        ?int $branchId = null,
        ?int $operatorId = null,
        ?string $idempotencyKey = null
    ): array
    {
        return DB::transaction(function () use ($course, $sessionDate, $startTime, $branchId, $operatorId, $idempotencyKey) {
            $lockedCourse = StudentClass::query()->where('ID', (int) $course->ID)->lockForUpdate()->firstOrFail();
            $packageId = (int) $lockedCourse->getAttribute('PackageID');
            if ($packageId > 0) {
                // Serialize package bookings so two operators cannot consume the
                // last pooled reservation at the same time (#228/#229).
                CoursePackage::query()
                    ->where('id', $packageId)
                    ->where('student_id', (int) $lockedCourse->getAttribute('StudentID'))
                    ->lockForUpdate()
                    ->first();
            }
            $check = $this->check($lockedCourse, $sessionDate, $startTime, $branchId);

            if (!$check['can_add']) {
                return $check;
            }

            if (!empty($check['existing_session_id'])) {
                $existing = ClassSession::find((int) $check['existing_session_id']);
                return array_merge($check, [
                    'created' => false,
                    'session' => $existing,
                ]);
            }

            $session = app(ClassSessionMaterializationService::class)->upsertSlot([
                'StudentClassID' => (int) $lockedCourse->ID,
                'SubjectID' => $lockedCourse->SubjectID ?: null,
                'SessionDate' => $check['session_date'],
                'StartTime' => $check['start_time'] . ':00',
                'EndTime' => $check['end_time'] . ':00',
                'Status' => 'scheduled',
                'Note' => '逐堂手動排課',
                'IsContractException' => 0,
                '_revive_cancelled' => true,
                '_student_class' => $lockedCourse,
            ])['session'];

            if (Schema::hasTable('schedule_audit_logs')) {
                ScheduleAuditLog::create([
                    'session_id' => (int) $session->id,
                    'action_type' => 'create',
                    'description' => 'manual_occurrence booking',
                    'operator_id' => $operatorId ?: null,
                    'branch_id' => $branchId ?: (int) (Student::where('id', (int) $lockedCourse->StudentID)->value('CampusID') ?? 0),
                    'new_data' => [
                        'source' => 'manual_occurrence',
                        'scheduling_policy' => self::POLICY,
                        'occurrence_key' => sprintf('%d|%s|%s', $lockedCourse->ID, $check['session_date'], $check['start_time']),
                        'idempotency_key' => $idempotencyKey,
                        'reserved_sessions' => $check['reserved_sessions'],
                        'remaining_sessions' => $check['remaining_sessions'],
                    ],
                ]);
            }

            return array_merge($check, [
                'created' => true,
                'session' => $session,
            ]);
        });
    }

    private function reservedSessionCount(StudentClass $course, string $today): int
    {
        $query = ClassSession::query()
            ->whereDate('SessionDate', '>=', $today)
            ->whereNotIn('Status', self::EXCLUDED_SESSION_STATUSES);

        $packageId = (int) $course->getAttribute('PackageID');
        if ($packageId > 0) {
            $memberIds = StudentClass::query()
                ->where('PackageID', $packageId)
                ->where('StudentID', (int) $course->getAttribute('StudentID'))
                ->pluck('ID');
            $query->whereIn('StudentClassID', $memberIds);
        } else {
            $query->where('StudentClassID', (int) $course->ID);
        }

        return (int) $query->count();
    }

    private function durationForDate(StudentClass $course, string $date): int
    {
        $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
        $duration = (int) $course->resolveSessionDurationForWeekday($isoDow);

        return max(30, min(480, $duration ?: 120));
    }

    private function timesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        $aStart = $this->minutes($startA);
        $aEnd = $this->minutes($endA);
        $bStart = $this->minutes($startB);
        $bEnd = $this->minutes($endB);

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
        return ($hour * 60) + $minute;
    }

    /** @param array<string, mixed> $base */
    private function blocked(array $base, string $code, string $message): array
    {
        return array_merge($base, [
            'can_add' => false,
            'conflict_type' => $code,
            'error_code' => strtoupper($code),
            'message' => $message,
            'suggested_actions' => ['改選其他日期或時間'],
        ]);
    }
}
