<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\ScheduleGuardService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    public function __construct(private ScheduleGuardService $scheduleGuardService)
    {
    }

    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $query = Schedule::query();

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('branch_id', $bid);
        } elseif (!empty($campusIds)) {
            $query->whereIn('branch_id', $campusIds);
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->input('teacher_id'));
        }

        if ($request->filled('student_course_id')) {
            $query->where('student_course_id', $request->input('student_course_id'));
        }

        if ($request->filled('status')) {
            $statusInput = $request->input('status');
            if (str_contains($statusInput, ',')) {
                $statuses = array_map('trim', explode(',', $statusInput));
                $query->whereIn('status', $statuses);
            } else {
                $query->where('status', $statusInput);
            }
        }

        // day_of_week filter (used by DirectorDashboard recurring schedules)
        if ($request->filled('day_of_week')) {
            $query->where('day_of_week', $request->input('day_of_week'));
        }

        // schedule_date__is=null — match rows with NULL schedule_date
        if ($request->input('schedule_date__is') === 'null') {
            $query->whereNull('schedule_date');
        } elseif ($request->filled('schedule_date')) {
            $query->where('schedule_date', $request->input('schedule_date'));
        }

        // Date range filters (start/end)
        if ($request->filled('start')) {
            $query->where('schedule_date', '>=', $request->input('start'));
        }
        if ($request->filled('end')) {
            $query->where('schedule_date', '<=', $request->input('end'));
        }

        // __limit shortcut
        if ($request->filled('__limit')) {
            $query->limit((int) $request->input('__limit'));
        }

        $perPage = $request->input('per_page');
        if ($perPage === 'all' || (int) $perPage >= 1000) {
            $query->limit(5000);
            return response()->json($query->orderBy('schedule_date', 'asc')->get());
        }

        return response()->json($query->orderBy('schedule_date', 'asc')->paginate(min((int) ($perPage ?? 200), 1000)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id'           => 'required|integer',
            'teacher_id'           => 'nullable|integer',
            'subject'              => 'nullable|string|max:32',
            'day_of_week'          => 'required|integer|min:0|max:7',
            'start_time'           => 'required|string|max:8',
            'end_time'             => 'required|string|max:8',
            'duration_hours'       => 'nullable|numeric',
            'class_type'           => 'nullable|string|max:32',
            'status'               => 'nullable|string|max:32',
            'type'                 => 'nullable|string|max:16',
            'deduction'            => 'nullable|integer',
            'branch_id'            => 'required|integer',
            'schedule_date'        => 'nullable|date',
            'student_course_id'    => 'nullable|integer',
            'original_schedule_id' => 'nullable|integer',
        ]);

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $branchId = (int) ($data['branch_id'] ?? 0);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'teacher') {
            $authTeacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            if ($authTeacherId <= 0) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if (!empty($data['teacher_id']) && (int) $data['teacher_id'] !== $authTeacherId) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if (empty($data['teacher_id'])) {
                $data['teacher_id'] = $authTeacherId;
            }
        }

        $studentCampusId = (int) (Student::where('id', (int) $data['student_id'])->value('CampusID') ?? 0);
        if ($studentCampusId > 0 && $studentCampusId !== $branchId) {
            return response()->json(['message' => '學生不屬於該分校，無法建立調課紀錄'], 422);
        }

        $effectiveTeacherId = (int) ($data['teacher_id'] ?? 0);
        $effectiveClassType = (string) ($data['class_type'] ?? 'one_on_one');
        $effectiveRoomId = null;

        $courseId = (int) ($data['student_course_id'] ?? 0);
        if ($courseId > 0) {
            $courseMeta = DB::table('StudentClass as sc')
                ->join('Student as st', 'st.id', '=', 'sc.StudentID')
                ->where('sc.ID', $courseId)
                ->select(['sc.ID', 'sc.TeacherID', 'sc.ClassType', 'sc.room_id', 'st.CampusID'])
                ->first();

            if (!$courseMeta) {
                return response()->json(['message' => '找不到對應課程，無法調課'], 422);
            }
            if ((int) $courseMeta->CampusID !== $branchId) {
                return response()->json(['message' => '課程不屬於該分校，無法調課'], 422);
            }

            if ($effectiveTeacherId <= 0) {
                $effectiveTeacherId = (int) ($courseMeta->TeacherID ?? 0);
            }
            if (empty($data['class_type'])) {
                $effectiveClassType = (string) ($courseMeta->ClassType ?: 'one_on_one');
            }
            if (!empty($courseMeta->room_id)) {
                $effectiveRoomId = (int) $courseMeta->room_id;
            }
        }

        if ($effectiveTeacherId > 0) {
            $data['teacher_id'] = $effectiveTeacherId;
        }
        if (empty($data['class_type'])) {
            $data['class_type'] = $effectiveClassType;
        }
        if (empty($data['status'])) {
            $data['status'] = 'scheduled';
        }

        if (($data['status'] ?? 'scheduled') === 'scheduled') {
            $guardConflicts = $this->scheduleGuardService->validateScheduleOccurrence([
                'teacher_id' => $effectiveTeacherId,
                'class_type' => $effectiveClassType,
                'room_id' => $effectiveRoomId,
                'branch_id' => $branchId,
                'schedule_date' => $data['schedule_date'] ?? null,
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
            ]);

            if (!empty($guardConflicts)) {
                Log::warning('Schedule create conflict', [
                    'student_id' => (int) ($data['student_id'] ?? 0),
                    'teacher_id' => $effectiveTeacherId,
                    'student_course_id' => $courseId > 0 ? $courseId : null,
                    'branch_id' => $branchId,
                    'schedule_date' => $data['schedule_date'] ?? null,
                    'start_time' => $data['start_time'] ?? null,
                    'end_time' => $data['end_time'] ?? null,
                    'class_type' => $effectiveClassType,
                    'conflicts' => $guardConflicts,
                ]);
                return response()->json([
                    'message' => $guardConflicts[0]['message'] ?? 'Teacher scheduling conflict detected',
                    'conflicts' => $guardConflicts,
                ], 409);
            }
        }

        $status = strtolower((string) ($data['status'] ?? 'scheduled'));
        if ($status === 'leave') {
            if ($courseId <= 0 || empty($data['schedule_date'])) {
                return response()->json(['message' => '請假需指定課程與堂次日期'], 422);
            }

            try {
                return DB::transaction(function () use ($data, $courseId) {
                    $schedule = Schedule::create($data);
                    [$rows, $extendedEndDate, $leaveSessionDate] = $this->applyLeaveCascade((int) $courseId, (string) $data['schedule_date']);

                    return response()->json([
                        'message' => '請假登記完成，該堂已標記請假且後續課程已順延',
                        'schedule' => $schedule,
                        'leave_session_date' => $leaveSessionDate,
                        'extended_end_date' => $extendedEndDate,
                        'class_sessions' => $rows,
                    ], 201);
                });
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $schedule = Schedule::create($data);
        return response()->json($schedule, 201);
    }

    /**
     * 將指定堂次標記為 leave（保留不刪除），清理相關評量與出席，
     * 並把後續未完成堂次往後遞延一個固定排課週期，
     * 最後補上一堂新的 scheduled，確保有效上課堂數不減少。
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string,2:string}
     */
    private function applyLeaveCascade(int $courseId, string $leaveDate): array
    {
        $course = StudentClass::where('ID', $courseId)->lockForUpdate()->first();
        if (!$course) {
            throw new \InvalidArgumentException('找不到課程，無法請假');
        }

        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
        if ($sessions->isEmpty()) {
            throw new \InvalidArgumentException('課程尚無堂次可請假');
        }

        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();
        $leaveSession = $sessions->first(function ($session) use ($normalizedLeaveDate) {
            $status = strtolower((string) ($session->Status ?? ''));
            return Carbon::parse($session->SessionDate)->toDateString() === $normalizedLeaveDate
                && !in_array($status, ['cancelled', 'leave', 'leave_adjusted'], true);
        });
        if (!$leaveSession) {
            throw new \InvalidArgumentException('找不到可請假的堂次');
        }

        $leaveStatus = strtolower((string) ($leaveSession->Status ?? ''));
        if (in_array($leaveStatus, ['completed', 'attended'], true)) {
            throw new \InvalidArgumentException('已完成堂次不可請假（如需補請假請使用 retro-leave）');
        }
        $hasApprovedRecord = LearningRecord::where('ClassSessionID', $leaveSession->id)
            ->active()
            ->where('Status', 'approved')
            ->exists();
        if ($hasApprovedRecord) {
            throw new \InvalidArgumentException('該堂已有核准評量，無法改為請假');
        }

        $leaveSessionDate = Carbon::parse($leaveSession->SessionDate)->toDateString();

        // Void (not delete) related records
        LearningRecord::where('ClassSessionID', (int) $leaveSession->id)
            ->active()
            ->update([
                'VoidedAt'        => now(),
                'VoidedByUserID'  => null,
                'VoidReason'      => '一般請假',
            ]);
        StudentSignIn::where('ClassSessionID', (int) $leaveSession->id)
            ->active()
            ->update([
                'VoidedAt'        => now(),
                'VoidedByUserID'  => null,
                'VoidReason'      => '一般請假',
            ]);

        $leaveSession->Status = 'leave';
        $leaveSession->Note = $this->appendNote($leaveSession->Note, 'leave');
        $leaveSession->save();

        [$rows, $extendedEndDate] = $this->shiftAndAppendAfterLeave($courseId, $leaveSessionDate, $leaveSession);

        return [$rows, $extendedEndDate, $leaveSessionDate];
    }

    /**
     * @return array<int>
     */
    private function resolveCourseWeekdays(StudentClass $course, int $fallbackIsoDow): array
    {
        $weekdays = [];
        foreach (['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $field) {
            $dow = (int) ($course->{$field} ?? 0);
            if ($dow >= 1 && $dow <= 7) {
                $weekdays[$dow] = $dow;
            }
        }
        if (empty($weekdays)) {
            $dow = max(1, min(7, $fallbackIsoDow));
            $weekdays[$dow] = $dow;
        }
        ksort($weekdays);
        return array_values($weekdays);
    }

    /**
     * @param  array<int>  $weekdays
     * @param  array<string,bool>  $occupiedDates
     */
    private function nextRecurringDate(Carbon $afterDate, array $weekdays, array $occupiedDates): string
    {
        $cursor = $afterDate->copy()->addDay()->startOfDay();
        $guard = 0;
        while ($guard < 3660) {
            $guard++;
            $isoDow = (int) $cursor->dayOfWeekIso;
            $date = $cursor->toDateString();
            if (in_array($isoDow, $weekdays, true) && !isset($occupiedDates[$date])) {
                return $date;
            }
            $cursor->addDay();
        }
        throw new \InvalidArgumentException('請假遞延失敗：找不到可用的後續上課日期');
    }

    private function syncLearningRecordSessionDate(ClassSession $session): void
    {
        LearningRecord::where('ClassSessionID', (int) $session->id)->update([
            'SessionDate' => $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null,
            'StartTime' => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
            'EndTime' => $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null,
        ]);
    }

    /**
     * @param  array<string,bool>  $dateMap
     */
    private function maxDateKey(array $dateMap): ?string
    {
        if (empty($dateMap)) {
            return null;
        }
        $keys = array_keys($dateMap);
        sort($keys, SORT_STRING);
        return end($keys) ?: null;
    }

    private function appendNote($existing, string $suffix): string
    {
        $base = trim((string) ($existing ?? ''));
        if ($base === '') {
            return $suffix;
        }
        if (str_contains($base, $suffix)) {
            return $base;
        }
        return $base . '; ' . $suffix;
    }

    /**
     * Retroactive leave: convert an already-attended session to leave.
     * Voids related attendance and learning records, writes a reverse
     * ledger entry to restore the deducted session, changes ClassSession
     * status to leave_adjusted, then runs the normal cascade (shift +
     * append).  Director/admin only.
     */
    public function retroLeave(Request $request)
    {
        $data = $request->validate([
            'student_course_id' => 'required|integer',
            'session_date'      => 'required|date',
            'reason'            => 'nullable|string|max:255',
        ]);

        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'], true)) {
            return response()->json(['message' => '僅主任/管理員可執行補請假'], 403);
        }

        $authUser = $request->attributes->get('auth_user');
        $authUserId = (int) ($authUser->id ?? 0);
        $courseId = (int) $data['student_course_id'];
        $sessionDate = Carbon::parse($data['session_date'])->toDateString();
        $reason = trim((string) ($data['reason'] ?? ''));

        try {
            return DB::transaction(function () use ($courseId, $sessionDate, $reason, $authUserId, $role, $request) {
                $course = StudentClass::where('ID', $courseId)->lockForUpdate()->first();
                if (!$course) {
                    return response()->json(['message' => '找不到課程'], 404);
                }

                $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
                $studentCampusId = (int) (Student::where('id', (int) $course->StudentID)->value('CampusID') ?? 0);
                if ($role !== 'super_admin' && !empty($campusIds) && !in_array($studentCampusId, $campusIds, true)) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }

                $session = ClassSession::where('StudentClassID', $courseId)
                    ->whereRaw("DATE(SessionDate) = ?", [$sessionDate])
                    ->lockForUpdate()
                    ->first();
                if (!$session) {
                    return response()->json(['message' => '找不到該日堂次'], 404);
                }

                $status = strtolower((string) ($session->Status ?? ''));

                if (in_array($status, ['leave', 'leave_adjusted', 'cancelled'], true)) {
                    return response()->json(['message' => '該堂已是請假/取消狀態'], 422);
                }

                $isAttended = in_array($status, ['attended', 'completed', 'late', 'present', 'absent', 'excused'], true);

                if (!$isAttended && $status === 'scheduled') {
                    // Delegate to normal leave cascade for non-attended sessions
                    [$rows, $extendedEndDate, $leaveSessionDate] = $this->applyLeaveCascade($courseId, $sessionDate);
                    return response()->json([
                        'message'             => '請假登記完成，該堂已標記請假且後續課程已順延',
                        'leave_session_date'  => $leaveSessionDate,
                        'extended_end_date'   => $extendedEndDate,
                        'class_sessions'      => $rows,
                    ]);
                }

                // ── Void attendance records ──
                $signIns = StudentSignIn::where('ClassSessionID', $session->id)
                    ->active()
                    ->lockForUpdate()
                    ->get();

                foreach ($signIns as $si) {
                    $si->VoidedAt = now();
                    $si->VoidedByUserID = $authUserId;
                    $si->VoidReason = $reason ?: '補請假：已上課改請假';
                    $si->save();
                }

                // ── Void learning records ──
                $learningRecords = LearningRecord::where('ClassSessionID', $session->id)
                    ->active()
                    ->lockForUpdate()
                    ->get();

                foreach ($learningRecords as $lr) {
                    $lr->VoidedAt = now();
                    $lr->VoidedByUserID = $authUserId;
                    $lr->VoidReason = $reason ?: '補請假：已上課改請假';
                    $lr->save();
                }

                // ── Reverse ledger entry ──
                SessionDeductionService::reverseForSession(
                    $courseId,
                    (int) $session->id,
                    'retro_leave',
                    $authUserId,
                    $reason ?: '補請假沖回'
                );

                // ── Update ClassSession to leave_adjusted ──
                $session->Status = 'leave_adjusted';
                $session->Note = $this->appendNote($session->Note, 'retro-leave');
                $session->save();

                // ── Cascade: shift subsequent sessions + append one ──
                [$rows, $extendedEndDate] = $this->shiftAndAppendAfterLeave($courseId, $sessionDate, $session);

                // ── Recompute counters from ledger ──
                SessionDeductionService::recomputeCounters($courseId);

                return response()->json([
                    'message'             => '補請假完成：堂數已沖回、堂次標記請假、後續課程已順延',
                    'leave_session_date'  => $sessionDate,
                    'extended_end_date'   => $extendedEndDate,
                    'class_sessions'      => $rows,
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * After marking a session as leave/leave_adjusted, shift remaining
     * future scheduled sessions forward and append one new session.
     * Extracted from applyLeaveCascade for reuse.
     *
     * @return array{0:array,1:?string}
     */
    private function shiftAndAppendAfterLeave(int $courseId, string $leaveDate, ClassSession $leaveSession): array
    {
        $course = StudentClass::where('ID', $courseId)->first();
        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();

        $sessionsToShift = $sessions
            ->filter(function ($s) use ($normalizedLeaveDate, $leaveSession) {
                if ((int) $s->id === (int) $leaveSession->id) {
                    return false;
                }
                $d = Carbon::parse($s->SessionDate)->toDateString();
                if ($d <= $normalizedLeaveDate) {
                    return false;
                }
                $st = strtolower((string) ($s->Status ?? ''));
                return !in_array($st, ['completed', 'attended', 'cancelled', 'leave', 'leave_adjusted'], true);
            })
            ->values();

        $shiftIdSet = [];
        foreach ($sessionsToShift as $s) {
            $shiftIdSet[(int) $s->id] = true;
        }

        $occupiedDates = [];
        $occupiedDates[$normalizedLeaveDate] = true;
        foreach ($sessions as $s) {
            if (isset($shiftIdSet[(int) $s->id])) {
                continue;
            }
            $occupiedDates[Carbon::parse($s->SessionDate)->toDateString()] = true;
        }

        $weekdays = $this->resolveCourseWeekdays(
            $course,
            Carbon::parse($leaveSession->SessionDate)->dayOfWeekIso
        );

        $templateSession = $sessionsToShift->last() ?: $leaveSession;
        foreach ($sessionsToShift as $s) {
            $currentDate = Carbon::parse($s->SessionDate)->startOfDay();
            $newDate = $this->nextRecurringDate($currentDate, $weekdays, $occupiedDates);
            $s->SessionDate = $newDate;
            $s->save();
            $this->syncLearningRecordSessionDate($s);
            $occupiedDates[$newDate] = true;
            $templateSession = $s;
        }

        $latestDate = $this->maxDateKey($occupiedDates);
        if (!$latestDate) {
            $latestDate = $normalizedLeaveDate;
        }
        $appendDate = $this->nextRecurringDate(Carbon::parse($latestDate)->startOfDay(), $weekdays, $occupiedDates);
        $newSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate'    => $appendDate,
            'StartTime'      => $templateSession->StartTime,
            'EndTime'        => $templateSession->EndTime,
            'Status'         => 'scheduled',
            'Note'           => $this->appendNote($templateSession->Note, 'auto-extended-after-leave'),
        ]);
        $occupiedDates[$appendDate] = true;
        $this->syncLearningRecordSessionDate($newSession);

        $extendedEndDate = $this->maxDateKey($occupiedDates);
        if ($extendedEndDate) {
            DB::table('StudentClass')
                ->where('ID', $courseId)
                ->update(['EndDate' => $extendedEndDate]);
        }

        $rows = $this->fetchCourseSessionRows($courseId);
        return [$rows, $extendedEndDate];
    }

    /**
     * Bulk leave for a holiday period: mark all eligible sessions in the
     * given branch + date range as leave, with the same cascade logic.
     */
    public function bulkHolidayLeave(Request $request)
    {
        $data = $request->validate([
            'branch_id'  => 'required|integer',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $role = $request->attributes->get('auth_role');
        if ($role === 'teacher') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $branchId = (int) $data['branch_id'];
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $startDate = Carbon::parse($data['start_date'])->toDateString();
        $endDate = Carbon::parse($data['end_date'])->toDateString();

        $eligibleSessions = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereBetween('cs.SessionDate', [$startDate, $endDate])
            ->whereNotIn(DB::raw('LOWER(cs.Status)'), ['completed', 'attended', 'cancelled', 'leave'])
            ->select(['cs.id as session_id', 'cs.StudentClassID as course_id', 'cs.SessionDate'])
            ->orderBy('cs.SessionDate')
            ->orderBy('cs.StudentClassID')
            ->get();

        $processed = 0;
        $skipped = [];
        $affectedCourseIds = [];
        $seen = [];

        foreach ($eligibleSessions as $row) {
            $courseId = (int) $row->course_id;
            $sessionDate = Carbon::parse($row->SessionDate)->toDateString();
            $key = "{$courseId}_{$sessionDate}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $hasApproved = LearningRecord::where('ClassSessionID', (int) $row->session_id)
                ->where('Status', 'approved')
                ->exists();
            if ($hasApproved) {
                $skipped[] = [
                    'course_id' => $courseId,
                    'session_date' => $sessionDate,
                    'reason' => '該堂已有核准評量',
                ];
                continue;
            }

            try {
                DB::transaction(function () use ($courseId, $sessionDate, $branchId) {
                    $this->applyLeaveCascade($courseId, $sessionDate);

                    $course = StudentClass::where('ID', $courseId)->first();
                    if ($course) {
                        $studentId = (int) ($course->StudentID ?? 0);
                        $teacherId = (int) ($course->TeacherID ?? 0);
                        $subject = (string) ($course->SubjectID ?? '');
                        $classType = (string) ($course->ClassType ?? 'one_on_one');
                        $startTime = $course->time ? substr((string) $course->time, 0, 5) : '16:00';
                        $endTime = $course->time2 ? substr((string) $course->time2, 0, 5) : '18:00';
                        $dayOfWeek = Carbon::parse($sessionDate)->dayOfWeekIso;

                        Schedule::create([
                            'student_id' => $studentId,
                            'teacher_id' => $teacherId > 0 ? $teacherId : null,
                            'subject' => $subject,
                            'day_of_week' => $dayOfWeek,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'class_type' => $classType,
                            'status' => 'leave',
                            'type' => 'normal',
                            'deduction' => 0,
                            'branch_id' => $branchId,
                            'schedule_date' => $sessionDate,
                            'student_course_id' => $courseId,
                        ]);
                    }
                });

                $processed++;
                if (!in_array($courseId, $affectedCourseIds, true)) {
                    $affectedCourseIds[] = $courseId;
                }
            } catch (\Exception $e) {
                Log::warning('Bulk leave skip', [
                    'course_id' => $courseId,
                    'session_date' => $sessionDate,
                    'error' => $e->getMessage(),
                ]);
                $skipped[] = [
                    'course_id' => $courseId,
                    'session_date' => $sessionDate,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "批次請假完成：已處理 {$processed} 筆，略過 " . count($skipped) . ' 筆',
            'processed_count' => $processed,
            'skipped_count' => count($skipped),
            'skipped' => $skipped,
            'affected_course_ids' => $affectedCourseIds,
        ]);
    }

    private function fetchCourseSessionRows(int $courseId): array
    {
        return DB::table('ClassSession as cs')
            ->leftJoin('LearningRecord as lr', 'lr.ClassSessionID', '=', 'cs.id')
            ->where('cs.StudentClassID', $courseId)
            ->select([
                'cs.id',
                'cs.StudentClassID',
                'cs.SessionDate',
                'cs.StartTime',
                'cs.EndTime',
                'cs.Status',
                'lr.id as learning_record_id',
                'lr.Status as learning_record_status',
            ])
            ->orderBy('cs.SessionDate', 'asc')
            ->orderBy('cs.StartTime', 'asc')
            ->orderBy('cs.id', 'asc')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'StudentClassID' => (int) $row->StudentClassID,
                    'SessionDate' => $row->SessionDate ? substr((string) $row->SessionDate, 0, 10) : null,
                    'StartTime' => $row->StartTime ? substr((string) $row->StartTime, 0, 5) : null,
                    'EndTime' => $row->EndTime ? substr((string) $row->EndTime, 0, 5) : null,
                    'Status' => (string) ($row->Status ?? ''),
                    'learning_record_id' => $row->learning_record_id !== null ? (int) $row->learning_record_id : null,
                    'learning_record_status' => $row->learning_record_status ?? 'missing',
                ];
            })
            ->values()
            ->all();
    }

    public function update(Request $request, Schedule $schedule)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $branchId = (int) ($schedule->branch_id ?? 0);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'teacher') {
            $authTeacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            if ($authTeacherId <= 0) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $incomingTeacherId = (int) ($request->input('teacher_id') ?? ($schedule->teacher_id ?? 0));
            if ($incomingTeacherId !== $authTeacherId) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $data = $request->validate([
            'status'        => 'nullable|string|max:32',
            'type'          => 'nullable|string|max:16',
            'schedule_date' => 'nullable|date',
            'start_time'    => 'nullable|string|max:8',
            'end_time'      => 'nullable|string|max:8',
            'teacher_id'    => 'nullable|integer',
            'class_type'    => 'nullable|string|max:32',
        ]);

        $merged = array_merge($schedule->toArray(), array_filter($data, fn ($v) => $v !== null));
        $effectiveTeacherId = (int) ($merged['teacher_id'] ?? 0);
        $effectiveClassType = (string) ($merged['class_type'] ?? 'one_on_one');
        $effectiveRoomId = null;
        $courseId = (int) ($merged['student_course_id'] ?? 0);
        if ($courseId > 0) {
            $courseMeta = DB::table('StudentClass')
                ->where('ID', $courseId)
                ->select(['ClassType', 'room_id'])
                ->first();
            if ($courseMeta) {
                if (empty($merged['class_type'])) {
                    $effectiveClassType = (string) ($courseMeta->ClassType ?: 'one_on_one');
                }
                if (!empty($courseMeta->room_id)) {
                    $effectiveRoomId = (int) $courseMeta->room_id;
                }
            }
        }

        if (($merged['status'] ?? 'scheduled') === 'scheduled') {
            $guardConflicts = $this->scheduleGuardService->validateScheduleOccurrence([
                'teacher_id' => $effectiveTeacherId,
                'class_type' => $effectiveClassType,
                'room_id' => $effectiveRoomId,
                'branch_id' => $branchId,
                'schedule_date' => $merged['schedule_date'] ?? null,
                'start_time' => $merged['start_time'] ?? null,
                'end_time' => $merged['end_time'] ?? null,
                'exclude_schedule_id' => (int) $schedule->id,
            ]);
            if (!empty($guardConflicts)) {
                Log::warning('Schedule update conflict', [
                    'schedule_id' => (int) $schedule->id,
                    'student_id' => (int) ($merged['student_id'] ?? 0),
                    'teacher_id' => $effectiveTeacherId,
                    'student_course_id' => $courseId > 0 ? $courseId : null,
                    'branch_id' => $branchId,
                    'schedule_date' => $merged['schedule_date'] ?? null,
                    'start_time' => $merged['start_time'] ?? null,
                    'end_time' => $merged['end_time'] ?? null,
                    'class_type' => $effectiveClassType,
                    'conflicts' => $guardConflicts,
                ]);
                return response()->json([
                    'message' => $guardConflicts[0]['message'] ?? 'Teacher scheduling conflict detected',
                    'conflicts' => $guardConflicts,
                ], 409);
            }
        }

        $schedule->fill(array_filter($data, fn ($v) => $v !== null));
        $schedule->save();

        return response()->json($schedule);
    }

    public function destroy(Schedule $schedule)
    {
        $schedule->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
