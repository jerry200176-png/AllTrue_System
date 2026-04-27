<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\UserCampus;
use App\Models\CoursePackage;
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
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if ($teacherId <= 0) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
            $this->applyTeacherOrSubstituteStudentClassScope($query, $teacherId);
            // Teachers see their courses + 單堂代課（schedules）之契約，不限分校 — auth_teacher_id 已範圍化
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
                $branchId = $request->input('branch_id');
                $query->where(function ($q) use ($branchId) {
                    // 有教室：依教室所屬校區判斷
                    $q->whereHas('room', function ($sub) use ($branchId) {
                        $sub->where('campus_id', $branchId);
                    })
                    // 無教室（room_id 為 null）：退而依學生 CampusID 判斷
                    ->orWhere(function ($q2) use ($branchId) {
                        $q2->whereNull('room_id')
                           ->whereHas('student', function ($sub) use ($branchId) {
                               $sub->where('CampusID', $branchId);
                           });
                    });
                });
            }
        }

        if ($request->filled('student_id')) {
            $query->where('StudentID', $request->input('student_id'));
        }

        // 老師端常帶 teacher_id=自己（智慧排課）；範圍已由 applyTeacherOrSubstituteStudentClassScope 處理。
        // 若再 where TeacherID=自己，會把「僅單堂代課」之 StudentClass（正班為他人）整批排除。
        if ($request->filled('teacher_id') && $role !== 'teacher') {
            $query->where('TeacherID', $request->input('teacher_id'));
        }

        if ($request->filled('teacher_name')) {
            $teacherTerm = trim((string) $request->input('teacher_name'));
            if ($teacherTerm !== '') {
                $pattern = '%' . $teacherTerm . '%';
                // Resolve matching teacher IDs at PHP level:
                // 1. Teacher.T_Name (Chinese name stored in Teacher table)
                // 2. User.Name (display name / English name stored in User table)
                // TeacherID = User.id = Teacher.id in this system.
                // Avoid JOIN and LoginName to prevent false positives from admin/director emails.
                $byTName = DB::table('Teacher')
                    ->where('T_Name', 'like', $pattern)
                    ->pluck('id');
                $byUserName = DB::table('User')
                    ->whereIn('type', ['T', 'U'])
                    ->where('Name', 'like', $pattern)
                    ->pluck('id');
                $matchedIds = $byTName->merge($byUserName)->unique()->values()->all();
                if (empty($matchedIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('TeacherID', $matchedIds);
                }
            }
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
        $paidAtMap = AlertController::lastPaidAtByStudentClassIds($classIds);

        $packageIds = $classes->getCollection()
            ->pluck('PackageID')->filter(fn ($id) => $id > 0)->unique()->values()->all();
        $packageMap = !empty($packageIds)
            ? CoursePackage::whereIn('id', $packageIds)->get()->keyBy('id')
            : collect();

        // Upcoming scheduled sessions only (for schedule_drift vs contract). Do not
        // merge completed/attended history — one-off substitute weekdays would false-positive.
        // Sessions with IsContractException=1 are legitimate add-on / makeup sessions and are
        // excluded from drift detection (tracked separately as contract_exception_count).
        $sessionSlotsByClassId = [];
        $contractExceptionCountByClassId = [];
        if (!empty($classIds)) {
            $today = Carbon::today()->toDateString();
            $hasExceptionCol = Schema::hasColumn('ClassSession', 'IsContractException');
            $selectCols = ['StudentClassID', 'SessionDate', 'StartTime', 'EndTime'];
            if ($hasExceptionCol) {
                $selectCols[] = 'IsContractException';
            }
            $futureSessions = ClassSession::whereIn('StudentClassID', $classIds)
                ->where('Status', 'scheduled')
                ->whereDate('SessionDate', '>=', $today)
                ->select($selectCols)
                ->get();

            foreach ($futureSessions as $cs) {
                $cid = (int) $cs->StudentClassID;

                if ($hasExceptionCol && !empty($cs->IsContractException)) {
                    $contractExceptionCountByClassId[$cid] = ($contractExceptionCountByClassId[$cid] ?? 0) + 1;
                    continue;
                }

                $date = $cs->SessionDate ? Carbon::parse($cs->SessionDate)->toDateString() : null;
                if (!$date) {
                    continue;
                }
                $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
                $start = substr((string) ($cs->StartTime ?? ''), 0, 5);
                if ($start === '') {
                    continue;
                }
                $endRaw = (string) ($cs->EndTime ?? '');
                $durMin = 0;
                if ($endRaw) {
                    $startM = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
                    $endM = ((int) substr($endRaw, 0, 2)) * 60 + (int) substr($endRaw, 3, 2);
                    $durMin = max(0, $endM - $startM);
                }
                $key = $isoDow . '|' . $start;
                if (!isset($sessionSlotsByClassId[$cid][$key])) {
                    $sessionSlotsByClassId[$cid][$key] = [
                        'day' => $isoDow,
                        'start_time' => $start,
                        'duration_hours' => $durMin > 0 ? round($durMin / 60, 1) : null,
                    ];
                }
            }
        }

        $classes->getCollection()->transform(function ($class) use ($courseNames, $subjectNames, $teacherNames, $userNames, $observedUsedByClass, $sessionSlotsByClassId, $contractExceptionCountByClassId, $paidAtMap, $packageMap) {
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

            // 課程主檔 week*/time* 為「已儲存的固定排課契約」。未來堂次若仍含已移除的星期（舊資料），
            // 不可讓其覆寫顯示成多出一個時段（例：只存週六卻因未刪除的週日預排而顯示週日）。
            $contractWeekdays = [];
            foreach ($class->day_time_slots as $slot) {
                $d = (int) ($slot['day'] ?? 0);
                if ($d >= 1 && $d <= 7) {
                    $contractWeekdays[$d] = true;
                }
            }

            // Detect drift between contract and future scheduled sessions (read-only, never overwrite contract)
            $csSlots = $sessionSlotsByClassId[(int) $class->ID] ?? [];
            $class->schedule_drift = false;
            if (!empty($csSlots) && !empty($class->day_time_slots)) {
                $contractKeys = [];
                foreach ($class->day_time_slots as $cs) {
                    $d = (int) ($cs['day'] ?? 0);
                    $t = (string) ($cs['start_time'] ?? '');
                    $dur = round((float) ($cs['duration_hours'] ?? $globalDurHours), 1);
                    if ($d >= 1 && $d <= 7 && $t !== '') {
                        $contractKeys[$d . '|' . $t . '|' . $dur] = true;
                    }
                }
                foreach ($csSlots as $ss) {
                    $d = (int) ($ss['day'] ?? 0);
                    $t = (string) ($ss['start_time'] ?? '');
                    $dur = round((float) ($ss['duration_hours'] ?? $globalDurHours), 1);
                    $key = $d . '|' . $t . '|' . $dur;
                    if (!isset($contractKeys[$key])) {
                        $class->schedule_drift = true;
                        break;
                    }
                }
            }

            $class->contract_exception_count = (int) ($contractExceptionCountByClassId[(int) $class->ID] ?? 0);

            // days_of_week 以實際有時間的時段為準（避免 week1=6 但 time1=null 造成前端顯示多餘星期）
            if (!empty($class->day_time_slots)) {
                $daysFromSlots = array_values(array_unique(array_column($class->day_time_slots, 'day')));
                sort($daysFromSlots);
                $class->days_of_week = $daysFromSlots;
                $class->day_of_week = (int) ($daysFromSlots[0] ?? $class->day_of_week ?? 0);
            }
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

            if ($class->isPartOfPackage() && isset($packageMap[$class->PackageID])) {
                $pkg = $packageMap[$class->PackageID];
                $class->package_remaining_sessions = max(0, (int) $pkg->remaining_sessions);
                $class->package_total_sessions     = (int) $pkg->total_sessions;
                $class->package_used_sessions      = (int) $pkg->used_sessions;
            }

            $directPaidAt = $class->PayDate ? substr($class->PayDate, 0, 10) : null;
            $invoicePaidAt = $paidAtMap[(int) $class->ID] ?? null;
            $hasInvoicePayment = $invoicePaidAt !== null;
            $class->payment_status = (empty($class->Paid) && !$hasInvoicePayment) ? 'unpaid' : 'paid';
            $class->paid_at = $directPaidAt;
            $class->last_paid_at = $directPaidAt ?? $invoicePaidAt;
            $class->status = empty($class->Stop) ? 'active' : 'inactive';
            $class->closed_reason = $class->closed_reason ?? null;
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
                ->where(function ($q) use ($branchId) {
                    $q->whereHas('room', function ($sub) use ($branchId) {
                        $sub->where('campus_id', $branchId);
                    })->orWhere(function ($q2) use ($branchId) {
                        $q2->whereNull('room_id')
                           ->whereHas('student', function ($sub) use ($branchId) {
                               $sub->where('CampusID', $branchId);
                           });
                    });
                });
            if ($role === 'teacher') {
                $teacherId = (int) $request->attributes->get('auth_teacher_id');
                if ($teacherId <= 0) {
                    return response()->json(['message' => 'Teacher not linked'], 403);
                }
                $this->applyTeacherOrSubstituteStudentClassScope($query, $teacherId);
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
            $teacherId = (int) request()->attributes->get('auth_teacher_id');
            if ($teacherId <= 0 || !$this->teacherOwnsOrSubstitutesCourse($teacherId, (int) $studentClass->ID)) {
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

        // Payment Gate (FR-3): 直接設 payment_status=paid 必須提供 paid_at（繳款日期）。
        // 前端一律透過 PaymentEntryModal 走 /api/v1/payment-reports/director-record，
        // 不應直接用 payment_status=paid 切換狀態，避免 PayDate 空白、帳務無從查核。
        $rawInput = $request->all();
        if (($rawInput['payment_status'] ?? null) === 'paid'
            && (!array_key_exists('paid_at', $rawInput) || empty($rawInput['paid_at']))) {
            return response()->json([
                'message' => '標記已繳費必須提供繳款日期',
                'code'    => 'paid_at_required',
            ], 422);
        }

        $mapped = $this->mapFrontendPayload($request);
        $scheduleSlotsForRebuild = is_array($mapped['ScheduleSlots'] ?? null) ? $mapped['ScheduleSlots'] : [];

        // Remove ScheduleSlots and ID references to prevent overwriting critical relationships
        unset($mapped['ScheduleSlots'], $mapped['StudentID'], $mapped['GradeID'], $mapped['RoomID'], $mapped['by1']);

        if ($studentClass->isPartOfPackage()) {
            unset($mapped['RemainingSessions']);
        }

        if (!empty($campusIds) && !empty($mapped['room_id'])) {
            $roomCampusId = DB::table('rooms')->where('id', (int) $mapped['room_id'])->value('campus_id');
            if ($roomCampusId !== null && !in_array((int) $roomCampusId, $campusIds, true)) {
                return response()->json(['message' => '所選教室不屬於您可存取的分校'], 422);
            }
        }

        // Rate/SessionCount 異動「前」快照舊 Charge vs 舊 Rate × 舊數量 的差額，
        // 以便在更新 Charge 時保留透過單堂時間調整（session_charge）累積的手動調整金額。
        $oldChargeSnapshot = (int) ($studentClass->Charge ?? 0);
        $oldRateSnapshot = (float) ($studentClass->Rate ?? 0);
        $oldSessionCountSnapshot = (int) ($studentClass->SessionCount ?? 0);
        $oldTotalHoursSnapshot = (int) ($studentClass->TotalHours ?? 0);
        $oldRateUnitSnapshot = strtolower(trim((string) ($studentClass->rate_unit ?? 'session')));
        if (!in_array($oldRateUnitSnapshot, ['session', 'hour'], true)) {
            $oldRateUnitSnapshot = 'session';
        }

        $studentClass->update($mapped);
        $studentClass->refresh();

        // Rate 或 SessionCount 異動時同步 Charge（總費用快照），
        // 並保留原本由單堂時間調整累積的 delta（老 Charge − 老 Rate×老數量），
        // 避免老師／主任調漲調降課程費率時，把已經手動微調過的金額一併洗掉。
        if (isset($mapped['Rate']) || isset($mapped['SessionCount'])) {
            $oldBase = $oldRateUnitSnapshot === 'hour'
                ? (int) round($oldRateSnapshot * $oldTotalHoursSnapshot)
                : (int) round($oldRateSnapshot * $oldSessionCountSnapshot);
            $preservedDelta = $oldChargeSnapshot - $oldBase;

            $rateUnit = $studentClass->rate_unit ?? 'session';
            if ($rateUnit === 'hour') {
                $newBase = (int) round((float) $studentClass->Rate * (int) $studentClass->TotalHours);
            } else {
                $newBase = (int) round((float) $studentClass->Rate * (int) $studentClass->SessionCount);
            }
            if ($newBase > 0) {
                $newCharge = max(0, $newBase + $preservedDelta);
                $studentClass->update(['Charge' => $newCharge]);
                $studentClass->refresh();
            }
        }

        $scheduleFieldsPresent = $this->scheduleFieldsPresentInMapped($mapped);

        // 若 SessionCount 被縮減，取消超出新堂數的 scheduled 堂次；若增加，補建新堂次。
        // 如果堂數未變但只是調整固定時段，先讓後面的 schedule sync 處理，避免互相覆蓋。
        if (array_key_exists('SessionCount', $mapped)
            && (string) ($studentClass->ScheduleMode ?? 'count') === 'count'
        ) {
            $newCount = max(0, (int) ($studentClass->SessionCount ?? 0));
            if ($newCount > 0) {
                $sessionCountChanged = $newCount !== $oldSessionCountSnapshot;
                if ($sessionCountChanged || !$scheduleFieldsPresent) {
                    $this->cancelExcessScheduledSessions((int) $studentClass->ID, $newCount);
                    $this->extendSessionsIfNeeded($studentClass, $newCount);
                }
            }
        }

        // 主任「強制重建未上堂次」：直接執行安全部分重建，不走完整重建流程
        if ($request->boolean('force_partial_rebuild', false)) {
            $slots = $this->resolveScheduleSlotsForRebuild($studentClass, $scheduleSlotsForRebuild);
            if (!empty($slots)) {
                $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
                $updatedCount = $this->syncFutureScheduledSessionTimes(
                    (int) $studentClass->ID,
                    $slots,
                    $durationMinutes
                );
                if ($updatedCount > 0) {
                    $this->reconcileWeekTimeFieldsFromSessions($studentClass);
                }
                return response()->json(array_merge($studentClass->fresh()->toArray(), [
                    'session_sync' => [
                        'rebuilt'                 => false,
                        'reason'                  => 'force_partial_rebuild',
                        'updated_future_sessions' => $updatedCount,
                        'reconcile_skipped'       => $updatedCount === 0,
                    ],
                ]));
            }
        }

        $sessionSync = $this->maybeRebuildSessionsAfterUpdate(
            $studentClass,
            $previousStartDate,
            $mapped,
            $scheduleSlotsForRebuild,
            (bool) $request->boolean('force_rebuild_if_mismatch', false)
        );

        // Only reconcile week/time fields from ClassSession when it is safe:
        // skip when the user explicitly changed schedule fields but the future
        // sessions could not be updated (history_exists with 0 rows touched),
        // because reconcile would overwrite the user's new values with the old
        // ClassSession times.
        $skipReconcile = $scheduleFieldsPresent
            && ($sessionSync['reason'] ?? '') === 'history_exists'
            && (int) ($sessionSync['updated_future_sessions'] ?? -1) === 0;

        if ($skipReconcile) {
            $sessionSync['reconcile_skipped'] = true;
            $sessionSync['warning'] = '未來堂次因狀態鎖定未更新時間，課程主檔已儲存新時段但堂次仍為舊時段，請檢查堂次狀態。';
        } else {
            $this->reconcileWeekTimeFieldsFromSessions($studentClass);
        }

        $monthlySessionSync = $this->ensureMonthlyFutureScheduledSessions($studentClass, $scheduleSlotsForRebuild);
        if (($monthlySessionSync['created_sessions'] ?? 0) > 0) {
            $sessionSync['monthly_future_sessions_created'] = (int) $monthlySessionSync['created_sessions'];
        }

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
                    $by1Map = ['one_on_one' => 1, 'one_on_two' => 2, 'one_on_three' => 3, 'tutoring' => 4, 'trial' => 1];
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
                                ->active()->exists();
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
     * 月結制課程續約：延長 EndDate，不建立新批次。
     * POST /api/v1/student-classes/{studentClass}/renew-monthly
     */
    public function renewMonthly(Request $request, StudentClass $studentClass)
    {
        $auth = $this->authorizeStudentClassAccess($studentClass);
        if ($auth !== null) {
            return $auth;
        }

        if ((string) ($studentClass->ScheduleMode ?? 'count') === 'count') {
            return response()->json([
                'message' => '此課程為堂數制，請使用「加購堂數」功能。',
                'errors' => ['mode' => ['堂數制課程不支援月結續約，請使用 purchase-batch 端點。']],
            ], 422);
        }

        $data = $request->validate([
            'end_date'    => 'required|date|after:today',
            'months'      => 'nullable|integer|min:1|max:24',
        ]);

        $newEndDate = Carbon::parse($data['end_date'])->toDateString();

        $studentClass->EndDate = $newEndDate;
        $studentClass->save();
        $studentClass->refresh();
        $sessionSync = $this->ensureMonthlyFutureScheduledSessions($studentClass);

        return response()->json([
            'message'  => '月結課程已續約，到期日更新為 ' . $newEndDate,
            'end_date' => $newEndDate,
            'session_sync' => $sessionSync,
            'course'   => [
                'id'                => (int) $studentClass->ID,
                'end_date'          => $newEndDate,
                'settlement_day'    => $studentClass->settlement_day,
                'monthly_sessions'  => $studentClass->monthly_sessions,
                'schedule_mode'     => $studentClass->ScheduleMode,
            ],
        ]);
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

        // 月結制課程不支援加購堂數，應使用 renew-monthly 端點
        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'count') {
            return response()->json([
                'message' => '月結制課程請使用「月結續約」功能延長課程，不支援加購堂數。',
                'errors'  => ['mode' => ['月結制課程不支援此操作，請使用 renew-monthly 端點。']],
            ], 422);
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

            // ── Build ClassSession rows for the new course ──
            $slots = $this->resolveScheduleSlotsForRebuild($newCourse);
            if (empty($slots)) {
                $isoDow = (int) Carbon::parse($startDate)->dayOfWeekIso;
                $fallbackTime = $this->normalizeSessionTime($newCourse->time ?? null, '16:00');
                $slots = [['weekday' => $isoDow, 'time' => substr($fallbackTime, 0, 5)]];
                if ($globalDur >= 30) {
                    $slots[0]['duration_minutes'] = $globalDur;
                }
            }
            $builtSessions = $this->buildSessionsForCount(
                (int) $newCourse->ID, $startDate, $sessions, $slots, $globalDur
            );

            $createdSessions = 0;
            $lastSessionDate = null;
            foreach ($builtSessions as $sess) {
                ClassSession::create($sess);
                $createdSessions++;
                $d = $sess['SessionDate'] ?? null;
                if ($d !== null && ($lastSessionDate === null || $d > $lastSessionDate)) {
                    $lastSessionDate = $d;
                }
            }

            if ($lastSessionDate) {
                $newCourse->EndDate = $lastSessionDate;
                $newCourse->save();
            }

            SessionDeductionService::syncCounters($newCourse);
            $newCourse->refresh();

            $sourceClosed = false;
            if (
                (string) ($studentClass->ScheduleMode ?? '') === 'count'
                && (int) ($studentClass->Paid ?? 0) === 1
                && (int) ($studentClass->RemainingSessions ?? 0) <= 0
            ) {
                $studentClass->Stop = 1;
                $studentClass->closed_reason = 'settled';
                $studentClass->EndDate = Carbon::today()->toDateString();
                $studentClass->save();
                $sourceClosed = true;
            }

            return response()->json([
                'message' => $sourceClosed
                    ? '已新增購買批次，舊批次已自動結案'
                    : '已新增購買批次',
                'mode' => $mode,
                'source_closed' => $sourceClosed,
                'created_sessions' => $createdSessions,
                'source_course' => [
                    'id' => (int) $studentClass->ID,
                    'session_count' => (int) ($studentClass->SessionCount ?? 0),
                    'remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
                    'paid' => (int) ($studentClass->Paid ?? 0),
                    'stop' => (int) ($studentClass->Stop ?? 0),
                    'closed_reason' => $studentClass->closed_reason,
                    'end_date' => $this->normalizeDateString($studentClass->EndDate),
                ],
                'new_course' => [
                    'id' => (int) $newCourse->ID,
                    'session_count' => (int) ($newCourse->SessionCount ?? 0),
                    'remaining_sessions' => (int) ($newCourse->RemainingSessions ?? 0),
                    'paid' => (int) ($newCourse->Paid ?? 0),
                    'start_date' => $this->normalizeDateString($newCourse->StartDate),
                    'end_date' => $this->normalizeDateString($newCourse->EndDate),
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

            $conflict = $this->detectAddSessionConflict(
                $classId, $sessionDate, $startTime, $isSessionMode, $sessionCount,
                $lockedSessionIdMap, $approvedSessionIds, $signInSessionIds, $todayYmd, $nowTime
            );

            if ($conflict['conflict_type'] !== 'none') {
                \Illuminate\Support\Facades\Log::info('add_session_conflict', [
                    'student_class_id' => $classId,
                    'session_date' => $sessionDate,
                    'start_time' => $startTime,
                    'conflict_type' => $conflict['conflict_type'],
                    'error_code' => $conflict['error_code'],
                ]);
                return response()->json($conflict, 409);
            }

            $existing = $conflict['_existing_session'];

            if ($existing) {
                $classSession = $existing;
                $classSession->EndTime = $endTime;
                $classSession->Status = $isEnded ? 'completed' : 'scheduled';
                if ($note !== '') {
                    $classSession->Note = $note;
                }
                $classSession->save();
            } else {
                $movableSession = $conflict['_movable_session'];

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

            $isException = !$this->sessionMatchesContract(
                $sessionDate, $startTime, $endTime, $studentClass
            );
            if ((bool) $classSession->IsContractException !== $isException) {
                $classSession->IsContractException = $isException;
                $classSession->save();
            }

            $record = LearningRecord::where('ClassSessionID', (int) $classSession->id)->active()->first();
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

    /**
     * Dry-run conflict check for add-session (no DB writes).
     * POST /api/v1/student-classes/{studentClass}/add-session/check
     */
    public function checkAddSession(Request $request, StudentClass $studentClass)
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
        ]);

        $sessionDate = Carbon::parse($data['session_date'])->toDateString();
        $startTime = $this->normalizeSessionTime($data['start_time'] ?? null, $studentClass->time ?: '16:00');

        $classId = (int) $studentClass->ID;
        $isSessionMode = ((string) ($studentClass->ScheduleMode ?? 'count') === 'count')
            || ((int) ($studentClass->SessionCount ?? 0) > 0);
        $sessionCount = max(0, (int) ($studentClass->SessionCount ?? 0));
        $todayYmd = Carbon::now()->toDateString();
        $nowTime = Carbon::now()->format('H:i:s');

        $approvedSessionIds = LearningRecord::where('StudentClassID', $classId)
            ->where('Status', 'approved')
            ->whereNotNull('ClassSessionID')
            ->pluck('ClassSessionID')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()->values()->all();
        $signInSessionIds = StudentSignIn::where('StudentClassID', $classId)
            ->whereNotNull('ClassSessionID')
            ->pluck('ClassSessionID')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()->values()->all();
        $lockedSessionIdMap = [];
        foreach (array_merge($approvedSessionIds, $signInSessionIds) as $sid) {
            $lockedSessionIdMap[(int) $sid] = true;
        }

        $result = $this->detectAddSessionConflict(
            $classId, $sessionDate, $startTime, $isSessionMode, $sessionCount,
            $lockedSessionIdMap, $approvedSessionIds, $signInSessionIds, $todayYmd, $nowTime
        );

        $canAdd = $result['conflict_type'] === 'none';

        return response()->json([
            'can_add' => $canAdd,
            'conflict_type' => $result['conflict_type'],
            'error_code' => $result['error_code'] ?? null,
            'message' => $result['message'],
            'has_attendance' => $result['has_attendance'] ?? false,
            'has_approved_learning_record' => $result['has_approved_learning_record'] ?? false,
            'conflict_session_id' => $result['conflict_session_id'] ?? null,
            'suggested_actions' => $result['suggested_actions'] ?? [],
        ]);
    }

    /**
     * Shared conflict detection for add-session and its check endpoint.
     * Returns a structured array; when conflict_type === 'none' the caller
     * may proceed with the write. Internal keys prefixed with '_' are
     * for caller use only and stripped from API responses.
     */
    private function detectAddSessionConflict(
        int $classId,
        string $sessionDate,
        string $startTime,
        bool $isSessionMode,
        int $sessionCount,
        array $lockedSessionIdMap,
        array $approvedSessionIds,
        array $signInSessionIds,
        string $todayYmd,
        string $nowTime
    ): array {
        $existing = ClassSession::where('StudentClassID', $classId)
            ->whereDate('SessionDate', $sessionDate)
            ->where('StartTime', $startTime)
            ->first();

        if ($existing && isset($lockedSessionIdMap[(int) $existing->id])) {
            $sid = (int) $existing->id;
            $hasAttendance = in_array($sid, $signInSessionIds, true);
            $hasApprovedLr = in_array($sid, $approvedSessionIds, true);
            $suggestions = ['改選其他時段'];
            if ($hasAttendance) {
                $suggestions[] = '先處理原堂次（調課或請假）';
            }
            return [
                'conflict_type' => 'locked_existing',
                'error_code' => 'SESSION_LOCKED',
                'message' => '該時段已有已點名或已核准堂次，請改選其他時段，或先處理原堂次（調課／請假）。',
                'conflict_session_id' => $sid,
                'has_attendance' => $hasAttendance,
                'has_approved_learning_record' => $hasApprovedLr,
                'suggested_actions' => $suggestions,
                '_existing_session' => $existing,
                '_movable_session' => null,
            ];
        }

        $movableSession = null;
        if (!$existing) {
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

            if ($isSessionMode && !$movableSession) {
                $activeSessionCount = (int) ClassSession::where('StudentClassID', $classId)
                    ->where('Status', '!=', 'cancelled')
                    ->count();
                if ($sessionCount > 0 && $activeSessionCount >= $sessionCount) {
                    return [
                        'conflict_type' => 'full_capacity',
                        'error_code' => 'SESSIONS_FULL',
                        'message' => '此課程堂次已排滿且無可調整的未來堂次。請先調課、請假，或增加總堂數。',
                        'suggested_actions' => ['調課', '請假', '增加總堂數'],
                        '_existing_session' => null,
                        '_movable_session' => null,
                    ];
                }
            }
        }

        return [
            'conflict_type' => 'none',
            'error_code' => null,
            'message' => '可加課',
            '_existing_session' => $existing,
            '_movable_session' => $movableSession,
        ];
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

    /**
     * 正班 TeacherID 或單堂代課（schedules.status=scheduled + original_schedule_id）之代課老師。
     */
    private function teacherOwnsOrSubstitutesCourse(int $teacherId, int $studentClassId): bool
    {
        if ($teacherId <= 0 || $studentClassId <= 0) {
            return false;
        }
        if (StudentClass::where('ID', $studentClassId)->where('TeacherID', $teacherId)->exists()) {
            return true;
        }

        return DB::table('schedules')
            ->where('student_course_id', $studentClassId)
            ->where('teacher_id', $teacherId)
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->exists();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\StudentClass>  $query
     */
    private function applyTeacherOrSubstituteStudentClassScope($query, int $teacherId): void
    {
        $query->where(function ($q) use ($teacherId) {
            $q->where('TeacherID', $teacherId)
                ->orWhereExists(function ($sub) use ($teacherId) {
                    $sub->select(DB::raw(1))
                        ->from('schedules')
                        ->whereColumn('schedules.student_course_id', 'StudentClass.ID')
                        ->where('schedules.teacher_id', $teacherId)
                        ->where('schedules.status', 'scheduled')
                        ->whereNotNull('schedules.original_schedule_id');
                });
        });
    }

    private function authorizeStudentClassAccess(StudentClass $studentClass)
    {
        $role = request()->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);

        if ($role === 'teacher') {
            $teacherId = (int) request()->attributes->get('auth_teacher_id');
            if ($teacherId <= 0 || (int) $studentClass->TeacherID !== $teacherId) {
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
        // paid_at is the source of truth for Paid when the key is present in the payload:
        //   - set a date   → PayDate=that date, Paid=1
        //   - explicit null/empty → PayDate=null, Paid=0 (UX: 清空繳費日期 = 改為未繳費)
        //   - key omitted  → do not touch Paid/PayDate
        // Callers that only edit other fields (Memo, 排課…) must not include paid_at
        // in the payload if they do not want to change the payment status.
        if (array_key_exists('paid_at', $input)) {
            $mappedData['PayDate'] = $input['paid_at'] ?: null;
            $mappedData['Paid'] = !empty($input['paid_at']) ? 1 : 0;
        }
        // Explicit payment_status still wins over paid_at (e.g. 列表按鈕切換狀態).
        // Guard: when toggling to unpaid and NO paid_at key is present in the payload,
        // also clear PayDate to prevent Paid=0 / PayDate IS NOT NULL inconsistency.
        // (If paid_at was explicitly sent alongside payment_status, the paid_at block above
        // already controls PayDate and takes precedence.)
        if (isset($input['payment_status'])) {
            $mappedData['Paid'] = $input['payment_status'] === 'paid' ? 1 : 0;
            if ($input['payment_status'] === 'unpaid' && !array_key_exists('paid_at', $input)) {
                $mappedData['PayDate'] = null;
            }
        }
        if (array_key_exists('room_id', $input)) $mappedData['room_id'] = $input['room_id'] ? (int) $input['room_id'] : null;
        if (array_key_exists('settlement_day', $input)) $mappedData['settlement_day'] = $input['settlement_day'] !== null && $input['settlement_day'] !== '' ? (int) $input['settlement_day'] : null;
        if (array_key_exists('monthly_sessions', $input)) $mappedData['monthly_sessions'] = $input['monthly_sessions'] !== null && $input['monthly_sessions'] !== '' ? (int) $input['monthly_sessions'] : null;
        if (array_key_exists('Memo', $input)) $mappedData['Memo'] = $input['Memo'];
        if (array_key_exists('memo', $input)) $mappedData['Memo'] = $input['memo'];

        // Default constraints for creation only
        if ($request->isMethod('post')) {
            $classType = $input['class_type'] ?? 'one_on_one';
            $by1Map = ['one_on_one' => 1, 'one_on_two' => 2, 'one_on_three' => 3, 'tutoring' => 4, 'trial' => 1];
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
     * 月結課程以 EndDate + 固定星期/時段為契約；課程管理詳情則讀 ClassSession。
     * 續約或編輯後補齊未來缺少的實體堂次，讓 UI 能立即顯示預排課程。
     *
     * @param  array<int, array<string, mixed>>  $providedSlots
     * @return array<string, mixed>
     */
    private function ensureMonthlyFutureScheduledSessions(StudentClass $studentClass, array $providedSlots = []): array
    {
        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'date') {
            return ['created_sessions' => 0, 'reason' => 'not_monthly'];
        }
        if ((int) ($studentClass->Stop ?? 0) === 1) {
            return ['created_sessions' => 0, 'reason' => 'inactive_course'];
        }

        $endDate = $this->normalizeDateString($studentClass->EndDate ?? null);
        if (!$endDate) {
            return ['created_sessions' => 0, 'reason' => 'end_date_missing'];
        }

        $today = Carbon::today()->toDateString();
        $startDate = $this->normalizeDateString($studentClass->StartDate ?? null) ?: $today;
        if ($startDate < $today) {
            $startDate = $today;
        }
        if ($endDate < $startDate) {
            return ['created_sessions' => 0, 'reason' => 'date_range_elapsed'];
        }

        $slots = $this->resolveScheduleSlotsForRebuild($studentClass, $providedSlots);
        if (empty($slots)) {
            return ['created_sessions' => 0, 'reason' => 'schedule_slots_missing'];
        }

        $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
        $proposedSessions = $this->buildSessionsFromWeeklySchedule(
            (int) $studentClass->ID,
            $startDate,
            $endDate,
            $slots,
            $durationMinutes
        );
        if (empty($proposedSessions)) {
            return ['created_sessions' => 0, 'reason' => 'no_matching_dates'];
        }

        $existingKeys = [];
        $existingRows = ClassSession::where('StudentClassID', (int) $studentClass->ID)
            ->whereDate('SessionDate', '>=', $startDate)
            ->whereDate('SessionDate', '<=', $endDate)
            ->get(['SessionDate', 'StartTime']);
        foreach ($existingRows as $row) {
            $date = $this->normalizeDateString($row->SessionDate ?? null);
            $start = substr((string) ($row->StartTime ?? ''), 0, 5);
            if ($date && $start !== '') {
                $existingKeys[$date . '|' . $start] = true;
            }
        }

        $created = 0;
        $now = Carbon::now();
        foreach ($proposedSessions as $session) {
            $sessionDate = $this->normalizeDateString($session['SessionDate'] ?? null);
            $start = substr((string) ($session['StartTime'] ?? ''), 0, 5);
            $endTime = $this->normalizeSessionTime($session['EndTime'] ?? null, '18:00:00');
            if (!$sessionDate || $start === '') {
                continue;
            }
            if ($this->sessionEndedByEndTime($sessionDate, $endTime, $now)) {
                continue;
            }
            $key = $sessionDate . '|' . $start;
            if (isset($existingKeys[$key])) {
                continue;
            }

            ClassSession::create($session);
            $existingKeys[$key] = true;
            $created++;
        }

        return [
            'created_sessions' => $created,
            'reason' => $created > 0 ? 'monthly_future_sessions_created' : 'already_complete',
        ];
    }

    /**
     * After an update, optionally align week/time DB fields with **future**
     * scheduled ClassSession rows (same cadence as index drift detection).
     *
     * Does **not** fall back to completed/attended history: one-off substitute
     * slots (e.g. 週二代課) must not overwrite the contract when the user removes
     * that weekday and there are no upcoming scheduled sessions left.
     */
    private function reconcileWeekTimeFieldsFromSessions(StudentClass $studentClass): void
    {
        $classId = (int) $studentClass->ID;
        $today = Carbon::today()->toDateString();
        $activeSessions = ClassSession::where('StudentClassID', $classId)
            ->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', $today)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get(['SessionDate', 'StartTime', 'EndTime']);

        if ($activeSessions->isEmpty()) {
            return;
        }

        $slotsByWeekday = [];
        foreach ($activeSessions as $cs) {
            $date = $this->normalizeDateString($cs->SessionDate ?? null);
            if (!$date) {
                continue;
            }
            $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
            $start = substr((string) ($cs->StartTime ?? ''), 0, 5);
            if ($start === '') {
                continue;
            }
            $endRaw = (string) ($cs->EndTime ?? '');
            $durMin = 0;
            if ($endRaw && $start) {
                $startM = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
                $endM = ((int) substr($endRaw, 0, 2)) * 60 + (int) substr($endRaw, 3, 2);
                $durMin = max(0, $endM - $startM);
            }
            $key = $isoDow . '|' . $start;
            if (!isset($slotsByWeekday[$key])) {
                $slotsByWeekday[$key] = [
                    'weekday' => $isoDow,
                    'start' => $start,
                    'dur' => $durMin > 0 ? $durMin : null,
                ];
            }
        }

        $uniqueSlots = array_values($slotsByWeekday);
        usort($uniqueSlots, fn ($a, $b) => $a['weekday'] <=> $b['weekday'] ?: strcmp($a['start'], $b['start']));

        if (empty($uniqueSlots)) {
            return;
        }

        $updates = [];
        $globalDur = (int) ($studentClass->SessionDuration ?? 0);
        $updates['week'] = $uniqueSlots[0]['weekday'];
        $updates['time'] = $uniqueSlots[0]['start'] . ':00';

        for ($i = 1; $i <= 6; $i++) {
            if (isset($uniqueSlots[$i])) {
                $updates['week' . $i] = $uniqueSlots[$i]['weekday'];
                $updates['time' . $i] = $uniqueSlots[$i]['start'] . ':00';
                $dur = $uniqueSlots[$i]['dur'];
                $updates['duration' . $i] = ($dur && $dur !== $globalDur) ? $dur : null;
            } else {
                $updates['week' . $i] = null;
                $updates['time' . $i] = null;
                $updates['duration' . $i] = null;
            }
        }

        $changed = false;
        foreach ($updates as $field => $value) {
            $current = $studentClass->{$field} ?? null;
            if ($field === 'time' || preg_match('/^time\d$/', $field)) {
                $currentNorm = $current ? substr((string) $current, 0, 5) : null;
                $newNorm = $value ? substr((string) $value, 0, 5) : null;
                if ($currentNorm !== $newNorm) {
                    $changed = true;
                    break;
                }
            } elseif ((string) ($current ?? '') !== (string) ($value ?? '')) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $studentClass->fill($updates);
            $studentClass->save();
        }
    }

    /**
     * Whether the mapped payload contains any schedule-related field changes
     * (week/time slots or duration).  Used to decide if reconcile should be
     * skipped after an update that could not touch ClassSession rows.
     */
    private function scheduleFieldsPresentInMapped(array $mapped): bool
    {
        static $fields = [
            'week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6',
            'time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6',
            'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6',
            'SessionDuration',
        ];
        foreach ($fields as $field) {
            if (array_key_exists($field, $mapped)) {
                return true;
            }
        }
        return false;
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
                // Start date unchanged — but if schedule fields (week/time)
                // changed, still sync future session times rather than doing
                // nothing (which lets reconcile overwrite the new values).
                if ($scheduleUpdated) {
                    $slots = $this->resolveScheduleSlotsForRebuild($studentClass, $scheduleSlots);
                    if (!empty($slots)) {
                        $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
                        $updatedCount = $this->syncFutureScheduledSessionTimes(
                            (int) $studentClass->ID,
                            $slots,
                            $durationMinutes
                        );
                        return [
                            'rebuilt' => false,
                            'reason' => 'history_exists',
                            'updated_future_sessions' => $updatedCount,
                        ];
                    }
                }
                return ['rebuilt' => false, 'reason' => 'start_date_unchanged'];
            }
        }

        if ($this->hasImmutableSessionHistory((int) $studentClass->ID)) {
            // 開課日有變更：嘗試安全部分重建（只動未鎖定的未來堂次，保留已點名/已核准）
            if ($startDateChanged) {
                $slots = $this->resolveScheduleSlotsForRebuild($studentClass, $scheduleSlots);
                if (!empty($slots)) {
                    $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
                    $updatedCount = $this->syncFutureScheduledSessionTimes(
                        (int) $studentClass->ID,
                        $slots,
                        $durationMinutes
                    );
                    return [
                        'rebuilt'                => false,
                        'reason'                 => 'partial_rebuild',
                        'updated_future_sessions' => $updatedCount,
                        'new_start_date'         => $newStartDate,
                    ];
                }
            }
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
     * @param  array<int, array{weekday:int,time:string,duration_minutes?:int}>  $slots
     * @return array<int, list<array{time:string,dur:int}>>
     */
    private function buildSlotsByWeekdayMap(array $slots, int $durationMinutes): array
    {
        $slotsByWeekday = [];
        foreach ($slots as $slot) {
            $weekday = (int) ($slot['weekday'] ?? 0);
            $time = (string) ($slot['time'] ?? '');
            if ($weekday < 1 || $weekday > 7 || $time === '') {
                continue;
            }
            $dur = (!empty($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30)
                ? (int) $slot['duration_minutes']
                : $durationMinutes;
            $slotsByWeekday[$weekday][] = ['time' => substr($time, 0, 5), 'dur' => $dur];
        }
        foreach ($slotsByWeekday as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['time'], $b['time']));
        }
        unset($list);

        return $slotsByWeekday;
    }

    /**
     * First calendar day on or after $ymd whose ISO weekday exists in the contract map.
     */
    private function snapDateToContractWeekday(string $ymd, array $slotsByWeekday): string
    {
        if ($ymd === '' || empty($slotsByWeekday)) {
            return $ymd;
        }
        $d = Carbon::parse($ymd)->startOfDay();
        for ($i = 0; $i < 21; $i++) {
            if (isset($slotsByWeekday[(int) $d->dayOfWeekIso])) {
                return $d->toDateString();
            }
            $d->addDay();
        }

        return $ymd;
    }

    /**
     * When fixed weekdays change (e.g. 週六 → 週日), future ClassSession rows may still sit on
     * the old weekday; time-only sync cannot move them. Reassign dates/times in order using
     * the same cadence as buildSessionsForCount.
     *
     * @param  \Illuminate\Support\Collection<int, ClassSession>  $unlockedSorted
     * @param  array<int, array{weekday:int,time:string,duration_minutes?:int}>  $slots
     */
    private function remapFutureScheduledSessionsToContract($unlockedSorted, array $slots, int $durationMinutes): int
    {
        $k = $unlockedSorted->count();
        if ($k <= 0) {
            return 0;
        }
        $anchor = $this->normalizeDateString($unlockedSorted->first()->SessionDate ?? null);
        if ($anchor === null || $anchor === '') {
            return 0;
        }
        $slotsByWeekday = $this->buildSlotsByWeekdayMap($slots, $durationMinutes);
        if (empty($slotsByWeekday)) {
            return 0;
        }
        $snapped = $this->snapDateToContractWeekday($anchor, $slotsByWeekday);
        $proposed = $this->buildSessionsForCount(0, $snapped, $k, $slots, $durationMinutes);

        $updated = 0;
        foreach ($unlockedSorted as $i => $session) {
            if (!isset($proposed[$i])) {
                break;
            }
            $p = $proposed[$i];
            $newDate = $this->normalizeDateString($p['SessionDate'] ?? null);
            $newStart = $this->normalizeSessionTime($p['StartTime'] ?? null, '16:00:00');
            $newEnd = $this->normalizeSessionTime($p['EndTime'] ?? null, '18:00:00');
            if ($newDate === null || $newDate === '') {
                continue;
            }
            $oldDate = $this->normalizeDateString($session->SessionDate ?? null);
            if (
                $oldDate !== $newDate
                || (string) $session->StartTime !== $newStart
                || (string) $session->EndTime !== $newEnd
            ) {
                $courseId = (int) $session->StudentClassID;
                $oldStartShort = $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null;

                $session->SessionDate = $newDate;
                $session->StartTime = $newStart;
                $session->EndTime = $newEnd;
                $session->save();
                LearningRecord::where('ClassSessionID', (int) $session->id)
                    ->whereNull('VoidedAt')
                    ->update([
                        'SessionDate' => $newDate,
                        'StartTime' => $newStart,
                        'EndTime' => $newEnd,
                    ]);

                // Keep schedules (substitute/reschedule records) in sync with the new date/time
                // so the class-sessions index join still matches correctly.
                if ($oldDate !== null && $oldDate !== $newDate) {
                    $schedQuery = Schedule::where('student_course_id', $courseId)
                        ->whereDate('schedule_date', $oldDate);
                    if ($oldStartShort) {
                        $schedQuery->where('start_time', $oldStartShort);
                    }
                    $newStartShort = substr($newStart, 0, 5);
                    $newEndShort = substr($newEnd, 0, 5);
                    $schedQuery->update([
                        'schedule_date' => $newDate,
                        'start_time' => $newStartShort,
                        'end_time' => $newEndShort,
                        'day_of_week' => (int) Carbon::parse($newDate)->dayOfWeekIso,
                    ]);
                }

                $updated++;
            }
        }

        return $updated;
    }

    /**
     * When SessionCount is reduced, cancel scheduled sessions beyond the new limit.
     * Only cancels sessions whose Status is 'scheduled'; attended/late/absent sessions are untouched.
     *
     * NOTE: public for cross-controller invocation (e.g. CoursePackageController::update
     * synchronising SessionCount across shared-package members). Do not integrate through
     * StudentClassController::update() to avoid triggering Charge preserved_delta path.
     */
    public function cancelExcessScheduledSessions(int $classId, int $newCount): void
    {
        $allActive = ClassSession::where('StudentClassID', $classId)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted', 'excused'])
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->orderBy('id')
            ->get();

        if ($allActive->count() <= $newCount) {
            return;
        }

        $excess = $allActive->slice($newCount);
        foreach ($excess as $session) {
            if ($session->Status === 'scheduled') {
                $session->Status = 'cancelled';
                $session->save();
            }
        }
    }

    /**
     * 對齊堂數制契約序列：優先補中間缺口，再取消多出的尾端 scheduled 堂次。
     *
     * NOTE: public for cross-controller invocation (see cancelExcessScheduledSessions).
     * Must never delete/rebuild locked history; only create missing contract rows
     * and cancel unlocked scheduled rows outside the first N contract slots.
     */
    public function extendSessionsIfNeeded(StudentClass $studentClass, int $newCount): void
    {
        $classId = (int) $studentClass->ID;
        $nonQuotaStatuses = ['cancelled', 'leave', 'leave_adjusted', 'excused'];

        // 計算現有「實際堂次數」：排除 cancelled 與 leave/excused（請假不佔用購買額度）
        // 與 cancelExcessScheduledSessions 的計算口徑保持一致
        $slots = $this->resolveScheduleSlotsForRebuild($studentClass);
        if (empty($slots)) {
            return;
        }

        $globalDur = max(30, (int) ($studentClass->SessionDuration ?? 120));
        $startFrom = $this->normalizeDateString($studentClass->StartDate ?? null)
            ?: Carbon::today()->toDateString();
        $expectedSessions = $this->buildSessionsForCount($classId, $startFrom, $newCount, $slots, $globalDur);
        if (empty($expectedSessions)) {
            return;
        }

        $expectedKeys = [];
        foreach ($expectedSessions as $session) {
            $date = $this->normalizeDateString($session['SessionDate'] ?? null);
            $start = substr((string) ($session['StartTime'] ?? ''), 0, 5);
            if ($date && $start !== '') {
                $expectedKeys[$date . '|' . $start] = true;
            }
        }

        $existingSessions = ClassSession::where('StudentClassID', $classId)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->orderBy('id')
            ->get();
        $existingQuotaKeys = [];
        $occupiedKeys = [];
        $currentCount = 0;
        $hasScheduledOutsideContract = false;
        $hasLockedQuotaOutsideContract = false;
        foreach ($existingSessions as $session) {
            $date = $this->normalizeDateString($session->SessionDate ?? null);
            $start = substr((string) ($session->StartTime ?? ''), 0, 5);
            if (!$date || $start === '') {
                continue;
            }
            $key = $date . '|' . $start;
            $status = strtolower((string) ($session->Status ?? ''));
            if ($status !== 'cancelled') {
                $occupiedKeys[$key] = true;
            }
            if (!in_array($status, $nonQuotaStatuses, true)) {
                $existingQuotaKeys[$key] = true;
                $currentCount++;
            }
            if ($status === 'scheduled'
                && !isset($expectedKeys[$key])
                && empty($session->IsContractException)
            ) {
                $hasScheduledOutsideContract = true;
            } elseif (!in_array($status, $nonQuotaStatuses, true)
                && !isset($expectedKeys[$key])
            ) {
                $hasLockedQuotaOutsideContract = true;
            }
        }

        if ($currentCount >= $newCount && $hasLockedQuotaOutsideContract) {
            SessionDeductionService::syncCounters($studentClass);
            return;
        }

        if ($currentCount >= $newCount && !$hasScheduledOutsideContract) {
            SessionDeductionService::syncCounters($studentClass);
            return;
        }

        $newSessions = [];
        foreach ($expectedSessions as $session) {
            $date = $this->normalizeDateString($session['SessionDate'] ?? null);
            $start = substr((string) ($session['StartTime'] ?? ''), 0, 5);
            if (!$date || $start === '') {
                continue;
            }
            $key = $date . '|' . $start;
            if (isset($existingQuotaKeys[$key]) || isset($occupiedKeys[$key])) {
                continue;
            }
            $newSessions[] = $session;
        }

        if ($currentCount < $newCount && empty($newSessions)) {
            // No contract gap was found; append after the last row as the legacy extension path.
            $lastSession = ClassSession::where('StudentClassID', $classId)
                ->orderByDesc('SessionDate')
                ->orderByDesc('StartTime')
                ->first();
            $appendFrom = $lastSession
                ? Carbon::parse($lastSession->SessionDate)->addDay()->toDateString()
                : $startFrom;
            $newSessions = $this->buildSessionsForCount($classId, $appendFrom, $newCount - $currentCount, $slots, $globalDur);
        }

        $now = Carbon::now();
        $teacherId = (int) ($studentClass->TeacherID ?? 0);
        $subjectName = DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $studentClass->SubjectID)->value('Val')
            ?? '評量';

        foreach ($newSessions as $session) {
            $sessionDate = $session['SessionDate'] ?? null;
            $endTime = $session['EndTime'] ?? '18:00:00';
            if (!$sessionDate) {
                continue;
            }
            $isEnded = $this->sessionEndedByEndTime($sessionDate, $endTime, $now);
            $session['Status'] = $isEnded ? 'completed' : 'scheduled';
            if ($isEnded && empty($session['Note'])) {
                $session['Note'] = '系統補建堂次（增加購買堂數）';
            }

            $classSession = ClassSession::create($session);

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
            }
        }

        $activeCount = ClassSession::where('StudentClassID', $classId)
            ->whereNotIn('Status', $nonQuotaStatuses)
            ->count();
        if ($activeCount > $newCount) {
            $excess = $activeCount - $newCount;
            $extraScheduled = ClassSession::where('StudentClassID', $classId)
                ->where('Status', 'scheduled')
                ->orderBy('SessionDate')
                ->orderBy('StartTime')
                ->orderBy('id')
                ->get()
                ->filter(function ($session) use ($expectedKeys) {
                    if (!empty($session->IsContractException)) {
                        return false;
                    }
                    $date = $this->normalizeDateString($session->SessionDate ?? null);
                    $start = substr((string) ($session->StartTime ?? ''), 0, 5);
                    return $date && $start !== '' && !isset($expectedKeys[$date . '|' . $start]);
                })
                ->values();

            foreach ($extraScheduled as $session) {
                if ($excess <= 0) {
                    break;
                }
                $session->Status = 'cancelled';
                $session->save();
                $excess--;
            }
        }

        SessionDeductionService::syncCounters($studentClass);
    }

    /**
     * Sync only future scheduled sessions' times by weekday mapping.
     * Keeps historical/locked sessions untouched.
     * Supports same-day multi-slot (e.g. Saturday 13:00 + 17:00): pairs
     * sessions and slots positionally (both sorted by time) per date.
     *
     * If the contract weekdays no longer include a future session's weekday (e.g. removed 週六),
     * remaps SessionDate (and times) in chronological order to match buildSessionsForCount cadence.
     *
     * @param  array<int, array{weekday:int,time:string,duration_minutes?:int}>  $slots
     */
    private function syncFutureScheduledSessionTimes(int $studentClassId, array $slots, int $durationMinutes): int
    {
        if ($studentClassId <= 0 || empty($slots)) {
            return 0;
        }

        $slotsByWeekday = $this->buildSlotsByWeekdayMap($slots, $durationMinutes);
        if (empty($slotsByWeekday)) {
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
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get();

        $unlocked = $sessions->filter(function ($session) use ($lockedBySessionId) {
            if (isset($lockedBySessionId[(int) $session->id])) {
                return false;
            }
            if (!empty($session->IsContractException)) {
                return false;
            }
            return true;
        })->values();

        $needsRemap = false;
        $sessionWeekdays = [];
        foreach ($unlocked as $session) {
            $date = $this->normalizeDateString($session->SessionDate ?? null);
            if (!$date) {
                continue;
            }
            $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
            $sessionWeekdays[$isoDow] = true;
            if (!isset($slotsByWeekday[$isoDow])) {
                $needsRemap = true;
                break;
            }
        }

        if (!$needsRemap && $unlocked->isNotEmpty()) {
            foreach (array_keys($slotsByWeekday) as $contractDay) {
                if (!isset($sessionWeekdays[$contractDay])) {
                    $needsRemap = true;
                    break;
                }
            }
        }

        if ($needsRemap && $unlocked->isNotEmpty()) {
            return $this->remapFutureScheduledSessionsToContract($unlocked, $slots, $durationMinutes);
        }

        $sessionsByDate = [];
        foreach ($sessions as $session) {
            if (!empty($session->IsContractException)) {
                continue;
            }
            $date = $this->normalizeDateString($session->SessionDate ?? null);
            if ($date) {
                $sessionsByDate[$date][] = $session;
            }
        }

        $updated = 0;
        foreach ($sessionsByDate as $date => $dateSessions) {
            $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
            $daySlots = $slotsByWeekday[$isoDow] ?? [];
            if (empty($daySlots)) {
                continue;
            }

            usort($dateSessions, fn ($a, $b) => strcmp((string) $a->StartTime, (string) $b->StartTime));

            foreach ($dateSessions as $idx => $session) {
                if (isset($lockedBySessionId[(int) $session->id])) {
                    continue;
                }
                $slot = $daySlots[min($idx, count($daySlots) - 1)];

                $newStartFull = $this->normalizeSessionTime($slot['time'], '16:00:00');
                $newEndFull = Carbon::createFromFormat('H:i:s', $newStartFull)
                    ->addMinutes(max(30, $slot['dur']))
                    ->format('H:i:s');

                if (
                    (string) $session->StartTime !== $newStartFull
                    || (string) $session->EndTime !== $newEndFull
                ) {
                    $session->StartTime = $newStartFull;
                    $session->EndTime = $newEndFull;
                    $session->save();
                    LearningRecord::where('ClassSessionID', (int) $session->id)
                        ->whereNull('VoidedAt')
                        ->update([
                            'StartTime' => $newStartFull,
                            'EndTime' => $newEndFull,
                        ]);
                    $updated++;
                }
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

    /**
     * Check if a session's (date, startTime, duration) falls within the contract slots.
     */
    private function sessionMatchesContract(string $sessionDate, string $startTime, ?string $endTime, StudentClass $studentClass): bool
    {
        $isoDow = (int) Carbon::parse($sessionDate)->dayOfWeekIso;
        $startHm = substr($startTime, 0, 5);
        $globalDurHours = $studentClass->SessionDuration
            ? round((int) $studentClass->SessionDuration / 60, 1)
            : 2;

        $weekFields = ['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'];
        $timeFields = ['time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6'];
        $durationFields = [null, 'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6'];

        foreach ($weekFields as $index => $wf) {
            $day = (int) ($studentClass->{$wf} ?? 0);
            if ($day < 1 || $day > 7) {
                continue;
            }
            $tf = $timeFields[$index] ?? 'time';
            $rawTime = (string) ($studentClass->{$tf} ?? $studentClass->time ?? '');
            $slotStart = $rawTime ? substr($rawTime, 0, 5) : '';
            if ($slotStart === '') {
                continue;
            }
            $df = $durationFields[$index] ?? null;
            $perDayMin = $df ? (int) ($studentClass->{$df} ?? 0) : 0;
            $slotDurHours = $perDayMin > 0 ? round($perDayMin / 60, 1) : $globalDurHours;

            $sessDurHours = $globalDurHours;
            if ($endTime) {
                $startM = ((int) substr($startHm, 0, 2)) * 60 + (int) substr($startHm, 3, 2);
                $endM = ((int) substr($endTime, 0, 2)) * 60 + (int) substr($endTime, 3, 2);
                $sessDurHours = max(0, $endM - $startM) > 0 ? round(($endM - $startM) / 60, 1) : $globalDurHours;
            }

            if ($day === $isoDow && $slotStart === $startHm && $slotDurHours === $sessDurHours) {
                return true;
            }
        }

        return false;
    }

    private function hasImmutableSessionHistory(int $studentClassId): bool
    {
        if ($studentClassId <= 0) {
            return false;
        }

        // 已作廢的 StudentSignIn 不算歷史記錄，排除後再判斷
        if (StudentSignIn::where('StudentClassID', $studentClassId)->whereNull('VoidedAt')->exists()) {
            return true;
        }

        if (LearningRecord::where('StudentClassID', $studentClassId)->where('Status', 'approved')->whereNull('VoidedAt')->exists()) {
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

    public function buildSessionsFromWeeklySchedule(
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
     * 第 1 堂固定為「首堂日」startDate（不論星期幾），使用該日匹配的所有時段；
     * 第 2～N 堂從 startDate 的隔天起，依序取「星期符合 slots」的日期。
     * 支援同日多時段（如週六 13:00 + 17:00 各算一堂）。
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

        $slotsByWeekday = [];
        foreach ($slots as $s) {
            $wd = (int) ($s['weekday'] ?? 0);
            if ($wd < 1 || $wd > 7) {
                continue;
            }
            $slotsByWeekday[$wd][] = $s;
        }
        foreach ($slotsByWeekday as &$group) {
            usort($group, fn ($a, $b) => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
        }
        unset($group);

        $slotWeekdays = array_keys($slotsByWeekday);
        $firstSlot = $slots[0];
        $firstTime = $firstSlot['time'] ?? '16:00';

        $appendSessionsForDate = function (Carbon $dateObj) use (
            $studentClassId, $durationMinutes, $slotsByWeekday,
            $firstTime, $sessionCount, &$sessions
        ) {
            $isoDow = (int) $dateObj->dayOfWeekIso;
            $daySlots = $slotsByWeekday[$isoDow] ?? [['time' => $firstTime]];
            foreach ($daySlots as $slot) {
                if (count($sessions) >= $sessionCount) {
                    return;
                }
                $time = $slot['time'] ?? $firstTime;
                $dur = (!empty($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30)
                    ? (int) $slot['duration_minutes']
                    : $durationMinutes;
                $start = Carbon::parse($dateObj->toDateString() . ' ' . $time);
                $end = $start->copy()->addMinutes($dur);
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
            }
        };

        $firstDate = Carbon::parse($startDate)->startOfDay();
        $appendSessionsForDate($firstDate);

        $date = Carbon::parse($startDate)->addDay()->startOfDay();
        $maxDate = Carbon::parse($startDate)->addYears(2);
        while (count($sessions) < $sessionCount && $date->lte($maxDate)) {
            $isoDow = (int) $date->dayOfWeekIso;
            if (isset($slotsByWeekday[$isoDow])) {
                $appendSessionsForDate($date);
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

        $reason = $request->input('reason'); // 'completed' or null

        if (!$reason
            && (string) ($sc->ScheduleMode ?? '') === 'count'
            && (int) ($sc->Paid ?? 0) === 1
            && (int) ($sc->RemainingSessions ?? 0) <= 0
        ) {
            $reason = 'completed';
        }

        // 月結制課程停用時，無論剩餘堂數，一律視為完課（completed）
        if (!$reason && (string) ($sc->ScheduleMode ?? 'count') !== 'count') {
            $reason = 'completed';
        }

        DB::beginTransaction();
        try {
            if ($action === 'pause') {
                $sc->Stop = 1;
                if ($reason === 'completed') {
                    $sc->closed_reason = 'completed';
                } elseif ($reason === 'settled') {
                    $sc->closed_reason = 'settled';
                    $sc->EndDate = $today;
                } else {
                    $sc->closed_reason = null;
                }
                $sc->save();

                $noteTag = $reason === 'settled' ? '[結案取消]' : '[暫停取消]';
                $cancelled = ClassSession::where('StudentClassID', $sc->ID)
                    ->where('SessionDate', '>=', $today)
                    ->where('Status', 'scheduled')
                    ->update([
                        'Status' => 'cancelled',
                        'Note' => DB::raw("CONCAT(COALESCE(Note,''), ' {$noteTag}')"),
                        'updated_at' => now(),
                    ]);

                $labels = ['completed' => '已完課', 'settled' => '已結案'];
                $label = $labels[$reason] ?? '已暫停';
                DB::commit();
                return response()->json([
                    'message' => "課程{$label}，已取消 {$cancelled} 堂未來排課。",
                    'cancelled_count' => $cancelled,
                ]);
            } else {
                $sc->Stop = 0;
                $sc->closed_reason = null;
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
