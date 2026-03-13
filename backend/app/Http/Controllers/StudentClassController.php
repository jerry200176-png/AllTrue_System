<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentClassController extends Controller
{
    public function index(Request $request)
    {
        $query = StudentClass::query()->with(['student', 'room.campus']);
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if ($role === 'teacher') {
            $teacherId = $request->attributes->get('auth_teacher_id');
            if (!$teacherId) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
            $query->where('TeacherID', $teacherId);
            // Teachers see all their courses regardless of branch — TeacherID already scopes data
        } else {
            if (!empty($campusIds)) {
                $query->whereHas('student', function ($sub) use ($campusIds) {
                    $sub->whereIn('CampusID', $campusIds);
                });
            }

            if ($request->filled('campus_id')) {
                $query->whereHas('student', function ($sub) use ($request) {
                    $sub->where('CampusID', $request->input('campus_id'));
                });
            }

            if ($request->filled('branch_id')) {
                $query->whereHas('student', function ($sub) use ($request) {
                    $sub->where('CampusID', $request->input('branch_id'));
                });
            }
        }

        if ($request->filled('student_id')) {
            $query->where('StudentID', $request->input('student_id'));
        }

        if ($request->filled('teacher_id')) {
            $query->where('TeacherID', $request->input('teacher_id'));
        }

        $perPage = min((int) $request->input('per_page', 20), 1000);
        $classes = $query->orderBy('ID', 'desc')->paginate($perPage);

        $courseNames = DB::table('BaseData')
            ->where('Name', '課程')
            ->pluck('Val', 'id')
            ->toArray();
        $subjectNames = DB::table('Subject')
            ->pluck('Subject_Name', 'id')
            ->toArray();
        $teacherNames = DB::table('Teacher')
            ->pluck('T_Name', 'id')
            ->toArray();
        $userNames = DB::table('User')
            ->whereIn('type', ['T', 'U'])
            ->pluck('Name', 'id')
            ->toArray();

        $classes->getCollection()->transform(function ($class) use ($courseNames, $subjectNames, $teacherNames, $userNames) {
            $class->subject_name = $courseNames[$class->SubjectID]
                ?? $subjectNames[$class->SubjectID]
                ?? null;
            $class->teacher_name = $teacherNames[$class->TeacherID]
                ?? $userNames[$class->TeacherID]
                ?? null;

            // Map backend PascalCase to frontend snake_case
            $class->id = (int) $class->ID;
            $class->student_id = (int) $class->StudentID;
            $class->teacher_id = (int) $class->TeacherID;
            $class->student_name = $class->student->name ?? null;

            $class->branch_id = $class->room?->campus_id ?? null;
            $class->branch_name = $class->room?->campus?->name ?? null;
            $class->room_name = $class->room?->name ?? null;
            $class->settlement_day = $class->settlement_day !== null ? (int) $class->settlement_day : null;
            $class->monthly_sessions = $class->monthly_sessions !== null ? (int) $class->monthly_sessions : null;
            $class->memo = $class->Memo ?? null;

            $reverseSubjectMap = [
                '國文' => 'Chinese',
                '英文' => 'English',
                '數學' => 'Math',
                '自然' => 'Science',
                '社會' => 'Social',
                '國語' => 'Chinese',
                '物理' => 'Science',
                '化學' => 'Science',
                '理化' => 'Science',
                '生物' => 'Science',
                '地科' => 'Science',
            ];
            $class->subject = $reverseSubjectMap[$class->subject_name] ?? 'Math';
            $class->class_type = $class->ClassType ?? 'one_on_one';
            $class->rate_per_30min = $class->Rate ?? 0;
            $class->duration_hours = $class->SessionDuration ? (int) round($class->SessionDuration / 60) : 2;
            // 固定排課多日（如 一四）：從 week + week1..week6 彙總成 days_of_week（寫入時第一日在 week，其餘在 week1..week6）
            $weekFields = ['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'];
            $daysOfWeek = [];
            foreach ($weekFields as $wf) {
                $d = (int) ($class->{$wf} ?? 0);
                if ($d >= 1 && $d <= 7 && !in_array($d, $daysOfWeek, true)) {
                    $daysOfWeek[] = $d;
                }
            }
            sort($daysOfWeek);
            if (empty($daysOfWeek) && (int) $class->week >= 1 && (int) $class->week <= 7) {
                $daysOfWeek = [(int) $class->week];
            }
            $class->days_of_week = $daysOfWeek;
            $class->day_of_week = (int) ($daysOfWeek[0] ?? $class->week ?? 0);

            // Build the 'weeks' array for frontend (week-of-month: 第1週..第5週)
            $weeks = [];
            for ($i = 1; $i <= 5; $i++) {
                $weeks[] = $i;
            }
            $class->weeks = $weeks;

            $class->start_time = $class->time ? substr($class->time, 0, 5) : '';
            $class->end_time = $class->start_time ? date('H:i', strtotime($class->start_time . ' +2 hours')) : null;
            $class->payment_type = ($class->ScheduleMode ?? 'count') === 'count' ? 'session' : 'monthly';
            $class->sessions_purchased = (int) ($class->SessionCount ?? 0);
            $class->remaining_sessions = (int) ($class->RemainingSessions ?? 0);
            $class->payment_status = empty($class->Paid) ? 'unpaid' : 'paid';
            $class->status = empty($class->Stop) ? 'active' : 'inactive';
            $class->first_class_date = $class->StartDate ? (\Carbon\Carbon::parse($class->StartDate)->toDateString()) : null;

            return $class;
        });

        return response()->json($classes);
    }

    /**
     * GET /api/v1/student-classes/session-dates?branch_id=1
     * POST 可帶 body: { branch_id, courses: [{ id, first_class_date, sessions_purchased, days_of_week }] }
     * 回傳每門課的實際上課日期（含 請假/調課），供課程管理顯示。POST 時依 courses 的 id 對應 Schedule，可顯示僅存在 Supabase 的堂數制預估日期且隨請假/調課變動。
     */
    public function sessionDates(Request $request)
    {
        $branchId = (int) ($request->input('branch_id') ?? $request->get('branch_id') ?? 0);
        if ($branchId <= 0) {
            $request->validate(['branch_id' => 'required|integer']);
        }
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if ($role !== 'super_admin' && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = [];

        // POST body: 課程管理傳入的堂數制課程（Supabase id），用 Schedule 計算含請假/調課的日期
        $bodyCourses = $request->get('courses');
        if (is_array($bodyCourses) && !empty($bodyCourses)) {
            $courseIds = [];
            foreach ($bodyCourses as $c) {
                $cid = $c['id'] ?? null;
                if ($cid !== null && $cid !== '') {
                    $courseIds[] = $cid;
                }
            }
            if (!empty($courseIds)) {
                $schedulesBody = Schedule::where('branch_id', $branchId)
                    ->whereNotNull('student_course_id')
                    ->whereIn('student_course_id', $courseIds)
                    ->select('student_course_id', 'schedule_date', 'status')
                    ->get();
                $leaveByClass = [];
                $scheduledByClass = [];
                foreach ($schedulesBody as $row) {
                    $id = (string) $row->student_course_id;
                    $d = $row->schedule_date ? Carbon::parse($row->schedule_date)->toDateString() : null;
                    if (!$d) {
                        continue;
                    }
                    if ($row->status === 'scheduled') {
                        if (!isset($scheduledByClass[$id])) {
                            $scheduledByClass[$id] = [];
                        }
                        $scheduledByClass[$id][$d] = true;
                    } else {
                        if (!isset($leaveByClass[$id])) {
                            $leaveByClass[$id] = [];
                        }
                        $leaveByClass[$id][$d] = true;
                    }
                }
                foreach ($bodyCourses as $c) {
                    $cid = $c['id'] ?? null;
                    $startDate = isset($c['first_class_date']) ? Carbon::parse($c['first_class_date'])->toDateString() : null;
                    $n = (int) ($c['sessions_purchased'] ?? 0);
                    $daysOfWeek = isset($c['days_of_week']) && is_array($c['days_of_week'])
                        ? array_values(array_unique(array_map('intval', array_filter($c['days_of_week'], function ($d) { return $d >= 1 && $d <= 7; }))))
                        : [];
                    if ($cid !== null && $startDate && $n > 0 && !empty($daysOfWeek)) {
                        $leaveSet = $leaveByClass[$cid] ?? [];
                        $scheduledSet = $scheduledByClass[$cid] ?? [];
                        $list = self::computeEffectiveSessionDates($startDate, $n, $daysOfWeek, $leaveSet, $scheduledSet);
                        $result[(string) $cid] = $list;
                    }
                }
            }
        }

        try {
            $query = StudentClass::query()
                ->whereHas('student', function ($q) use ($branchId) {
                    $q->where('CampusID', $branchId);
                });
            if ($role === 'teacher') {
                $teacherId = $request->attributes->get('auth_teacher_id');
                if (!$teacherId) {
                    return response()->json(['message' => 'Teacher not linked'], 403);
                }
                $query->where('TeacherID', $teacherId);
            }
            $classIds = $query->pluck('ID')->map(function ($id) {
                return (int) $id;
            })->all();
        } catch (\Throwable $e) {
            return response()->json(empty($result) ? (object) [] : $result);
        }

        if (empty($classIds)) {
            return response()->json(empty($result) ? (object) [] : $result);
        }

        try {
            $classes = StudentClass::whereIn('ID', $classIds)
                ->select('ID', 'StartDate', 'SessionCount', 'ScheduleMode', 'week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6')
                ->get()
                ->keyBy('ID');

            $schedules = Schedule::where('branch_id', $branchId)
                ->whereNotNull('student_course_id')
                ->whereIn('student_course_id', $classIds)
                ->select('student_course_id', 'schedule_date', 'status')
                ->get();

            $sessions = ClassSession::whereIn('StudentClassID', $classIds)
                ->select('StudentClassID', 'SessionDate')
                ->get();

            $leaveByClass = [];
            $scheduledByClass = [];
            foreach ($schedules as $row) {
                $id = (int) $row->student_course_id;
                $d = $row->schedule_date ? Carbon::parse($row->schedule_date)->toDateString() : null;
                if (!$d) {
                    continue;
                }
                if ($row->status === 'scheduled') {
                    if (!isset($scheduledByClass[$id])) {
                        $scheduledByClass[$id] = [];
                    }
                    $scheduledByClass[$id][$d] = true;
                } else {
                    if (!isset($leaveByClass[$id])) {
                        $leaveByClass[$id] = [];
                    }
                    $leaveByClass[$id][$d] = true;
                }
            }

            foreach ($classIds as $id) {
                if (isset($result[(string) $id])) {
                    continue;
                }
                $class = $classes->get($id);
                $isSessionMode = $class && ($class->ScheduleMode ?? '') === 'count' && (int) ($class->SessionCount ?? 0) > 0;
                $startDate = $class && $class->StartDate ? Carbon::parse($class->StartDate)->toDateString() : null;

                $weekFields = ['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'];
                $daysOfWeek = [];
                if ($class) {
                    foreach ($weekFields as $wf) {
                        $d = (int) ($class->{$wf} ?? 0);
                        if ($d >= 1 && $d <= 7 && !in_array($d, $daysOfWeek, true)) {
                            $daysOfWeek[] = $d;
                        }
                    }
                    if (empty($daysOfWeek) && (int) ($class->week ?? 0) >= 1 && (int) ($class->week ?? 0) <= 7) {
                        $daysOfWeek = [(int) $class->week];
                    }
                }

                if ($isSessionMode && $startDate && !empty($daysOfWeek)) {
                    $n = (int) $class->SessionCount;
                    $leaveSet = $leaveByClass[$id] ?? [];
                    $scheduledSet = $scheduledByClass[$id] ?? [];
                    $list = self::computeEffectiveSessionDates($startDate, $n, $daysOfWeek, $leaveSet, $scheduledSet);
                    $result[(string) $id] = $list;
                    continue;
                }

                $set = [];
                foreach ($sessions as $row) {
                    if ((int) $row->StudentClassID !== $id) {
                        continue;
                    }
                    $d = $row->SessionDate ? Carbon::parse($row->SessionDate)->toDateString() : null;
                    if ($d) {
                        $set[$d] = true;
                    }
                }
                foreach ($schedules as $row) {
                    if ((int) $row->student_course_id !== $id) {
                        continue;
                    }
                    $d = $row->schedule_date ? Carbon::parse($row->schedule_date)->toDateString() : null;
                    if (!$d) {
                        continue;
                    }
                    if ($row->status === 'scheduled') {
                        $set[$d] = true;
                    } else {
                        unset($set[$d]);
                    }
                }
                $list = array_keys($set);
                sort($list);
                $n = (int) ($class->SessionCount ?? 0);
                if ($n > 0 && count($list) > $n) {
                    $list = array_slice($list, 0, $n);
                }
                $result[(string) $id] = $list;
            }
        } catch (\Throwable $e) {
            // Laravel DB/Schedule may be empty or schema differs (e.g. branch uses Supabase only); return what we have
            return response()->json(empty($result) ? (object) [] : $result);
        }

        return response()->json($result);
    }

    /**
     * 堂數制：從第一堂日開始，依排課星期與請假/調課/加課，算出恰好 N 堂的有效日期（請假會讓結束日往後推）。
     */
    private static function computeEffectiveSessionDates(string $startDate, int $n, array $daysOfWeek, array $leaveSet, array $scheduledSet): array
    {
        $list = [];
        $d = Carbon::parse($startDate . ' 12:00:00');
        $end = $d->copy()->addYears(2);
        while ($d <= $end && count($list) < $n) {
            $ymd = $d->toDateString();
            $dow = $d->dayOfWeekIso;
            $isRegular = in_array($dow, $daysOfWeek, true);
            $isLeave = isset($leaveSet[$ymd]);
            $isScheduledExtra = isset($scheduledSet[$ymd]);

            if ($isRegular && !$isLeave) {
                $list[] = $ymd;
            } elseif ($isScheduledExtra && !$isRegular) {
                $list[] = $ymd;
            }
            $d->addDay();
        }
        return array_slice($list, 0, $n);
    }

    public function show(StudentClass $studentClass)
    {
        $role = request()->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);
        if ($role === 'teacher') {
            $teacherId = request()->attributes->get('auth_teacher_id');
            if (!$teacherId || (int) $studentClass->TeacherID !== (int) $teacherId) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        if (!empty($campusIds)) {
            $allowed = Student::whereIn('CampusID', $campusIds)
                ->where('id', $studentClass->StudentID)
                ->exists();
            if (!$allowed) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $studentClass->load(['student', 'classSessions', 'room.campus']);
        $studentClass->branch_id = $studentClass->room?->campus_id;
        $studentClass->branch_name = $studentClass->room?->campus?->name;
        $studentClass->room_name = $studentClass->room?->name;

        return response()->json($studentClass);
    }

    public function store(Request $request)
    {
        $mapped = $this->mapFrontendPayload($request);
        $request->replace(array_merge($request->all(), $mapped));

        $data = $request->validate([
            'StudentID' => 'required|integer',
            'GradeID' => 'nullable|integer',
            'SubjectID' => 'required|integer',
            'ClassType' => 'nullable|string|max:32',
            'TeacherID' => 'nullable|integer',
            'by1' => 'required|integer',
            'Period' => 'nullable|integer',
            'StartDate' => 'required|date',
            'EndDate' => 'nullable|date',
            'TotalHours' => 'nullable|integer',
            'Memo' => 'nullable|string|max:512',
            'Charge' => 'nullable|integer',
            'Pay' => 'nullable|integer',
            'PayDate' => 'nullable|date',
            'Paid' => 'nullable|integer',
            'Stop' => 'nullable|integer',
            'Disconunt' => 'nullable|integer',
            'Rate' => 'nullable|numeric',
            'LearnTimeID' => 'nullable|integer',
            'RoomID' => 'nullable|string|max:32',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'settlement_day' => 'nullable|integer|min:1|max:28',
            'monthly_sessions' => 'nullable|integer|min:0',
            'ScheduleMode' => 'required|in:date,count',
            'SessionCount' => 'nullable|integer',
            'RemainingSessions' => 'nullable|integer',
            'SessionDuration' => 'nullable|integer|min:30',

            'ScheduleSlots' => 'nullable|array',
            'ScheduleSlots.*.weekday' => 'required_with:ScheduleSlots|integer|min:0|max:6',
            'ScheduleSlots.*.time' => 'required_with:ScheduleSlots|date_format:H:i',
        ]);

        if ($data['ScheduleMode'] === 'date') {
            if (empty($data['settlement_day']) || (int) $data['settlement_day'] < 1 || (int) $data['settlement_day'] > 28) {
                return response()->json([
                    'message' => '月結制度必須填寫結算日（每月 1–28 號）',
                    'errors' => ['settlement_day' => ['月結時結算日為必填，且須為 1–28。']],
                ], 422);
            }
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role === 'teacher') {
            $teacherId = $request->attributes->get('auth_teacher_id');
            if (!$teacherId || (int) $data['TeacherID'] !== (int) $teacherId) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        if (!empty($campusIds)) {
            $allowed = Student::whereIn('CampusID', $campusIds)
                ->where('id', $data['StudentID'])
                ->exists();
            if (!$allowed) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        if (!empty($campusIds) && !empty($data['room_id'])) {
            $roomCampusId = DB::table('rooms')->where('id', (int) $data['room_id'])->value('campus_id');
            if ($roomCampusId !== null && !in_array((int) $roomCampusId, $campusIds, true)) {
                return response()->json(['message' => '所選教室不屬於您可存取的分校'], 422);
            }
        }

        // ── Validate teacher is assigned to at least one accessible campus ──
        if (!empty($campusIds)) {
            $teacherHasCampus = UserCampus::where('UserID', (int) $data['TeacherID'])
                ->whereIn('CampusID', $campusIds)
                ->exists();
            if (!$teacherHasCampus) {
                return response()->json([
                    'message' => 'Teacher is not assigned to any of your accessible campuses. Please add the teacher to the branch first.',
                ], 422);
            }
        }

        // Auto-create Teacher row if User exists but Teacher doesn't
        if (!empty($data['TeacherID'])) {
            $tid = (int) $data['TeacherID'];
            if ($tid && !DB::table('Teacher')->where('id', $tid)->exists()) {
                $userName = DB::table('User')->where('id', $tid)->value('Name') ?? '';
                DB::table('Teacher')->insert([
                    'id' => $tid,
                    'T_Name' => $userName,
                    'CampusID' => 0,
                    'Enable' => 1,
                    'MDT' => now(),
                    'TelegramID' => '',
                ]);
            }
        }

        $scheduleSlots = $data['ScheduleSlots'] ?? [];

        if (!isset($data['Period'])) {
            $data['Period'] = 4;
        }

        if (!isset($data['ClassType'])) {
            $data['ClassType'] = 'regular';
        }

        if (!isset($data['TotalHours'])) {
            $data['TotalHours'] = 0;
        }

        if ($data['ScheduleMode'] === 'count' && !isset($data['RemainingSessions'])) {
            $data['RemainingSessions'] = $data['SessionCount'] ?? null;
        }

        return DB::transaction(function () use ($data, $scheduleSlots) {
            $createData = $this->mapScheduleSlots($data, $scheduleSlots);
            // Remove fields that may not exist as DB columns
            unset($createData['ScheduleSlots']);

            try {
                $studentClass = StudentClass::create($createData);
            } catch (\Illuminate\Database\QueryException $e) {
                // If columns like SessionCount/RemainingSessions/ScheduleMode don't exist, retry without them
                if (str_contains($e->getMessage(), 'Unknown column')) {
                    preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $m);
                    $badCol = $m[1] ?? '';
                    unset($createData[$badCol]);
                    // Try again, removing up to 5 missing columns
                    for ($retry = 0; $retry < 5; $retry++) {
                        try {
                            $studentClass = StudentClass::create($createData);
                            break;
                        } catch (\Illuminate\Database\QueryException $e2) {
                            if (str_contains($e2->getMessage(), 'Unknown column')) {
                                preg_match("/Unknown column '([^']+)'/", $e2->getMessage(), $m2);
                                unset($createData[$m2[1] ?? '__none']);
                            } else {
                                throw $e2;
                            }
                        }
                    }
                    if (!isset($studentClass)) {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }

            $sessionDuration = $data['SessionDuration'] ?? 120;
            $sessions = [];

            if ($data['ScheduleMode'] === 'date') {
                if (!empty($scheduleSlots) && !empty($data['EndDate'])) {
                    $sessions = $this->buildSessionsFromWeeklySchedule(
                        $studentClass->ID,
                        $data['StartDate'],
                        $data['EndDate'],
                        $scheduleSlots,
                        $sessionDuration
                    );
                }
            }

            if ($data['ScheduleMode'] === 'count') {
                if (!empty($scheduleSlots) && !empty($data['SessionCount'])) {
                    $sessions = $this->buildSessionsForCount(
                        $studentClass->ID,
                        $data['StartDate'],
                        (int) $data['SessionCount'],
                        $scheduleSlots,
                        $sessionDuration
                    );
                }
            }

            // ── Server-side conflict detection ──────────────────────────
            if (!empty($sessions)) {
                $conflicts = $this->detectTeacherConflicts((int) $data['TeacherID'], $sessions);
                if (!empty($conflicts)) {
                    // Abort the transaction - rollback is automatic
                    throw new \Illuminate\Validation\ValidationException(
                        validator([], []),
                        response()->json([
                            'message' => 'Teacher scheduling conflict detected',
                            'conflicts' => $conflicts,
                        ], 409)
                    );
                }

                // 逐筆建立 ClassSession 以取得 id，再建立對應 LearningRecord（ClassSessionID 不可為 null）
                $subjectName = DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
                    ?? DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
                    ?? '評量';
                $teacherId = (int) ($data['TeacherID'] ?? 0);
                $today = Carbon::today()->toDateString();
                foreach ($sessions as $sess) {
                    $classSession = ClassSession::create($sess);
                    // 今天以後還沒上的課不需要填寫評量表，不建立 pending LearningRecord
                    if ($sess['SessionDate'] <= $today) {
                        LearningRecord::create([
                            'StudentClassID' => $studentClass->ID,
                            'ClassSessionID' => $classSession->id,
                            'TeacherID' => $teacherId,
                            'CreatedByUserID' => null,
                            'Content' => '',
                            'Subject' => $subjectName,
                            'SessionDate' => $sess['SessionDate'],
                            'StartTime' => $sess['StartTime'] ?? '00:00:00',
                            'EndTime' => $sess['EndTime'] ?? '00:00:00',
                            'Status' => 'pending',
                        ]);
                    }
                }
            }

            return response()->json($studentClass, 201);
        });
    }

    public function update(Request $request, StudentClass $studentClass)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if (!empty($campusIds)) {
            $allowed = Student::whereIn('CampusID', $campusIds)
                ->where('id', $studentClass->StudentID)
                ->exists();
            if (!$allowed) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $mapped = $this->mapFrontendPayload($request);

        // Remove ScheduleSlots and ID references to prevent overwriting critical relationships
        unset($mapped['ScheduleSlots'], $mapped['StudentID'], $mapped['GradeID'], $mapped['RoomID'], $mapped['by1']);

        if (!empty($campusIds) && !empty($mapped['room_id'])) {
            $roomCampusId = DB::table('rooms')->where('id', (int) $mapped['room_id'])->value('campus_id');
            if ($roomCampusId !== null && !in_array((int) $roomCampusId, $campusIds, true)) {
                return response()->json(['message' => '所選教室不屬於您可存取的分校'], 422);
            }
        }

        $studentClass->update($mapped);

        return response()->json($studentClass);
    }

    /**
     * POST /api/v1/student-classes/sync
     * Accepts an array of Supabase courses and upserts them into MySQL so that
     * LearningRecord deduction and dashboard queries can work with matching IDs.
     */
    public function sync(Request $request)
    {
        $courses = $request->input('courses', []);
        if (!is_array($courses) || empty($courses)) {
            return response()->json(['synced' => 0]);
        }

        // Build a per-course exception map from the frontend payload (Supabase schedules).
        // Keys: (string) student_course_id → ['leave' => Set, 'scheduled' => Set]
        $exceptionsByCourse = [];
        foreach ($request->input('exceptions', []) as $ex) {
            $cid = (string) ($ex['student_course_id'] ?? '');
            $sd  = $ex['schedule_date'] ?? null;
            $st  = $ex['status'] ?? null;
            if (!$cid || !$sd || !$st) continue;
            if (!isset($exceptionsByCourse[$cid])) {
                $exceptionsByCourse[$cid] = ['leave' => [], 'scheduled' => []];
            }
            if ($st === 'leave' || $st === 'rescheduled') {
                $exceptionsByCourse[$cid]['leave'][$sd] = true;
            } elseif ($st === 'scheduled') {
                $exceptionsByCourse[$cid]['scheduled'][$sd] = true;
            }
        }

        $subjectMap = [
            'Chinese' => '國文', 'English' => '英文', 'Math' => '數學',
            'Physics' => '物理', 'Chemistry' => '化學', 'Science' => '自然', 'Social' => '社會',
        ];

        $synced = 0;
        foreach ($courses as $c) {
            $id = $c['id'] ?? null;
            if ($id === null || $id === '') continue;

            $studentId = $c['student_id'] ?? null;
            $supabaseTeacherId = $c['teacher_id'] ?? null;
            $teacherEmail = $c['teacher_email'] ?? null;
            $teacherId = null;
            if ($teacherEmail) {
                $teacherId = DB::table('User')->where('LoginName', $teacherEmail)->value('id');
            }
            if (!$teacherId && $supabaseTeacherId) {
                $teacherId = DB::table('User')->where('id', $supabaseTeacherId)->value('id');
            }

            // Auto-create Teacher row if User exists but Teacher doesn't
            if ($teacherId && !DB::table('Teacher')->where('id', $teacherId)->exists()) {
                $userName = DB::table('User')->where('id', $teacherId)->value('Name') ?? '';
                DB::table('Teacher')->insert([
                    'id' => $teacherId,
                    'T_Name' => $userName,
                    'CampusID' => 0,
                    'Enable' => 1,
                    'MDT' => now(),
                    'TelegramID' => '',
                ]);
            }
            $frontendSubject = $c['subject'] ?? 'Math';
            $subjectName = $subjectMap[$frontendSubject] ?? $frontendSubject;
            $subjectId = DB::table('Subject')->where('Subject_Name', 'like', "%{$subjectName}%")->value('id')
                ?? DB::table('BaseData')->where('Name', '課程')->where('Val', 'like', "%{$subjectName}%")->value('id')
                ?? 1;

            $paymentType = $c['payment_type'] ?? 'session';
            $scheduleMode = $paymentType === 'session' ? 'count' : 'date';
            $sessionCount = (int) ($c['sessions_purchased'] ?? 0);
            $remaining = $c['remaining_sessions'] ?? null;
            $startDate = $c['first_class_date'] ?? null;
            $daysOfWeek = $c['days_of_week'] ?? [];
            $classType = $c['class_type'] ?? 'one_on_one';
            $startTime = $c['start_time'] ?? null;

            $weekFields = ['week' => null, 'week1' => null, 'week2' => null, 'week3' => null, 'week4' => null, 'week5' => null, 'week6' => null];
            if (is_array($daysOfWeek) && !empty($daysOfWeek)) {
                $weekFields['week'] = (int) $daysOfWeek[0];
                foreach ($daysOfWeek as $idx => $dow) {
                    if ($idx < 6) $weekFields['week' . ($idx + 1)] = (int) $dow;
                }
            }

            $row = [
                'StudentID' => $studentId,
                'TeacherID' => $teacherId,
                'SubjectID' => $subjectId,
                'ClassType' => $classType,
                'ScheduleMode' => $scheduleMode,
                'SessionCount' => $sessionCount > 0 ? $sessionCount : null,
                'StartDate' => $startDate,
                'time' => $startTime ? substr($startTime, 0, 5) . ':00' : null,
                'MDate' => now(),
                'Stop' => 0,
            ] + $weekFields;

            if ($remaining !== null) {
                $row['RemainingSessions'] = (int) $remaining;
            }

            try {
                $existing = StudentClass::find($id);
                if ($existing) {
                    $updateRow = $row;
                    unset($updateRow['RemainingSessions']);
                    $existing->fill($updateRow);
                    $existing->save();
                } else {
                    $row['ID'] = (int) $id;
                    $row['GradeID'] = 1;
                    $row['RoomID'] = '1';
                    $row['Period'] = 4;
                    $row['TotalHours'] = 0;
                    $by1Map = ['one_on_one' => 1, 'one_on_two' => 2, 'one_on_three' => 3, 'tutoring' => 4];
                    $row['by1'] = $by1Map[$classType] ?? 1;
                    if ($remaining !== null) {
                        $row['RemainingSessions'] = (int) $remaining;
                    } elseif ($sessionCount > 0) {
                        $approvedCount = LearningRecord::where('StudentClassID', $id)->where('Status', 'approved')->count();
                        $row['RemainingSessions'] = max(0, $sessionCount - $approvedCount);
                        $row['UsedSessions'] = $approvedCount;
                    }
                    DB::statement('SET @old_auto_increment = (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "StudentClass")');
                    DB::table('StudentClass')->insert($row);
                    $maxId = (int) DB::table('StudentClass')->max('ID');
                    if ($maxId >= (int) $id) {
                        DB::statement('ALTER TABLE StudentClass AUTO_INCREMENT = ' . ($maxId + 1));
                    }
                }
                $synced++;

                // Incrementally create ClassSessions + pending LearningRecords using exceptions from payload
                if ($startDate && $sessionCount > 0 && is_array($daysOfWeek) && !empty($daysOfWeek)) {
                    $intDays = array_map('intval', $daysOfWeek);
                    $courseExceptions = $exceptionsByCourse[(string) $id] ?? ['leave' => [], 'scheduled' => []];
                    $leaveSet = $courseExceptions['leave'];
                    $scheduledSet = $courseExceptions['scheduled'];

                    $dates = self::computeEffectiveSessionDates($startDate, $sessionCount, $intDays, $leaveSet, $scheduledSet);
                    $validDatesSet = array_flip($dates);
                    $today = Carbon::today()->toDateString();
                    $sTime = $startTime ? substr($startTime, 0, 5) : '16:00';
                    $eTime = date('H:i', strtotime($sTime . ' +2 hours'));

                    // Only remove ClassSessions/LRs for explicit leave dates.
                    // Never delete ClassSessions that have approved LRs — they are permanent records.
                    $existingSessions = ClassSession::where('StudentClassID', (int) $id)->get();
                    foreach ($existingSessions as $cs) {
                        $csDate = Carbon::parse($cs->SessionDate)->toDateString();
                        if (!isset($validDatesSet[$csDate]) && isset($leaveSet[$csDate])) {
                            $hasApproved = LearningRecord::where('StudentClassID', (int) $id)
                                ->where('SessionDate', $csDate)
                                ->where('Status', 'approved')
                                ->exists();
                            if (!$hasApproved) {
                                LearningRecord::where('StudentClassID', (int) $id)
                                    ->where('SessionDate', $csDate)
                                    ->where('Status', 'pending')
                                    ->delete();
                                $cs->delete();
                            }
                        }
                    }

                    // Remove orphaned pending LRs only for explicit leave dates (not for unknown dates)
                    $allPendingLrDates = LearningRecord::where('StudentClassID', (int) $id)
                        ->where('Status', 'pending')
                        ->pluck('SessionDate')
                        ->map(fn($d) => Carbon::parse($d)->toDateString())
                        ->unique()
                        ->all();
                    foreach ($allPendingLrDates as $lrDate) {
                        if (!isset($validDatesSet[$lrDate]) && isset($leaveSet[$lrDate])) {
                            LearningRecord::where('StudentClassID', (int) $id)
                                ->where('SessionDate', $lrDate)
                                ->where('Status', 'pending')
                                ->delete();
                        }
                    }

                    // Fetch existing ClassSession dates after cleanup
                    $existingCsDates = ClassSession::where('StudentClassID', (int) $id)
                        ->pluck('SessionDate')
                        ->map(fn($d) => Carbon::parse($d)->toDateString())
                        ->flip()
                        ->all();

                    foreach ($dates as $sessionDate) {
                        if (isset($existingCsDates[$sessionDate])) continue;
                        $cs = ClassSession::create([
                            'StudentClassID' => (int) $id,
                            'SessionDate' => $sessionDate,
                            'StartTime' => $sTime,
                            'EndTime' => $eTime,
                            'Status' => $sessionDate <= $today ? 'completed' : 'scheduled',
                        ]);
                        if ($sessionDate <= $today && $teacherId) {
                            $lrExists = LearningRecord::where('StudentClassID', (int) $id)
                                ->where('SessionDate', $sessionDate)
                                ->exists();
                            if (!$lrExists) {
                                LearningRecord::create([
                                    'StudentClassID' => (int) $id,
                                    'ClassSessionID' => $cs->id,
                                    'TeacherID' => (int) $teacherId,
                                    'Subject' => $subjectName,
                                    'Content' => '',
                                    'SessionDate' => $sessionDate,
                                    'StartTime' => $sTime,
                                    'EndTime' => $eTime,
                                    'Status' => 'pending',
                                ]);
                            }
                        }
                    }

                    // Recalculate remaining sessions based on ALL approved LRs for this course
                    $totalApproved = LearningRecord::where('StudentClassID', (int) $id)
                        ->where('Status', 'approved')
                        ->count();
                    $newRemaining = max(0, $sessionCount - $totalApproved);
                    DB::table('StudentClass')->where('ID', (int) $id)->update([
                        'RemainingSessions' => $newRemaining,
                        'UsedSessions' => $totalApproved,
                    ]);
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return response()->json(['synced' => $synced]);
    }

    public function confirmPayment(StudentClass $studentClass)
    {
        $studentClass->Paid = 1;
        $studentClass->PayDate = now()->toDateString();
        $studentClass->save();

        return response()->json(['message' => '已確認繳費', 'class_id' => $studentClass->ID]);
    }

    public function destroy(StudentClass $studentClass)
    {
        $role = request()->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);

        if (!empty($campusIds)) {
            $allowed = Student::whereIn('CampusID', $campusIds)
                ->where('id', $studentClass->StudentID)
                ->exists();
            if (!$allowed) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        return DB::transaction(function () use ($studentClass) {
            // Delete associated class sessions first
            ClassSession::where('StudentClassID', $studentClass->ID)->delete();

            // Delete the student class
            $studentClass->delete();

            return response()->json(['message' => 'Course deleted successfully']);
        });
    }

    private function mapFrontendPayload(Request $request): array
    {
        $input = $request->json()->all();
        if (isset($input[0]) && is_array($input[0])) {
            $input = $input[0];
        }

        // If no translation needed (e.g. standard backend request), return straight away
        if (isset($input['StudentID'])) {
            return $input;
        }

        $subjectMap = [
            'Chinese' => '國文',
            'English' => '英文',
            'Math' => '數學',
            'Physics' => '物理',
            'Chemistry' => '化學',
            'Science' => '自然',
            'Social' => '社會'
        ];
        $frontendSubject = $input['subject'] ?? 'Math';
        $subjectName = $subjectMap[$frontendSubject] ?? $frontendSubject;
        $subjectId = DB::table('Subject')->where('Subject_Name', 'like', "%$subjectName%")->value('id') ??
            DB::table('BaseData')->where('Name', '課程')->where('Val', 'like', "%$subjectName%")->value('id') ?? 1;

        $mappedData = [];
        if (isset($input['student_id'])) $mappedData['StudentID'] = $input['student_id'];
        if (isset($input['teacher_id'])) $mappedData['TeacherID'] = $input['teacher_id'];
        if (isset($input['subject'])) $mappedData['SubjectID'] = $subjectId;
        if (isset($input['class_type'])) $mappedData['ClassType'] = $input['class_type'];
        if (isset($input['payment_type'])) $mappedData['ScheduleMode'] = ($input['payment_type'] === 'session') ? 'count' : 'date';
        if (isset($input['rate_per_30min'])) $mappedData['Rate'] = $input['rate_per_30min'];
        if (isset($input['duration_hours'])) $mappedData['SessionDuration'] = (int) round((float) $input['duration_hours'] * 60);
        if (isset($input['sessions_purchased'])) $mappedData['SessionCount'] = $input['sessions_purchased'];
        if (isset($input['remaining_sessions'])) $mappedData['RemainingSessions'] = $input['remaining_sessions'];
        if (isset($input['status'])) $mappedData['Stop'] = $input['status'] === 'inactive' ? 1 : 0;
        if (isset($input['payment_status'])) $mappedData['Paid'] = $input['payment_status'] === 'paid' ? 1 : 0;
        if (array_key_exists('room_id', $input)) $mappedData['room_id'] = $input['room_id'] ? (int) $input['room_id'] : null;
        if (array_key_exists('settlement_day', $input)) $mappedData['settlement_day'] = $input['settlement_day'] !== null && $input['settlement_day'] !== '' ? (int) $input['settlement_day'] : null;
        if (array_key_exists('monthly_sessions', $input)) $mappedData['monthly_sessions'] = $input['monthly_sessions'] !== null && $input['monthly_sessions'] !== '' ? (int) $input['monthly_sessions'] : null;
        if (array_key_exists('Memo', $input)) $mappedData['Memo'] = $input['Memo'];
        if (array_key_exists('memo', $input)) $mappedData['Memo'] = $input['memo'];

        // Default constraints for creation only
        if ($request->isMethod('post')) {
            $classType = $input['class_type'] ?? 'one_on_one';
            $by1Map = ['one_on_one' => 1, 'one_on_two' => 2, 'one_on_three' => 3, 'tutoring' => 4];
            $mappedData['by1'] = $by1Map[$classType] ?? 1;

            $mappedData['StartDate'] = $input['first_class_date'] ?? now()->toDateString();
            $mappedData['RoomID'] = isset($input['room_id']) && $input['room_id'] ? (string) $input['room_id'] : '1';
            $mappedData['GradeID'] = 1;
            if (isset($mappedData['StudentID'])) {
                try {
                    $gradeId = \App\Models\Student::where('id', $mappedData['StudentID'])->value('GradeID');
                    if ($gradeId) $mappedData['GradeID'] = $gradeId;
                } catch (\Throwable $e) {
                    // GradeID column may not exist in Student table
                }
            }
        }

        if (($request->isMethod('put') || $request->isMethod('patch')) && !empty($input['first_class_date'])) {
            $mappedData['StartDate'] = $input['first_class_date'];
        }

        // Handle days_of_week for both create and update (so 課程管理 編輯儲存 會更新 week1..week6)
        $daysOfWeek = $input['days_of_week'] ?? null;
        if (is_array($daysOfWeek) && !empty($daysOfWeek)) {
            for ($i = 1; $i <= 6; $i++) {
                $mappedData["week{$i}"] = null;
            }
            foreach ($daysOfWeek as $idx => $dow) {
                if ($idx < 6) {
                    $mappedData['week' . ($idx + 1)] = (int) $dow;
                }
            }
            $mappedData['week'] = (int) $daysOfWeek[0];
        } elseif (isset($input['day_of_week']) && ($request->isMethod('post') || $request->isMethod('put'))) {
            $dow = (int) $input['day_of_week'];
            $mappedData['week'] = $dow;
            for ($i = 1; $i <= 6; $i++) {
                $mappedData["week{$i}"] = null;
            }
            if ($dow > 0) {
                $mappedData['week1'] = $dow;
                $mappedData['week2'] = $dow;
                $mappedData['week3'] = $dow;
                $mappedData['week4'] = $dow;
                $mappedData['week5'] = $dow;
            }
        }

        // Build ScheduleSlots from days_of_week or day_of_week
        $startTime = $input['start_time'] ?? null;
        $daysOfWeek = $input['days_of_week'] ?? null;
        if ($startTime && is_array($daysOfWeek) && !empty($daysOfWeek)) {
            $mappedData['ScheduleSlots'] = array_map(fn($d) => [
                'weekday' => (int) $d,
                'time' => $startTime,
            ], $daysOfWeek);
            $mappedData['week'] = (int) $daysOfWeek[0];
            $mappedData['time'] = substr($startTime, 0, 5) . ':00';
        } elseif (isset($input['day_of_week']) && $startTime) {
            $mappedData['ScheduleSlots'] = [
                [
                    'weekday' => (int) $input['day_of_week'],
                    'time' => $startTime,
                ]
            ];
            $mappedData['week'] = (int) $input['day_of_week'];
            $mappedData['time'] = substr($startTime, 0, 5) . ':00';
        }

        return $mappedData;
    }

    private function mapScheduleSlots(array $data, array $slots): array
    {
        $mapped = $data;

        $weekFields = ['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'];
        $timeFields = ['time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6'];

        foreach ($weekFields as $index => $weekField) {
            $mapped[$weekField] = $slots[$index]['weekday'] ?? null;
            $mapped[$timeFields[$index]] = $slots[$index]['time'] ?? null;
        }

        $mapped['MDate'] = now();
        $mapped['Stop'] = $mapped['Stop'] ?? 0;

        return $mapped;
    }

    /**
     * Detect scheduling conflicts for a teacher against proposed new sessions.
     *
     * Uses a pessimistic lock (FOR UPDATE) on existing ClassSession rows via
     * the teacher's active StudentClass records to prevent concurrent inserts
     * from creating overlapping bookings.
     *
     * @return array List of conflict descriptions (empty = no conflicts)
     */
    private function detectTeacherConflicts(int $teacherId, array $proposedSessions): array
    {
        if (empty($proposedSessions)) {
            return [];
        }

        // Collect all unique dates from proposed sessions
        $dates = array_unique(array_column($proposedSessions, 'SessionDate'));

        // Get all active StudentClass IDs for this teacher (locked to prevent concurrent inserts)
        $teacherClassIds = StudentClass::where('TeacherID', $teacherId)
            ->where('Stop', 0)
            ->lockForUpdate()
            ->pluck('ID')
            ->all();

        if (empty($teacherClassIds)) {
            return [];
        }

        // Get existing sessions on the same dates for this teacher's classes
        $existingSessions = ClassSession::whereIn('StudentClassID', $teacherClassIds)
            ->whereIn('SessionDate', $dates)
            ->whereNotIn('Status', ['cancelled'])
            ->lockForUpdate()
            ->get();

        $conflicts = [];

        foreach ($proposedSessions as $proposed) {
            $pStart = Carbon::parse($proposed['SessionDate'] . ' ' . $proposed['StartTime']);
            $pEnd = Carbon::parse($proposed['SessionDate'] . ' ' . $proposed['EndTime']);

            foreach ($existingSessions as $existing) {
                if ($existing->SessionDate !== $proposed['SessionDate']) {
                    continue;
                }

                $eStart = Carbon::parse($existing->SessionDate . ' ' . $existing->StartTime);
                $eEnd = Carbon::parse($existing->SessionDate . ' ' . $existing->EndTime);

                // Check time overlap: two intervals overlap if start < other_end AND end > other_start
                if ($pStart->lt($eEnd) && $pEnd->gt($eStart)) {
                    $conflicts[] = [
                        'date' => $proposed['SessionDate'],
                        'proposed_time' => $proposed['StartTime'] . '-' . $proposed['EndTime'],
                        'existing_time' => $existing->StartTime . '-' . $existing->EndTime,
                        'existing_session_id' => $existing->id,
                    ];
                    break; // One conflict per proposed session is enough
                }
            }
        }

        return $conflicts;
    }

    private function buildSessionsFromWeeklySchedule(
        int $studentClassId,
        string $startDate,
        string $endDate,
        array $slots,
        int $durationMinutes
    ): array {
        $sessions = [];
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            foreach ($slots as $slot) {
                if ((int) $date->dayOfWeek === (int) $slot['weekday']) {
                    $startTime = Carbon::parse($date->toDateString() . ' ' . $slot['time']);
                    $endTime = $startTime->copy()->addMinutes($durationMinutes);

                    $sessions[] = [
                        'StudentClassID' => $studentClassId,
                        'SessionDate' => $startTime->toDateString(),
                        'StartTime' => $startTime->format('H:i:s'),
                        'EndTime' => $endTime->format('H:i:s'),
                        'Status' => 'scheduled',
                        'Note' => '',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        return $sessions;
    }

    /**
     * 堂數制：排定共 sessionCount 堂。
     * 第 1 堂固定為「首堂日」startDate（不論星期幾），使用 slots 的第一個時段；
     * 第 2～N 堂從 startDate 的隔天起，依序取「星期符合 slots」的日期（如一四則只取週一、週四）。
     */
    private function buildSessionsForCount(
        int $studentClassId,
        string $startDate,
        int $sessionCount,
        array $slots,
        int $durationMinutes
    ): array {
        $sessions = [];
        if ($sessionCount < 1 || empty($slots)) {
            return $sessions;
        }

        $firstSlot = $slots[0];
        $firstTime = $firstSlot['time'] ?? '16:00';
        $slotWeekdays = array_values(array_unique(array_column($slots, 'weekday')));
        $timeByWeekday = [];
        foreach ($slots as $s) {
            $timeByWeekday[(int) $s['weekday']] = $s['time'] ?? $firstTime;
        }

        // 第 1 堂：首堂日（可為任意星期，例如 2/9 週二）
        $firstDate = Carbon::parse($startDate)->startOfDay();
        $startTime = Carbon::parse($firstDate->toDateString() . ' ' . $firstTime);
        $endTime = $startTime->copy()->addMinutes($durationMinutes);
        $sessions[] = [
            'StudentClassID' => $studentClassId,
            'SessionDate' => $startTime->toDateString(),
            'StartTime' => $startTime->format('H:i:s'),
            'EndTime' => $endTime->format('H:i:s'),
            'Status' => 'scheduled',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // 第 2～N 堂：從首堂日隔天起，只取星期在 slotWeekdays 的日期
        $date = Carbon::parse($startDate)->addDay()->startOfDay();
        $needed = $sessionCount - 1;
        while (count($sessions) < $sessionCount && $needed > 0) {
            $dow = (int) $date->dayOfWeek; // 0=Sun, 1=Mon, ...
            $isoDow = $dow === 0 ? 7 : $dow; // 1=Mon .. 7=Sun for slots
            if (in_array($isoDow, $slotWeekdays, true)) {
                $time = $timeByWeekday[$isoDow] ?? $firstTime;
                $start = Carbon::parse($date->toDateString() . ' ' . $time);
                $end = $start->copy()->addMinutes($durationMinutes);
                $sessions[] = [
                    'StudentClassID' => $studentClassId,
                    'SessionDate' => $start->toDateString(),
                    'StartTime' => $start->format('H:i:s'),
                    'EndTime' => $end->format('H:i:s'),
                    'Status' => 'scheduled',
                    'Note' => '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $needed--;
            }
            $date->addDay();
        }

        return $sessions;
    }
}
