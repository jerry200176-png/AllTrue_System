<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\UserCampus;
use App\Services\EnrollmentService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClassSessionController extends Controller
{
    public function batchStore(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:Student,id',
            'teacher_id' => 'required|integer|exists:User,id',
            'subject' => 'required|string|max:64',
            'class_type' => 'required|in:one_on_one,one_on_two,one_on_three,tutoring',
            'total_classes' => 'nullable|integer|min:1|max:500',
            'confirmed_dates' => 'present|array|max:500',
            'confirmed_dates.*' => 'date',
            'future_dates' => 'present|array|max:500',
            'future_dates.*' => 'required|date',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:1|max:7',
            'start_time' => 'required_without:day_time_slots|date_format:H:i',
            'day_time_slots' => 'nullable|array|max:7',
            'day_time_slots.*.day' => 'required_with:day_time_slots|integer|min:1|max:7',
            'day_time_slots.*.start_time' => 'required_with:day_time_slots|date_format:H:i',
            'day_time_slots.*.duration_minutes' => 'nullable|integer|min:30|max:480',
            'duration_minutes' => 'required|integer|min:30|max:480',
            'rate_unit' => 'nullable|in:session,hour',
            'price_per_session' => 'required|numeric|min:0',
            'payment_type' => 'required|in:session,monthly',
            'settlement_day' => 'nullable|integer|min:1|max:31',
            'monthly_sessions' => 'nullable|integer|min:1|max:500',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'memo' => 'nullable|string|max:512',
            'branch_id' => 'nullable|integer|min:1',
            'mode' => 'nullable|in:create,backfill',
        ]);

        return app(EnrollmentService::class)->store($request, $data);
    }

    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);

        if ($role === 'teacher' && $teacherId <= 0) {
            return response()->json(['message' => 'Teacher not linked'], 403);
        }

        $requestedCampus = (int) ($request->input('branch_id') ?? $request->input('campus_id') ?? 0);
        if ($requestedCampus > 0) {
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($requestedCampus, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
            }
            $campusIds = [$requestedCampus];
        }

        $query = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('LearningRecord as lr', function ($join) {
                $join->on('lr.ClassSessionID', '=', 'cs.id')
                     ->whereNull('lr.VoidedAt');
            })
            ->leftJoin('StudentSingIn as si', function ($join) {
                $join->on('si.ClassSessionID', '=', 'cs.id')
                     ->whereNull('si.VoidedAt');
            })
            ->leftJoin('Teacher as t', 't.id', '=', 'sc.TeacherID')
            ->leftJoin('User as u', 'u.id', '=', 'sc.TeacherID')
            ->leftJoin('Teacher as sit', 'sit.id', '=', 'si.TeacherID')
            ->leftJoin('User as siu', 'siu.id', '=', 'si.TeacherID')
            ->leftJoin('User as rbu', 'rbu.id', '=', 'si.RecordedByUserID')
            ->select([
                'cs.id',
                'cs.StudentClassID',
                'cs.SessionDate',
                'cs.StartTime',
                'cs.EndTime',
                'cs.Status',
                'sc.StudentID',
                'sc.TeacherID',
                's.CampusID',
                's.name as student_name',
                DB::raw('COALESCE(t.T_Name, u.Name, "") as teacher_name'),
                'lr.id as learning_record_id',
                'lr.Status as learning_record_status',
                'si.SignInDT as attendance_sign_in_at',
                'si.Memo as attendance_memo',
                DB::raw('COALESCE(rbu.Name, sit.T_Name, siu.Name, "") as recorded_by_name'),
            ]);

        if ($role === 'teacher') {
            $query->where('sc.TeacherID', $teacherId);
        }

        if (!empty($campusIds)) {
            $query->whereIn('s.CampusID', $campusIds);
        }

        if ($request->filled('teacher_id')) {
            $query->where('sc.TeacherID', (int) $request->input('teacher_id'));
        }

        if ($request->filled('student_id')) {
            $query->where('sc.StudentID', (int) $request->input('student_id'));
        }

        if ($request->filled('student_class_id')) {
            $query->where('sc.ID', (int) $request->input('student_class_id'));
        }

        if ($request->filled('student_class_ids')) {
            $ids = $this->normalizeIds($request->input('student_class_ids'));
            if (!empty($ids)) {
                $query->whereIn('sc.ID', $ids);
            }
        }

        if ($request->filled('status')) {
            $statuses = $this->normalizeStringList($request->input('status'));
            if (!empty($statuses)) {
                $query->whereIn('cs.Status', $statuses);
            }
        }

        if ($request->filled('learning_record_status')) {
            $lrStatuses = $this->normalizeStringList($request->input('learning_record_status'));
            if (!empty($lrStatuses)) {
                $query->whereIn('lr.Status', $lrStatuses);
            }
        }

        if ($request->filled('start')) {
            $query->whereDate('cs.SessionDate', '>=', $request->input('start'));
        }

        if ($request->filled('end')) {
            $query->whereDate('cs.SessionDate', '<=', $request->input('end'));
        }

        $perPage = min(max((int) $request->input('per_page', 1000), 1), 2000);
        $rows = $query
            ->orderBy('cs.SessionDate', 'asc')
            ->orderBy('cs.StartTime', 'asc')
            ->orderBy('cs.id', 'asc')
            ->paginate($perPage);

        $rows->getCollection()->transform(function ($row) {
            $row->id = (int) $row->id;
            $row->student_class_id = (int) $row->StudentClassID;
            $row->student_id = (int) $row->StudentID;
            $row->teacher_id = (int) ($row->TeacherID ?? 0);
            $row->branch_id = (int) ($row->CampusID ?? 0);
            $row->session_date = $row->SessionDate ? substr((string) $row->SessionDate, 0, 10) : null;
            $row->start_time = $row->StartTime ? substr((string) $row->StartTime, 0, 5) : null;
            $row->end_time = $row->EndTime ? substr((string) $row->EndTime, 0, 5) : null;
            $row->status = (string) ($row->Status ?? '');
            $row->learning_record_id = $row->learning_record_id !== null ? (int) $row->learning_record_id : null;
            $row->learning_record_status = $row->learning_record_status ?? 'missing';
            $row->attendance_sign_in_at = $row->attendance_sign_in_at ?: null;
            $row->attendance_memo = $row->attendance_memo ?: '';
            $row->recorded_by_name = (string) ($row->recorded_by_name ?? '');
            unset(
                $row->StudentClassID,
                $row->StudentID,
                $row->TeacherID,
                $row->CampusID,
                $row->SessionDate,
                $row->StartTime,
                $row->EndTime,
                $row->Status
            );
            return $row;
        });

        $byClass = [];
        foreach ($rows->items() as $item) {
            $key = (string) $item->student_class_id;
            if (!isset($byClass[$key])) {
                $byClass[$key] = [];
            }
            $byClass[$key][] = $item;
        }

        return response()->json([
            'data' => $rows->items(),
            'by_class' => $byClass,
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
        ]);
    }

    /**
     * State-machine transitions for ClassSession.Status.
     * Key = current status, value = allowed next statuses.
     */
    private const STATUS_TRANSITIONS = [
        'scheduled'      => ['attended', 'late', 'absent', 'excused', 'leave', 'cancelled'],
        'attended'       => ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'excused', 'cancelled'],
        'completed'      => ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'excused', 'cancelled'],
        'late'           => ['leave', 'leave_adjusted', 'scheduled', 'attended', 'absent', 'excused', 'cancelled'],
        'absent'         => ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'excused', 'cancelled'],
        'excused'        => ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'absent', 'cancelled'],
        'leave'          => ['scheduled', 'cancelled'],
        'leave_adjusted' => ['cancelled'],
        'cancelled'      => ['scheduled'],
    ];

    private const ATTENDED_STATUSES = ['attended', 'completed', 'late', 'absent', 'excused'];
    private const LEAVE_ADJUSTED_REQUIRES = ['director', 'admin', 'super_admin'];

    /**
     * PATCH /api/v1/class-sessions/{id}
     * Single-session status update with state machine validation.
     */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'status'     => 'required|string|max:32',
            'start_time' => 'nullable|string|max:8',
            'end_time'   => 'nullable|string|max:8',
            'note'       => 'nullable|string|max:500',
            'reason'     => 'nullable|string|max:255',
        ]);

        $role = $request->attributes->get('auth_role');
        $authUser = $request->attributes->get('auth_user');
        $authUserId = (int) ($authUser->id ?? 0);
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $session = ClassSession::find($id);
        if (!$session) {
            return response()->json(['message' => '找不到該堂次'], 404);
        }

        $studentClass = StudentClass::where('ID', $session->StudentClassID)->first();
        if (!$studentClass) {
            return response()->json(['message' => '找不到對應課程'], 404);
        }

        $studentCampusId = (int) (Student::where('id', (int) $studentClass->StudentID)->value('CampusID') ?? 0);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($studentCampusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $currentStatus = strtolower(trim((string) ($session->Status ?? 'scheduled')));
        $newStatus = strtolower(trim((string) $data['status']));

        if ($currentStatus === $newStatus) {
            $this->applyTimeAndNoteUpdates($session, $data);
            return $this->sessionUpdateResponse($session, '堂次已更新');
        }

        // Validate state machine transition
        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            return response()->json([
                'message' => "狀態轉移不允許：{$currentStatus} → {$newStatus}",
                'allowed' => $allowed,
            ], 422);
        }

        // leave_adjusted requires director+
        if ($newStatus === 'leave_adjusted') {
            if (!in_array($role, self::LEAVE_ADJUSTED_REQUIRES, true)) {
                return response()->json(['message' => '僅主任/管理員可執行補請假'], 403);
            }
        }

        if ($role === 'teacher') {
            $teacherAllowed = ['attended', 'late', 'absent', 'excused', 'leave'];
            if (!in_array($newStatus, $teacherAllowed, true)) {
                return response()->json(['message' => '老師僅可標記出缺勤或請假狀態'], 403);
            }
        }

        try {
            return DB::transaction(function () use ($session, $studentClass, $currentStatus, $newStatus, $data, $authUserId, $role) {
                $session = ClassSession::where('id', $session->id)->lockForUpdate()->first();
                $reason = trim((string) ($data['reason'] ?? ''));

                $wasAttended = in_array($currentStatus, self::ATTENDED_STATUSES, true);
                $willAttend = in_array($newStatus, self::ATTENDED_STATUSES, true);

                // --- Transition: anything attended-like → leave/leave_adjusted ---
                if ($newStatus === 'leave_adjusted' || ($wasAttended && $newStatus === 'leave')) {
                    return $this->handleRetroLeaveTransition($session, $studentClass, $authUserId, $reason);
                }

                // --- Transition: scheduled → attended (mark attendance) ---
                if ($currentStatus === 'scheduled' && $willAttend) {
                    $session->Status = $newStatus;
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();

                    if (in_array($newStatus, ['attended', 'late'], true)) {
                        SessionDeductionService::deductOnAttendance($studentClass, null, (int) $session->id);
                    }

                    return $this->sessionUpdateResponse($session, '狀態已更新為' . $newStatus);
                }

                // --- Transition: attended swap (e.g. attended → late) ---
                if ($wasAttended && $willAttend) {
                    $session->Status = $newStatus;
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();
                    return $this->sessionUpdateResponse($session, '狀態已更新為' . $newStatus);
                }

                // --- Transition: attended-like → scheduled/cancelled ---
                if ($wasAttended && in_array($newStatus, ['scheduled', 'cancelled'], true)) {
                    $this->voidAttendanceArtifacts($session, $authUserId, $reason ?: '由已上調整狀態');
                    SessionDeductionService::reverseForSession(
                        (int) $studentClass->ID,
                        (int) $session->id,
                        'status_adjust',
                        $authUserId,
                        $reason ?: '狀態調整沖回'
                    );
                    $session->Status = $newStatus;
                    if ($newStatus === 'cancelled') {
                        $session->Note = $this->appendSessionNote($session->Note, 'cancelled-after-attended');
                    } else {
                        $session->Note = $this->appendSessionNote($session->Note, 'revert-to-scheduled');
                    }
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();
                    SessionDeductionService::recomputeCounters((int) $studentClass->ID);
                    return $this->sessionUpdateResponse($session, '已更新為' . $newStatus . '，並完成堂數沖回');
                }

                // --- Transition: scheduled → leave ---
                if ($currentStatus === 'scheduled' && $newStatus === 'leave') {
                    LearningRecord::where('ClassSessionID', $session->id)
                        ->active()
                        ->update([
                            'VoidedAt' => now(), 'VoidedByUserID' => $authUserId ?: null,
                            'VoidReason' => $reason ?: '單堂標記請假',
                        ]);
                    StudentSignIn::where('ClassSessionID', $session->id)
                        ->active()
                        ->update([
                            'VoidedAt' => now(), 'VoidedByUserID' => $authUserId ?: null,
                            'VoidReason' => $reason ?: '單堂標記請假',
                        ]);

                    $session->Status = 'leave';
                    $session->Note = $this->appendSessionNote($session->Note, 'leave');
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();

                    $extended = $this->tryExtendOnLeave($studentClass, $session);
                    $msg = '已標記請假';
                    if ($extended) {
                        $msg .= '，並已自動順延一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
                    }
                    return $this->sessionUpdateResponse($session, $msg);
                }

                // --- Generic safe transitions ---
                $session->Status = $newStatus;
                $this->applyTimeAndNoteUpdates($session, $data);
                $session->save();

                SessionDeductionService::syncCounters($studentClass);

                return $this->sessionUpdateResponse($session, '狀態已更新為' . $newStatus);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function handleRetroLeaveTransition(ClassSession $session, StudentClass $studentClass, int $authUserId, string $reason)
    {
        $this->voidAttendanceArtifacts($session, $authUserId, $reason ?: '補請假：已上課改請假');

        SessionDeductionService::reverseForSession(
            (int) $studentClass->ID,
            (int) $session->id,
            'retro_leave',
            $authUserId,
            $reason ?: '補請假沖回'
        );

        $session->Status = 'leave_adjusted';
        $session->Note = $this->appendSessionNote($session->Note, 'retro-leave');
        $session->save();

        SessionDeductionService::recomputeCounters((int) $studentClass->ID);

        $extended = $this->tryExtendOnLeave($studentClass, $session);
        $msg = '補請假完成：堂數已沖回';
        if ($extended) {
            $msg .= '，並已自動順延一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
        }
        return $this->sessionUpdateResponse($session, $msg);
    }

    private function voidAttendanceArtifacts(ClassSession $session, int $authUserId, string $reason): void
    {
        StudentSignIn::where('ClassSessionID', $session->id)
            ->active()
            ->get()
            ->each(function ($si) use ($authUserId, $reason) {
                $si->VoidedAt = now();
                $si->VoidedByUserID = $authUserId ?: null;
                $si->VoidReason = $reason;
                $si->save();
            });

        LearningRecord::where('ClassSessionID', $session->id)
            ->active()
            ->get()
            ->each(function ($lr) use ($authUserId, $reason) {
                $lr->VoidedAt = now();
                $lr->VoidedByUserID = $authUserId ?: null;
                $lr->VoidReason = $reason;
                $lr->save();
            });
    }

    private function applyTimeAndNoteUpdates(ClassSession $session, array $data): void
    {
        if (!empty($data['start_time'])) {
            $session->StartTime = substr($data['start_time'], 0, 5);
        }
        if (!empty($data['end_time'])) {
            $session->EndTime = substr($data['end_time'], 0, 5);
        }
        if (array_key_exists('note', $data) && $data['note'] !== null) {
            $session->Note = $data['note'];
        }
        $session->save();
    }

    private function tryExtendOnLeave(StudentClass $studentClass, ClassSession $leaveSession): ?ClassSession
    {
        $mode = strtolower(trim((string) ($studentClass->ScheduleMode ?? '')));
        if ($mode === 'date') {
            return null;
        }

        $lastSession = ClassSession::where('StudentClassID', $studentClass->ID)
            ->whereNotIn('Status', ['cancelled'])
            ->orderByDesc('SessionDate')
            ->orderByDesc('StartTime')
            ->first();

        if (!$lastSession) {
            return null;
        }

        $baseDate = Carbon::parse($lastSession->SessionDate);
        $weekday = (int) ($studentClass->week ?? 0);
        if ($weekday < 1 || $weekday > 7) {
            $weekday = $baseDate->dayOfWeekIso;
        }

        $nextDate = $baseDate->copy()->addDay();
        for ($i = 0; $i < 14; $i++) {
            if ($nextDate->dayOfWeekIso === $weekday) {
                break;
            }
            $nextDate->addDay();
        }

        $exists = ClassSession::where('StudentClassID', $studentClass->ID)
            ->whereDate('SessionDate', $nextDate->toDateString())
            ->where('StartTime', $leaveSession->StartTime)
            ->exists();
        if ($exists) {
            return null;
        }

        return ClassSession::create([
            'StudentClassID' => $studentClass->ID,
            'SessionDate'    => $nextDate->toDateString(),
            'StartTime'      => $leaveSession->StartTime,
            'EndTime'        => $leaveSession->EndTime,
            'Status'         => 'scheduled',
            'Note'           => '請假自動順延',
        ]);
    }

    private function sessionUpdateResponse(ClassSession $session, string $message)
    {
        $session->refresh();
        return response()->json([
            'message' => $message,
            'session' => [
                'id'               => (int) $session->id,
                'student_class_id' => (int) $session->StudentClassID,
                'session_date'     => $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null,
                'start_time'       => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
                'end_time'         => $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null,
                'status'           => (string) ($session->Status ?? ''),
                'note'             => $session->Note,
            ],
        ]);
    }

    private function appendSessionNote($existing, string $suffix): string
    {
        $base = trim((string) ($existing ?? ''));
        if ($base === '') return $suffix;
        if (str_contains($base, $suffix)) return $base;
        return $base . '; ' . $suffix;
    }

    /**
     * @return array<int>
     */
    private function normalizeIds($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('intval', $raw), fn ($v) => $v > 0));
        }
        if (!is_string($raw)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $raw)), fn ($v) => $v > 0));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $raw), fn ($v) => $v !== ''));
        }
        if (!is_string($raw)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<int, string>
     */
    private function normalizeDateArray(array $dates): array
    {
        $normalized = [];
        foreach ($dates as $date) {
            try {
                $normalized[] = Carbon::parse($date)->toDateString();
            } catch (\Throwable $e) {
                // validation already catches date format
            }
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $days
     * @return array<int, int>
     */
    private function normalizeWeekdayArray(array $days): array
    {
        $normalized = array_values(array_unique(array_map('intval', $days)));
        $normalized = array_values(array_filter($normalized, fn ($d) => $d >= 1 && $d <= 7));
        sort($normalized);
        return $normalized;
    }

    private function normalizeTime(string $time): string
    {
        $parsed = Carbon::createFromFormat('H:i', substr($time, 0, 5));
        return $parsed->format('H:i:s');
    }

    private function computeEndTime(string $startTime, int $durationMinutes): string
    {
        return Carbon::createFromFormat('H:i:s', $startTime)
            ->addMinutes($durationMinutes)
            ->format('H:i:s');
    }

    private function sessionEndedByEndTime(string $sessionDate, string $endTime, ?Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now();
        $sessionEndAt = Carbon::parse($sessionDate . ' ' . $endTime);
        return $sessionEndAt->lte($now);
    }

    private function resolveSubjectId(string $frontendSubject): int
    {
        $subjectMap = [
            'Chinese' => '國文',
            'English' => '英文',
            'Math' => '數學',
            'Physics' => '物理',
            'Chemistry' => '化學',
            'Science' => '理化',
            'Biology' => '生物',
            'Social' => '社會',
        ];
        $subjectName = $subjectMap[$frontendSubject] ?? $frontendSubject;

        return (int) (
            DB::table('Subject')->where('Subject_Name', 'like', '%' . $subjectName . '%')->value('id')
            ?? DB::table('BaseData')->where('Name', '課程')->where('Val', 'like', '%' . $subjectName . '%')->value('id')
            ?? 1
        );
    }

    private function resolveSubjectName(int $subjectId, string $fallback): string
    {
        $name = DB::table('Subject')->where('id', $subjectId)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $subjectId)->value('Val');
        return (string) ($name ?: $fallback);
    }

    /**
     * Retry StudentClass::create by removing unknown columns for mixed schemas.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createStudentClassResilient(array $payload): StudentClass
    {
        $attempts = 0;
        while ($attempts < 8) {
            try {
                return StudentClass::create($payload);
            } catch (QueryException $e) {
                if (!str_contains($e->getMessage(), 'Unknown column')) {
                    throw $e;
                }
                if (!preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $m)) {
                    throw $e;
                }
                $badColumn = $m[1] ?? null;
                if (!$badColumn || !array_key_exists($badColumn, $payload)) {
                    throw $e;
                }
                unset($payload[$badColumn]);
                $attempts++;
            }
        }
        return StudentClass::create($payload);
    }

    /**
     * @return array{created: bool, approved: bool, deducted: bool}
     */
    private function syncApprovedLearningRecord(
        StudentClass $studentClass,
        ClassSession $classSession,
        int $teacherId,
        string $subjectName,
        ?int $approvedByUserId,
        bool $hasSessionDeductedColumn
    ): array {
        $created = false;
        $approved = false;
        $deducted = false;

        $record = LearningRecord::where('ClassSessionID', $classSession->id)->first();
        if (!$record) {
            $payload = [
                'StudentClassID' => $studentClass->ID,
                'ClassSessionID' => $classSession->id,
                'TeacherID' => $teacherId,
                'CreatedByUserID' => $approvedByUserId ?: null,
                'Content' => '（系統自動核准）',
                'Subject' => $subjectName,
                'SessionDate' => $classSession->SessionDate,
                'StartTime' => $classSession->StartTime,
                'EndTime' => $classSession->EndTime,
                'Status' => 'approved',
                'ApprovedBy' => $approvedByUserId ?: null,
                'ApprovedAt' => now(),
            ];
            if ($hasSessionDeductedColumn) {
                $payload['SessionDeducted'] = false;
            }
            $record = LearningRecord::create($payload);
            $created = true;
            $approved = true;
        } else {
            if ((string) $record->Status !== 'approved') {
                $approved = true;
            }
            $record->StudentClassID = $studentClass->ID;
            $record->TeacherID = $teacherId;
            $record->Subject = $subjectName;
            $record->SessionDate = $classSession->SessionDate;
            $record->StartTime = $classSession->StartTime;
            $record->EndTime = $classSession->EndTime;
            $record->Status = 'approved';
            if (empty((string) $record->Content)) {
                $record->Content = '（系統自動核准）';
            }
            if ($approvedByUserId) {
                $record->ApprovedBy = $approvedByUserId;
                if (empty($record->CreatedByUserID)) {
                    $record->CreatedByUserID = $approvedByUserId;
                }
            }
            $record->ApprovedAt = now();
            $record->save();
        }

        $alreadyDeducted = $hasSessionDeductedColumn && (bool) $record->SessionDeducted;
        if (!$alreadyDeducted && $this->deductSessionForApprovedRecord($studentClass, $classSession->id)) {
            $deducted = true;
            if ($hasSessionDeductedColumn) {
                $record->SessionDeducted = true;
                $record->save();
            }
        }

        return [
            'created' => $created,
            'approved' => $approved,
            'deducted' => $deducted,
        ];
    }

    private function deductSessionForApprovedRecord(StudentClass $studentClass, int $classSessionId): bool
    {
        return SessionDeductionService::deductOnAttendance(
            $studentClass,
            null,
            $classSessionId > 0 ? $classSessionId : null
        );
    }

    private function recalculateSessionCounters(StudentClass $studentClass): void
    {
        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'count') {
            $studentClass->Stop = 0;
            $studentClass->save();
            return;
        }

        $sessionCount = max(0, (int) ($studentClass->SessionCount ?? 0));
        if ($sessionCount <= 0) {
            return;
        }

        $completedCount = ClassSession::query()
            ->where('StudentClassID', $studentClass->ID)
            ->where('Status', 'completed')
            ->count();

        // Backward compatibility: some historical records were written as "attended".
        $legacyAttendedCount = ClassSession::query()
            ->where('StudentClassID', $studentClass->ID)
            ->where('Status', 'attended')
            ->count();

        $usedSessions = min($sessionCount, $completedCount + $legacyAttendedCount);
        $remainingSessions = max(0, $sessionCount - $usedSessions);

        $studentClass->UsedSessions = $usedSessions;
        $studentClass->RemainingSessions = $remainingSessions;
        $studentClass->Stop = $remainingSessions <= 0 ? 1 : 0;
        $studentClass->save();
    }

    private function resolveStudentGradeId(int $studentId): int
    {
        try {
            $gradeId = Student::where('id', $studentId)->value('GradeID');
            return $gradeId ? (int) $gradeId : 1;
        } catch (\Throwable $e) {
            return 1;
        }
    }
}

