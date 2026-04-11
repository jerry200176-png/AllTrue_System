<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\UserCampus;
use App\Services\FrontendSubjectIdResolver;
use App\Services\SessionDeductionService;
use App\Services\ScheduleGuardService;
use App\Services\TeacherScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentClassController extends Controller
{
    public function __construct(private ScheduleGuardService $scheduleGuardService)
    {
    }

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

        if ($request->filled('status')) {
            $statusVal = $request->input('status');
            if ($statusVal === 'inactive') {
                $query->where('Stop', 1);
            } elseif ($statusVal === 'active') {
                $query->where(function ($q) {
                    $q->where('Stop', 0)->orWhereNull('Stop');
                });
            }
        }

        if ($request->filled('name')) {
            $nameTerm = $request->input('name');
            $query->whereHas('student', function ($sub) use ($nameTerm) {
                $sub->where('name', 'like', '%' . $nameTerm . '%');
            });
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
        $classIds = $classes->getCollection()->pluck('ID')->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->values()->all();
        $observedUsedByClass = SessionDeductionService::batchObservedUsedSessions($classIds);

        $classes->getCollection()->transform(function ($class) use ($courseNames, $subjectNames, $teacherNames, $userNames, $observedUsedByClass) {
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
                '物理' => 'Physics',
                '化學' => 'Chemistry',
                '理化' => 'Science',
                '生物' => 'Biology',
                '地科' => 'Science',
                // Subject table may already store English keys.
                'Chinese' => 'Chinese',
                'English' => 'English',
                'Math' => 'Math',
                'Physics' => 'Physics',
                'Chemistry' => 'Chemistry',
                'Science' => 'Science',
                'Biology' => 'Biology',
                'Social' => 'Social',
            ];
            $subjectNameKey = trim((string) ($class->subject_name ?? ''));
            $class->subject = $reverseSubjectMap[$subjectNameKey] ?? 'Math';
            $class->class_type = $class->ClassType ?? 'one_on_one';
            $class->rate_per_30min = $class->Rate ?? 0;
            $class->duration_hours = $class->SessionDuration ? round($class->SessionDuration / 60, 1) : 2;
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
            $dayTimeSlots = [];
            $timeFields = ['time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6'];
            $durationFields = [null, 'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6'];
            $globalDurHours = $class->duration_hours;
            foreach ($weekFields as $index => $wf) {
                $day = (int) ($class->{$wf} ?? 0);
                if ($day < 1 || $day > 7) {
                    continue;
                }
                $timeField = $timeFields[$index] ?? 'time';
                $rawTime = (string) ($class->{$timeField} ?? $class->time ?? '');
                $start = $rawTime ? substr($rawTime, 0, 5) : '';
                if ($start === '') {
                    continue;
                }
                $durField = $durationFields[$index] ?? null;
                $perDayMin = $durField ? (int) ($class->{$durField} ?? 0) : 0;
                $dayTimeSlots[] = [
                    'day' => $day,
                    'start_time' => $start,
                    'duration_hours' => $perDayMin > 0 ? round($perDayMin / 60, 1) : $globalDurHours,
                ];
            }
            $class->day_time_slots = $this->dedupeIdenticalConsecutiveDayTimeSlots($dayTimeSlots);
            $class->rate_unit = $class->rate_unit ?? 'session';

            // Build the 'weeks' array for frontend (week-of-month: 第1週..第5週)
            $weeks = [];
            for ($i = 1; $i <= 5; $i++) {
                $weeks[] = $i;
            }
            $class->weeks = $weeks;

            $class->start_time = !empty($class->day_time_slots)
                ? (string) ($class->day_time_slots[0]['start_time'] ?? '')
                : ($class->time ? substr($class->time, 0, 5) : '');
            $durationSecs = (int) round($class->duration_hours * 3600);
            $class->end_time = $class->start_time ? date('H:i', strtotime($class->start_time) + $durationSecs) : null;
            $class->payment_type = ($class->ScheduleMode ?? 'count') === 'count' ? 'session' : 'monthly';
            $class->sessions_purchased = (int) ($class->SessionCount ?? 0);
            $observedUsedSessions = (int) ($observedUsedByClass[$class->ID] ?? 0);

            // Remaining = 購買堂數 − 實際已上（扣點、已完成堂次、已核准評量取最大後再與購買數取 cap）

            if ($class->sessions_purchased > 0) {
                $observedUsedSessions = min($class->sessions_purchased, $observedUsedSessions);
                $class->UsedSessions = $observedUsedSessions;
                $class->RemainingSessions = max(0, $class->sessions_purchased - $observedUsedSessions);
            }
            $class->sessions_used = (int) ($class->UsedSessions ?? 0);
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
                $classSessionsBody = ClassSession::whereIn('StudentClassID', $courseIds)
                    ->select('StudentClassID', 'SessionDate', 'Status')
                    ->get();
                $leaveByClass = [];
                $scheduledByClass = [];
                $sessionDatesByClass = [];
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
                foreach ($classSessionsBody as $row) {
                    $id = (string) $row->StudentClassID;
                    $d = $row->SessionDate ? Carbon::parse($row->SessionDate)->toDateString() : null;
                    if (!$d) {
                        continue;
                    }
                    $status = strtolower((string) ($row->Status ?? ''));
                    if (in_array($status, ['cancelled', 'leave'], true)) {
                        continue;
                    }
                    if (!isset($sessionDatesByClass[$id])) {
                        $sessionDatesByClass[$id] = [];
                    }
                    $sessionDatesByClass[$id][] = $d;
                }
                foreach ($bodyCourses as $c) {
                    $cid = $c['id'] ?? null;
                    $startDate = isset($c['first_class_date']) ? Carbon::parse($c['first_class_date'])->toDateString() : null;
                    $n = (int) ($c['sessions_purchased'] ?? 0);
                    $daysOfWeek = isset($c['days_of_week']) && is_array($c['days_of_week'])
                        ? array_values(array_unique(array_map('intval', array_filter($c['days_of_week'], function ($d) { return $d >= 1 && $d <= 7; }))))
                        : [];
                    if ($cid !== null && isset($sessionDatesByClass[(string) $cid])) {
                        $list = $sessionDatesByClass[(string) $cid];
                        sort($list);
                        $result[(string) $cid] = array_values($list);
                        continue;
                    }
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
                ->select('StudentClassID', 'SessionDate', 'Status')
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
                    $actualSessionSet = [];
                    foreach ($sessions as $row) {
                        if ((int) $row->StudentClassID !== $id) {
                            continue;
                        }
                        $status = strtolower((string) ($row->Status ?? ''));
                        if (in_array($status, ['cancelled', 'leave'], true)) {
                            continue;
                        }
                        $d = $row->SessionDate ? Carbon::parse($row->SessionDate)->toDateString() : null;
                        if ($d) {
                            $actualSessionSet[$d] = true;
                        }
                    }
                    if (!empty($actualSessionSet)) {
                        $list = array_keys($actualSessionSet);
                        sort($list);
                        $result[(string) $id] = $list;
                        continue;
                    }

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
        return response()->json([
            'message' => 'Legacy scheduling endpoint retired. Use POST /api/v1/class-sessions/batch.',
            'code' => 'legacy_schedule_endpoint_retired',
        ], 410);

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
            'settlement_day' => 'nullable|integer|min:1|max:31',
            'monthly_sessions' => 'nullable|integer|min:0',
            'ScheduleMode' => 'required|in:date,count',
            'SessionCount' => 'nullable|integer',
            'RemainingSessions' => 'nullable|integer',
            'SessionDuration' => 'nullable|integer|min:30',

            'ScheduleSlots' => 'nullable|array',
            'ScheduleSlots.*.weekday' => 'required_with:ScheduleSlots|integer|min:0|max:6',
            'ScheduleSlots.*.time' => 'required_with:ScheduleSlots|date_format:H:i',
            'skip_auto_sessions' => 'nullable|boolean',
        ]);

        if ($data['ScheduleMode'] === 'date') {
            if (empty($data['settlement_day']) || (int) $data['settlement_day'] < 1 || (int) $data['settlement_day'] > 31) {
                return response()->json([
                    'message' => '月結制度必須填寫結算日（每月 1–31 號）',
                    'errors' => ['settlement_day' => ['月結時結算日為必填，且須為 1–31。']],
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

        $studentCampusId = (int) (Student::where('id', $data['StudentID'])->value('CampusID') ?? 0);
        if (!empty($campusIds)) {
            $allowed = $studentCampusId > 0 && in_array($studentCampusId, $campusIds, true);
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
        $skipAutoSessions = (bool) ($data['skip_auto_sessions'] ?? false);

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

        return DB::transaction(function () use ($data, $scheduleSlots, $skipAutoSessions, $studentCampusId) {
            $createData = $this->mapScheduleSlots($data, $scheduleSlots);
            // Remove fields that may not exist as DB columns
            unset($createData['ScheduleSlots'], $createData['skip_auto_sessions']);

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

            if (!$skipAutoSessions && $data['ScheduleMode'] === 'date') {
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

            if (!$skipAutoSessions && $data['ScheduleMode'] === 'count') {
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
                $conflicts = $this->detectTeacherConflicts(
                    (int) ($data['TeacherID'] ?? 0),
                    $sessions,
                    (string) ($data['ClassType'] ?? 'one_on_one'),
                    !empty($data['room_id']) ? (int) $data['room_id'] : null,
                    $studentCampusId
                );
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
                            'SessionDate' => $classSession->SessionDate,
                            'StartTime' => $classSession->StartTime,
                            'EndTime' => $classSession->EndTime,
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
        $previousStartDate = $this->normalizeDateString($studentClass->StartDate ?? null);

        if (!empty($campusIds)) {
            $allowed = Student::whereIn('CampusID', $campusIds)
                ->where('id', $studentClass->StudentID)
                ->exists();
            if (!$allowed) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $mapped = $this->mapFrontendPayload($request);
        $scheduleSlotsForRebuild = is_array($mapped['ScheduleSlots'] ?? null) ? $mapped['ScheduleSlots'] : [];

        // Remove ScheduleSlots and ID references to prevent overwriting critical relationships
        unset($mapped['ScheduleSlots'], $mapped['StudentID'], $mapped['GradeID'], $mapped['RoomID'], $mapped['by1']);

        if (!empty($campusIds) && !empty($mapped['room_id'])) {
            $roomCampusId = DB::table('rooms')->where('id', (int) $mapped['room_id'])->value('campus_id');
            if ($roomCampusId !== null && !in_array((int) $roomCampusId, $campusIds, true)) {
                return response()->json(['message' => '所選教室不屬於您可存取的分校'], 422);
            }
        }

        $studentClass->update($mapped);
        $studentClass->refresh();

        $sessionSync = $this->maybeRebuildSessionsAfterUpdate(
            $studentClass,
            $previousStartDate,
            $mapped,
            $scheduleSlotsForRebuild,
            (bool) $request->boolean('force_rebuild_if_mismatch', false)
        );

        $payload = $studentClass->toArray();
        $payload['session_sync'] = $sessionSync;

        $gradeId = (int) ($studentClass->GradeID ?? Student::where('id', $studentClass->StudentID)->value('ClassID') ?? 0);
        $scopeResult = TeacherScopeService::check(
            (int) $studentClass->TeacherID,
            (int) $studentClass->SubjectID,
            $gradeId ?: null
        );
        if (!empty($scopeResult['warnings'])) {
            $payload['scope_warning'] = implode(' ', $scopeResult['warnings']);
        }

        return response()->json($payload);
    }

    /**
     * POST /api/v1/student-classes/sync
     * Accepts an array of Supabase courses and upserts them into MySQL so that
     * LearningRecord deduction and dashboard queries can work with matching IDs.
     */
    public function sync(Request $request)
    {
        return response()->json([
            'message' => 'Legacy schedule sync retired. Use POST /api/v1/class-sessions/batch with explicit dates.',
            'code' => 'legacy_schedule_sync_retired',
        ], 410);

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
            'Physics' => '物理', 'Chemistry' => '化學', 'Science' => '理化', 'Biology' => '生物', 'Social' => '社會',
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
                        $row['RemainingSessions'] = $sessionCount;
                        $row['UsedSessions'] = 0;
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
                            $lrExists = LearningRecord::where('ClassSessionID', $cs->id)
                                ->exists();
                            if (!$lrExists) {
                                LearningRecord::create([
                                    'StudentClassID' => (int) $id,
                                    'ClassSessionID' => $cs->id,
                                    'TeacherID' => (int) $teacherId,
                                    'Subject' => $subjectName,
                                    'Content' => '',
                                    'SessionDate' => $cs->SessionDate,
                                    'StartTime' => $cs->StartTime,
                                    'EndTime' => $cs->EndTime,
                                    'Status' => 'pending',
                                ]);
                            }
                        }
                    }

                    $classForCounters = StudentClass::find((int) $id);
                    if ($classForCounters) {
                        SessionDeductionService::syncCounters($classForCounters);
                    }
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

    /**
     * 新增堂數批次（不併入舊課程）。
     * POST /api/v1/student-classes/{studentClass}/purchase-batch
     */
    public function purchaseBatch(Request $request, StudentClass $studentClass)
    {
        $auth = $this->authorizeStudentClassAccess($studentClass);
        if ($auth !== null) {
            return $auth;
        }

        $data = $request->validate([
            'sessions' => 'required|integer|min:1|max:500',
            'start_date' => 'required|date',
            'mode' => 'nullable|in:new_purchase',
        ]);

        $mode = (string) ($data['mode'] ?? 'new_purchase');
        if ($mode !== 'new_purchase') {
            return response()->json([
                'message' => '僅支援新增購買批次（new_purchase）',
                'errors' => [
                    'mode' => ['目前僅支援 new_purchase，舊有 split 模式已停用。'],
                ],
            ], 422);
        }

        $sessions = (int) $data['sessions'];
        $startDate = Carbon::parse($data['start_date'])->toDateString();

        return DB::transaction(function () use ($studentClass, $sessions, $startDate, $mode) {
            $rate = (float) ($studentClass->Rate ?? 0);
            $rateUnit = (string) ($studentClass->rate_unit ?? 'session');
            $globalDur = (int) ($studentClass->SessionDuration ?? 120);

            $totalHours = 0;
            $charge = 0;
            if ($rateUnit === 'hour') {
                $slots = $this->resolveScheduleSlotsForRebuild($studentClass);
                $durSum = 0;
                $slotCount = max(1, count($slots));
                foreach ($slots as $slot) {
                    $durSum += !empty($slot['duration_minutes']) ? (int) $slot['duration_minutes'] : $globalDur;
                }
                $avgDur = $durSum / $slotCount;
                $totalHours = (int) round(($sessions * $avgDur) / 60);
                $charge = (int) round($rate * $totalHours);
            } else {
                $totalHours = (int) ($studentClass->SessionDuration ? round(($sessions * $globalDur) / 60) : ($studentClass->TotalHours ?? 0));
                $charge = (int) round($rate * $sessions);
            }

            $newPayload = [
                'StudentID' => (int) $studentClass->StudentID,
                'GradeID' => (int) ($studentClass->GradeID ?? 1),
                'SubjectID' => (int) ($studentClass->SubjectID ?? 1),
                'TeacherID' => (int) ($studentClass->TeacherID ?? 0),
                'by1' => (int) ($studentClass->by1 ?? 1),
                'Period' => (int) ($studentClass->Period ?? 4),
                'StartDate' => $startDate,
                'EndDate' => null,
                'week' => $studentClass->week,
                'time' => $studentClass->time,
                'week1' => $studentClass->week1,
                'time1' => $studentClass->time1,
                'week2' => $studentClass->week2,
                'time2' => $studentClass->time2,
                'week3' => $studentClass->week3,
                'time3' => $studentClass->time3,
                'week4' => $studentClass->week4,
                'time4' => $studentClass->time4,
                'week5' => $studentClass->week5,
                'time5' => $studentClass->time5,
                'week6' => $studentClass->week6,
                'time6' => $studentClass->time6,
                'duration1' => $studentClass->duration1,
                'duration2' => $studentClass->duration2,
                'duration3' => $studentClass->duration3,
                'duration4' => $studentClass->duration4,
                'duration5' => $studentClass->duration5,
                'duration6' => $studentClass->duration6,
                'TotalHours' => $totalHours,
                'Memo' => $studentClass->Memo,
                'Charge' => $charge,
                'Pay' => 0,
                'PayDate' => null,
                'Paid' => 0,
                'Disconunt' => $studentClass->Disconunt,
                'Rate' => $rate,
                'rate_unit' => $rateUnit,
                'LearnTimeID' => $studentClass->LearnTimeID,
                'RoomID' => $studentClass->RoomID,
                'room_id' => $studentClass->room_id,
                'settlement_day' => $studentClass->settlement_day,
                'monthly_sessions' => $studentClass->monthly_sessions,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => $sessions,
                'SessionDuration' => $globalDur,
                'RemainingSessions' => $sessions,
                'ClassType' => $studentClass->ClassType ?: 'one_on_one',
                'UsedSessions' => 0,
            ];

            $newCourse = $this->createStudentClassRecordResilient($newPayload);
            return response()->json([
                'message' => '已新增購買批次',
                'mode' => $mode,
                'source_course' => [
                    'id' => (int) $studentClass->ID,
                    'session_count' => (int) ($studentClass->SessionCount ?? 0),
                    'remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
                    'paid' => (int) ($studentClass->Paid ?? 0),
                ],
                'new_course' => [
                    'id' => (int) $newCourse->ID,
                    'session_count' => (int) ($newCourse->SessionCount ?? 0),
                    'remaining_sessions' => (int) ($newCourse->RemainingSessions ?? 0),
                    'paid' => (int) ($newCourse->Paid ?? 0),
                    'start_date' => $this->normalizeDateString($newCourse->StartDate),
                ],
            ], 201);
        });
    }

    /**
     * 課程管理加課／補登（不增加總堂數）。
     * POST /api/v1/student-classes/{studentClass}/add-session
     */
    public function addSession(Request $request, StudentClass $studentClass)
    {
        $auth = $this->authorizeStudentClassAccess($studentClass);
        if ($auth !== null) {
            return $auth;
        }

        $data = $request->validate([
            'session_date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'duration_minutes' => 'nullable|integer|min:30|max:480',
            'end_time' => 'nullable|date_format:H:i',
            'teacher_id' => 'nullable|integer',
            'note' => 'nullable|string|max:255',
            'auto_approve' => 'nullable|boolean',
        ]);

        $sessionDate = Carbon::parse($data['session_date'])->toDateString();
        $startTime = $this->normalizeSessionTime($data['start_time'] ?? null, $studentClass->time ?: '16:00');

        $globalDur = (int) ($studentClass->SessionDuration ?? 120);
        $isoDow = (int) Carbon::parse($sessionDate)->dayOfWeekIso;
        $perDayDur = $this->resolvePerDayDuration($studentClass, $isoDow);
        $durationMinutes = (int) ($data['duration_minutes'] ?? ($perDayDur ?: $globalDur));
        if (!empty($data['end_time'])) {
            $endTime = $this->normalizeSessionTime($data['end_time'], '18:00');
            $durationMinutes = Carbon::createFromFormat('H:i:s', $startTime)
                ->diffInMinutes(Carbon::createFromFormat('H:i:s', $endTime), false);
            if ($durationMinutes <= 0) {
                $durationMinutes += 24 * 60;
            }
            $durationMinutes = max(30, $durationMinutes);
        } else {
            $endTime = Carbon::createFromFormat('H:i:s', $startTime)->addMinutes(max(30, $durationMinutes))->format('H:i:s');
        }

        $now = Carbon::now();
        $isEnded = $this->sessionEndedByEndTime($sessionDate, $endTime, $now);
        $autoApprove = array_key_exists('auto_approve', $data) ? (bool) $data['auto_approve'] : $isEnded;
        $teacherId = (int) ($data['teacher_id'] ?? $studentClass->TeacherID ?? 0);
        $note = trim((string) ($data['note'] ?? ''));

        return DB::transaction(function () use (
            $studentClass,
            $sessionDate,
            $startTime,
            $endTime,
            $isEnded,
            $autoApprove,
            $teacherId,
            $note
        ) {
            $authUser = request()->attributes->get('auth_user');
            $authUserId = is_object($authUser) ? (int) ($authUser->id ?? 0) : 0;
            $hasLearningRecordSessionDeducted = Schema::hasColumn('LearningRecord', 'SessionDeducted');
            $classId = (int) $studentClass->ID;
            $isSessionMode = ((string) ($studentClass->ScheduleMode ?? 'count') === 'count')
                || ((int) ($studentClass->SessionCount ?? 0) > 0);
            $sessionCount = max(0, (int) ($studentClass->SessionCount ?? 0));
            $movedFromDate = null;
            $todayYmd = Carbon::now()->toDateString();
            $nowTime = Carbon::now()->format('H:i:s');

            $approvedSessionIds = LearningRecord::where('StudentClassID', $classId)
                ->where('Status', 'approved')
                ->whereNotNull('ClassSessionID')
                ->pluck('ClassSessionID')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();
            $signInSessionIds = StudentSignIn::where('StudentClassID', $classId)
                ->whereNotNull('ClassSessionID')
                ->pluck('ClassSessionID')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();
            $lockedSessionIdMap = [];
            foreach (array_merge($approvedSessionIds, $signInSessionIds) as $sid) {
                $lockedSessionIdMap[(int) $sid] = true;
            }

            $existing = ClassSession::where('StudentClassID', $classId)
                ->whereDate('SessionDate', $sessionDate)
                ->where('StartTime', $startTime)
                ->first();

            if ($existing) {
                if (isset($lockedSessionIdMap[(int) $existing->id])) {
                    return response()->json([
                        'message' => '該堂已有出缺勤或核准評量，無法重覆補登',
                    ], 409);
                }
                $classSession = $existing;
                $classSession->EndTime = $endTime;
                $classSession->Status = $isEnded ? 'completed' : 'scheduled';
                if ($note !== '') {
                    $classSession->Note = $note;
                }
                $classSession->save();
            } else {
                $movableQuery = ClassSession::where('StudentClassID', $classId)
                    ->where('Status', 'scheduled')
                    ->where(function ($q) use ($todayYmd, $nowTime) {
                        $q->whereDate('SessionDate', '>', $todayYmd)
                            ->orWhere(function ($q2) use ($todayYmd, $nowTime) {
                                $q2->whereDate('SessionDate', $todayYmd)
                                    ->where('EndTime', '>', $nowTime);
                            });
                    });
                if (!empty($lockedSessionIdMap)) {
                    $movableQuery->whereNotIn('id', array_keys($lockedSessionIdMap));
                }
                $movableSession = $movableQuery
                    ->orderBy('SessionDate', 'desc')
                    ->orderBy('StartTime', 'desc')
                    ->first();

                if ($isSessionMode && $movableSession) {
                    $movedFromDate = $this->normalizeDateString($movableSession->SessionDate);
                    $classSession = $movableSession;
                    $classSession->SessionDate = $sessionDate;
                    $classSession->StartTime = $startTime;
                    $classSession->EndTime = $endTime;
                    $classSession->Status = $isEnded ? 'completed' : 'scheduled';
                    if ($note !== '') {
                        $classSession->Note = $note;
                    } else {
                        $suffix = $movedFromDate ? ("系統調整堂次（原 {$movedFromDate}）") : '系統調整堂次';
                        $baseNote = trim((string) ($classSession->Note ?? ''));
                        $classSession->Note = $baseNote === '' ? $suffix : ($baseNote . '; ' . $suffix);
                    }
                    $classSession->save();
                } else {
                    $activeSessionCount = (int) ClassSession::where('StudentClassID', $classId)
                        ->where('Status', '!=', 'cancelled')
                        ->count();
                    if ($isSessionMode && $sessionCount > 0 && $activeSessionCount >= $sessionCount) {
                        return response()->json([
                            'message' => '此課程堂次已排滿，若不增加總堂數請先調課或請假。',
                        ], 409);
                    }

                    $classSession = ClassSession::create([
                        'StudentClassID' => $classId,
                        'SessionDate' => $sessionDate,
                        'StartTime' => $startTime,
                        'EndTime' => $endTime,
                        'Status' => $isEnded ? 'completed' : 'scheduled',
                        'Note' => $note !== '' ? $note : ($isEnded ? '系統補登加課' : '系統加課'),
                    ]);
                }
            }

            $record = LearningRecord::where('ClassSessionID', (int) $classSession->id)->first();
            $approved = false;
            $deducted = false;

            if ($autoApprove && $isEnded) {
                $record = $record ?: LearningRecord::create([
                    'StudentClassID' => (int) $studentClass->ID,
                    'ClassSessionID' => (int) $classSession->id,
                    'TeacherID' => $teacherId,
                    'CreatedByUserID' => $authUserId > 0 ? $authUserId : null,
                    'Content' => '（系統補登加課）',
                    'Subject' => DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
                        ?? DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
                        ?? '評量',
                    'SessionDate' => $sessionDate,
                    'StartTime' => $startTime,
                    'EndTime' => $endTime,
                    'Status' => 'approved',
                    'ApprovedBy' => $authUserId > 0 ? $authUserId : null,
                    'ApprovedAt' => now(),
                ]);

                if ($record->Status !== 'approved') {
                    $record->Status = 'approved';
                    if ($authUserId > 0) {
                        $record->ApprovedBy = $authUserId;
                    }
                    $record->ApprovedAt = now();
                }
                $record->TeacherID = $teacherId ?: $record->TeacherID;
                $record->SessionDate = $sessionDate;
                $record->StartTime = $startTime;
                $record->EndTime = $endTime;
                $record->save();
                $approved = true;

                $alreadyDeducted = $hasLearningRecordSessionDeducted && (bool) ($record->SessionDeducted ?? false);
                if (!$alreadyDeducted && SessionDeductionService::deductOnAttendance($studentClass, null, (int) $classSession->id)) {
                    if ($hasLearningRecordSessionDeducted) {
                        $record->SessionDeducted = true;
                        $record->save();
                    }
                    $deducted = true;
                }
            } elseif ($isEnded) {
                if (!$record) {
                    LearningRecord::create([
                        'StudentClassID' => (int) $studentClass->ID,
                        'ClassSessionID' => (int) $classSession->id,
                        'TeacherID' => $teacherId,
                        'Content' => '',
                        'Subject' => DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
                            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
                            ?? '評量',
                        'SessionDate' => $sessionDate,
                        'StartTime' => $startTime,
                        'EndTime' => $endTime,
                        'Status' => 'pending',
                    ]);
                } else {
                    $record->TeacherID = $teacherId ?: $record->TeacherID;
                    $record->SessionDate = $sessionDate;
                    $record->StartTime = $startTime;
                    $record->EndTime = $endTime;
                    $record->save();
                }
            } elseif ($record && (string) ($record->Status ?? '') !== 'approved') {
                $record->TeacherID = $teacherId ?: $record->TeacherID;
                $record->SessionDate = $sessionDate;
                $record->StartTime = $startTime;
                $record->EndTime = $endTime;
                $record->save();
            }

            return response()->json([
                'message' => $approved
                    ? '已補登加課並扣堂'
                    : ($isEnded ? '已補登堂次，待老師填寫評量' : '已調整加課堂次'),
                'student_class_id' => (int) $studentClass->ID,
                'class_session_id' => (int) $classSession->id,
                'session_date' => $sessionDate,
                'start_time' => substr($startTime, 0, 5),
                'end_time' => substr($endTime, 0, 5),
                'auto_approved' => $approved,
                'deducted' => $deducted,
                'moved_from_date' => $movedFromDate,
                'no_total_increase' => $movedFromDate !== null || !$isSessionMode,
            ], 201);
        });
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

    private function authorizeStudentClassAccess(StudentClass $studentClass)
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

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createStudentClassRecordResilient(array $payload): StudentClass
    {
        $attempts = 0;
        while ($attempts < 8) {
            try {
                return StudentClass::create($payload);
            } catch (\Illuminate\Database\QueryException $e) {
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

        $frontendSubject = $input['subject'] ?? 'Math';
        $subjectId = FrontendSubjectIdResolver::resolve($frontendSubject);
        if (!$subjectId) {
            $subjectId = 66;
        }

        $mappedData = [];
        if (isset($input['student_id'])) $mappedData['StudentID'] = $input['student_id'];
        if (isset($input['teacher_id'])) $mappedData['TeacherID'] = $input['teacher_id'];
        if (isset($input['subject'])) $mappedData['SubjectID'] = $subjectId;
        if (isset($input['class_type'])) $mappedData['ClassType'] = $input['class_type'];
        if (isset($input['payment_type'])) $mappedData['ScheduleMode'] = ($input['payment_type'] === 'session') ? 'count' : 'date';
        if (isset($input['rate_per_30min'])) $mappedData['Rate'] = $input['rate_per_30min'];
        if (isset($input['rate_unit'])) $mappedData['rate_unit'] = $input['rate_unit'];
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

        // Handle days + time slots for both create and update.
        $dayTimeSlots = $this->normalizeDayTimeSlotsInput($input['day_time_slots'] ?? []);
        if (empty($dayTimeSlots)) {
            $startTime = $input['start_time'] ?? null;
            $daysOfWeek = $input['days_of_week'] ?? null;
            if ($startTime && is_array($daysOfWeek) && !empty($daysOfWeek)) {
                $dayTimeSlots = array_map(fn ($d) => [
                    'day' => (int) $d,
                    'start_time' => substr((string) $startTime, 0, 5),
                ], $daysOfWeek);
            } elseif (isset($input['day_of_week']) && $startTime) {
                $dayTimeSlots = [[
                    'day' => (int) $input['day_of_week'],
                    'start_time' => substr((string) $startTime, 0, 5),
                ]];
            }
            $dayTimeSlots = $this->normalizeDayTimeSlotsInput($dayTimeSlots);
        }

        if (!empty($dayTimeSlots)) {
            for ($i = 1; $i <= 6; $i++) {
                $mappedData["week{$i}"] = null;
                $mappedData["time{$i}"] = null;
                $mappedData["duration{$i}"] = null;
            }
            $primary = $dayTimeSlots[0];
            $mappedData['week'] = (int) $primary['day'];
            $mappedData['time'] = substr((string) $primary['start_time'], 0, 5) . ':00';
            if (!empty($primary['duration_minutes']) && (int) $primary['duration_minutes'] >= 30) {
                $mappedData['SessionDuration'] = (int) $primary['duration_minutes'];
            }
            $rest = array_slice($dayTimeSlots, 1);
            foreach ($rest as $j => $slot) {
                if ($j >= 6) {
                    break;
                }
                $n = $j + 1;
                $mappedData['week' . $n] = (int) $slot['day'];
                $mappedData['time' . $n] = substr((string) $slot['start_time'], 0, 5) . ':00';
                if (!empty($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30) {
                    $mappedData['duration' . $n] = (int) $slot['duration_minutes'];
                }
            }
            $mappedData['ScheduleSlots'] = array_map(fn ($slot) => [
                'weekday' => (int) $slot['day'],
                'time' => substr((string) $slot['start_time'], 0, 5),
                'duration_minutes' => !empty($slot['duration_minutes']) ? (int) $slot['duration_minutes'] : null,
            ], $dayTimeSlots);
        }

        return $mappedData;
    }

    /**
     * @param  mixed  $rawSlots
     * @return array<int, array{day:int,start_time:string,duration_minutes?:int|null}>
     */
    private function normalizeDayTimeSlotsInput($rawSlots): array
    {
        if (!is_array($rawSlots)) {
            return [];
        }
        $out = [];
        foreach ($rawSlots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            if (count($out) >= 7) {
                break;
            }
            $day = (int) ($slot['day'] ?? 0);
            $startTime = trim((string) ($slot['start_time'] ?? ''));
            if ($day < 1 || $day > 7 || $startTime === '') {
                continue;
            }
            $durMin = isset($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30
                ? (int) $slot['duration_minutes']
                : null;
            $out[] = [
                'day' => $day,
                'start_time' => substr($startTime, 0, 5),
                'duration_minutes' => $durMin,
            ];
        }

        return $out;
    }

    /**
     * Legacy mapFrontendPayload wrote slot 0 into both week/time and week1/time1; drop exact duplicate neighbors.
     *
     * @param  array<int, array{day:int,start_time:string,duration_hours:float|int}>  $slots
     * @return array<int, array{day:int,start_time:string,duration_hours:float|int}>
     */
    private function dedupeIdenticalConsecutiveDayTimeSlots(array $slots): array
    {
        if (count($slots) < 2) {
            return $slots;
        }
        $out = [$slots[0]];
        for ($i = 1; $i < count($slots); $i++) {
            $prev = $out[count($out) - 1];
            $cur = $slots[$i];
            if ((int) ($prev['day'] ?? 0) === (int) ($cur['day'] ?? 0)
                && (string) ($prev['start_time'] ?? '') === (string) ($cur['start_time'] ?? '')
                && (string) ($prev['duration_hours'] ?? '') === (string) ($cur['duration_hours'] ?? '')
            ) {
                continue;
            }
            $out[] = $cur;
        }

        return $out;
    }

    /**
     * Rebuild upcoming class sessions when first class date is edited and no immutable history exists.
     *
     * @param  array<string, mixed>  $mapped
     * @param  array<int, array<string, mixed>>  $scheduleSlots
     * @return array<string, mixed>
     */
    private function maybeRebuildSessionsAfterUpdate(
        StudentClass $studentClass,
        ?string $previousStartDate,
        array $mapped,
        array $scheduleSlots = [],
        bool $forceRebuildIfMismatch = false
    ): array {
        $scheduleFields = [
            'week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6',
            'time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6',
            'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6',
            'SessionDuration',
        ];
        $scheduleUpdated = false;
        foreach ($scheduleFields as $field) {
            if (array_key_exists($field, $mapped)) {
                $scheduleUpdated = true;
                break;
            }
        }

        if (!array_key_exists('StartDate', $mapped)) {
            if (!$scheduleUpdated) {
                return ['rebuilt' => false, 'reason' => 'start_date_not_updated'];
            }

            $slots = $this->resolveScheduleSlotsForRebuild($studentClass, $scheduleSlots);
            if (empty($slots)) {
                return ['rebuilt' => false, 'reason' => 'schedule_slots_missing'];
            }

            $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
            $classId = (int) $studentClass->ID;

            // If immutable history exists, do a safe partial sync (times only).
            if ($this->hasImmutableSessionHistory($classId)) {
                $updatedCount = $this->syncFutureScheduledSessionTimes(
                    $classId,
                    $slots,
                    $durationMinutes
                );

                return [
                    'rebuilt' => false,
                    'reason' => 'history_exists',
                    'updated_future_sessions' => $updatedCount,
                ];
            }

            $startDate = $this->normalizeDateString($studentClass->StartDate ?? null) ?: Carbon::today()->toDateString();
            $scheduleMode = (string) ($studentClass->ScheduleMode ?? 'count');
            $sessionCount = max(0, (int) ($studentClass->SessionCount ?? 0));
            $sessions = [];

            if ($scheduleMode === 'date') {
                $endDate = $this->normalizeDateString($studentClass->EndDate ?? null);
                if (!$endDate) {
                    return ['rebuilt' => false, 'reason' => 'end_date_missing'];
                }
                if ($endDate < $startDate) {
                    $endDate = $startDate;
                    $studentClass->EndDate = $endDate;
                    $studentClass->save();
                }
                $sessions = $this->buildSessionsFromWeeklySchedule(
                    $classId,
                    $startDate,
                    $endDate,
                    $slots,
                    $durationMinutes
                );
                if ($sessionCount > 0 && count($sessions) > $sessionCount) {
                    $sessions = array_slice($sessions, 0, $sessionCount);
                }
            } else {
                if ($sessionCount <= 0) {
                    return ['rebuilt' => false, 'reason' => 'session_count_missing'];
                }
                $sessions = $this->buildSessionsForCount(
                    $classId,
                    $startDate,
                    $sessionCount,
                    $slots,
                    $durationMinutes
                );
            }

            $sessionIds = ClassSession::where('StudentClassID', $classId)->pluck('id')->all();
            if (!empty($sessionIds)) {
                LearningRecord::whereIn('ClassSessionID', $sessionIds)->delete();
            }
            LearningRecord::where('StudentClassID', $classId)
                ->whereNull('ClassSessionID')
                ->delete();
            ClassSession::where('StudentClassID', $classId)->delete();

            $createdSessions = 0;
            $createdPendingRecords = 0;
            $now = Carbon::now();
            $teacherId = (int) ($studentClass->TeacherID ?? 0);
            $subjectName = DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
                ?? DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
                ?? '評量';
            foreach ($sessions as $session) {
                $sessionDate = $this->normalizeDateString($session['SessionDate'] ?? null);
                $endTime = $this->normalizeSessionTime($session['EndTime'] ?? null, '18:00:00');
                if (!$sessionDate) {
                    continue;
                }
                $isEnded = $this->sessionEndedByEndTime($sessionDate, $endTime, $now);
                $session['Status'] = $isEnded ? 'completed' : 'scheduled';
                if ($isEnded && empty($session['Note'])) {
                    $session['Note'] = '系統重建堂次（固定星期調整）';
                }

                $classSession = ClassSession::create($session);
                $createdSessions++;

                if ($isEnded) {
                    LearningRecord::create([
                        'StudentClassID' => $classId,
                        'ClassSessionID' => (int) $classSession->id,
                        'TeacherID' => $teacherId,
                        'Content' => '',
                        'Subject' => $subjectName,
                        'SessionDate' => $classSession->SessionDate,
                        'StartTime' => $classSession->StartTime,
                        'EndTime' => $classSession->EndTime,
                        'Status' => 'pending',
                    ]);
                    $createdPendingRecords++;
                }
            }

            if ($sessionCount > 0) {
                SessionDeductionService::syncCounters($studentClass);
            }

            return [
                'rebuilt' => true,
                'reason' => 'schedule_changed',
                'created_sessions' => $createdSessions,
                'created_pending_records' => $createdPendingRecords,
            ];
        }

        $newStartDate = $this->normalizeDateString($studentClass->StartDate ?? null);
        if (!$newStartDate) {
            return ['rebuilt' => false, 'reason' => 'start_date_unchanged'];
        }
        $startDateChanged = $newStartDate !== $previousStartDate;
        if (!$startDateChanged) {
            if (
                !$forceRebuildIfMismatch
                || !$this->hasSessionStartDateMismatch((int) $studentClass->ID, $newStartDate)
            ) {
                return ['rebuilt' => false, 'reason' => 'start_date_unchanged'];
            }
        }

        if ($this->hasImmutableSessionHistory((int) $studentClass->ID)) {
            return ['rebuilt' => false, 'reason' => 'history_exists'];
        }

        $slots = $this->resolveScheduleSlotsForRebuild($studentClass, $scheduleSlots);
        if (empty($slots)) {
            return ['rebuilt' => false, 'reason' => 'schedule_slots_missing'];
        }

        $sessionCount = max(0, (int) ($studentClass->SessionCount ?? 0));
        $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
        $scheduleMode = (string) ($studentClass->ScheduleMode ?? 'count');

        if ($scheduleMode === 'count' && $sessionCount <= 0) {
            return ['rebuilt' => false, 'reason' => 'session_count_missing'];
        }

        $sessions = [];
        if ($scheduleMode === 'date') {
            $endDate = $this->normalizeDateString($studentClass->EndDate ?? null);
            if (!$endDate) {
                return ['rebuilt' => false, 'reason' => 'end_date_missing'];
            }
            if ($endDate < $newStartDate) {
                $endDate = $newStartDate;
                $studentClass->EndDate = $endDate;
                $studentClass->save();
            }
            $sessions = $this->buildSessionsFromWeeklySchedule(
                (int) $studentClass->ID,
                $newStartDate,
                $endDate,
                $slots,
                $durationMinutes
            );
            if ($sessionCount > 0 && count($sessions) > $sessionCount) {
                $sessions = array_slice($sessions, 0, $sessionCount);
            }
        } else {
            $sessions = $this->buildSessionsForCount(
                (int) $studentClass->ID,
                $newStartDate,
                $sessionCount,
                $slots,
                $durationMinutes
            );
        }

        $sessionIds = ClassSession::where('StudentClassID', (int) $studentClass->ID)->pluck('id')->all();
        if (!empty($sessionIds)) {
            LearningRecord::whereIn('ClassSessionID', $sessionIds)->delete();
        }
        LearningRecord::where('StudentClassID', (int) $studentClass->ID)
            ->whereNull('ClassSessionID')
            ->delete();
        ClassSession::where('StudentClassID', (int) $studentClass->ID)->delete();

        $createdSessions = 0;
        $createdPendingRecords = 0;
        $now = Carbon::now();
        $teacherId = (int) ($studentClass->TeacherID ?? 0);
        $subjectName = DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
            ?? '評量';

        foreach ($sessions as $session) {
            $sessionDate = $this->normalizeDateString($session['SessionDate'] ?? null);
            $endTime = $this->normalizeSessionTime($session['EndTime'] ?? null, '18:00:00');
            if (!$sessionDate) {
                continue;
            }

            $isEnded = $this->sessionEndedByEndTime($sessionDate, $endTime, $now);
            $session['Status'] = $isEnded ? 'completed' : 'scheduled';
            if ($isEnded && empty($session['Note'])) {
                $session['Note'] = '系統重建堂次（開課日調整）';
            }

            $classSession = ClassSession::create($session);
            $createdSessions++;

            if ($isEnded) {
                LearningRecord::create([
                    'StudentClassID' => (int) $studentClass->ID,
                    'ClassSessionID' => (int) $classSession->id,
                    'TeacherID' => $teacherId,
                    'Content' => '',
                    'Subject' => $subjectName,
                    'SessionDate' => $classSession->SessionDate,
                    'StartTime' => $classSession->StartTime,
                    'EndTime' => $classSession->EndTime,
                    'Status' => 'pending',
                ]);
                $createdPendingRecords++;
            }
        }

        if ($sessionCount > 0) {
            SessionDeductionService::syncCounters($studentClass);
        }

        return [
            'rebuilt' => true,
            'reason' => $startDateChanged ? 'start_date_changed' : 'start_date_aligned',
            'new_start_date' => $newStartDate,
            'created_sessions' => $createdSessions,
            'created_pending_records' => $createdPendingRecords,
        ];
    }

    /**
     * Sync only future scheduled sessions' times by weekday mapping.
     * Keeps historical/locked sessions untouched.
     *
     * @param  array<int, array{weekday:int,time:string}>  $slots
     */
    private function syncFutureScheduledSessionTimes(int $studentClassId, array $slots, int $durationMinutes): int
    {
        if ($studentClassId <= 0 || empty($slots)) {
            return 0;
        }

        $timeByIsoWeekday = [];
        $durByIsoWeekday = [];
        foreach ($slots as $slot) {
            $weekday = (int) ($slot['weekday'] ?? 0);
            $time = (string) ($slot['time'] ?? '');
            if ($weekday < 1 || $weekday > 7 || $time === '') {
                continue;
            }
            $timeByIsoWeekday[$weekday] = substr($time, 0, 5);
            if (!empty($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30) {
                $durByIsoWeekday[$weekday] = (int) $slot['duration_minutes'];
            }
        }
        if (empty($timeByIsoWeekday)) {
            return 0;
        }

        $lockedBySessionId = LearningRecord::where('StudentClassID', $studentClassId)
            ->where('Status', 'approved')
            ->whereNotNull('ClassSessionID')
            ->pluck('ClassSessionID')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->flip()
            ->all();
        $signInLocked = StudentSignIn::where('StudentClassID', $studentClassId)
            ->whereNotNull('ClassSessionID')
            ->pluck('ClassSessionID')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->all();
        foreach ($signInLocked as $sid) {
            $lockedBySessionId[(int) $sid] = true;
        }

        $today = Carbon::today()->toDateString();
        $sessions = ClassSession::where('StudentClassID', $studentClassId)
            ->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', $today)
            ->get();

        $updated = 0;
        foreach ($sessions as $session) {
            if (isset($lockedBySessionId[(int) $session->id])) {
                continue;
            }
            $date = $this->normalizeDateString($session->SessionDate ?? null);
            if (!$date) {
                continue;
            }
            $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
            $newStart = $timeByIsoWeekday[$isoDow] ?? null;
            if (!$newStart) {
                continue;
            }

            $newStartFull = $this->normalizeSessionTime($newStart, '16:00:00');
            $slotDur = $durByIsoWeekday[$isoDow] ?? $durationMinutes;
            $newEndFull = Carbon::createFromFormat('H:i:s', $newStartFull)
                ->addMinutes(max(30, $slotDur))
                ->format('H:i:s');

            if (
                (string) $session->StartTime !== $newStartFull
                || (string) $session->EndTime !== $newEndFull
            ) {
                $session->StartTime = $newStartFull;
                $session->EndTime = $newEndFull;
                $session->save();
                $updated++;
            }
        }

        return $updated;
    }

    private function hasSessionStartDateMismatch(int $studentClassId, string $startDate): bool
    {
        if ($studentClassId <= 0 || $startDate === '') {
            return false;
        }
        $firstActive = ClassSession::where('StudentClassID', $studentClassId)
            ->where('Status', '!=', 'cancelled')
            ->orderBy('SessionDate', 'asc')
            ->orderBy('StartTime', 'asc')
            ->first();
        if (!$firstActive) {
            return false;
        }
        $firstDate = $this->normalizeDateString($firstActive->SessionDate ?? null);
        return $firstDate !== null && $firstDate !== $startDate;
    }

    private function hasImmutableSessionHistory(int $studentClassId): bool
    {
        if ($studentClassId <= 0) {
            return false;
        }

        if (StudentSignIn::where('StudentClassID', $studentClassId)->exists()) {
            return true;
        }

        if (LearningRecord::where('StudentClassID', $studentClassId)->where('Status', 'approved')->exists()) {
            return true;
        }
        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $providedSlots
     * @return array<int, array{weekday:int,time:string}>
     */
    private function resolveScheduleSlotsForRebuild(StudentClass $studentClass, array $providedSlots = []): array
    {
        $slots = [];

        if (!empty($providedSlots)) {
            foreach ($providedSlots as $slot) {
                $weekday = (int) ($slot['weekday'] ?? 0);
                if ($weekday < 1 || $weekday > 7) {
                    continue;
                }
                $time = $this->normalizeSessionTime($slot['time'] ?? null, '16:00');
                $entry = [
                    'weekday' => $weekday,
                    'time' => substr($time, 0, 5),
                ];
                if (!empty($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30) {
                    $entry['duration_minutes'] = (int) $slot['duration_minutes'];
                }
                $slots[] = $entry;
            }
        }

        if (empty($slots)) {
            $globalDur = (int) ($studentClass->SessionDuration ?? 0);
            $candidates = [
                ['week', 'time', null],
                ['week1', 'time1', 'duration1'],
                ['week2', 'time2', 'duration2'],
                ['week3', 'time3', 'duration3'],
                ['week4', 'time4', 'duration4'],
                ['week5', 'time5', 'duration5'],
                ['week6', 'time6', 'duration6'],
            ];
            foreach ($candidates as [$weekField, $timeField, $durField]) {
                $weekday = (int) ($studentClass->{$weekField} ?? 0);
                if ($weekday < 1 || $weekday > 7) {
                    continue;
                }
                $time = $this->normalizeSessionTime($studentClass->{$timeField} ?? null, $studentClass->time ?? '16:00');
                $entry = [
                    'weekday' => $weekday,
                    'time' => substr($time, 0, 5),
                ];
                $perDayDur = $durField !== null ? (int) ($studentClass->{$durField} ?? 0) : 0;
                if ($perDayDur >= 30) {
                    $entry['duration_minutes'] = $perDayDur;
                } elseif ($globalDur >= 30) {
                    $entry['duration_minutes'] = $globalDur;
                }
                $slots[] = $entry;
            }
            $slots = $this->dedupeIdenticalConsecutiveScheduleSlots($slots);
        }

        usort($slots, function ($a, $b) {
            $c = ($a['weekday'] <=> $b['weekday']);

            return $c !== 0 ? $c : strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? ''));
        });

        return $slots;
    }

    /**
     * @param  array<int, array{weekday:int,time:string,duration_minutes?:int}>  $slots
     * @return array<int, array{weekday:int,time:string,duration_minutes?:int}>
     */
    private function dedupeIdenticalConsecutiveScheduleSlots(array $slots): array
    {
        if (count($slots) < 2) {
            return $slots;
        }
        $out = [$slots[0]];
        for ($i = 1; $i < count($slots); $i++) {
            $prev = $out[count($out) - 1];
            $cur = $slots[$i];
            if ((int) ($prev['weekday'] ?? 0) === (int) ($cur['weekday'] ?? 0)
                && (string) ($prev['time'] ?? '') === (string) ($cur['time'] ?? '')
                && (int) ($prev['duration_minutes'] ?? 0) === (int) ($cur['duration_minutes'] ?? 0)
            ) {
                continue;
            }
            $out[] = $cur;
        }

        return $out;
    }

    private function normalizeDateString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Look up the per-day duration stored in duration1-6 by matching the ISO weekday.
     * Returns the duration in minutes, or 0 if not set.
     */
    private function resolvePerDayDuration(StudentClass $sc, int $isoWeekday): int
    {
        $candidates = [
            ['week1', 'duration1'], ['week2', 'duration2'], ['week3', 'duration3'],
            ['week4', 'duration4'], ['week5', 'duration5'], ['week6', 'duration6'],
        ];
        foreach ($candidates as [$wf, $df]) {
            if ((int) ($sc->{$wf} ?? 0) === $isoWeekday) {
                $dur = (int) ($sc->{$df} ?? 0);
                return $dur >= 30 ? $dur : 0;
            }
        }
        return 0;
    }

    private function normalizeSessionTime($value, string $fallback = '16:00:00'): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            $raw = $fallback;
        }
        try {
            if (preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
                return Carbon::createFromFormat('H:i', $raw)->format('H:i:s');
            }
            if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $raw)) {
                return Carbon::createFromFormat('H:i:s', $raw)->format('H:i:s');
            }
            return Carbon::parse($raw)->format('H:i:s');
        } catch (\Throwable $e) {
            try {
                return Carbon::parse($fallback)->format('H:i:s');
            } catch (\Throwable $ignore) {
                return '16:00:00';
            }
        }
    }

    private function sessionEndedByEndTime(string $sessionDate, string $endTime, ?Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now();
        $sessionEndAt = Carbon::parse($sessionDate . ' ' . $endTime);
        return $sessionEndAt->lte($now);
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
     * Detect scheduling conflicts for a teacher against proposed sessions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function detectTeacherConflicts(
        int $teacherId,
        array $proposedSessions,
        string $newClassType = 'one_on_one',
        ?int $roomId = null,
        int $branchId = 0
    ): array
    {
        if ($teacherId <= 0 || $branchId <= 0 || empty($proposedSessions)) {
            return [];
        }

        $conflicts = [];
        $seen = [];
        foreach ($proposedSessions as $proposed) {
            $date = isset($proposed['SessionDate']) ? Carbon::parse($proposed['SessionDate'])->toDateString() : null;
            $start = isset($proposed['StartTime']) ? substr((string) $proposed['StartTime'], 0, 5) : null;
            $end = isset($proposed['EndTime']) ? substr((string) $proposed['EndTime'], 0, 5) : null;
            if (!$date || !$start || !$end) {
                continue;
            }

            $slotConflicts = $this->scheduleGuardService->validateScheduleOccurrence([
                'teacher_id' => $teacherId,
                'class_type' => $newClassType,
                'room_id' => $roomId,
                'branch_id' => $branchId,
                'schedule_date' => $date,
                'start_time' => $start,
                'end_time' => $end,
            ]);
            if (empty($slotConflicts)) {
                continue;
            }

            foreach ($slotConflicts as $conflict) {
                $key = implode('|', [
                    (string) ($conflict['type'] ?? ''),
                    $date,
                    $start,
                    $end,
                    (string) ($conflict['room_id'] ?? ''),
                ]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $conflicts[] = array_merge([
                    'date' => $date,
                    'proposed_time' => $start . '-' . $end,
                ], $conflict);
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
                    $slotDur = !empty($slot['duration_minutes']) ? (int) $slot['duration_minutes'] : $durationMinutes;
                    $endTime = $startTime->copy()->addMinutes($slotDur);

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
        $durByWeekday = [];
        foreach ($slots as $s) {
            $timeByWeekday[(int) $s['weekday']] = $s['time'] ?? $firstTime;
            if (!empty($s['duration_minutes']) && (int) $s['duration_minutes'] >= 30) {
                $durByWeekday[(int) $s['weekday']] = (int) $s['duration_minutes'];
            }
        }

        $firstDate = Carbon::parse($startDate)->startOfDay();
        $firstIsoDow = (int) $firstDate->dayOfWeekIso;
        $firstDur = $durByWeekday[$firstIsoDow] ?? $durationMinutes;
        $startTime = Carbon::parse($firstDate->toDateString() . ' ' . $firstTime);
        $endTime = $startTime->copy()->addMinutes($firstDur);
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

        $date = Carbon::parse($startDate)->addDay()->startOfDay();
        $needed = $sessionCount - 1;
        while (count($sessions) < $sessionCount && $needed > 0) {
            $dow = (int) $date->dayOfWeek;
            $isoDow = $dow === 0 ? 7 : $dow;
            if (in_array($isoDow, $slotWeekdays, true)) {
                $time = $timeByWeekday[$isoDow] ?? $firstTime;
                $slotDur = $durByWeekday[$isoDow] ?? $durationMinutes;
                $start = Carbon::parse($date->toDateString() . ' ' . $time);
                $end = $start->copy()->addMinutes($slotDur);
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

    /**
     * Toggle pause/resume for a student course.
     * Pause: sets Stop=1 and cancels future scheduled sessions.
     * Resume: sets Stop=0.
     */
    public function togglePause(Request $request, StudentClass $studentClass)
    {
        $sc = $studentClass;

        $action = $request->input('action', 'pause');
        $today = Carbon::today()->toDateString();

        DB::beginTransaction();
        try {
            if ($action === 'pause') {
                $sc->Stop = 1;
                $sc->save();

                $cancelled = ClassSession::where('StudentClassID', $sc->ID)
                    ->where('SessionDate', '>=', $today)
                    ->where('Status', 'scheduled')
                    ->update([
                        'Status' => 'cancelled',
                        'Note' => DB::raw("CONCAT(COALESCE(Note,''), ' [暫停取消]')"),
                        'updated_at' => now(),
                    ]);

                DB::commit();
                return response()->json([
                    'message' => "課程已暫停，已取消 {$cancelled} 堂未來排課。",
                    'cancelled_count' => $cancelled,
                ]);
            } else {
                $sc->Stop = 0;
                $sc->save();

                DB::commit();
                return response()->json([
                    'message' => '課程已恢復，可重新排課。',
                ]);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => '操作失敗：' . $e->getMessage()], 500);
        }
    }
}
