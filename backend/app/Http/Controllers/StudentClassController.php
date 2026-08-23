<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LearningRecord;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Schedule;
use App\Models\ScheduleAuditLog;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\UserCampus;
use App\Models\CoursePackage;
use App\Support\SessionStatus;
use App\Support\Utf8mb3SearchSanitizer;
use App\Services\BillingModeConversionArchiveService;
use App\Services\ClassSessionMaterializationService;
use App\Services\ContractScheduleMatcher;
use App\Services\Scheduling\BillingContractLockGuard;
use App\Services\Scheduling\DeductionBasis;
use App\Services\Scheduling\LessonEntitlementCoverageCalculator;
use App\Services\ClassSessionContractReflowService;
use App\Services\FrontendSubjectIdResolver;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\SessionDeductionService;
use App\Services\ScheduleGuardService;
use App\Services\ManualSessionBookingService;
use App\Services\SessionProjectionReadService;
use App\Services\TeacherScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentClassController extends Controller
{
    public function __construct(
        private ScheduleGuardService $scheduleGuardService,
        private ClassSessionContractReflowService $contractSessionReflowService,
        private InvoiceAmountReconciliationService $invoiceAmounts,
        private BillingModeConversionArchiveService $billingModeConversionArchive
    )
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
            $teacherTerm = Utf8mb3SearchSanitizer::forLike((string) $request->input('teacher_name'));
            if ($teacherTerm !== '') {
                $pattern = '%' . $teacherTerm . '%';
                // TeacherID = User.id in this system. Avoid LoginName to prevent
                // false positives from admin/director emails.
                $matchedIds = DB::table('User')
                    ->whereIn('type', ['T', 'U'])
                    ->where('Name', 'like', $pattern)
                    ->pluck('id');
                $matchedIds = $matchedIds->unique()->values()->all();
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
            $nameTerm = Utf8mb3SearchSanitizer::forLike((string) $request->input('name'));
            if ($nameTerm === '') {
                // utf8mb3 Student.name cannot match 4-byte-only terms (emoji).
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('student', function ($sub) use ($nameTerm) {
                    $sub->where('name', 'like', '%' . $nameTerm . '%');
                });
            }
        }

        // TD-062 P4-b (#740): optional calendar window — align with schedules/class-sessions start/end.
        // Omitted params preserve legacy full-branch fetch (StudentsList, CourseManagement, etc.).
        if ($request->filled('start') && $request->filled('end')) {
            $this->applyCalendarWindowFilter($query, $request->input('start'), $request->input('end'));
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
        $teacherNames = DB::table('User')
            ->whereIn('type', ['T', 'U'])
            ->pluck('Name', 'id')
            ->toArray();
        $userStatuses = DB::table('User')
            ->whereIn('type', ['T', 'U'])
            ->pluck('status', 'id')
            ->toArray();
        $classIds = $classes->getCollection()->pluck('ID')->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->values()->all();
        // Keep the list response honest when a cancelled session left behind a
        // sign-in/ledger artifact. Choosing one evidence source silently creates
        // the "已上 6 / 剩 1" contradiction directors see.
        $usageDiagnosticsByClass = SessionDeductionService::batchExpectedUsedSessionDiagnostics($classIds);
        $observedUsedByClass = array_map(
            static fn (array $diagnostic): int => (int) $diagnostic['observed_used'],
            $usageDiagnosticsByClass
        );
        $paidAtMap = AlertController::lastPaidAtByStudentClassIds($classIds);
        $invoiceAggMap = AlertController::invoiceAggregateByStudentClassIds($classIds);

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

        $classes->getCollection()->transform(function ($class) use ($courseNames, $subjectNames, $teacherNames, $userStatuses, $observedUsedByClass, $usageDiagnosticsByClass, $sessionSlotsByClassId, $contractExceptionCountByClassId, $paidAtMap, $invoiceAggMap, $packageMap) {
            $class->subject_name = $courseNames[$class->SubjectID]
                ?? $subjectNames[$class->SubjectID]
                ?? null;
            $class->teacher_name = $teacherNames[$class->TeacherID]
                ?? null;
            $class->teacher_status = strtolower((string) ($userStatuses[$class->TeacherID] ?? 'active'));

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
            $storedCharge = (int) ($class->Charge ?? 0);
            $effectiveCharge = $storedCharge;
            if (
                $effectiveCharge <= 0
                && $class->payment_type === 'session'
                && !$class->isPartOfPackage()
                && (float) ($class->Rate ?? 0) > 0
                && $class->sessions_purchased > 0
            ) {
                $effectiveCharge = $this->calculateCourseChargeFromRate(
                    (float) ($class->Rate ?? 0),
                    (string) ($class->rate_unit ?? 'session'),
                    $class->sessions_purchased,
                    (int) ($class->TotalHours ?? 0)
                );
            }
            $class->charge = $effectiveCharge;
            $class->effective_charge = $effectiveCharge;
            $class->charge_is_fallback = $storedCharge <= 0 && $effectiveCharge > 0;
            $observedUsedSessions = (int) ($observedUsedByClass[$class->ID] ?? 0);
            $usageDiagnostic = $usageDiagnosticsByClass[(int) $class->ID] ?? null;

            // Remaining = 購買堂數 − 實際已上（扣點、已完成堂次、已核准評量取最大後再與購買數取 cap）

            // #613 A1：若課程有「部分時數」事件（RemainingMinutes 非整堂倍數），分鐘為權威，
            // 不可用 count-based observed 覆寫 recomputeCounters 已寫入的衍生值；否則沿用既有 self-heal。
            $perSessionMin = max(1, (int) ($class->SessionDuration ?: 60));
            $storedRemainingMinutes = $class->RemainingMinutes;
            $hasFractionalBalance = $storedRemainingMinutes !== null
                && ((int) $storedRemainingMinutes % $perSessionMin !== 0);

            if ($class->sessions_purchased > 0 && !$hasFractionalBalance) {
                $observedUsedSessions = min($class->sessions_purchased, $observedUsedSessions);
                $class->UsedSessions = $observedUsedSessions;
                $class->RemainingSessions = max(0, $class->sessions_purchased - $observedUsedSessions);
            }
            $class->sessions_used = (int) ($class->UsedSessions ?? 0);
            $class->remaining_sessions = (int) ($class->RemainingSessions ?? 0);
            if ($usageDiagnostic !== null) {
                $expectedRemaining = max(
                    0,
                    (int) $class->sessions_purchased - (int) $usageDiagnostic['expected_used']
                );
                $class->usage_balance_status = (
                    (int) $usageDiagnostic['cancelled_usage_artifacts'] > 0
                    || (int) ($class->RemainingSessions ?? 0) !== $expectedRemaining
                ) ? 'review_required' : 'ok';
                $class->usage_balance_diagnostic = [
                    'observed_used_sessions' => (int) $usageDiagnostic['observed_used'],
                    'class_session_used_sessions' => (int) $usageDiagnostic['class_session_used'],
                    'cancelled_usage_artifacts' => (int) $usageDiagnostic['cancelled_usage_artifacts'],
                    'ledger_used_sessions' => (int) $usageDiagnostic['ledger_used'],
                    'expected_used_sessions' => (int) $usageDiagnostic['expected_used'],
                    'expected_remaining_sessions' => $expectedRemaining,
                ];
            }
            // 精確剩餘分鐘（部分補課顯示用）；null = 尚未分鐘化的舊資料。
            $class->remaining_minutes = $storedRemainingMinutes !== null ? (int) $storedRemainingMinutes : null;
            $this->attachPreciseBalanceFields($class);

            if ($class->isPartOfPackage() && isset($packageMap[$class->PackageID])) {
                $pkg = $packageMap[$class->PackageID];
                $class->package_remaining_sessions = max(0, (int) $pkg->remaining_sessions);
                $class->package_total_sessions     = (int) $pkg->total_sessions;
                $class->package_used_sessions      = (int) $pkg->used_sessions;
            }

            $directPaidAt = $class->PayDate ? substr($class->PayDate, 0, 10) : null;
            $invoicePaidAt = $paidAtMap[(int) $class->ID] ?? null;
            $invoicePaidAmount = (int) (($invoiceAggMap[(int) $class->ID]['paid_amount'] ?? 0));
            // Display paid = Paid flag OR full invoice cover (R94 / TD-083 B1). Never "any payment".
            $class->payment_status = StudentClass::isFullyPaid(
                (int) ($class->Paid ?? 0) === 1,
                $invoicePaidAmount,
                $effectiveCharge
            ) ? 'paid' : 'unpaid';
            $class->paid_at = $directPaidAt;
            $class->last_paid_at = $invoicePaidAt ?? $directPaidAt;
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
     * 回傳每門課的 { materialized: ClassSessionSlot[], projected: SessionProjection[] }，
     * 不再回傳混合的 flat date list。projected 項目不含 ClassSession.id。
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
        $rangeStart = $this->normalizeDateString($request->input('range_start') ?? null)
            ?: Carbon::today()->startOfMonth()->toDateString();
        $rangeEnd = $this->normalizeDateString($request->input('range_end') ?? null)
            ?: Carbon::parse($rangeStart)->endOfMonth()->toDateString();
        if ($rangeEnd < $rangeStart) {
            $rangeEnd = $rangeStart;
        }

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
                $bodyClasses = StudentClass::whereIn('ID', $courseIds)
                    ->with('student')
                    ->select(
                        'ID',
                        'StudentID',
                        'PackageID',
                        'week',
                        'time',
                        'week1',
                        'time1',
                        'week2',
                        'time2',
                        'week3',
                        'time3',
                        'week4',
                        'time4',
                        'week5',
                        'time5',
                        'week6',
                        'time6',
                        'SessionDuration',
                        'duration1',
                        'duration2',
                        'duration3',
                        'duration4',
                        'duration5',
                        'duration6'
                    )
                    ->get()
                    ->keyBy('ID');
                $packageIds = $bodyClasses->pluck('PackageID')
                    ->map(fn ($pid) => (int) $pid)
                    ->filter(fn ($pid) => $pid > 0)
                    ->unique()
                    ->values();
                $studentIds = $bodyClasses->pluck('StudentID')
                    ->map(fn ($sid) => (int) $sid)
                    ->filter(fn ($sid) => $sid > 0)
                    ->unique()
                    ->values();
                if ($packageIds->isNotEmpty() && $studentIds->isNotEmpty()) {
                    $packageSiblings = StudentClass::whereIn('PackageID', $packageIds->all())
                        ->whereIn('StudentID', $studentIds->all())
                        ->with('student')
                        ->select(
                            'ID',
                            'StudentID',
                            'PackageID',
                            'week',
                            'time',
                            'week1',
                            'time1',
                            'week2',
                            'time2',
                            'week3',
                            'time3',
                            'week4',
                            'time4',
                            'week5',
                            'time5',
                            'week6',
                            'time6',
                            'SessionDuration',
                            'duration1',
                            'duration2',
                            'duration3',
                            'duration4',
                            'duration5',
                            'duration6'
                        )
                        ->get()
                        ->keyBy('ID');
                    $bodyClasses = $bodyClasses->merge($packageSiblings);
                }
                $bodyPackageFallbackDays = $this->buildPackageFallbackDaysMap($bodyClasses);

                $schedulesBody = Schedule::where('branch_id', $branchId)
                    ->whereNotNull('student_course_id')
                    ->whereIn('student_course_id', $courseIds)
                    ->select('student_course_id', 'schedule_date', 'status')
                    ->get();
                $classSessionsBody = ClassSession::whereIn('StudentClassID', $courseIds)
                    ->where('SessionDate', '>=', $rangeStart)
                    ->where('SessionDate', '<=', $rangeEnd)
                    ->select('id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status')
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
                $projectionReader = app(SessionProjectionReadService::class);
                foreach ($bodyCourses as $c) {
                    $cid = $c['id'] ?? null;
                    $startDate = isset($c['first_class_date']) ? Carbon::parse($c['first_class_date'])->toDateString() : null;
                    $n = (int) ($c['sessions_purchased'] ?? 0);
                    $daysOfWeek = isset($c['days_of_week']) && is_array($c['days_of_week'])
                        ? array_values(array_unique(array_map('intval', array_filter($c['days_of_week'], function ($d) { return $d >= 1 && $d <= 7; }))))
                        : [];
                    if (empty($daysOfWeek) && $cid !== null) {
                        $daysOfWeek = $bodyPackageFallbackDays[(int) $cid] ?? [];
                    }
                    // Bug #497 / in-app #126：當 bodyCourses 未夾帶 days_of_week，且 package fallback
                    // 因該課自身 week 欄位已有值而被略過時（buildPackageFallbackDaysMap 只填補空白
                    // 的 sibling），仍應退回讀自身 `week, week1..week6`，否則 SessionCount=24 的
                    // 月度課只會回傳已實體化的 ClassSession 日期，後續週期堂次完全消失。
                    //
                    // 注意：上方 `$bodyClasses = $bodyClasses->merge($packageSiblings)` 因 array_merge
                    // 行為會把整數鍵重新索引，不能再用 `$bodyClasses[(int) $cid]`；
                    // 用 firstWhere('ID', ...) 才能精確命中該課。
                    if (empty($daysOfWeek) && $cid !== null) {
                        $self = $bodyClasses->firstWhere('ID', (int) $cid);
                        if ($self) {
                            $selfDays = [];
                            foreach (['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $wf) {
                                $d = (int) ($self->{$wf} ?? 0);
                                if ($d >= 1 && $d <= 7 && !in_array($d, $selfDays, true)) {
                                    $selfDays[] = $d;
                                }
                            }
                            if (!empty($selfDays)) {
                                sort($selfDays);
                                $daysOfWeek = $selfDays;
                            }
                        }
                    }
                    $actualSessionSet = [];
                    if ($cid !== null && isset($sessionDatesByClass[(string) $cid])) {
                        foreach ($sessionDatesByClass[(string) $cid] as $date) {
                            $actualSessionSet[(string) $date] = true;
                        }
                    }

                    if ($cid !== null && $startDate && $n > 0 && !empty($daysOfWeek)) {
                        $leaveSet = $leaveByClass[$cid] ?? [];
                        $scheduledSet = $scheduledByClass[$cid] ?? [];
                        $contractList = self::computeEffectiveSessionDates($startDate, $n, $daysOfWeek, $leaveSet, $scheduledSet);
                        $mergedSet = [];
                        foreach ($contractList as $date) {
                            $mergedSet[$date] = true;
                        }
                        foreach (array_keys($actualSessionSet) as $date) {
                            $mergedSet[$date] = true;
                        }
                        $list = array_keys($mergedSet);
                        sort($list);

                        if (count($list) > $n) {
                            $selected = [];
                            $actualDates = array_keys($actualSessionSet);
                            sort($actualDates);
                            foreach ($actualDates as $date) {
                                $selected[$date] = true;
                                if (count($selected) >= $n) {
                                    break;
                                }
                            }
                            if (count($selected) < $n) {
                                foreach ($contractList as $date) {
                                    if (isset($selected[$date])) {
                                        continue;
                                    }
                                    $selected[$date] = true;
                                    if (count($selected) >= $n) {
                                        break;
                                    }
                                }
                            }
                            $list = array_keys($selected);
                            sort($list);
                        }
                        $result[(string) $cid] = $this->buildSessionDatesSplit(
                            $projectionReader,
                            (int) $cid,
                            $list,
                            $classSessionsBody,
                            $bodyClasses->firstWhere('ID', (int) $cid),
                            $rangeStart,
                            $rangeEnd
                        );
                        continue;
                    }

                    if ($cid !== null && !empty($actualSessionSet)) {
                        $list = array_keys($actualSessionSet);
                        sort($list);
                        $result[(string) $cid] = $this->buildSessionDatesSplit(
                            $projectionReader,
                            (int) $cid,
                            $list,
                            $classSessionsBody,
                            $bodyClasses->firstWhere('ID', (int) $cid),
                            $rangeStart,
                            $rangeEnd
                        );
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
                ->with('student')
                ->select(
                    'ID',
                    'StudentID',
                    'PackageID',
                    'scheduling_policy',
                    'StartDate',
                    'EndDate',
                    'SessionCount',
                    'ScheduleMode',
                    'week',
                    'time',
                    'week1',
                    'time1',
                    'week2',
                    'time2',
                    'week3',
                    'time3',
                    'week4',
                    'time4',
                    'week5',
                    'time5',
                    'week6',
                    'time6',
                    'SessionDuration',
                    'duration1',
                    'duration2',
                    'duration3',
                    'duration4',
                    'duration5',
                    'duration6'
                )
                ->get()
                ->keyBy('ID');
            $packageFallbackDaysByClassId = $this->buildPackageFallbackDaysMap($classes);

            $schedules = Schedule::where('branch_id', $branchId)
                ->whereNotNull('student_course_id')
                ->whereIn('student_course_id', $classIds)
                ->select('student_course_id', 'schedule_date', 'status')
                ->get();

            $sessions = ClassSession::whereIn('StudentClassID', $classIds)
                ->select('id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status')
                ->get();

            $projectionReader = app(SessionProjectionReadService::class);

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
                $class = $classes->get($id);
                if (isset($result[(string) $id]) && (!$class || ($class->ScheduleMode ?? '') !== 'date')) {
                    continue;
                }
                $isSessionMode = $class
                    && ($class->ScheduleMode ?? '') === 'count'
                    && (int) ($class->SessionCount ?? 0) > 0
                    && (string) ($class->scheduling_policy ?? 'auto_recurrence') !== ManualSessionBookingService::POLICY;
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
                    if (empty($daysOfWeek)) {
                        $daysOfWeek = $packageFallbackDaysByClassId[$id] ?? [];
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
                    $n = (int) $class->SessionCount;
                    $leaveSet = $leaveByClass[$id] ?? [];
                    $scheduledSet = $scheduledByClass[$id] ?? [];
                    $contractList = self::computeEffectiveSessionDates($startDate, $n, $daysOfWeek, $leaveSet, $scheduledSet);

                    // Regression guard (#440): when a count-mode course already has historical
                    // ClassSession rows but future scheduled rows are missing, we must not return
                    // "history only". Merge actual rows with contract dates so the UI can still
                    // show upcoming sessions.
                    $mergedSet = [];
                    foreach ($contractList as $date) {
                        $mergedSet[$date] = true;
                    }
                    foreach (array_keys($actualSessionSet) as $date) {
                        $mergedSet[$date] = true;
                    }
                    $list = array_keys($mergedSet);
                    sort($list);

                    if ($n > 0 && count($list) > $n) {
                        // Keep existing actual rows first, then fill remaining slots by contract order.
                        $selected = [];
                        $actualDates = array_keys($actualSessionSet);
                        sort($actualDates);
                        foreach ($actualDates as $date) {
                            $selected[$date] = true;
                            if (count($selected) >= $n) {
                                break;
                            }
                        }
                        if (count($selected) < $n) {
                            foreach ($contractList as $date) {
                                if (isset($selected[$date])) {
                                    continue;
                                }
                                $selected[$date] = true;
                                if (count($selected) >= $n) {
                                    break;
                                }
                            }
                        }
                        $list = array_keys($selected);
                        sort($list);
                    }
                    $result[(string) $id] = $this->buildSessionDatesSplit(
                        $projectionReader,
                        $id,
                        $list,
                        $sessions,
                        $class,
                        $rangeStart,
                        $rangeEnd
                    );
                    continue;
                }

                $set = [];
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
                if ($class && ($class->ScheduleMode ?? '') === 'date') {
                    $leaveSet = $leaveByClass[$id] ?? [];
                    $scheduledSet = $scheduledByClass[$id] ?? [];
                    $list = $this->computeMonthlyEffectiveSessionDates($class, $rangeStart, $rangeEnd, $leaveSet, $scheduledSet, $set);
                    $result[(string) $id] = $this->buildSessionDatesSplit(
                        $projectionReader,
                        $id,
                        $list,
                        $sessions,
                        $class,
                        $rangeStart,
                        $rangeEnd
                    );
                    continue;
                }
                sort($list);
                $n = (int) ($class->SessionCount ?? 0);
                if ($n > 0 && count($list) > $n) {
                    $list = array_slice($list, 0, $n);
                }
                $result[(string) $id] = $this->buildSessionDatesSplit(
                    $projectionReader,
                    $id,
                    $list,
                    $sessions,
                    $class,
                    $rangeStart,
                    $rangeEnd
                );
            }
        } catch (\Throwable $e) {
            // Laravel DB/Schedule may be empty or schema differs (e.g. branch uses Supabase only); return what we have
            return response()->json(empty($result) ? (object) [] : $result);
        }

        return response()->json($result);
    }

    /**
     * @param  list<string>  $effectiveDateList
     * @param  iterable<object>  $sessionRows
     * @return array{materialized: list<array<string, mixed>>, projected: list<array<string, mixed>>}
     */
    private function buildSessionDatesSplit(
        SessionProjectionReadService $reader,
        int $classId,
        array $effectiveDateList,
        iterable $sessionRows,
        ?StudentClass $class,
        string $rangeStart,
        string $rangeEnd
    ): array {
        $materialized = $reader->collectMaterializedFromRows($sessionRows, $classId, $rangeStart, $rangeEnd);
        $projected = $reader->buildProjectedFromEffectiveDates($classId, $effectiveDateList, $materialized, $class);

        return $reader->wrapCourseSplit($materialized, $projected);
    }

    /**
     * 月結制詳情顯示：以固定星期/時段推算查詢月份的日期，再與既有 ClassSession/schedules 合併。
     * Legacy 月結課可能沒有 EndDate/monthly_sessions，但仍有 week/time 契約欄位。
     *
     * @param  array<string, bool>  $leaveSet
     * @param  array<string, bool>  $scheduledSet
     * @param  array<string, bool>  $existingSet
     * @return array<int, string>
     */
    public function computeMonthlyEffectiveSessionDates(StudentClass $class, string $rangeStart, string $rangeEnd, array $leaveSet, array $scheduledSet, array $existingSet): array
    {
        $start = $this->normalizeDateString($class->StartDate ?? null) ?: $rangeStart;
        if ($start < $rangeStart) {
            $start = $rangeStart;
        }

        $end = $this->normalizeDateString($class->EndDate ?? null) ?: $rangeEnd;
        if ($end > $rangeEnd) {
            $end = $rangeEnd;
        }
        if ($end < $start) {
            $end = $start;
        }

        $weekdays = [];
        $candidates = [
            ['week', 'time'],
            ['week1', 'time1'],
            ['week2', 'time2'],
            ['week3', 'time3'],
            ['week4', 'time4'],
            ['week5', 'time5'],
            ['week6', 'time6'],
        ];
        foreach ($candidates as [$weekField, $timeField]) {
            $weekday = (int) ($class->{$weekField} ?? 0);
            $time = trim((string) ($class->{$timeField} ?? ''));
            if ($weekday >= 1 && $weekday <= 7 && $time !== '') {
                $weekdays[$weekday] = true;
            }
        }

        $set = $existingSet;
        $cursor = Carbon::parse($start . ' 12:00:00');
        $last = Carbon::parse($end . ' 12:00:00');
        while ($cursor->lte($last)) {
            $ymd = $cursor->toDateString();
            $dow = (int) $cursor->dayOfWeekIso;
            if (isset($weekdays[$dow]) && !isset($leaveSet[$ymd])) {
                $set[$ymd] = true;
            }
            if (isset($scheduledSet[$ymd])) {
                $set[$ymd] = true;
            }
            $cursor->addDay();
        }

        foreach (array_keys($set) as $date) {
            if ($date < $rangeStart || $date > $rangeEnd) {
                unset($set[$date]);
            }
        }

        $list = array_keys($set);
        sort($list);
        return $list;
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

    /**
     * For package courses missing contract weekday fields, fallback to a sibling member
     * in the same package that has valid weekdays configured.
     *
     * @param  \Illuminate\Support\Collection<int, StudentClass>  $classes
     * @return array<int, array<int, int>>
     */
    private function buildPackageFallbackDaysMap($classes): array
    {
        $fallback = [];
        if ($classes->isEmpty()) {
            return $fallback;
        }

        $daysByClass = [];
        $classIdsByPackageStudent = [];
        foreach ($classes as $class) {
            $classId = (int) ($class->ID ?? 0);
            if ($classId <= 0) {
                continue;
            }
            $days = [];
            foreach (['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $wf) {
                $d = (int) ($class->{$wf} ?? 0);
                if ($d >= 1 && $d <= 7 && !in_array($d, $days, true)) {
                    $days[] = $d;
                }
            }
            sort($days);
            $daysByClass[$classId] = $days;

            $packageId = (int) ($class->PackageID ?? 0);
            $studentId = (int) ($class->StudentID ?? 0);
            if ($packageId > 0 && $studentId > 0) {
                $key = $packageId . ':' . $studentId;
                $classIdsByPackageStudent[$key][] = $classId;
            }
        }

        foreach ($classIdsByPackageStudent as $classIdsInPackage) {
            $packageFallback = [];
            foreach ($classIdsInPackage as $cid) {
                $candidate = $daysByClass[$cid] ?? [];
                if (!empty($candidate)) {
                    $packageFallback = $candidate;
                    break;
                }
            }
            if (empty($packageFallback)) {
                continue;
            }
            foreach ($classIdsInPackage as $cid) {
                if (empty($daysByClass[$cid] ?? [])) {
                    $fallback[$cid] = $packageFallback;
                }
            }
        }

        return $fallback;
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
            'Memo' => 'nullable|string|max:' . StudentClass::MEMO_MAX_LENGTH,
            'Charge' => 'nullable|integer',
            'Pay' => 'nullable|integer',
            'PayDate' => 'nullable|date',
            'Paid' => 'nullable|integer',
            'Stop' => 'nullable|integer',
            'Disconunt' => 'nullable|integer',
            'Rate' => 'nullable|numeric',
            'LearnTimeID' => 'nullable|integer',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'settlement_day' => 'nullable|integer|min:1|max:31',
            'monthly_sessions' => 'nullable|integer|min:0',
            'ScheduleMode' => 'required|in:date,count',
            'SessionCount' => 'nullable|integer',
            'RemainingSessions' => 'nullable|integer',
            'SessionDuration' => 'nullable|integer|min:30',

            'ScheduleSlots' => 'nullable|array',
            'ScheduleSlots.*.weekday' => 'required_with:ScheduleSlots|integer|min:0|max:7',
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

        // Store ISO weekdays (1-7) in week columns — DB convention is 7=Sunday, never 0.
        $scheduleSlots = array_map(function ($slot) {
            $slot['weekday'] = self::isoWeekday($slot['weekday'] ?? 0);

            return $slot;
        }, $data['ScheduleSlots'] ?? []);
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
                    $classSession = app(ClassSessionMaterializationService::class)->upsertSlot($sess)['session'];
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
        $oldScheduleMode = (string) ($studentClass->ScheduleMode ?? 'count');

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

        // Payment Gate (#799)：列表顯示的繳費狀態 = StudentClass.Paid OR 非作廢帳單付款。
        // 帳單仍有收款入帳時，改 Paid=0 只會在重新整理後被帳單蓋回「已繳費」（靜默失敗）。
        // 因此「改為未繳費」在有有效收款紀錄時直接 409 擋下並導引到收費頁作廢，
        // 涵蓋兩條降級路徑：顯式 payment_status=unpaid 與編輯表單清空 paid_at。
        $wantsUnpaid = ($rawInput['payment_status'] ?? null) === 'unpaid'
            || (array_key_exists('paid_at', $rawInput)
                && empty($rawInput['paid_at'])
                && ($rawInput['payment_status'] ?? null) !== 'paid');
        if ($wantsUnpaid) {
            $activePayment = DB::table('Invoice')
                ->join('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
                ->where('Invoice.StudentClassID', (int) $studentClass->getKey())
                ->where(function ($q) {
                    $q->whereNull('Invoice.Status')->orWhere('Invoice.Status', '!=', 'void');
                })
                ->selectRaw('COALESCE(SUM(Payment.Amount), 0) AS total_amount, MAX(Payment.PaidAt) AS last_paid_at')
                ->first();
            if ($activePayment && $activePayment->last_paid_at !== null) {
                $lastPaidDate = substr((string) $activePayment->last_paid_at, 0, 10);
                return response()->json([
                    'message' => "此課程在 {$lastPaidDate} 已有收款入帳紀錄，無法直接改為未繳費。"
                        . '若該筆收款是誤登錄，請至「收費」頁將該帳單作廢，狀態會自動恢復為未繳費。',
                    'code' => 'payment_record_locked',
                    'warnings' => [
                        'total_paid_amount' => (int) $activePayment->total_amount,
                        'last_paid_at' => $lastPaidDate,
                    ],
                ], 409);
            }
        }

        $mapped = $this->mapFrontendPayload($request);
        if (array_key_exists('scheduling_policy', $mapped)
            && !in_array($mapped['scheduling_policy'], ['auto_recurrence', ManualSessionBookingService::POLICY], true)
        ) {
            return response()->json(['message' => 'Invalid scheduling policy'], 422);
        }
        $effectivePolicy = (string) ($mapped['scheduling_policy'] ?? ($studentClass->scheduling_policy ?? 'auto_recurrence'));
        $effectiveScheduleMode = (string) ($mapped['ScheduleMode'] ?? ($studentClass->ScheduleMode ?? 'count'));
        if ($effectivePolicy === ManualSessionBookingService::POLICY
            && $effectiveScheduleMode !== 'count'
        ) {
            return response()->json([
                'message' => 'Manual occurrence scheduling requires a session course',
            ], 422);
        }
        $scheduleSlotsForRebuild = is_array($mapped['ScheduleSlots'] ?? null) ? $mapped['ScheduleSlots'] : [];

        if (array_key_exists('Memo', $mapped) && is_string($mapped['Memo'])) {
            $memoMax = StudentClass::MEMO_MAX_LENGTH;
            if (mb_strlen($mapped['Memo']) > $memoMax) {
                return response()->json([
                    'message' => "備註太長，請刪到 {$memoMax} 字以內再存。",
                    'code' => 'memo_too_long',
                ], 422);
            }
        }

        // Remove ScheduleSlots and ID references to prevent overwriting critical relationships
        unset($mapped['ScheduleSlots'], $mapped['StudentID'], $mapped['GradeID'], $mapped['by1']);

        if ($studentClass->isPartOfPackage()) {
            unset($mapped['RemainingSessions']);
        }

        // RFC non-standard duration: validate + guard the billing contract fields.
        if ($contractError = $this->guardBillingContractUpdate($studentClass, $mapped)) {
            return $contractError;
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
        $oldRemainingSessionsSnapshot = (int) ($studentClass->RemainingSessions ?? 0);
        $oldTotalHoursSnapshot = (int) ($studentClass->TotalHours ?? 0);
        $oldTeacherSnapshot = (int) ($studentClass->TeacherID ?? 0);
        $oldRateUnitSnapshot = strtolower(trim((string) ($studentClass->rate_unit ?? 'session')));
        if (!in_array($oldRateUnitSnapshot, ['session', 'hour'], true)) {
            $oldRateUnitSnapshot = 'session';
        }

        $studentClass->update($mapped);
        $studentClass->refresh();

        $billingModeConversion = null;
        if (array_key_exists('ScheduleMode', $mapped)) {
            $newScheduleMode = (string) ($studentClass->ScheduleMode ?? 'count');
            if ($oldScheduleMode !== $newScheduleMode) {
                $actor = $request->attributes->get('auth_user');
                $billingModeConversion = $this->billingModeConversionArchive->archiveAfterScheduleModeChange(
                    $studentClass,
                    $oldScheduleMode,
                    $newScheduleMode,
                    $actor?->id
                );
            }
        }

        // #1811: manual SessionCount / RemainingSessions edits need who/when/old→new evidence.
        $sessionCountTouched = array_key_exists('SessionCount', $mapped)
            && (int) ($studentClass->SessionCount ?? 0) !== $oldSessionCountSnapshot;
        $remainingTouched = array_key_exists('RemainingSessions', $mapped)
            && (int) ($studentClass->RemainingSessions ?? 0) !== $oldRemainingSessionsSnapshot;
        if ($sessionCountTouched || $remainingTouched) {
            $actor = $request->attributes->get('auth_user');
            $campusId = (int) (optional($studentClass->student)->CampusID ?: 0) ?: null;
            SecurityAuditEvent::append(
                'student_class.session_balance_adjust',
                'success',
                [
                    'actor_type' => 'user',
                    'actor_id' => $actor?->id,
                    'subject_type' => 'student_class',
                    'subject_id' => $studentClass->getKey(),
                    'campus_id' => $campusId,
                ],
                [
                    'old_session_count' => $oldSessionCountSnapshot,
                    'new_session_count' => (int) ($studentClass->SessionCount ?? 0),
                    'old_remaining_sessions' => $oldRemainingSessionsSnapshot,
                    'new_remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
                    'reason_code' => 'manual_update',
                ]
            );
        }

        if (array_key_exists('TeacherID', $mapped)) {
            $newTeacherId = (int) ($studentClass->TeacherID ?? 0);
            if ($newTeacherId > 0 && $newTeacherId !== $oldTeacherSnapshot) {
                $courseIdForTeacherSync = (int) $studentClass->ID;
                // in-app #207: pin past attended/history to former teacher BEFORE
                // future schedule rows move to the new contract teacher.
                $this->pinPastSessionsToFormerTeacherAfterContractTeacherChange(
                    $courseIdForTeacherSync,
                    $oldTeacherSnapshot,
                    $newTeacherId
                );
                $this->syncFutureScheduleTeachersAfterContractTeacherChange(
                    $courseIdForTeacherSync,
                    $oldTeacherSnapshot,
                    $newTeacherId
                );
            }
        }

        if (array_key_exists('paid_at', $rawInput)) {
            $this->syncLatestPaymentDateForCourse($studentClass, $rawInput['paid_at'] ?? null);
        }

        if (array_key_exists('Stop', $mapped) && (int) ($mapped['Stop'] ?? 0) === 1) {
            $this->cancelFutureScheduledSessions($studentClass, null);
            $studentClass->refresh();
        }

        // Rate 或 SessionCount 異動時同步 Charge（總費用快照），
        // 並保留原本由單堂時間調整累積的 delta（老 Charge − 老 Rate×老數量），
        // 避免老師／主任調漲調降課程費率時，把已經手動微調過的金額一併洗掉。
        // #798：delta 只在課程實際存在單堂時間調整（session_charge）時才保留；
        // 否則視為錯誤存量資料，直接以 費率×數量 重算，讓主任能從 UI 改回正確金額。
        if (isset($mapped['Rate']) || isset($mapped['SessionCount'])) {
            $oldBase = $oldRateUnitSnapshot === 'hour'
                ? (int) round($oldRateSnapshot * $oldTotalHoursSnapshot)
                : (int) round($oldRateSnapshot * $oldSessionCountSnapshot);
            $hasSessionChargeAdjustments = DB::table('ClassSession')
                ->where('StudentClassID', (int) $studentClass->getKey())
                ->whereNotNull('session_charge')
                ->exists();
            $preservedDelta = $hasSessionChargeAdjustments ? ($oldChargeSnapshot - $oldBase) : 0;

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
                // A round-trip edit (for example memo/payment metadata) may
                // carry the same SessionCount without changing the schedule.
                // Only an actual count change is allowed to cancel/extend
                // projected sessions; otherwise saving a note can pre-plan new
                // lessons unexpectedly (#231).
                if ($sessionCountChanged) {
                    $this->cancelExcessScheduledSessions((int) $studentClass->ID, $newCount);
                    if ((string) ($studentClass->scheduling_policy ?? 'auto_recurrence') !== ManualSessionBookingService::POLICY) {
                        $this->extendSessionsIfNeeded($studentClass, $newCount);
                    }
                }
            }
        }

        // 主任「強制重建未上堂次」：直接執行安全部分重建，不走完整重建流程
        if ($request->boolean('force_partial_rebuild', false)) {
            if ((string) ($studentClass->scheduling_policy ?? 'auto_recurrence') === ManualSessionBookingService::POLICY) {
                return response()->json(array_merge($studentClass->fresh()->toArray(), [
                    'session_sync' => [
                        'rebuilt' => false,
                        'reason' => 'manual_occurrence_policy',
                    ],
                ]));
            }
            $slots = $this->resolveScheduleSlotsForRebuild($studentClass, $scheduleSlotsForRebuild);
            if (!empty($slots)) {
                $durationMinutes = max(30, (int) ($studentClass->SessionDuration ?? 120));
                $updatedCount = $this->syncFutureScheduledSessionTimes(
                    (int) $studentClass->ID,
                    $slots,
                    $durationMinutes
                );
                return response()->json(array_merge($studentClass->fresh()->toArray(), [
                    'session_sync' => [
                        'rebuilt'                 => false,
                        'reason'                  => 'force_partial_rebuild',
                        'updated_future_sessions' => $updatedCount,
                        'reconcile_skipped'       => true,
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

        // When the request explicitly carries fixed schedule fields, those
        // fields are the contract source of truth. Future ClassSession rows may
        // be sparse near the end of a course (e.g. only one Wednesday remains),
        // so reverse-reconciling from them would erase newly edited weekdays.
        if ($scheduleFieldsPresent) {
            if (($sessionSync['reason'] ?? '') === 'history_exists'
                && (
                    !array_key_exists('updated_future_sessions', $sessionSync)
                    || (int) ($sessionSync['updated_future_sessions'] ?? 0) === 0
                )
            ) {
                $sessionSync['reconcile_skipped'] = true;
                $sessionSync['warning'] = '未來堂次因狀態鎖定未更新時間，課程主檔已儲存新時段但堂次仍為舊時段，請檢查堂次狀態。';
            }
        } elseif ((string) ($studentClass->scheduling_policy ?? 'auto_recurrence') !== ManualSessionBookingService::POLICY) {
            $this->reconcileWeekTimeFieldsFromSessions($studentClass);
        }

        $monthlySessionSync = $this->ensureMonthlyFutureScheduledSessions($studentClass, $scheduleSlotsForRebuild);
        if (($monthlySessionSync['created_sessions'] ?? 0) > 0) {
            $sessionSync['monthly_future_sessions_created'] = (int) $monthlySessionSync['created_sessions'];
        }

        $payload = $studentClass->toArray();
        $payload['session_sync'] = $sessionSync;
        if ($billingModeConversion !== null) {
            $payload['billing_mode_conversion'] = $billingModeConversion;
        }

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
                        $cs = app(ClassSessionMaterializationService::class)->upsertSlot([
                            'StudentClassID' => (int) $id,
                            'SessionDate' => $sessionDate,
                            'StartTime' => $sTime,
                            'EndTime' => $eTime,
                            'Status' => $sessionDate <= $today ? 'completed' : 'scheduled',
                        ])['session'];
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

    /**
     * Correct an unpaid count-based contract after deduction history exists.
     *
     * The ordinary update endpoint deliberately remains locked once history is
     * present. This named endpoint is the auditable exception for a count that
     * was entered incorrectly before collection.
     */
    public function billingCorrection(Request $request, StudentClass $studentClass)
    {
        if ($accessError = $this->authorizeStudentClassAccess($studentClass)) {
            return $accessError;
        }

        $payload = $request->validate([
            'new_session_count' => ['required', 'integer', 'min:1'],
            'new_charge' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'count') {
            return response()->json([
                'message' => '只有堂數制課程可以使用未收款堂數更正。',
                'code' => 'billing_correction_count_mode_only',
            ], 422);
        }

        if ($studentClass->isPartOfPackage()) {
            return response()->json([
                'message' => '共用課程包請使用方案調整流程，不可單獨更正課程堂數。',
                'code' => 'billing_correction_package_forbidden',
            ], 422);
        }

        if ((int) ($studentClass->Paid ?? 0) === 1) {
            return response()->json([
                'message' => '此課程已標記收款，請先走帳務更正／作廢流程。',
                'code' => 'billing_correction_paid_locked',
            ], 409);
        }

        $classId = (int) $studentClass->getKey();
        $activePayment = DB::table('Invoice')
            ->leftJoin('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
            ->where('Invoice.StudentClassID', $classId)
            ->where(function ($q) {
                $q->whereNull('Invoice.Status')->orWhere('Invoice.Status', '!=', 'void');
            })
            ->where(function ($q) {
                $q->where('Invoice.PaidAmount', '>', 0)
                    ->orWhere(function ($payment) {
                        $payment->where('Payment.Amount', '>', 0)
                            ->where(function ($method) {
                                $method->whereNull('Payment.Method')->orWhere('Payment.Method', '!=', 'void');
                            });
                    });
            })
            ->exists();
        if ($activePayment) {
            return response()->json([
                'message' => '此課程已有有效收款紀錄，請先至帳務流程作廢或更正帳單。',
                'code' => 'billing_correction_payment_locked',
            ], 409);
        }

        $hasPendingReport = PaymentReport::query()
            ->where('StudentClassID', $classId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();
        if ($hasPendingReport) {
            return response()->json([
                'message' => '此課程已有待處理或已確認的繳費回報，請先完成或作廢該筆回報。',
                'code' => 'billing_correction_payment_report_locked',
            ], 409);
        }

        $newCount = (int) $payload['new_session_count'];
        $newCharge = (int) $payload['new_charge'];
        $oldCount = (int) ($studentClass->SessionCount ?? 0);
        $oldCharge = (int) ($studentClass->Charge ?? 0);
        $rateUnit = strtolower(trim((string) ($studentClass->rate_unit ?? 'session')));
        if ($rateUnit !== 'session') {
            return response()->json([
                'message' => '只有按堂計費課程可以使用此更正流程。',
                'code' => 'billing_correction_session_rate_only',
            ], 422);
        }

        $rate = (float) ($studentClass->getAttribute('Rate') ?? 0);
        $expectedCharge = (int) round($rate * $newCount);
        if ($newCharge !== $expectedCharge) {
            return response()->json([
                'message' => "更正金額必須等於單堂 {$rate} × {$newCount} 堂 = {$expectedCharge} 元。",
                'code' => 'billing_correction_charge_mismatch',
                'expected_charge' => $expectedCharge,
            ], 422);
        }

        $observedUsed = (int) (SessionDeductionService::batchObservedUsedSessions([$classId])[$classId] ?? 0);
        if ($newCount < $observedUsed) {
            return response()->json([
                'message' => "更正後堂數（{$newCount}）不可少於已使用 {$observedUsed} 堂；已發生的扣堂紀錄不會被改寫。"
                    . "如需調整收費金額，請改到一般課程編輯畫面手動下修「總費用」（堂數維持不變，不影響已發生的扣堂紀錄）。",
                'code' => 'billing_correction_below_observed_usage',
                'observed_used_sessions' => $observedUsed,
                'next_step' => 'edit_charge_only',
            ], 422);
        }

        if ($newCount >= $oldCount) {
            return response()->json([
                'message' => '此流程只允許未收款課程減少堂數更正；增加堂數請使用加購／續報。',
                'code' => 'billing_correction_reduction_only',
            ], 422);
        }

        $result = DB::transaction(function () use ($classId, $newCount, $newCharge, $payload, $oldCount, $oldCharge, $studentClass, $observedUsed) {
            $locked = StudentClass::query()->where('ID', $classId)->lockForUpdate()->first();
            if (!$locked) {
                abort(404);
            }
            if ((int) ($locked->Paid ?? 0) === 1) {
                abort(response()->json([
                    'message' => '此課程在處理期間已被標記收款，請重新整理後再操作。',
                    'code' => 'billing_correction_paid_locked',
                ], 409));
            }

            $locked->SessionCount = $newCount;
            $locked->Charge = $newCharge;
            $locked->save();

            // An unpaid invoice may already exist even though no payment was
            // entered. Keep the future payment report and receipt on the same
            // corrected amount; paid invoices were rejected above.
            $adjustedInvoiceCount = 0;
            $openInvoices = Invoice::query()
                ->where('StudentClassID', $classId)
                ->where(function ($q) {
                    $q->whereNull('Status')->orWhere('Status', '!=', 'void');
                })
                ->lockForUpdate()
                ->get();
            foreach ($openInvoices as $invoice) {
                if ((int) ($invoice->PaidAmount ?? 0) !== 0) {
                    abort(response()->json([
                        'message' => '此課程已有有效收款紀錄，請先至帳務流程作廢或更正帳單。',
                        'code' => 'billing_correction_payment_locked',
                    ], 409));
                }
                $invoice->TotalAmount = $newCharge;
                $invoice->save();
                InvoiceItem::query()
                    ->where('InvoiceID', $invoice->id)
                    ->where('StudentClassID', $classId)
                    ->update(['Amount' => $newCharge]);
                $adjustedInvoiceCount++;
            }

            $this->cancelExcessScheduledSessions($classId, $newCount);
            SessionDeductionService::recomputeCounters($classId);
            $fresh = $locked->fresh();

            SecurityAuditEvent::append(
                'student_class.billing_contract_correction',
                'success',
                [
                    'campus_id' => $studentClass->student?->CampusID,
                    'actor_type' => 'user',
                    'actor_id' => request()->attributes->get('auth_user')?->id,
                    'subject_type' => 'student_class',
                    'subject_id' => $classId,
                ],
                [
                    'old_session_count' => $oldCount,
                    'new_session_count' => $newCount,
                    'old_charge' => $oldCharge,
                    'new_charge' => $newCharge,
                    'old_remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
                    'new_remaining_sessions' => (int) ($fresh->RemainingSessions ?? 0),
                    'reason_code' => 'post_deduction_unpaid_billing_correction',
                    'outcome' => 'success',
                ]
            );

            return [
                'student_class_id' => $classId,
                'old_session_count' => $oldCount,
                'new_session_count' => $newCount,
                'old_charge' => $oldCharge,
                'new_charge' => $newCharge,
                'observed_used_sessions' => $observedUsed,
                'remaining_sessions' => (int) ($fresh->RemainingSessions ?? 0),
                'payment_status' => 'unpaid',
                'reason' => $payload['reason'],
                'adjusted_invoice_count' => $adjustedInvoiceCount,
            ];
        });

        return response()->json($result);
    }

    public function confirmPayment(StudentClass $studentClass)
    {
        $auth = $this->authorizeStudentClassAccess($studentClass);
        if ($auth !== null) {
            return $auth;
        }

        $studentClass->Paid = 1;
        $studentClass->PayDate = now()->toDateString();
        $studentClass->save();

        return response()->json(['message' => '已確認繳費', 'class_id' => $studentClass->ID]);
    }

    /**
     * 續報預覽：只回傳將發生的課程 / 帳單 / 排課影響，不寫入資料。
     * POST /api/v1/student-classes/{studentClass}/renewal-preview
     */
    public function renewalPreview(Request $request, StudentClass $studentClass)
    {
        $auth = $this->authorizeStudentClassAccess($studentClass);
        if ($auth !== null) {
            return $auth;
        }

        $data = $request->validate([
            'mode'       => 'required|in:purchase_batch,renew_monthly',
            'sessions'   => 'nullable|integer|min:1|max:500',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'months'     => 'nullable|integer|min:1|max:24',
        ]);

        $preview = $this->buildRenewalPreview($studentClass, $data);

        return response()->json($preview, $preview['severity'] === 'blocked' ? 422 : 200);
    }

    /**
     * 續報確認：重新計算 preview，state 沒變才委派既有續報邏輯。
     * POST /api/v1/student-classes/{studentClass}/renewal-confirm
     */
    public function renewalConfirm(Request $request, StudentClass $studentClass)
    {
        $auth = $this->authorizeStudentClassAccess($studentClass);
        if ($auth !== null) {
            return $auth;
        }

        $data = $request->validate([
            'preview_id' => 'required|string|max:128',
            'state_hash' => 'required|string|max:128',
            'mode'       => 'required|in:purchase_batch,renew_monthly',
            'payload'    => 'required|array',
        ]);

        return DB::transaction(function () use ($request, $studentClass, $data) {
            $lockedStudentClass = StudentClass::where('ID', $studentClass->ID)
                ->lockForUpdate()
                ->firstOrFail();

            $payload = array_merge($data['payload'], ['mode' => $data['mode']]);
            $preview = $this->buildRenewalPreview($lockedStudentClass, $payload);

            if ($preview['state_hash'] !== $data['state_hash'] || $preview['preview_id'] !== $data['preview_id']) {
                return response()->json([
                    'message' => '課程狀態已變更，請重新預覽後再確認。',
                    'preview' => $preview,
                ], 409);
            }

            if ($preview['severity'] === 'blocked') {
                return response()->json([
                    'message' => '此續報目前不可執行。',
                    'preview' => $preview,
                ], 422);
            }

            if ($data['mode'] === 'purchase_batch') {
                $originalInput = $request->all();
                $request->replace([
                    'sessions'   => $payload['sessions'] ?? null,
                    'start_date' => $payload['start_date'] ?? null,
                    'mode'       => 'new_purchase',
                ]);
                try {
                    $response = $this->purchaseBatch($request, $lockedStudentClass);
                } finally {
                    $request->replace($originalInput);
                }
            } else {
                $originalInput = $request->all();
                $request->replace([
                    'end_date' => $preview['proposed_course']['end_date'] ?? ($payload['end_date'] ?? null),
                    'months'   => $payload['months'] ?? null,
                ]);
                try {
                    $response = $this->renewMonthly($request, $lockedStudentClass);
                } finally {
                    $request->replace($originalInput);
                }
            }

            $status = $response->getStatusCode();
            $result = method_exists($response, 'getData') ? $response->getData(true) : [];
            if ($status >= 400) {
                return $response;
            }

            return response()->json([
                'receipt_id' => substr(hash('sha256', ($preview['preview_id'] ?? '') . '|' . now()->timestamp), 0, 16),
                'message' => $result['message'] ?? '續報已完成',
                'mode' => $data['mode'],
                'preview_id' => $preview['preview_id'],
                'source_course' => $result['source_course'] ?? $preview['source_course'],
                'new_course' => $result['new_course'] ?? null,
                'invoice' => $data['mode'] === 'renew_monthly'
                    ? ($result['invoice'] ?? ($preview['billing']['invoice'] ?? null))
                    : null,
                'schedule' => [
                    'created_sessions' => $result['created_sessions'] ?? ($preview['schedule']['created_sessions'] ?? 0),
                    'first_session_date' => $result['new_course']['first_session_date'] ?? ($preview['schedule']['first_session_date'] ?? null),
                    'last_session_date' => $result['new_course']['last_session_date'] ?? ($preview['schedule']['last_session_date'] ?? null),
                ],
                'next_actions' => $data['mode'] === 'purchase_batch'
                    ? ['view_new_course', 'record_payment']
                    : ['view_invoices', 'record_payment'],
            ], $status);
        });
    }

    /**
     * 月結制課程續約：建立新一期課程，舊課程結算。
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

        return DB::transaction(function () use ($studentClass, $newEndDate) {
            $studentClass = StudentClass::where('ID', $studentClass->ID)
                ->lockForUpdate()
                ->firstOrFail();

            $sourceEndDate = $this->normalizeDateString($studentClass->EndDate ?? null);
            $newStartDate = $sourceEndDate
                ? Carbon::parse($sourceEndDate)->addDay()->toDateString()
                : Carbon::today()->toDateString();

            if ($newEndDate < $newStartDate) {
                return response()->json([
                    'message' => '新到期日必須晚於新一期開始日。',
                    'errors' => ['end_date' => ['新到期日不可早於舊課程結束後的下一天。']],
                ], 422);
            }

            $duplicate = $this->findDuplicateMonthlyRenewal($studentClass, $newStartDate, $newEndDate);
            if ($duplicate !== null) {
                return response()->json([
                    'message' => '偵測到相同學生、科目與期間的月結續報課程，請先確認是否已續報過。',
                    'errors' => ['duplicate' => ['已存在相同期間的月結續報課程。']],
                    'duplicate_course' => [
                        'id' => (int) $duplicate->ID,
                        'start_date' => $this->normalizeDateString($duplicate->StartDate),
                        'end_date' => $this->normalizeDateString($duplicate->EndDate),
                        'paid' => (int) ($duplicate->Paid ?? 0),
                    ],
                ], 409);
            }

            $rate = (float) ($studentClass->Rate ?? 0);
            $rateUnit = strtolower(trim((string) ($studentClass->rate_unit ?? 'session')));
            if (!in_array($rateUnit, ['session', 'hour'], true)) {
                $rateUnit = 'session';
            }
            $globalDur = max(30, (int) ($studentClass->SessionDuration ?? 120));
            $slots = $this->resolveScheduleSlotsForRebuild($studentClass);
            $periodSessions = !empty($slots)
                ? $this->buildSessionsFromWeeklySchedule(
                    (int) $studentClass->getKey(),
                    $newStartDate,
                    $newEndDate,
                    $slots,
                    $globalDur
                )
                : [];
            $periodSessionCount = count($periodSessions);
            if ($periodSessionCount <= 0) {
                $periodSessionCount = max(0, (int) ($studentClass->monthly_sessions ?? 0));
            }
            $periodTotalHours = !empty($periodSessions)
                ? (int) round(array_reduce($periodSessions, function ($carry, $session) {
                    $start = substr((string) ($session['StartTime'] ?? ''), 0, 5);
                    $end = substr((string) ($session['EndTime'] ?? ''), 0, 5);
                    if ($start === '' || $end === '') {
                        return $carry;
                    }
                    $startM = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
                    $endM = ((int) substr($end, 0, 2)) * 60 + (int) substr($end, 3, 2);
                    return $carry + max(0, $endM - $startM);
                }, 0) / 60)
                : (int) round(($periodSessionCount * $globalDur) / 60);
            $periodCharge = $this->calculateCourseChargeFromRate(
                $rate,
                $rateUnit,
                $periodSessionCount,
                $periodTotalHours
            );
            if ($periodCharge <= 0) {
                $periodCharge = max(0, (int) ($studentClass->Charge ?? 0));
            }

            $newPayload = [
                'StudentID' => (int) $studentClass->StudentID,
                'GradeID' => (int) ($studentClass->GradeID ?? 1),
                'SubjectID' => (int) ($studentClass->SubjectID ?? 1),
                'TeacherID' => (int) ($studentClass->TeacherID ?? 0),
                'by1' => (int) ($studentClass->by1 ?? 1),
                'Period' => (int) ($studentClass->Period ?? 4),
                'StartDate' => $newStartDate,
                'EndDate' => $newEndDate,
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
                'TotalHours' => $periodTotalHours,
                'Memo' => $studentClass->Memo,
                'Charge' => $periodCharge,
                'Pay' => 0,
                'PayDate' => null,
                'Paid' => 0,
                'Disconunt' => $studentClass->Disconunt,
                'Rate' => $rate,
                'rate_unit' => $rateUnit,
                'LearnTimeID' => $studentClass->LearnTimeID,
                'room_id' => $studentClass->room_id,
                'settlement_day' => $studentClass->settlement_day,
                'monthly_sessions' => $studentClass->monthly_sessions,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'date',
                'SessionCount' => $periodSessionCount,
                'SessionDuration' => $globalDur,
                'RemainingSessions' => $periodSessionCount,
                'ClassType' => $studentClass->ClassType ?: 'one_on_one',
                'UsedSessions' => 0,
            ];

            $newCourse = $this->createStudentClassRecordResilient($newPayload);
            $newCourse->refresh();

            // Close and cancel the old period before materializing the new
            // period. A legacy source course may contain future rows beyond
            // EndDate; leaving it active during generation makes the new
            // renewal look like a real student overlap.
            $studentClass->Stop = 1;
            $studentClass->closed_reason = 'settled';
            $studentClass->save();
            $cancelled = $this->cancelFutureScheduledSessions($studentClass, 'settled');
            $studentClass->refresh();

            $sessionSync = $this->ensureMonthlyFutureScheduledSessions($newCourse);

            $billingPeriod = Carbon::parse($newStartDate)->format('Y-m');
            $totalAmount = max(0, (int) ($newCourse->Charge ?? 0));
            $dueDay = max(1, min(31, (int) ($newCourse->settlement_day ?? 15)));
            $dueDate = Carbon::parse($newStartDate)->startOfMonth()->addDays($dueDay - 1)->toDateString();

            $invoice = Invoice::create([
                'StudentID'      => (int) $newCourse->StudentID,
                'StudentClassID' => (int) $newCourse->ID,
                'IssueDate'      => Carbon::today()->toDateString(),
                'DueDate'        => $dueDate,
                'TotalAmount'    => $totalAmount,
                'PaidAmount'     => 0,
                'ScheduleModeAtIssue' => $newCourse->ScheduleMode,
                'Status'         => 'unpaid',
                'Note'           => '',
                'billing_period' => $billingPeriod,
            ]);

            $periodLabel = Carbon::parse($newStartDate)->locale('zh_TW')->isoFormat('YYYY年M月');
            InvoiceItem::create([
                'InvoiceID'   => $invoice->id,
                'Description' => '月結費用 ' . $periodLabel,
                'Amount'      => $totalAmount,
                'PeriodStart' => $newStartDate,
                'PeriodEnd'   => $newEndDate,
            ]);

            return response()->json([
                'message' => '已建立月結新一期課程，舊期已結算',
                'mode' => 'renew_monthly',
                'source_closed' => true,
                'cancelled_source_sessions' => $cancelled,
                'session_sync' => $sessionSync,
                'source_course' => [
                    'id' => (int) $studentClass->ID,
                    'start_date' => $this->normalizeDateString($studentClass->StartDate),
                    'end_date' => $this->normalizeDateString($studentClass->EndDate),
                    'paid' => (int) ($studentClass->Paid ?? 0),
                    'stop' => (int) ($studentClass->Stop ?? 0),
                    'closed_reason' => $studentClass->closed_reason,
                ],
                'new_course' => [
                    'id' => (int) $newCourse->ID,
                    'start_date' => $this->normalizeDateString($newCourse->StartDate),
                    'end_date' => $this->normalizeDateString($newCourse->EndDate),
                    'settlement_day' => $newCourse->settlement_day,
                    'monthly_sessions' => $newCourse->monthly_sessions,
                    'schedule_mode' => $newCourse->ScheduleMode,
                    'paid' => (int) ($newCourse->Paid ?? 0),
                ],
                'invoice' => [
                    'id' => (int) $invoice->id,
                    'billing_period' => $invoice->billing_period,
                    'status' => $invoice->Status,
                    'total_amount' => (int) $invoice->TotalAmount,
                    'due_date' => $this->normalizeDateString($invoice->DueDate),
                ],
            ], 201);
        });
    }

    /**
     * FR-007：回傳月結課程的逐期帳單列表。
     * GET /api/v1/student-classes/{studentClass}/invoices
     */
    public function invoices(Request $request, StudentClass $studentClass)
    {
        $auth = $this->authorizeStudentClassAccess($studentClass);
        if ($auth !== null) {
            return $auth;
        }

        // account_last5 (匯款後五碼) follows the same PII-minimization precedent as
        // PaymentReportController::index() — director/super_admin only, not teacher.
        $role = $request->attributes->get('auth_role');
        $canSeeAccountLast5 = $role !== 'teacher';

        // GET /payment-reports/{id}/receipt lives in the role:director-only route
        // group (routes/api.php ~line 260) — teacher is never in that group, so a
        // teacher's receipt fetch always 403s regardless of anything computed here.
        // Expose that as an explicit, authoritative capability instead of letting the
        // frontend infer permission from account_last5 or any other unrelated field.
        $canViewReceipt = $role !== 'teacher';

        $invoices = Invoice::where('StudentClassID', $studentClass->ID)
            ->notVoided()
            ->with(['payments' => function ($query) {
                $query->select(['id', 'InvoiceID', 'Amount', 'PaidAt', 'Method', 'Note', 'payment_report_id'])
                    ->orderBy('PaidAt')
                    ->orderBy('id');
            }])
            ->orderByRaw("COALESCE(billing_period, DATE_FORMAT(IssueDate, '%Y-%m')) DESC")
            ->get(['id', 'StudentClassID', 'billing_period', 'IssueDate', 'DueDate', 'TotalAmount', 'PaidAmount', 'Status']);

        $payments = $invoices->flatMap(fn ($invoice) => $invoice->payments);
        $paymentIds = $payments->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $reportIds = $payments->pluck('payment_report_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $reports = empty($paymentIds) && empty($reportIds)
            ? collect()
            : PaymentReport::query()
                ->where(function ($query) use ($paymentIds, $reportIds) {
                    if (!empty($paymentIds)) {
                        $query->whereIn('payment_id', $paymentIds);
                    }
                    if (!empty($reportIds)) {
                        $method = empty($paymentIds) ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('id', $reportIds);
                    }
                })
                ->get(['id', 'payment_id', 'status', 'payment_date', 'account_last5']);
        $reportsByPaymentId = $reports->whereNotNull('payment_id')->keyBy('payment_id');
        $reportsById = $reports->keyBy('id');

        return response()->json([
            'invoices' => $invoices->map(function ($inv) use ($reportsByPaymentId, $reportsById, $studentClass, $canSeeAccountLast5, $canViewReceipt) {
                $effectivePayments = $inv->payments
                    ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) > 0 && (string) ($payment->Method ?? '') !== 'void')
                    ->values();
                $voidPayments = $inv->payments
                    ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void')
                    ->values();
                $reconciliation = $this->invoiceAmounts->resolve($inv, $studentClass);
                $netApplied = (int) $reconciliation['net_applied'];
                $storedTotalAmount = (int) $reconciliation['stored_total_amount'];
                $totalAmount = (int) $reconciliation['total_amount'];
                $computedTotalAmount = $reconciliation['computed_total_amount'];
                $amountSource = $reconciliation['amount_source'];
                $amountDiscrepancy = $reconciliation['amount_discrepancy'];
                $periodSessions = $reconciliation['period_sessions'];
                $periodStart = $reconciliation['period_start'];
                $periodEnd = $reconciliation['period_end'];
                $appliedAmount = min($totalAmount, $netApplied);
                $outstandingAmount = max(0, $totalAmount - $appliedAmount);
                $status = (string) ($inv->Status ?? '');
                $ledgerStatus = $status ?: 'unknown';
                $ledgerLabel = null;
                $ledgerAnomalies = [];

                if (in_array($status, ['unpaid', 'partial'], true) && $totalAmount > 0 && $outstandingAmount === 0) {
                    $ledgerStatus = 'open_status_without_balance';
                    $ledgerLabel = '已收足額 · 狀態待修復';
                    $ledgerAnomalies[] = 'open_status_without_balance';
                } elseif ($status === 'paid' && $outstandingAmount > 0) {
                    $ledgerStatus = 'paid_status_with_balance';
                    $ledgerLabel = '已繳狀態 · 仍有餘額';
                    $ledgerAnomalies[] = 'paid_status_with_balance';
                } elseif ((int) ($inv->PaidAmount ?? 0) !== $appliedAmount) {
                    $ledgerStatus = 'paid_amount_mismatch';
                    $ledgerLabel = '金額待修復';
                    $ledgerAnomalies[] = 'paid_amount_mismatch';
                }
                $paidAt = $effectivePayments
                    ->map(fn ($payment) => $payment->PaidAt ? substr((string) $payment->PaidAt, 0, 10) : null)
                    ->filter()
                    ->sort()
                    ->last();

                return [
                    'id'             => (int) $inv->id,
                    'invoice_no'     => 'INV-' . preg_replace('/[^0-9]/', '', (string) ($inv->billing_period ?: substr((string) $inv->IssueDate, 0, 7))) . '-' . str_pad((string) $inv->id, 6, '0', STR_PAD_LEFT),
                    'course_ref'     => 'COURSE-' . str_pad((string) $inv->StudentClassID, 6, '0', STR_PAD_LEFT),
                    'billing_period' => $inv->billing_period,
                    'issue_date'     => $inv->IssueDate ? substr((string) $inv->IssueDate, 0, 10) : null,
                    'due_date'       => $inv->DueDate   ? substr((string) $inv->DueDate, 0, 10)   : null,
                    'paid_at'        => $paidAt,
                    'payment_count'  => $effectivePayments->count(),
                    'payments'       => $inv->payments->map(function ($payment) use ($reportsByPaymentId, $reportsById, $canSeeAccountLast5, $canViewReceipt) {
                        $report = $reportsByPaymentId->get((int) $payment->id)
                            ?? ($payment->payment_report_id ? $reportsById->get((int) $payment->payment_report_id) : null);
                        $isVoid = (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void';

                        return [
                            'id'                => (int) $payment->id,
                            'paid_at'           => $payment->PaidAt ? substr((string) $payment->PaidAt, 0, 10) : null,
                            'amount'            => (int) $payment->Amount,
                            'method'            => (string) ($payment->Method ?? ''),
                            'note'              => (string) ($payment->Note ?? ''),
                            'is_void'           => $isVoid,
                            'report_id'         => $report ? (int) $report->id : null,
                            'receipt_no'        => $report ? 'RCPT-' . ($report->payment_date ? $report->payment_date->format('Ym') : 'LEGACY') . '-' . str_pad((string) $report->id, 6, '0', STR_PAD_LEFT) : null,
                            'status'            => $report ? (string) $report->status : null,
                            'account_last5'     => ($canSeeAccountLast5 && $report) ? $report->account_last5 : null,
                            'can_view_receipt'  => $canViewReceipt && $report && $report->status === 'confirmed',
                        ];
                    })->values()->all(),
                    'total_amount'   => $totalAmount,
                    'stored_total_amount' => $storedTotalAmount,
                    'computed_total_amount' => $computedTotalAmount,
                    'amount_source' => $amountSource,
                    'amount_discrepancy' => $amountDiscrepancy,
                    'period_sessions' => $periodSessions,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'paid_amount'    => (int) $inv->PaidAmount,
                    'status'         => $inv->Status,
                    'ledger_status'  => $ledgerStatus,
                    'ledger_label'   => $ledgerLabel,
                    'ledger_anomalies' => $ledgerAnomalies,
                    'calculated_paid_amount' => $appliedAmount,
                    'outstanding_amount' => $outstandingAmount,
                    'can_direct_void' => !in_array($status, ['paid', 'partial', 'void'], true) && (int) ($inv->PaidAmount ?? 0) <= 0 && $netApplied <= 0,
                    'can_exception_void' => $netApplied > 0 && $status !== 'void',
                ];
            })->values()->all(),
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
            $studentClass = StudentClass::where('ID', $studentClass->ID)
                ->lockForUpdate()
                ->firstOrFail();

            $duplicate = $this->findDuplicatePurchaseBatch($studentClass, $startDate, $sessions);
            if ($duplicate !== null) {
                return response()->json([
                    'message' => '偵測到相同學生、科目、開課日與堂數的既有批次，請先確認是否已續報過。',
                    'errors' => [
                        'duplicate' => ['已存在相同條件的續報批次。'],
                    ],
                    'duplicate_course' => [
                        'id' => (int) $duplicate->ID,
                        'start_date' => $this->normalizeDateString($duplicate->StartDate),
                        'end_date' => $this->normalizeDateString($duplicate->EndDate),
                        'session_count' => (int) ($duplicate->SessionCount ?? 0),
                        'remaining_sessions' => (int) ($duplicate->RemainingSessions ?? 0),
                        'paid' => (int) ($duplicate->Paid ?? 0),
                    ],
                ], 409);
            }

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
                $upsert = app(ClassSessionMaterializationService::class)->upsertSlot($sess);
                if ($upsert['created']) {
                    $createdSessions++;
                }
                $d = $sess['SessionDate'] ?? null;
                if ($d !== null && ($lastSessionDate === null || $d > $lastSessionDate)) {
                    $lastSessionDate = $d;
                }
            }

            if ($lastSessionDate) {
                $newCourse->EndDate = $lastSessionDate;
                $newCourse->save();
            }

            $firstSessionDate = null;
            if (!empty($builtSessions)) {
                $firstSessionDate = $builtSessions[0]['SessionDate'] ?? null;
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
                    'created_sessions' => $createdSessions,
                    'paid' => (int) ($newCourse->Paid ?? 0),
                    'start_date' => $this->normalizeDateString($newCourse->StartDate),
                    'end_date' => $this->normalizeDateString($newCourse->EndDate),
                    'first_session_date' => $this->normalizeDateString($firstSessionDate),
                    'last_session_date' => $this->normalizeDateString($lastSessionDate),
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
            $packageId = (int) $studentClass->getAttribute('PackageID');
            if ($packageId > 0) {
                CoursePackage::query()
                    ->where('id', $packageId)
                    ->where('student_id', (int) $studentClass->getAttribute('StudentID'))
                    ->lockForUpdate()
                    ->first();
                $studentClass->refresh();
            }
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
                $lockedSessionIdMap, $approvedSessionIds, $signInSessionIds, $todayYmd, $nowTime,
                $studentClass
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
                        $combinedNote = $baseNote === '' ? $suffix : ($baseNote . '; ' . $suffix);
                        // Note is varchar(255); repeated moves would otherwise grow this
                        // unbounded and trip a DB truncation error (Sentry PHP-LARAVEL-29).
                        // Keep the tail so the most recent adjustments survive truncation.
                        $classSession->Note = mb_strlen($combinedNote) > 255 ? mb_substr($combinedNote, -255) : $combinedNote;
                    }
                    $classSession->save();
                } else {
                    $classSession = app(ClassSessionMaterializationService::class)->upsertSlot([
                        'StudentClassID' => $classId,
                        'SessionDate' => $sessionDate,
                        'StartTime' => $startTime,
                        'EndTime' => $endTime,
                        'Status' => $isEnded ? 'completed' : 'scheduled',
                        'Note' => $note !== '' ? $note : ($isEnded ? '系統補登加課' : '系統加課'),
                    ])['session'];
                }
            }

            // R84: ClassSessionObserver::updating() auto-recomputes this for the
            // $existing/$movableSession branches above (already-persisted rows).
            // It deliberately does NOT fire on create (ambiguous intent — see
            // observer docblock), so the upsertSlot() branch's brand-new row
            // needs one explicit recompute here; harmless no-op for the other
            // two branches since the observer already set the correct value.
            app(ContractScheduleMatcher::class)->applyExceptionFlag($classSession);
            if ($classSession->isDirty('IsContractException')) {
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
            $lockedSessionIdMap, $approvedSessionIds, $signInSessionIds, $todayYmd, $nowTime,
            $studentClass
        );

        $canAdd = $result['conflict_type'] === 'none';

        $endTime = !empty($data['end_time'])
            ? $this->normalizeSessionTime($data['end_time'], '18:00')
            : Carbon::createFromFormat('H:i:s', $startTime)
                ->addMinutes(max(30, (int) ($data['duration_minutes'] ?? 120)))
                ->format('H:i:s');
        $isEnded = $this->sessionEndedByEndTime($sessionDate, $endTime);

        return response()->json([
            'can_add' => $canAdd,
            'conflict_type' => $result['conflict_type'],
            'error_code' => $result['error_code'] ?? null,
            'message' => $result['message'],
            'has_attendance' => $result['has_attendance'] ?? false,
            'has_approved_learning_record' => $result['has_approved_learning_record'] ?? false,
            'conflict_session_id' => $result['conflict_session_id'] ?? null,
            'suggested_actions' => $result['suggested_actions'] ?? [],
            // R-quickadd-confirm: this slot's end time has already passed —
            // add-session will silently mark it completed + auto-approve the
            // evaluation when auto_approve stays true. FE must confirm explicitly
            // (in-app #197 follow-up / 黃奕暟 7/28 mis-add incident, 2026-07-29).
            'is_ended' => $isEnded,
        ]);
    }

    /**
     * Dry-run check for the formal one-occurrence-at-a-time scheduling flow.
     * POST /api/v1/student-classes/{studentClass}/manual-sessions/check
     */
    public function checkManualSession(Request $request, StudentClass $studentClass)
    {
        if ($auth = $this->authorizeManualSessionAccess($studentClass)) {
            return $auth;
        }

        $data = $request->validate([
            'session_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
        ]);

        $result = app(ManualSessionBookingService::class)->check(
            $studentClass,
            Carbon::parse($data['session_date'])->toDateString(),
            $data['start_time']
        );

        return response()->json($result, $result['can_add'] ? 200 : 422);
    }

    /**
     * Create exactly one future occurrence. Repeated requests for the same
     * course/date/start slot are idempotent.
     * POST /api/v1/student-classes/{studentClass}/manual-sessions
     */
    public function createManualSession(Request $request, StudentClass $studentClass)
    {
        if ($auth = $this->authorizeManualSessionAccess($studentClass)) {
            return $auth;
        }

        $data = $request->validate([
            'session_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $operatorId = is_object($authUser) ? (int) ($authUser->id ?? 0) : null;
        $result = app(ManualSessionBookingService::class)->create(
            $studentClass,
            Carbon::parse($data['session_date'])->toDateString(),
            $data['start_time'],
            null,
            $operatorId ?: null,
            $request->header('Idempotency-Key')
        );

        if (!$result['can_add']) {
            $status = in_array($result['conflict_type'] ?? null, ['student_conflict', 'schedule_conflict'], true)
                ? 409
                : 422;
            return response()->json($result, $status);
        }

        $session = $result['session'] ?? null;
        return response()->json(array_merge($result, [
            'session' => $session ? [
                'id' => (int) $session->id,
                'student_class_id' => (int) $session->StudentClassID,
                'session_date' => Carbon::parse($session->SessionDate)->toDateString(),
                'start_time' => substr((string) $session->StartTime, 0, 5),
                'end_time' => substr((string) $session->EndTime, 0, 5),
                'status' => (string) $session->Status,
            ] : null,
        ]), $result['created'] ?? false ? 201 : 200);
    }

    private function authorizeManualSessionAccess(StudentClass $studentClass): ?\Illuminate\Http\JsonResponse
    {
        $role = request()->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Only directors and administrators may book manual occurrences'], 403);
        }

        $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds) && !Student::whereIn('CampusID', $campusIds)
            ->where('id', $studentClass->StudentID)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
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
        string $nowTime,
        ?StudentClass $studentClass = null
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

            $packageHasCapacity = true;
            $packageId = $studentClass ? (int) $studentClass->getAttribute('PackageID') : 0;
            if ($studentClass && $packageId > 0 && !$movableSession) {
                $package = CoursePackage::query()
                    ->where('id', $packageId)
                    ->where('student_id', (int) $studentClass->getAttribute('StudentID'))
                    ->first();
                $memberIds = StudentClass::query()
                    ->where('PackageID', $packageId)
                    ->where('StudentID', (int) $studentClass->getAttribute('StudentID'))
                    ->pluck('ID');
                $reserved = (int) ClassSession::query()
                    ->whereIn('StudentClassID', $memberIds)
                    ->whereDate('SessionDate', '>=', $todayYmd)
                    // #1733/#228/#229: leave-like occurrences do not consume
                    // shared package future reservation capacity.
                    ->whereNotIn('Status', SessionStatus::futureReservationExclusionStatuses())
                    ->count();
                $remaining = $package ? max(0, (int) $package->computeRemainingFromLedger()) : 0;
                $packageHasCapacity = $package !== null && $reserved < $remaining;
            }

            if ($isSessionMode && !$movableSession) {
                $activeSessionCount = (int) ClassSession::where('StudentClassID', $classId)
                    ->where('Status', '!=', 'cancelled')
                    ->count();
                if (!$packageHasCapacity && $studentClass && $packageId > 0) {
                    return [
                    'conflict_type' => 'full_capacity',
                        'error_code' => 'SESSIONS_FULL',
                        'message' => '共用方案可用堂數已排滿，請先調課、請假，或增加方案總堂數。',
                        'suggested_actions' => ['調課', '請假', '增加總堂數'],
                        '_existing_session' => null,
                        '_movable_session' => null,
                    ];
                }
                if ($sessionCount > 0
                    && $activeSessionCount >= $sessionCount
                    && !($studentClass && $packageId > 0)
                ) {
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

    /**
     * 轉移堂次紀錄到另一門課程（interim workaround for in-app #1901/#1902/#1904）。
     *
     * 只搬「已存在的 ClassSession 及其評量／點名紀錄」，完全不動任一課程的
     * SessionCount／Charge／standard_lesson_minutes 等計費鎖定欄位 —— 那些欄位
     * 一旦有扣堂紀錄就由 BillingContractLockGuard 鎖死（RFC_NONSTANDARD_SESSION_
     * DURATION_BILLING，Founder-gated），此端點不繞過、也不嘗試重新詮釋。
     * 金額/堂數對帳仍是既有的人工流程；本端點只解決「評量表要重填」這個痛點。
     * POST /api/v1/student-classes/{studentClass}/transfer-sessions
     */
    public function transferSessions(Request $request, StudentClass $studentClass)
    {
        $auth = $this->authorizeStudentClassAccess($studentClass);
        if ($auth !== null) {
            return $auth;
        }

        $data = $request->validate([
            'session_ids' => 'required|array|min:1|max:100',
            'session_ids.*' => 'integer',
            'target_student_class_id' => 'required|integer',
        ]);

        return DB::transaction(function () use ($studentClass, $data) {
            $source = StudentClass::where('ID', $studentClass->ID)->lockForUpdate()->firstOrFail();
            $target = StudentClass::where('ID', $data['target_student_class_id'])->lockForUpdate()->firstOrFail();

            // Caller must have write authority over the TARGET course too, not just the
            // source — otherwise a caller could smuggle session/evaluation records into
            // any course id belonging to the same student, including one on a campus or
            // under a teacher they have no access to (IDOR).
            $authTarget = $this->authorizeStudentClassAccess($target);
            if ($authTarget !== null) {
                return $authTarget;
            }

            if ($source->hasDeductionHistory() && (string) $source->getAttribute('closed_reason') === 'usage_settled') {
                return response()->json([
                    'message' => '來源課程已提前結清，堂次與紀錄已鎖定，無法轉移。',
                ], 422);
            }
            if ((int) $target->ID === (int) $source->ID) {
                return response()->json([
                    'message' => '來源課程與目標課程不可相同。',
                ], 422);
            }
            if ((int) $target->StudentID !== (int) $source->StudentID) {
                return response()->json([
                    'message' => '目標課程與來源課程的學生不一致，拒絕轉移。',
                ], 422);
            }
            if ((int) ($target->SubjectID ?? 0) !== (int) ($source->SubjectID ?? 0)) {
                return response()->json([
                    'message' => '目標課程與來源課程的科目不一致，拒絕轉移。',
                ], 422);
            }

            $sessions = ClassSession::where('StudentClassID', $source->ID)
                ->whereIn('id', $data['session_ids'])
                ->get();
            $foundIds = $sessions->pluck('id')->all();
            $missing = array_diff($data['session_ids'], $foundIds);
            if (!empty($missing)) {
                return response()->json([
                    'message' => '部分堂次不存在於來源課程，未執行任何轉移。',
                    'errors' => ['session_ids' => ['不存在的堂次 id: ' . implode(',', $missing)]],
                ], 422);
            }

            $movableStatuses = ['attended', 'completed', 'late'];
            $blockedSessions = $sessions->filter(function (ClassSession $session) use ($movableStatuses): bool {
                return !in_array(
                    strtolower((string) $session->getAttribute('Status')),
                    $movableStatuses,
                    true
                );
            });
            if ($blockedSessions->isNotEmpty()) {
                return response()->json([
                    'message' => '只能轉移已上課的堂次；未上課、請假或缺席堂次請留在原合約處理。',
                    'errors' => [
                        'session_ids' => ['不可轉移堂次：' . $blockedSessions->pluck('id')->implode(', ')],
                    ],
                ], 422);
            }

            foreach ($sessions as $session) {
                $session->StudentClassID = $target->ID;
                $session->save(); // fires assertCourseIsMutable() against the TARGET course
            }

            LearningRecord::whereIn('ClassSessionID', $foundIds)->update(['StudentClassID' => $target->ID]);
            StudentSignIn::whereIn('ClassSessionID', $foundIds)->update(['StudentClassID' => $target->ID]);

            return response()->json([
                'message' => "已轉移 " . count($foundIds) . " 堂課程紀錄（含已填評量／點名），計費堂數與金額請照原流程人工對帳。",
                'transferred_session_ids' => $foundIds,
                'source_course_id' => (int) $source->ID,
                'target_course_id' => (int) $target->ID,
            ]);
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

    private function buildRenewalPreview(StudentClass $studentClass, array $data): array
    {
        $mode = (string) ($data['mode'] ?? '');
        $student = $studentClass->relationLoaded('student') ? $studentClass->student : Student::find($studentClass->StudentID);
        $severity = 'ok';
        $warnings = [];
        $blockers = [];
        $proposedCourse = [];
        $billing = [];
        $schedule = [
            'created_sessions' => 0,
            'first_session_date' => null,
            'last_session_date' => null,
        ];

        if ((int) ($studentClass->Stop ?? 0) === 1) {
            $warnings[] = [
                'code' => 'source_paused',
                'message' => '來源課程目前為暫停狀態，確認前請確認是否仍要續報。',
            ];
        }

        if ($mode === 'purchase_batch') {
            if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'count') {
                $blockers[] = [
                    'code' => 'monthly_course_purchase_batch',
                    'message' => '月結制課程不可加購堂數，請使用月結續報。',
                ];
            }

            $sessions = (int) ($data['sessions'] ?? 0);
            $startDate = $this->normalizeDateString($data['start_date'] ?? null);
            if ($sessions < 1) {
                $blockers[] = [
                    'code' => 'sessions_required',
                    'message' => '請輸入本次新增堂數。',
                ];
            }
            if (!$startDate) {
                $blockers[] = [
                    'code' => 'start_date_required',
                    'message' => '請選擇新批次開課日。',
                ];
            }

            $rate = (float) ($studentClass->Rate ?? 0);
            $rateUnit = (string) ($studentClass->rate_unit ?? 'session');
            $globalDur = max(30, (int) ($studentClass->SessionDuration ?? 120));
            $slots = $this->resolveScheduleSlotsForRebuild($studentClass);
            $totalHours = 0;
            $charge = 0;
            if ($sessions > 0) {
                if ($rateUnit === 'hour') {
                    $durSum = 0;
                    $slotCount = max(1, count($slots));
                    foreach ($slots as $slot) {
                        $durSum += !empty($slot['duration_minutes']) ? (int) $slot['duration_minutes'] : $globalDur;
                    }
                    $avgDur = $durSum / $slotCount;
                    $totalHours = (int) round(($sessions * $avgDur) / 60);
                    $charge = (int) round($rate * $totalHours);
                } else {
                    $totalHours = (int) round(($sessions * $globalDur) / 60);
                    $charge = (int) round($rate * $sessions);
                }
            }

            if ($startDate && $sessions > 0 && !empty($slots)) {
                $sessionsPreview = $this->buildSessionsForCount((int) $studentClass->ID, $startDate, $sessions, $slots, $globalDur);
                $schedule['created_sessions'] = count($sessionsPreview);
                $schedule['first_session_date'] = isset($sessionsPreview[0])
                    ? $this->normalizeDateString($sessionsPreview[0]['SessionDate'])
                    : null;
                $lastSession = !empty($sessionsPreview) ? $sessionsPreview[count($sessionsPreview) - 1] : null;
                $schedule['last_session_date'] = $lastSession
                    ? $this->normalizeDateString($lastSession['SessionDate'])
                    : null;
            }

            $duplicate = ($startDate && $sessions > 0)
                ? $this->findDuplicatePurchaseBatch($studentClass, $startDate, $sessions)
                : null;
            if ($duplicate !== null) {
                $blockers[] = [
                    'code' => 'possible_duplicate_batch',
                    'message' => '系統偵測到相同學生、科目、開課日與堂數的既有批次，請先確認是否已續報過。',
                    'duplicate_course_id' => (int) $duplicate->ID,
                ];
            }

            $proposedCourse = [
                'schedule_mode' => 'count',
                'sessions' => $sessions,
                'start_date' => $startDate,
                'end_date' => $schedule['last_session_date'],
                'charge' => $charge,
                'paid' => 0,
                'total_hours' => $totalHours,
            ];
            $billing = [
                'payment_status_after_confirm' => 'unpaid',
                'amount_due' => $charge,
            ];
        } elseif ($mode === 'renew_monthly') {
            if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'date') {
                $blockers[] = [
                    'code' => 'non_monthly_course',
                    'message' => '堂數制課程不可月結續報，請使用新增購買批次。',
                ];
            }

            $currentEnd = $this->normalizeDateString($studentClass->EndDate ?? null);
            $newEnd = $this->normalizeDateString($data['end_date'] ?? null);
            if (!$newEnd && !empty($data['months'])) {
                $base = $currentEnd ?: Carbon::today()->toDateString();
                $newEnd = Carbon::parse($base)->addMonths((int) $data['months'])->toDateString();
            }
            if (!$newEnd) {
                $blockers[] = [
                    'code' => 'end_date_required',
                    'message' => '請選擇新的月結結束日。',
                ];
            } else {
                if ($currentEnd && $newEnd <= $currentEnd) {
                    $blockers[] = [
                        'code' => 'end_date_not_extended',
                        'message' => '新的結束日必須晚於目前結束日，避免誤把課程縮短。',
                    ];
                }
                if ($newEnd <= Carbon::today()->toDateString()) {
                    $blockers[] = [
                        'code' => 'end_date_in_past',
                        'message' => '新的結束日必須晚於今天。',
                    ];
                }
            }

            $billingPeriod = $newEnd ? Carbon::parse($newEnd)->format('Y-m') : null;
            $invoiceExists = false;
            if ($billingPeriod) {
                $invoiceExists = Invoice::where('StudentClassID', $studentClass->ID)
                    ->where('billing_period', $billingPeriod)
                    ->exists();
                if ($invoiceExists) {
                    $warnings[] = [
                        'code' => 'invoice_already_exists',
                        'message' => '此月份已有帳單，確認時會沿用既有帳單，不會重複建立。',
                    ];
                }
            }

            $dueDay = max(1, min(31, (int) ($studentClass->settlement_day ?? 15)));
            $dueDate = $newEnd
                ? Carbon::parse($newEnd)->startOfMonth()->addDays($dueDay - 1)->toDateString()
                : null;
            $previewRate = (float) ($studentClass->Rate ?? 0);
            $previewRateUnit = strtolower(trim((string) ($studentClass->rate_unit ?? 'session')));
            if (!in_array($previewRateUnit, ['session', 'hour'], true)) {
                $previewRateUnit = 'session';
            }
            $previewSessionCount = max(0, (int) ($studentClass->monthly_sessions ?? 0));
            $previewTotalHours = (int) ($studentClass->TotalHours ?? 0);
            if ($newEnd) {
                $newStart = $currentEnd
                    ? Carbon::parse($currentEnd)->addDay()->toDateString()
                    : Carbon::today()->toDateString();
                $previewSlots = $this->resolveScheduleSlotsForRebuild($studentClass);
                $previewDur = max(30, (int) ($studentClass->SessionDuration ?? 120));
                $previewSessions = !empty($previewSlots)
                    ? $this->buildSessionsFromWeeklySchedule(
                        (int) $studentClass->getKey(),
                        $newStart,
                        $newEnd,
                        $previewSlots,
                        $previewDur
                    )
                    : [];
                if (!empty($previewSessions)) {
                    $previewSessionCount = count($previewSessions);
                    $previewTotalHours = (int) round(array_reduce($previewSessions, function ($carry, $session) {
                        $start = substr((string) ($session['StartTime'] ?? ''), 0, 5);
                        $end = substr((string) ($session['EndTime'] ?? ''), 0, 5);
                        if ($start === '' || $end === '') {
                            return $carry;
                        }
                        $startM = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
                        $endM = ((int) substr($end, 0, 2)) * 60 + (int) substr($end, 3, 2);
                        return $carry + max(0, $endM - $startM);
                    }, 0) / 60);
                }
            }
            $amount = $this->calculateCourseChargeFromRate(
                $previewRate,
                $previewRateUnit,
                $previewSessionCount,
                $previewTotalHours
            );
            if ($amount <= 0) {
                $amount = max(0, (int) ($studentClass->Charge ?? 0));
            }

            $openExceptions = 0;
            if (Schema::hasColumn('ClassSession', 'IsContractException')) {
                $openExceptions = (int) ClassSession::query()
                    ->where('StudentClassID', $studentClass->getKey())
                    ->where('Status', 'scheduled')
                    ->where('IsContractException', 1)
                    ->whereDate('SessionDate', '>=', Carbon::today()->toDateString())
                    ->count();
            }
            if ($openExceptions > 0) {
                $warnings[] = [
                    'code' => 'open_contract_exceptions',
                    'message' => "本期尚有 {$openExceptions} 堂調課／例外排課；新期將依契約固定時段展開，這些單堂調整不會帶過去。若要整期改時段，請先編輯固定排課。",
                    'exception_count' => $openExceptions,
                ];
            }

            $proposedCourse = [
                'schedule_mode' => 'date',
                'start_date' => $this->normalizeDateString($studentClass->StartDate ?? null),
                'current_end_date' => $currentEnd,
                'end_date' => $newEnd,
                'paid' => 0,
            ];
            $billing = [
                'payment_status_after_confirm' => 'unpaid',
                'amount_due' => $amount,
                'invoice' => [
                    'billing_period' => $billingPeriod,
                    'due_date' => $dueDate,
                    'total_amount' => $amount,
                    'will_create' => $billingPeriod ? !$invoiceExists : false,
                ],
            ];
            $schedule = [
                'created_sessions' => null,
                'first_session_date' => null,
                'last_session_date' => $newEnd,
            ];
        } else {
            $blockers[] = [
                'code' => 'mode_required',
                'message' => '請選擇續報類型。',
            ];
        }

        if (!empty($blockers)) {
            $severity = 'blocked';
        } elseif (!empty($warnings)) {
            $severity = 'warning';
        }

        $stateSource = [
            'source' => [
                'id' => (int) $studentClass->ID,
                'student_id' => (int) $studentClass->StudentID,
                'subject_id' => (int) ($studentClass->SubjectID ?? 0),
                'teacher_id' => (int) ($studentClass->TeacherID ?? 0),
                'schedule_mode' => (string) ($studentClass->ScheduleMode ?? ''),
                'start_date' => $this->normalizeDateString($studentClass->StartDate ?? null),
                'end_date' => $this->normalizeDateString($studentClass->EndDate ?? null),
                'paid' => (int) ($studentClass->Paid ?? 0),
                'remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
                'session_count' => (int) ($studentClass->SessionCount ?? 0),
                'charge' => (int) ($studentClass->Charge ?? 0),
                'updated_at' => (string) ($studentClass->updated_at ?? ''),
            ],
            'mode' => $mode,
            'payload' => [
                'sessions' => $data['sessions'] ?? null,
                'start_date' => $this->normalizeDateString($data['start_date'] ?? null),
                'end_date' => $this->normalizeDateString($data['end_date'] ?? null),
                'months' => $data['months'] ?? null,
            ],
            'billing' => $billing,
            'schedule' => $schedule,
            'blockers' => $blockers,
        ];
        $stateJson = json_encode($stateSource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stateHash = hash('sha256', $stateJson ?: '');

        return [
            'preview_id' => substr(hash('sha256', 'renewal-preview|' . $stateHash), 0, 24),
            'state_hash' => $stateHash,
            'mode' => $mode,
            'severity' => $severity,
            'source_course' => [
                'id' => (int) $studentClass->ID,
                'student_id' => (int) $studentClass->StudentID,
                'student_name' => $student?->name,
                'subject_id' => (int) ($studentClass->SubjectID ?? 0),
                'teacher_id' => (int) ($studentClass->TeacherID ?? 0),
                'schedule_mode' => (string) ($studentClass->ScheduleMode ?? ''),
                'start_date' => $this->normalizeDateString($studentClass->StartDate ?? null),
                'end_date' => $this->normalizeDateString($studentClass->EndDate ?? null),
                'paid' => (int) ($studentClass->Paid ?? 0),
                'remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
            ],
            'proposed_course' => $proposedCourse,
            'billing' => $billing,
            'schedule' => $schedule,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
    }

    private function findDuplicatePurchaseBatch(StudentClass $studentClass, string $startDate, int $sessions): ?StudentClass
    {
        return StudentClass::where('ID', '<>', $studentClass->ID)
            ->where('StudentID', $studentClass->StudentID)
            ->where('SubjectID', $studentClass->SubjectID)
            ->where('ScheduleMode', 'count')
            ->where('StartDate', $startDate)
            ->where('SessionCount', $sessions)
            ->where(function ($q) {
                $q->whereNull('Stop')->orWhere('Stop', 0);
            })
            ->orderBy('ID')
            ->first();
    }

    private function findDuplicateMonthlyRenewal(StudentClass $studentClass, string $startDate, string $endDate): ?StudentClass
    {
        return StudentClass::where('ID', '<>', $studentClass->ID)
            ->where('StudentID', $studentClass->StudentID)
            ->where('SubjectID', $studentClass->SubjectID)
            ->where('ScheduleMode', 'date')
            ->whereDate('StartDate', $startDate)
            ->whereDate('EndDate', $endDate)
            ->where(function ($q) {
                $q->whereNull('Stop')->orWhere('Stop', 0);
            })
            ->orderBy('ID')
            ->first();
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

    /** Conservative calendar window: contract overlap OR ClassSession/Schedule in range. */
    private function applyCalendarWindowFilter($query, $start, $end): void
    {
        $rangeStart = $this->normalizeDateString($start);
        $rangeEnd = $this->normalizeDateString($end);
        if (!$rangeStart || !$rangeEnd || $rangeEnd < $rangeStart) {
            return;
        }

        $query->where(function ($q) use ($rangeStart, $rangeEnd) {
            $q->where(function ($sub) use ($rangeStart, $rangeEnd) {
                $sub->where(function ($dates) use ($rangeStart) {
                    $dates->whereNull('EndDate')->orWhere('EndDate', '>=', $rangeStart);
                })->where(function ($dates) use ($rangeEnd) {
                    $dates->whereNull('StartDate')->orWhere('StartDate', '<=', $rangeEnd);
                });
            })->orWhereExists(function ($sub) use ($rangeStart, $rangeEnd) {
                $sub->select(DB::raw(1))
                    ->from('ClassSession')
                    ->whereColumn('ClassSession.StudentClassID', 'StudentClass.ID')
                    ->where('ClassSession.SessionDate', '>=', $rangeStart)
                    ->where('ClassSession.SessionDate', '<=', $rangeEnd);
            })->orWhereExists(function ($sub) use ($rangeStart, $rangeEnd) {
                $sub->select(DB::raw(1))
                    ->from('schedules')
                    ->whereColumn('schedules.student_course_id', 'StudentClass.ID')
                    ->where('schedules.schedule_date', '>=', $rangeStart)
                    ->where('schedules.schedule_date', '<=', $rangeEnd);
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
        if (array_key_exists('scheduling_policy', $input)) {
            $mappedData['scheduling_policy'] = (string) $input['scheduling_policy'];
        }
        if (isset($input['rate_per_30min'])) $mappedData['Rate'] = $input['rate_per_30min'];
        if (isset($input['rate_unit'])) $mappedData['rate_unit'] = $input['rate_unit'];
        if (isset($input['duration_hours'])) $mappedData['SessionDuration'] = (int) round((float) $input['duration_hours'] * 60);
        // RFC non-standard duration D1/D2: the billing standard is its own per-course
        // field, kept separate from SessionDuration (the scheduling default).
        if (array_key_exists('standard_lesson_minutes', $input)) {
            $mappedData['standard_lesson_minutes'] = ($input['standard_lesson_minutes'] === null || $input['standard_lesson_minutes'] === '')
                ? null
                : (int) $input['standard_lesson_minutes'];
        }
        if (isset($input['deduction_basis'])) $mappedData['deduction_basis'] = (string) $input['deduction_basis'];
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
        if (array_key_exists('end_date', $input)) $mappedData['EndDate'] = $input['end_date'] ?: null;
        if (array_key_exists('Memo', $input)) $mappedData['Memo'] = $input['Memo'];
        if (array_key_exists('memo', $input)) $mappedData['Memo'] = $input['memo'];

        // Default constraints for creation only
        if ($request->isMethod('post')) {
            $classType = $input['class_type'] ?? 'one_on_one';
            $by1Map = ['one_on_one' => 1, 'one_on_two' => 2, 'one_on_three' => 3, 'tutoring' => 4, 'trial' => 1];
            $mappedData['by1'] = $by1Map[$classType] ?? 1;

            $mappedData['StartDate'] = $input['first_class_date'] ?? now()->toDateString();
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
        $dayTimeSlots = $this->backfillMissingSelectedDaySlots(
            $dayTimeSlots,
            $input['days_of_week'] ?? [],
            $input['start_time'] ?? null,
            $input['duration_hours'] ?? null
        );
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

    private function syncLatestPaymentDateForCourse(StudentClass $studentClass, mixed $paidAt): void
    {
        if (empty($paidAt)) {
            return;
        }

        $normalizedDate = $this->normalizeDateString((string) $paidAt);
        if (!$normalizedDate) {
            return;
        }

        $invoiceIds = Invoice::query()
            ->where('StudentClassID', (int) $studentClass->ID)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        if (empty($invoiceIds)) {
            return;
        }

        $latestPayment = Payment::query()
            ->whereIn('InvoiceID', $invoiceIds)
            ->where('Amount', '>', 0)
            ->where('Method', '!=', 'void')
            ->orderByRaw("COALESCE(PaidAt, '0000-00-00') DESC")
            ->orderByDesc('id')
            ->first();

        if (!$latestPayment) {
            return;
        }

        $latestPayment->PaidAt = $normalizedDate;
        $latestPayment->save();

        PaymentReport::query()
            ->where('payment_id', (int) $latestPayment->id)
            ->where('status', 'confirmed')
            ->update(['payment_date' => $normalizedDate]);
    }

    /**
     * @param  array<int, array{day:int,start_time:string,duration_minutes?:int|null}>  $slots
     * @param  mixed  $rawDays
     * @return array<int, array{day:int,start_time:string,duration_minutes?:int|null}>
     */
    private function backfillMissingSelectedDaySlots(array $slots, $rawDays, $fallbackStartTime = null, $fallbackDurationHours = null): array
    {
        if (!is_array($rawDays)) {
            return $slots;
        }

        $selectedDays = array_values(array_unique(array_filter(array_map('intval', $rawDays), fn ($day) => $day >= 1 && $day <= 7)));
        if ($selectedDays === []) {
            return $slots;
        }

        $slotDays = [];
        foreach ($slots as $slot) {
            $slotDays[(int) ($slot['day'] ?? 0)] = true;
        }

        $fallbackTime = $fallbackStartTime
            ? substr((string) $fallbackStartTime, 0, 5)
            : ($slots[0]['start_time'] ?? '16:00');
        $fallbackDurationMinutes = null;
        if ($fallbackDurationHours !== null && (float) $fallbackDurationHours > 0) {
            $fallbackDurationMinutes = (int) round((float) $fallbackDurationHours * 60);
        }

        foreach ($selectedDays as $day) {
            if (isset($slotDays[$day]) || count($slots) >= 7) {
                continue;
            }
            $slots[] = [
                'day' => $day,
                'start_time' => $fallbackTime,
                'duration_minutes' => $fallbackDurationMinutes,
            ];
            $slotDays[$day] = true;
        }

        return $this->normalizeDayTimeSlotsInput($slots);
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

            $upsert = app(ClassSessionMaterializationService::class)->upsertSlot($session);
            if ($upsert['created']) {
                $existingKeys[$key] = true;
                $created++;
            }
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
        $activeSessionsQuery = ClassSession::where('StudentClassID', $classId)
            ->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', $today)
            ->orderBy('SessionDate')
            ->orderBy('StartTime');

        // One-off 調課／補課 must not rewrite series week/time (SaaS: this occurrence only).
        if (Schema::hasColumn('ClassSession', 'IsContractException')) {
            $activeSessionsQuery->where(function ($q) {
                $q->whereNull('IsContractException')->orWhere('IsContractException', 0);
            });
        }

        $activeSessions = $activeSessionsQuery->get(['SessionDate', 'StartTime', 'EndTime']);

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

                $upsert = app(ClassSessionMaterializationService::class)->upsertSlot($session);
                $classSession = $upsert['session'];
                if ($upsert['created']) {
                    $createdSessions++;
                }

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

        // R99 follow-up (in-app #219): mass ::delete() query builder calls bypass Eloquent
        // model events entirely, so ClassSessionObserver::deleted() never fires and the
        // existing schedule_audit_logs trail is silently skipped — this is exactly why an
        // earlier production repair on this same rebuild path left no recoverable trace of
        // the deleted session or its evaluation record. Snapshot LearningRecord content here
        // (it has no observer of its own) and delete ClassSession rows one at a time so the
        // established audit-log infrastructure actually captures what's being destroyed.
        $operatorId = (int) (optional(request()->attributes->get('auth_user'))->id ?: 0) ?: null;
        $branchId = (int) (optional($studentClass->student)->CampusID ?: 0) ?: null;
        $sessionIds = ClassSession::where('StudentClassID', (int) $studentClass->ID)->pluck('id')->all();
        if (!empty($sessionIds)) {
            $recordsToDelete = LearningRecord::whereIn('ClassSessionID', $sessionIds)->get();
            foreach ($recordsToDelete as $record) {
                ScheduleAuditLog::create([
                    'session_id'  => $record->ClassSessionID,
                    'action_type' => 'delete',
                    'description' => "開課日/排課調整重建：刪除評量記錄 #{$record->id}（StudentClassID {$studentClass->ID}，learning_record_id={$record->id}）",
                    'operator_id' => $operatorId,
                    'branch_id'   => $branchId,
                    'old_data'    => $record->toArray(),
                    'new_data'    => null,
                ]);
            }
            LearningRecord::whereIn('ClassSessionID', $sessionIds)->delete();
        }
        LearningRecord::where('StudentClassID', (int) $studentClass->ID)
            ->whereNull('ClassSessionID')
            ->delete();
        ClassSession::where('StudentClassID', (int) $studentClass->ID)->get()->each->delete();

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

            $upsert = app(ClassSessionMaterializationService::class)->upsertSlot($session);
            $classSession = $upsert['session'];
            if ($upsert['created']) {
                $createdSessions++;
            }

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
     * Nearest calendar day around $ymd whose ISO weekday exists in the contract map.
     * Prefer closer days first; on equal distance prefer earlier day to avoid skipping
     * the immediate week when changing weekday (e.g. Sun -> Sat should pick previous day).
     * Never return a date earlier than today.
     */
    private function snapDateToContractWeekday(string $ymd, array $slotsByWeekday): string
    {
        if ($ymd === '' || empty($slotsByWeekday)) {
            return $ymd;
        }
        $anchor = Carbon::parse($ymd)->startOfDay();
        $today = Carbon::today()->startOfDay();

        if (isset($slotsByWeekday[(int) $anchor->dayOfWeekIso]) && $anchor->greaterThanOrEqualTo($today)) {
            return $anchor->toDateString();
        }

        for ($offset = 1; $offset <= 7; $offset++) {
            $prev = $anchor->copy()->subDays($offset);
            $next = $anchor->copy()->addDays($offset);

            if ($prev->greaterThanOrEqualTo($today) && isset($slotsByWeekday[(int) $prev->dayOfWeekIso])) {
                return $prev->toDateString();
            }
            if ($next->greaterThanOrEqualTo($today) && isset($slotsByWeekday[(int) $next->dayOfWeekIso])) {
                return $next->toDateString();
            }
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

        // Collect only the rows whose slot actually changes.
        $reflowIds = [];
        foreach ($unlockedSorted as $s) {
            $reflowIds[(int) $s->id] = true;
        }

        $moves = [];
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
                $oldDate === $newDate
                && (string) $session->StartTime === $newStart
                && (string) $session->EndTime === $newEnd
            ) {
                continue; // already on the contract slot
            }
            $moves[] = [
                'session'       => $session,
                'oldDate'       => $oldDate,
                'oldStartShort' => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
                'newDate'       => $newDate,
                'newStart'      => $newStart,
                'newEnd'        => $newEnd,
            ];
        }

        if (empty($moves)) {
            return 0;
        }

        $courseId = (int) $unlockedSorted->first()->StudentClassID;
        // #1163: a bulk reflow remaps unlocked[i] -> proposed[i]; a mixed/swap
        // permutation cannot move in place (moving one row onto a not-yet-moved
        // sibling's slot 1062s under uq_class_session_slot). Guard external
        // occupants up front, then move in two phases (park to sentinel slots,
        // then place) inside a transaction so nothing is stranded on failure.
        //
        // External-occupant pre-check (before any write): a target held by a
        // non-reflow live session (e.g. a locked/attended row) cannot be reflowed
        // onto — surface a clean 422 instead of a raw 1062.
        return $this->contractSessionReflowService->move($courseId, $reflowIds, $moves);
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
    /**
     * @param  \Illuminate\Support\Collection<int, ClassSession>|null  $preloadedExistingSessions  When the
     *         caller already batch-fetched this class's existing ClassSession rows (e.g. TD-018-style
     *         batch preload across many classes in one query), pass them here to skip the per-class
     *         query. Pass null (default) to have this method query them itself, as before.
     */
    public function extendSessionsIfNeeded(StudentClass $studentClass, int $newCount, ?\Illuminate\Support\Collection $preloadedExistingSessions = null): void
    {
        if ((string) ($studentClass->scheduling_policy ?? 'auto_recurrence') === ManualSessionBookingService::POLICY) {
            return;
        }
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

        $existingSessions = $preloadedExistingSessions ?? ClassSession::where('StudentClassID', $classId)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->orderBy('id')
            ->get();
        $existingQuotaKeys = [];
        $occupiedKeys = [];
        $currentCount = 0;
        $hasScheduledOutsideContract = false;
        $hasLockedQuotaSession = false;
        $hasLockedQuotaOutsideContract = false;
        foreach ($existingSessions as $session) {
            $date = $this->normalizeDateString($session->SessionDate ?? null);
            $start = substr((string) ($session->StartTime ?? ''), 0, 5);
            if (!$date || $start === '') {
                continue;
            }
            $key = $date . '|' . $start;
            $status = strtolower((string) ($session->Status ?? ''));
            // Cancelled sessions must still occupy the calendar key; otherwise we
            // treat the slot as empty and refill from the contract sequence —
            // recreating the same date/time (classic "取消了又補回" on count-mode courses).
            $occupiedKeys[$key] = true;
            if (!in_array($status, $nonQuotaStatuses, true)) {
                $existingQuotaKeys[$key] = true;
                $currentCount++;
                if ($status !== 'scheduled') {
                    $hasLockedQuotaSession = true;
                }
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

        if ($currentCount >= $newCount && ($hasLockedQuotaSession || $hasLockedQuotaOutsideContract)) {
            SessionDeductionService::syncCounters($studentClass);
            return;
        }

        if ($currentCount >= $newCount && !$hasScheduledOutsideContract) {
            SessionDeductionService::syncCounters($studentClass);
            return;
        }

        $newSessions = [];
        $quotaShortfall = max(0, $newCount - $currentCount);
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
            if ($quotaShortfall > 0 && count($newSessions) >= $quotaShortfall) {
                break;
            }
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

            $classSession = app(ClassSessionMaterializationService::class)->upsertSlot($session)['session'];

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
        $unlockedCountByDate = [];
        foreach ($unlocked as $session) {
            $date = $this->normalizeDateString($session->SessionDate ?? null);
            if (!$date) {
                continue;
            }
            $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
            $sessionWeekdays[$isoDow] = true;
            $unlockedCountByDate[$date] = ($unlockedCountByDate[$date] ?? 0) + 1;
            if (!isset($slotsByWeekday[$isoDow])) {
                $needsRemap = true;
                break;
            }
        }

        if (!$needsRemap && $unlocked->isNotEmpty()) {
            foreach ($unlockedCountByDate as $date => $countOnDate) {
                $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
                $contractSlotsForDay = $slotsByWeekday[$isoDow] ?? [];
                if (!empty($contractSlotsForDay) && count($contractSlotsForDay) > $countOnDate) {
                    $needsRemap = true;
                    break;
                }
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

        // Build a permutation-safe move list. Same-day time shifts under
        // uq_class_session_slot 1062 if we update in place onto a sibling's
        // not-yet-vacated StartTime (Sentry PHP-LARAVEL-25 / #1384). Also avoid
        // assigning two unlocked rows onto the identical contract slot.
        $moves = [];
        $reflowIds = [];
        $claimedTargets = [];

        foreach ($sessionsByDate as $date => $dateSessions) {
            $isoDow = (int) Carbon::parse($date)->dayOfWeekIso;
            $daySlots = $slotsByWeekday[$isoDow] ?? [];
            if (empty($daySlots)) {
                continue;
            }

            usort($dateSessions, fn ($a, $b) => strcmp((string) $a->StartTime, (string) $b->StartTime));

            foreach ($dateSessions as $idx => $session) {
                $sessionId = (int) $session->id;
                if (isset($lockedBySessionId[$sessionId])) {
                    continue;
                }
                // One unlocked row per contract slot on this date; extras keep
                // their current time rather than collapsing onto the last slot.
                if ($idx >= count($daySlots)) {
                    continue;
                }
                $slot = $daySlots[$idx];

                $newStartFull = $this->normalizeSessionTime($slot['time'], '16:00:00');
                $newEndFull = Carbon::createFromFormat('H:i:s', $newStartFull)
                    ->addMinutes(max(30, $slot['dur']))
                    ->format('H:i:s');

                $targetKey = $date . '|' . $newStartFull;
                if (isset($claimedTargets[$targetKey])) {
                    continue;
                }
                $claimedTargets[$targetKey] = $sessionId;

                if (
                    (string) $session->StartTime === $newStartFull
                    && (string) $session->EndTime === $newEndFull
                ) {
                    continue;
                }

                $oldDate = $this->normalizeDateString($session->SessionDate ?? null);
                $moves[] = [
                    'session'       => $session,
                    'oldDate'       => $oldDate,
                    'oldStartShort' => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
                    'newDate'       => $date,
                    'newStart'      => $newStartFull,
                    'newEnd'        => $newEndFull,
                ];
                $reflowIds[$sessionId] = true;
            }
        }

        if (empty($moves)) {
            return 0;
        }

        $courseId = (int) ($moves[0]['session']->StudentClassID ?? 0);
        try {
            return $this->contractSessionReflowService->move($courseId, $reflowIds, $moves);
        } catch (\App\Exceptions\SlotOccupiedException $e) {
            // Soft sync must not 500 the course update: skip colliding moves
            // one-by-one so compatible rows still realign.
            $updated = 0;
            foreach ($moves as $move) {
                $sid = (int) $move['session']->id;
                try {
                    $updated += $this->contractSessionReflowService->move(
                        $courseId,
                        [$sid => true],
                        [$move]
                    );
                } catch (\App\Exceptions\SlotOccupiedException $ignored) {
                    continue;
                }
            }
            return $updated;
        }
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
     * Keep past / already-taught sessions on the former contract teacher when
     * StudentClass.TeacherID changes (in-app #207).
     *
     * Calendar display prefers substitute schedule rows (original_schedule_id NOT NULL
     * + teacher_id <> contract). Without pinning, past attended sessions fall through
     * to the new contract teacher and look like history was rewritten.
     */
    private function pinPastSessionsToFormerTeacherAfterContractTeacherChange(
        int $courseId,
        int $oldTeacherId,
        int $newTeacherId
    ): void {
        if ($courseId <= 0 || $oldTeacherId <= 0 || $newTeacherId <= 0 || $oldTeacherId === $newTeacherId) {
            return;
        }

        $course = DB::table('StudentClass')->where('ID', $courseId)->first();
        if (!$course) {
            return;
        }

        $studentId = (int) ($course->StudentID ?? 0);
        $campusId = $studentId > 0
            ? (int) (DB::table('Student')->where('id', $studentId)->value('CampusID') ?? 0)
            : 0;
        if ($studentId <= 0 || $campusId <= 0) {
            return;
        }

        $today = Carbon::today()->toDateString();
        $subject = (string) (DB::table('Subject')->where('id', $course->SubjectID)->value('Subject_Name') ?? '');
        $classType = (string) ($course->class_type ?? $course->ClassType ?? 'one_on_one');

        $pastSessions = DB::table('ClassSession')
            ->where('StudentClassID', $courseId)
            ->where(function ($q) use ($today) {
                $q->whereDate('SessionDate', '<', $today)
                    ->orWhereIn('Status', ['attended', 'late', 'leave', 'excused', 'completed', 'absent']);
            })
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get();

        foreach ($pastSessions as $session) {
            try {
                $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
            } catch (\Throwable $e) {
                continue;
            }
            $startTime = substr((string) ($session->StartTime ?? ''), 0, 5);
            $endTime = substr((string) ($session->EndTime ?? ''), 0, 5);
            if ($startTime === '' || $endTime === '') {
                continue;
            }

            // Already has a substitute-style exception with a non-contract teacher → leave it.
            $existingPin = DB::table('schedules')
                ->where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'scheduled')
                ->whereNotNull('original_schedule_id')
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->where('teacher_id', '<>', $newTeacherId)
                ->exists();
            if ($existingPin) {
                continue;
            }

            $dayOfWeek = (int) Carbon::parse($sessionDate)->dayOfWeekIso;
            $startM = ((int) substr($startTime, 0, 2)) * 60 + (int) substr($startTime, 3, 2);
            $endM = ((int) substr($endTime, 0, 2)) * 60 + (int) substr($endTime, 3, 2);
            $durationHours = max(0.5, round(max(0, $endM - $startM) / 60, 1));
            $now = now();

            $rescheduledId = DB::table('schedules')->insertGetId([
                'student_id' => $studentId,
                'teacher_id' => $oldTeacherId,
                'subject' => $subject,
                'day_of_week' => $dayOfWeek,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_hours' => $durationHours,
                'class_type' => $classType,
                'status' => 'rescheduled',
                'type' => 'normal',
                'deduction' => 0,
                'branch_id' => $campusId,
                'schedule_date' => $sessionDate,
                'student_course_id' => $courseId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('schedules')->insert([
                'student_id' => $studentId,
                'teacher_id' => $oldTeacherId,
                'subject' => $subject,
                'day_of_week' => $dayOfWeek,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_hours' => $durationHours,
                'class_type' => $classType,
                'status' => 'scheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => $campusId,
                'schedule_date' => $sessionDate,
                'student_course_id' => $courseId,
                'original_schedule_id' => (int) $rescheduledId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Keep future schedule rows aligned when contract teacher changes.
     */
    private function syncFutureScheduleTeachersAfterContractTeacherChange(int $courseId, int $oldTeacherId, int $newTeacherId): void
    {
        if ($courseId <= 0 || $newTeacherId <= 0 || $oldTeacherId <= 0 || $oldTeacherId === $newTeacherId) {
            return;
        }

        $today = Carbon::today()->toDateString();

        Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->whereDate('schedule_date', '>=', $today)
            ->whereNull('original_schedule_id')
            ->where('teacher_id', $oldTeacherId)
            ->update([
                'teacher_id' => $newTeacherId,
                'updated_at' => now(),
            ]);

        $staleAnchorIds = Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->whereDate('schedule_date', '>=', $today)
            ->whereNotNull('original_schedule_id')
            ->where('teacher_id', $oldTeacherId)
            ->pluck('original_schedule_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (!empty($staleAnchorIds)) {
            Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', '>=', $today)
                ->where('status', 'scheduled')
                ->whereIn('original_schedule_id', $staleAnchorIds)
                ->delete();

            Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', '>=', $today)
                ->where('status', 'rescheduled')
                ->whereIn('id', $staleAnchorIds)
                ->delete();
        }
    }

    /**
     * Check if a session's (date, startTime, duration) falls within the contract slots.
     */
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
     * Normalize a weekday value to ISO-8601 (1=Mon … 7=Sun).
     * Accepts both ISO 1-7 and legacy JS 0-6 (0=Sunday → 7).
     */
    public static function isoWeekday($weekday): int
    {
        $weekday = (int) $weekday;

        return $weekday === 0 ? 7 : $weekday;
    }

    private function calculateCourseChargeFromRate(
        float $rate,
        string $rateUnit,
        int $sessionCount,
        int $totalHours
    ): int {
        if ($rate <= 0) {
            return 0;
        }

        if ($rateUnit === 'hour') {
            return (int) round($rate * max(0, $totalHours));
        }

        return (int) round($rate * max(0, $sessionCount));
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

    /**
     * RFC non-standard duration — precise balance fields for the course list.
     *
     * Minutes stay the integer authoritative truth; lesson equivalents are emitted as
     * fixed-precision decimal STRINGS so no binary float becomes a billing figure and
     * so the UI is not forced to re-derive them. The legacy integer `remaining_sessions`
     * is kept for compatibility but must not be the only thing an actual-duration
     * course displays: rounding 0.50 lessons to "1" is exactly the confusion this RFC
     * exists to remove.
     *
     * `uncovered_minutes` is derived from the ledger, not stored — it is the amount by
     * which real consumption has outrun the purchased entitlement, which the floored
     * `RemainingMinutes` column cannot express.
     */
    private function attachPreciseBalanceFields(object $class): void
    {
        $basis = (string) ($class->deduction_basis ?? DeductionBasis::FIXED_SESSION);
        $class->deduction_basis = $basis === '' ? DeductionBasis::FIXED_SESSION : $basis;
        $standard = (int) ($class->standard_lesson_minutes ?? 0);
        $class->standard_lesson_minutes = $standard > 0 ? $standard : null;

        $remainingMinutes = $class->remaining_minutes;
        $class->remaining_hours = $remainingMinutes === null
            ? null
            : $this->decimalString($remainingMinutes, 60);

        // Lesson equivalents need a per-course standard; without one there is nothing
        // meaningful to divide by, so they stay null rather than assuming a default.
        if ($standard >= 1 && $remainingMinutes !== null) {
            $calc = new LessonEntitlementCoverageCalculator();
            $purchasedMinutes = (int) ($class->PurchasedMinutes ?? 0);
            $class->remaining_lesson_equivalent = $calc->lessonEquivalent((int) $remainingMinutes, $standard);
            $class->used_lesson_equivalent = $calc->lessonEquivalent(
                max(0, $purchasedMinutes - (int) $remainingMinutes),
                $standard
            );
        } else {
            $class->remaining_lesson_equivalent = null;
            $class->used_lesson_equivalent = null;
        }
    }

    /** Fixed 2dp decimal string from integer minutes — never a binary float. */
    private function decimalString(int $minutes, int $perUnit): string
    {
        $whole = intdiv($minutes, $perUnit);
        $hundredths = intdiv(($minutes % $perUnit) * 200 + $perUnit, $perUnit * 2);
        if ($hundredths >= 100) {
            $whole++;
            $hundredths -= 100;
        }

        return sprintf('%d.%02d', $whole, $hundredths);
    }

    /**
     * RFC non-standard duration D1/D2/D4 + contract lock — validate the billing
     * contract fields on an update, and refuse changes once entitlement has been
     * consumed.
     *
     * Returns a JSON error response to bail out with, or null to proceed.
     *
     * @param  array<string, mixed>  $mapped
     */
    private function guardBillingContractUpdate(StudentClass $studentClass, array $mapped): ?\Illuminate\Http\JsonResponse
    {
        $touchesContract = array_key_exists('standard_lesson_minutes', $mapped)
            || array_key_exists('deduction_basis', $mapped);

        if ($touchesContract) {
            $basis = (string) ($mapped['deduction_basis'] ?? $studentClass->deduction_basis ?? DeductionBasis::FIXED_SESSION);
            if (!DeductionBasis::isValid($basis)) {
                return response()->json([
                    'message' => '扣堂方式僅接受 ' . DeductionBasis::validationList(),
                    'errors' => ['deduction_basis' => ['扣堂方式無效。']],
                ], 422);
            }

            $minutes = array_key_exists('standard_lesson_minutes', $mapped)
                ? $mapped['standard_lesson_minutes']
                : $studentClass->standard_lesson_minutes;

            if ($minutes !== null && ((int) $minutes < 30 || (int) $minutes > 480)) {
                return response()->json([
                    'message' => '標準一堂時長需介於 30 至 480 分鐘',
                    'errors' => ['standard_lesson_minutes' => ['標準一堂時長需介於 30 至 480 分鐘。']],
                ], 422);
            }

            // Fail closed: actual-duration billing without a persisted standard would
            // have nothing to divide by, and must never fall back to a house default.
            if ($basis === DeductionBasis::ACTUAL_DURATION && $minutes === null) {
                return response()->json([
                    'message' => '依實際時長扣堂的課程必須設定標準一堂時長',
                    'errors' => ['standard_lesson_minutes' => ['請先設定標準一堂時長（分鐘）。']],
                ], 422);
            }

            // D4: actual-duration courses cannot live in a shared CoursePackage, whose
            // pool ledger can only represent whole ±1 lessons (TD-059).
            if ($basis === DeductionBasis::ACTUAL_DURATION && $studentClass->isPartOfPackage()) {
                return response()->json([
                    'message' => '共用課程包內的課程不可改為依實際時長扣堂（共用池目前只支援整堂扣除）',
                    'errors' => ['deduction_basis' => ['共用課程包不支援依實際時長扣堂。']],
                ], 422);
            }
        }

        $lock = app(BillingContractLockGuard::class)->inspect($studentClass, $mapped);
        if ($lock['locked']) {
            return response()->json([
                'message' => $lock['message'],
                'code' => 'billing_contract_locked',
                'locked_fields' => $lock['attempted_fields'],
            ], 422);
        }

        return null;
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
                // Slots arrive in two conventions: ISO 1-7 (DB week columns, day_time_slots)
                // and legacy JS 0-6 (ScheduleSlots param). Both agree on Mon-Sat (1-6);
                // Sunday is 7 (ISO) or 0 (JS). Comparing raw dayOfWeek (0-6) silently
                // dropped every ISO-Sunday slot (GitHub #1096: 0-amount monthly invoices).
                if ((int) $date->dayOfWeekIso === self::isoWeekday($slot['weekday'])) {
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
        $auth = $this->authorizeStudentClassAccess($studentClass);
        if ($auth !== null) {
            return $auth;
        }

        $sc = $studentClass;

        $action = $request->input('action', 'pause');
        $today = Carbon::today()->toDateString();

        $reason = $request->input('reason'); // 'completed' or null
        $cancelRemaining = $request->boolean('cancel_remaining', true);
        $forfeitRemaining = $request->boolean('forfeit_remaining', false);

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

        $remainingOwed = (int) ($sc->getAttribute('RemainingSessions') ?? 0);

        // #1839: count-mode still owes sessions — do not settle/complete and wipe
        // the leave-cascade tail. Pause (no settled/completed reason) stays allowed.
        if (
            $action === 'pause'
            && !$forfeitRemaining
            && in_array((string) $reason, ['settled', 'completed'], true)
            && (string) ($sc->ScheduleMode ?? '') === 'count'
            && $remainingOwed > 0
        ) {
            return response()->json([
                'message' => "還有 {$remainingOwed} 堂未上，不能結案。請先把請假順延的堂次排好，或確認放棄餘額後再結案。",
                'error_code' => 'remaining_sessions_unscheduled',
                'remaining_sessions' => $remainingOwed,
            ], 422);
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

                $cancelled = $cancelRemaining
                    ? $this->cancelFutureScheduledSessions($sc, $reason)
                    : 0;

                $labels = ['completed' => '已完課', 'settled' => '已結案'];
                $label = $labels[$reason] ?? '已暫停';
                DB::commit();
                return response()->json([
                    'message' => $cancelRemaining
                        ? "課程{$label}，已取消 {$cancelled} 堂未來排課。"
                        : "課程{$label}（未取消剩餘排課）。",
                    'cancelled_count' => $cancelled,
                    'cancel_remaining' => $cancelRemaining,
                ]);
            } else {
                if ($sc->isUsageSettlementLocked()) {
                    DB::rollBack();
                    return response()->json([
                        'message' => '已提前結清的課程不可恢復；請為學生建立新約。',
                    ], 422);
                }
                $sc->Stop = 0;
                $sc->closed_reason = null;
                $sc->save();

                $restored = $this->restorePauseCancelledSessions($sc);

                DB::commit();
                return response()->json([
                    'message' => $restored > 0
                        ? "課程已恢復，可重新排課。已恢復 {$restored} 堂先前暫停時取消的堂次。"
                        : '課程已恢復，可重新排課。',
                    'restored_count' => $restored,
                ]);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => '操作失敗：' . $e->getMessage()], 500);
        }
    }

    private function cancelFutureScheduledSessions(StudentClass $studentClass, ?string $reason): int
    {
        $today = Carbon::today()->toDateString();
        $noteTag = $reason === 'settled' ? '[結案取消]' : '[暫停取消]';

        return ClassSession::where('StudentClassID', $studentClass->ID)
            ->where('SessionDate', '>=', $today)
            ->where('Status', 'scheduled')
            ->update([
                'Status' => 'cancelled',
                'Note' => DB::raw("CONCAT(COALESCE(Note,''), ' {$noteTag}')"),
                'updated_at' => now(),
            ]);
    }

    /**
     * Undo exactly what cancelFutureScheduledSessions() (or the FixOrphanScheduledSessions
     * companion job, tag [孤兒停用取消]) did on pause: restore sessions it cancelled back to
     * scheduled, scoped to our own note tags so a director's unrelated manual cancellation is
     * never touched (in-app #219: resume only reset Stop/closed_reason, never the sessions the
     * pause itself cancelled, so they silently stayed invisible on the calendar).
     */
    private function restorePauseCancelledSessions(StudentClass $studentClass): int
    {
        return ClassSession::where('StudentClassID', $studentClass->ID)
            ->where('Status', 'cancelled')
            ->where(function ($q) {
                $q->where('Note', 'like', '%[暫停取消]%')
                    ->orWhere('Note', 'like', '%[結案取消]%')
                    ->orWhere('Note', 'like', '%[孤兒停用取消]%');
            })
            ->update([
                'Status' => 'scheduled',
                'Note' => DB::raw("TRIM(REPLACE(REPLACE(REPLACE(COALESCE(Note,''), ' [暫停取消]', ''), ' [結案取消]', ''), ' [孤兒停用取消]', ''))"),
                'updated_at' => now(),
            ]);
    }
}
