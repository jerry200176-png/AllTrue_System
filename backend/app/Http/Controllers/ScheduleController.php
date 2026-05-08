<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\CourseLeaveCascadeService;
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
        // Defensive unwrap: older frontend sends JSON array [{...}] instead of object.
        $all = $request->all();
        if (array_keys($all) === [0] && is_array($all[0])) {
            $request->replace($all[0]);
        }

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

        // Extract origId early so FR-001/FR-002 guards can use it.
        $origId = $data['original_schedule_id'] ?? null;

        // FR-001: When creating a same-day substitute exception (status=scheduled +
        // original_schedule_id present, and the new schedule_date matches the original
        // schedule's date), verify a live ClassSession exists on that date.
        // Prevents orphaned schedules rows from being written for pure substitute assignments.
        //
        // NOTE: This guard must NOT fire for cross-date reschedule (調課) operations where
        // schedule_date (new date) differs from the original schedule's date. In that case,
        // the ClassSession is still on the original date and will be moved to the new date
        // by the subsequent reschedule-session API call. Firing here would block valid 調課.
        if (($data['status'] ?? 'scheduled') === 'scheduled'
            && (int) ($origId ?? 0) > 0
            && $courseId > 0
            && !empty($data['schedule_date'])
        ) {
            $origSched = Schedule::where('id', (int) $origId)->first();
            $isSameDaySubstitute = $origSched && $origSched->schedule_date === $data['schedule_date'];

            if ($isSameDaySubstitute) {
                $sessionExists = ClassSession::where('StudentClassID', $courseId)
                    ->whereDate('SessionDate', $data['schedule_date'])
                    ->whereNotIn('Status', ['cancelled', 'voided'])
                    ->exists();
                if (!$sessionExists) {
                    return response()->json([
                        'message' => '目標日期尚無課堂紀錄，請先確認課堂已建立再指派代課。',
                        'code'    => 'no_class_session',
                    ], 422);
                }
            }
        }

        // FR-002: When replacing an existing substitute row, look up its id so we can
        // pass it as exclude_schedule_id to the guard — preventing self-referential conflicts.
        $excludeScheduledId = null;
        if (($data['status'] ?? 'scheduled') === 'scheduled'
            && (int) ($origId ?? 0) > 0
            && $courseId > 0
            && !empty($data['schedule_date'])
        ) {
            $excludeScheduledId = Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $data['schedule_date'])
                ->where('status', 'scheduled')
                ->where('original_schedule_id', $origId)
                ->value('id');
        }

        if (($data['status'] ?? 'scheduled') === 'scheduled') {
            $guardConflicts = $this->scheduleGuardService->validateScheduleOccurrence([
                'teacher_id'          => $effectiveTeacherId,
                'class_type'          => $effectiveClassType,
                'room_id'             => $effectiveRoomId,
                'branch_id'           => $branchId,
                'schedule_date'       => $data['schedule_date'] ?? null,
                'start_time'          => $data['start_time'] ?? null,
                'end_time'            => $data['end_time'] ?? null,
                'exclude_student_id'  => (int) ($data['student_id'] ?? 0),
                'exclude_schedule_id' => $excludeScheduledId,
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
                    [$rows, $extendedEndDate, $leaveSessionDate] = CourseLeaveCascadeService::applyLeaveCascade((int) $courseId, (string) $data['schedule_date']);

                    $leaveSession = ClassSession::where('StudentClassID', $courseId)
                        ->whereDate('SessionDate', $leaveSessionDate)
                        ->first();
                    if ($leaveSession) {
                        $course = StudentClass::where('ID', $courseId)->first();
                        if ($course && !StudentSignIn::where('ClassSessionID', $leaveSession->id)->whereNull('VoidedAt')->exists()) {
                            $campusId = (int) (Student::where('id', (int) $course->StudentID)->value('CampusID') ?? 0);
                            StudentSignIn::create([
                                'StudentClassID'  => $courseId,
                                'StudentID'       => (int) $course->StudentID,
                                'TeacherID'       => (int) ($course->TeacherID ?? 0) ?: null,
                                'GradeID'         => $course->GradeID,
                                'SubjectID'       => $course->SubjectID,
                                'CampusID'        => $campusId,
                                'SignInDT'        => $leaveSession->StartTime
                                    ? Carbon::parse($leaveSessionDate . ' ' . $leaveSession->StartTime)
                                    : Carbon::parse($leaveSessionDate),
                                'SignOutDT'       => null,
                                'MDT'             => now(),
                                'ClassSessionID'  => (int) $leaveSession->id,
                                'Status'          => 'leave',
                                'SessionDeducted' => 0,
                            ]);
                        }
                    }

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

        // When marking a date as rescheduled, clean up any existing scheduled exception
        // for the same student/course/date to prevent ghost records from multi-hop reschedules.
        if ($status === 'rescheduled' && $courseId > 0 && !empty($data['schedule_date'])) {
            DB::table('schedules')
                ->where('student_course_id', $courseId)
                ->where('schedule_date', $data['schedule_date'])
                ->where('status', 'scheduled')
                ->delete();
        }

        // Prevent duplicate scheduled rows for reschedule/substitute on same course+date+time.
        // If an identical row already exists, update it instead of creating a new one.
        // ($origId was already extracted above for FR-001/FR-002 guards.)
        if ($status === 'scheduled' && $courseId > 0 && !empty($data['schedule_date']) && $origId) {
            $existing = Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $data['schedule_date'])
                ->where('start_time', $data['start_time'] ?? '')
                ->where('status', 'scheduled')
                ->where('original_schedule_id', $origId)
                ->first();
            if ($existing) {
                $existing->update(array_filter($data, fn ($v) => $v !== null));
                $this->ensureClassSessionForScheduleData($existing->toArray());
                return response()->json($existing, 201);
            }
        }

        $schedule = Schedule::create($data);

        $this->ensureClassSessionForScheduleData($schedule->toArray());

        return response()->json($schedule, 201);
    }

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

                $isAttended = in_array($status, ['attended', 'completed', 'late', 'present', 'absent'], true);

                if (!$isAttended && $status === 'scheduled') {
                    [$rows, $extendedEndDate, $leaveSessionDate] = CourseLeaveCascadeService::applyLeaveCascade($courseId, $sessionDate);
                    return response()->json([
                        'message'             => '請假登記完成，該堂已標記請假且後續課程已順延',
                        'leave_session_date'  => $leaveSessionDate,
                        'extended_end_date'   => $extendedEndDate,
                        'class_sessions'      => $rows,
                    ]);
                }

                // ── Void attendance records ──
                // Query ALL sign-ins (including already-voided) to decide whether
                // to create a new leave record or just void the active one.
                $allSignIns = StudentSignIn::where('ClassSessionID', $session->id)
                    ->lockForUpdate()
                    ->get();

                $activeSignIns = $allSignIns->filter(fn ($si) => $si->VoidedAt === null);

                foreach ($activeSignIns as $si) {
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

                $session->Status = 'leave_adjusted';
                $session->Note = CourseLeaveCascadeService::appendNote($session->Note, 'retro-leave');
                $session->save();

                [$rows, $extendedEndDate] = CourseLeaveCascadeService::shiftAndAppendAfterLeave($courseId, $sessionDate, $session);

                // ── Recompute counters from ledger ──
                SessionDeductionService::recomputeCounters($courseId);

                // Only create a new leave sign-in if no sign-in record exists at
                // all for this ClassSession.  Avoids unique-key collision on
                // ClassSessionID while keeping the voided audit trail intact.
                if ($allSignIns->isEmpty()) {
                    $campusId = (int) (Student::where('id', (int) $course->StudentID)->value('CampusID') ?? 0);
                    StudentSignIn::create([
                        'StudentClassID'   => $courseId,
                        'StudentID'        => (int) $course->StudentID,
                        'TeacherID'        => (int) ($course->TeacherID ?? 0) ?: null,
                        'ClassSessionID'   => (int) $session->id,
                        'RecordedByUserID' => $authUserId ?: null,
                        'GradeID'          => $course->GradeID,
                        'SubjectID'        => $course->SubjectID,
                        'CampusID'         => $campusId,
                        'SignInDT'         => $session->StartTime
                            ? Carbon::parse($sessionDate . ' ' . $session->StartTime)
                            : Carbon::parse($sessionDate),
                        'SignOutDT'        => null,
                        'MDT'              => now(),
                        'Status'           => 'leave',
                        'SessionDeducted'  => 0,
                    ]);
                }

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
     * Trigger leave cascade from a ClassSession ID.
     * Used when a session was already marked leave (e.g. via attendance)
     * but the cascade (shift + append) was not yet executed.
     */
    public function leaveBySession(Request $request)
    {
        $data = $request->validate([
            'class_session_id' => 'required|integer',
        ]);

        $sessionId = (int) $data['class_session_id'];

        try {
            return DB::transaction(function () use ($sessionId, $request) {
                $session = ClassSession::where('id', $sessionId)->lockForUpdate()->first();
                if (!$session) {
                    return response()->json(['message' => '找不到堂次'], 404);
                }

                $courseId = (int) $session->StudentClassID;
                $course   = StudentClass::where('ID', $courseId)->lockForUpdate()->first();
                if (!$course) {
                    return response()->json(['message' => '找不到課程'], 404);
                }

                $role      = $request->attributes->get('auth_role');
                $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
                $studentCampusId = (int) (Student::where('id', (int) $course->StudentID)->value('CampusID') ?? 0);
                if ($role !== 'super_admin' && !empty($campusIds) && !in_array($studentCampusId, $campusIds, true)) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }

                $sessionDate = Carbon::parse($session->SessionDate)->toDateString();

                // Run cascade (accepts leave sessions where cascade hasn't run)
                [$rows, $extendedEndDate, $leaveSessionDate] =
                    CourseLeaveCascadeService::applyLeaveCascade($courseId, $sessionDate);

                $hasSignIn = StudentSignIn::where('ClassSessionID', (int) $session->id)
                    ->whereNull('VoidedAt')
                    ->exists();
                if (!$hasSignIn) {
                    $authUser = $request->attributes->get('auth_user');
                    StudentSignIn::create([
                        'StudentClassID'   => $courseId,
                        'StudentID'        => (int) $course->StudentID,
                        'TeacherID'        => (int) ($course->TeacherID ?? 0) ?: null,
                        'RecordedByUserID' => (int) ($authUser->id ?? 0) ?: null,
                        'GradeID'          => $course->GradeID,
                        'SubjectID'        => $course->SubjectID,
                        'CampusID'         => $studentCampusId,
                        'SignInDT'         => $session->StartTime
                            ? Carbon::parse($sessionDate . ' ' . $session->StartTime)
                            : now(),
                        'SignOutDT'        => null,
                        'MDT'              => now(),
                        'ClassSessionID'   => (int) $session->id,
                        'Status'           => 'leave',
                        'SessionDeducted'  => 0,
                    ]);
                }

                // Record schedules leave entry (created after cascade so the "already cascaded"
                // detection in applyLeaveCascade doesn't fire on retries)
                Schedule::firstOrCreate(
                    ['student_course_id' => $courseId, 'schedule_date' => $sessionDate, 'status' => 'leave'],
                    [
                        'student_id'  => (int) $course->StudentID,
                        'day_of_week' => Carbon::parse($sessionDate)->dayOfWeekIso,
                        'start_time'  => $session->StartTime,
                        'end_time'    => $session->EndTime,
                        'class_type'  => $course->ClassType ?? 'one_on_one',
                        'type'        => 'normal',
                        'deduction'   => 0,
                        'branch_id'   => $studentCampusId,
                        'teacher_id'  => (int) ($course->TeacherID ?? 0),
                        'subject'     => $course->SubjectID ?? null,
                    ]
                );

                return response()->json([
                    'message'            => '已請假並順延後續課程',
                    'leave_session_date' => $leaveSessionDate,
                    'extended_end_date'  => $extendedEndDate,
                    'class_sessions'     => $rows,
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
                    CourseLeaveCascadeService::applyLeaveCascade($courseId, $sessionDate);

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
        $this->ensureClassSessionForScheduleData($schedule->toArray());

        return response()->json($schedule);
    }

    /**
     * Ensure schedule-backed sessions are materialized into ClassSession so
     * SmartCalendar / 點名 / 評量 can render the same source of truth.
     */
    private function ensureClassSessionForScheduleData(array $schedule): void
    {
        $courseId = (int) ($schedule['student_course_id'] ?? 0);
        $sessionDateRaw = (string) ($schedule['schedule_date'] ?? '');
        $status = strtolower((string) ($schedule['status'] ?? 'scheduled'));
        $scheduleType = strtolower((string) ($schedule['type'] ?? 'normal'));

        if ($courseId <= 0 || $sessionDateRaw === '') {
            return;
        }

        // Keep legacy behavior for makeup destination (type=extra), and also
        // cover normal scheduled exceptions that previously stayed as "gray chips".
        $shouldEnsure = $status === 'scheduled'
            || ($scheduleType === 'extra' && in_array($status, ['scheduled', 'rescheduled'], true));
        if (!$shouldEnsure) {
            return;
        }

        $sessionDate = Carbon::parse($sessionDateRaw)->toDateString();
        $startTime = substr((string) ($schedule['start_time'] ?? '00:00:00'), 0, 8);
        $endTime = substr((string) ($schedule['end_time'] ?? '00:00:00'), 0, 8);
        if (strlen($startTime) === 5) {
            $startTime .= ':00';
        }
        if (strlen($endTime) === 5) {
            $endTime .= ':00';
        }

        ClassSession::firstOrCreate(
            [
                'StudentClassID' => $courseId,
                'SessionDate' => $sessionDate,
                'StartTime' => $startTime,
            ],
            [
                'SubjectID' => StudentClass::where('ID', $courseId)->value('SubjectID') ?: null,
                'EndTime' => $endTime,
                'Status' => 'scheduled',
                'Note' => 'auto-materialized-from-schedule',
            ]
        );
    }

    public function destroy(Schedule $schedule)
    {
        $schedule->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
