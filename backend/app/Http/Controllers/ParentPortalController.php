<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\LearningRecordFeedback;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentLineBinding;
use App\Models\StudentSignIn;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Announcement;
use App\Models\Subject;
use App\Models\User;
use App\Http\Controllers\LearningRecordController;
use App\Services\ExceptionWorkflowService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ParentPortalController extends Controller
{
    // ── Login: Student ID + Phone OR Student Name + Phone ───────────────────

    public function login(Request $request)
    {
        $data = $request->validate([
            'StudentID' => 'nullable|integer',
            'Name' => 'nullable|string|max:64',
            'Phone' => 'required|string|max:20',
        ]);

        $phoneNorm = $this->normalizePhone($data['Phone']);
        if ($phoneNorm === '') {
            return response()->json(['message' => '請輸入手機號碼'], 422);
        }

        $rawName = trim((string) ($data['Name'] ?? ''));
        $hasStudentId = !empty($data['StudentID']) && (int) $data['StudentID'] > 0;

        // PRD-B FR-B-001: require precise single-row match. Either:
        //   (a) StudentID + Phone (exact match), or
        //   (b) Name + Phone (must return exactly one matching Student).
        // 「相同 Phone 的所有學生均列出」邏輯已於 2026-04-18 移除以避免跨家庭 PII 洩漏。
        if (!$hasStudentId && $rawName === '') {
            return response()->json(['message' => '請輸入學生姓名與手機號碼'], 422);
        }

        $student = null;

        if ($hasStudentId) {
            $candidate = Student::find((int) $data['StudentID']);
            if ($candidate && empty(trim($this->resolveContactPhone($candidate)))) {
                return response()->json(['message' => '此學生尚未設定聯絡手機，請聯繫分校補登後再登入'], 401);
            }
            $contactPhone = $candidate ? $this->resolveContactPhone($candidate) : '';
            if ($candidate
                && !empty($contactPhone)
                && $this->normalizePhone($contactPhone) === $phoneNorm
                && ($rawName === '' || trim((string) $candidate->name) === $rawName)) {
                $student = $candidate;
            }
        } else {
            $allByName = Student::whereRaw('TRIM(name) = ?', [$rawName])->get();
            \Illuminate\Support\Facades\Log::info('parent.login.debug', [
                'name'       => $rawName,
                'phoneNorm'  => $phoneNorm,
                'matches'    => $allByName->map(fn ($s) => [
                    'id'           => $s->id,
                    'campus'       => $s->CampusID,
                    'Phone'        => $s->Phone,
                    'parent_phone' => $s->parent_phone,
                    'resolved'     => $this->normalizePhone($this->resolveContactPhone($s)),
                ]),
            ]);
            $candidates = $allByName
                ->filter(function ($s) use ($phoneNorm) {
                    $contact = $this->resolveContactPhone($s);
                    return !empty($contact) && $this->normalizePhone($contact) === $phoneNorm;
                })
                ->values();

            if ($candidates->count() === 1) {
                $student = $candidates->first();
            } elseif ($candidates->count() > 1) {
                // 極罕見：姓名 + 手機完全相同但不同 Student 記錄。業界作法為不自動登入，
                // 要求使用者改以 LINE 綁定或 StudentID 精確登入，避免誤選他家庭學生。
                return response()->json([
                    'message' => '找到多筆相符資料，請改以 LINE 綁定或提供學生代號登入',
                ], 409);
            } else {
                // Hint to front desk if name matched but phone didn't for any row with empty phone
                $nameOnly = Student::whereRaw('TRIM(name) = ?', [$rawName])->get();
                if ($nameOnly->isNotEmpty() && $nameOnly->contains(fn ($s) => empty(trim($this->resolveContactPhone($s))))) {
                    return response()->json(['message' => '此學生尚未設定聯絡手機，請聯繫分校補登後再登入'], 401);
                }
            }
        }

        if (!$student) {
            return response()->json(['message' => '查無此學生或手機號碼不符，請確認姓名與手機是否正確'], 404);
        }

        \Illuminate\Support\Facades\Log::info('parent.login.success', [
            'student_id' => $student->id,
            'ip' => $request->ip(),
        ]);

        $result = $this->createSession($student);

        // Only attach additional students if they share an explicit LINE binding.
        // 不再以「相同 Phone」自動帶出 siblings，避免跨家庭 PII 洩漏。
        $lineUserIds = StudentLineBinding::where('student_id', $student->id)
            ->pluck('line_user_id')
            ->filter(fn ($id) => $this->isValidLineUserId($id));
        if ($lineUserIds->isNotEmpty()) {
            $siblingIds = StudentLineBinding::whereIn('line_user_id', $lineUserIds)
                ->where('student_id', '!=', $student->id)
                ->pluck('student_id')
                ->unique();
            if ($siblingIds->isNotEmpty()) {
                $siblings = Student::whereIn('id', $siblingIds)->get();
                $allStudents = collect([['id' => $student->id, 'name' => $student->name]])
                    ->concat($siblings->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]))
                    ->values();
                if ($allStudents->count() > 1) {
                    $result['students'] = $allStudents;
                }
            }
        }

        return response()->json($result);
    }

    // ── Resolve LIFF ID from hostname (public, no auth) ──────────────────

    public function resolveLiff(Request $request)
    {
        $host = $request->getHost();

        $campus = \Illuminate\Support\Facades\DB::table('Campus')
            ->whereNotNull('LIFFID')
            ->where('LIFFID', '!=', '')
            ->whereNotNull('URL')
            ->where('URL', '!=', '')
            ->get()
            ->first(function ($c) use ($host) {
                $parsed = parse_url($c->URL ?? '', PHP_URL_HOST);
                if (!$parsed) return false;
                return $parsed === $host || str_ends_with($host, '.' . $parsed) || str_ends_with($parsed, '.' . $host);
            });

        if (!$campus) {
            return response()->json(['liff_id' => null]);
        }

        return response()->json([
            'liff_id'     => $campus->LIFFID,
            'campus_id'   => $campus->id,
            'campus_name' => $campus->name,
        ]);
    }

    // ── Login: LINE userId ────────────────────────────────────────────────

    public function loginWithLine(Request $request)
    {
        $data = $request->validate([
            'line_user_id' => 'required|string',
            'campus_id'    => 'nullable|integer',
        ]);

        $studentIds = StudentLineBinding::where('line_user_id', $data['line_user_id'])
            ->pluck('student_id');
        $students = $studentIds->isNotEmpty()
            ? Student::whereIn('id', $studentIds)->get()
            : collect();
        if ($students->isEmpty()) {
            return response()->json(['message' => '尚未綁定學生帳號（此入口僅供家長/學生）。請透過 LINE 官方帳號輸入「綁定 學生姓名 手機號碼」完成綁定'], 404);
        }

        // If a campus_id was provided (e.g. from a branch-specific portal link),
        // prioritise students from that campus so the first session matches the link's branch.
        // Families with the same child enrolled in multiple campuses benefit from this ordering.
        $preferredCampusId = !empty($data['campus_id']) ? (int) $data['campus_id'] : null;
        if ($preferredCampusId) {
            $students = $students->sortByDesc(fn ($s) => (int) $s->CampusID === $preferredCampusId)->values();
        }

        // Create session for the first student (frontend can switch later)
        $result = $this->createSession($students->first());
        $result['students'] = $students->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values();
        return response()->json($result);
    }

    // ── Switch student (for multi-child parents) ───────────────────────────

    public function switchStudent(Request $request)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'student_id' => 'required|integer',
        ]);

        $targetStudent = Student::find($data['student_id']);
        if (!$targetStudent) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $currentStudent = Student::find($session->StudentID);
        $allowed = false;

        if ($currentStudent) {
            // Check via student_line_bindings: any shared *valid* line_user_id
            $currentLineIds = StudentLineBinding::where('student_id', $currentStudent->id)
                ->pluck('line_user_id')
                ->filter(fn ($id) => $this->isValidLineUserId($id));
            if ($currentLineIds->isNotEmpty()) {
                $allowed = StudentLineBinding::where('student_id', $targetStudent->id)
                    ->whereIn('line_user_id', $currentLineIds)
                    ->exists();
            }
            // PRD-B FR-B-001: 不再以「相同 Phone」允許切換，避免跨家庭 PII 洩漏。
            // 若需共用入口，家長需透過 LINE 綁定明確連結多位學生。
        }

        if (!$allowed) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Create new session for the target student
        return response()->json($this->createSession($targetStudent));
    }

    // ── Dashboard ─────────────────────────────────────────────────────────

    public function dashboard(Request $request)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $student = Student::find($session->StudentID);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $classes = StudentClass::where('StudentID', $student->id)
            ->orderBy('ID', 'desc')
            ->get();

        $classIds = $classes->pluck('ID')->all();
        $observedUsedByClass = SessionDeductionService::batchObservedUsedSessions($classIds);
        $paidAtMap = AlertController::lastPaidAtByStudentClassIds($classIds);

        // PRD-H (2026-04-18)：月結制專用的「本月已上堂數」與預估月費，供家長端學習情況卡片顯示。
        $monthlyClassIds = $classes
            ->filter(fn ($c) => (string) ($c->ScheduleMode ?? 'count') !== 'count')
            ->pluck('ID')->values()->all();
        $attendedThisMonth = [];
        $currentMonthLabel = Carbon::now()->format('n') . '月';
        $monthlyBillingPeriods = [];
        $monthlyDisplayLabels = [];
        $monthlyInvoiceRowsByClass = collect();
        if (!empty($monthlyClassIds)) {
            $monthlyInvoiceRowsByClass = Invoice::query()
                ->where('StudentID', $student->id)
                ->whereIn('StudentClassID', $monthlyClassIds)
                ->notVoided()
                ->whereNotNull('billing_period')
                ->orderByRaw("CASE WHEN Status IN ('unpaid', 'partial', 'partially_paid', 'open', 'pending') THEN 0 ELSE 1 END")
                ->orderBy('billing_period', 'desc')
                ->orderBy('DueDate', 'desc')
                ->get(['StudentClassID', 'billing_period', 'Status', 'DueDate', 'IssueDate'])
                ->groupBy('StudentClassID');

            foreach ($classes as $class) {
                if (!in_array((int) $class->ID, $monthlyClassIds, true)) {
                    continue;
                }
                $period = $this->resolveMonthlyDisplayPeriod($class, $monthlyInvoiceRowsByClass->get($class->ID));
                $monthlyBillingPeriods[(int) $class->ID] = $period;
                $monthlyDisplayLabels[(int) $class->ID] = $this->formatBillingPeriodLabel($period);
            }

            $periodStarts = collect($monthlyBillingPeriods)->map(fn ($period) => $period . '-01');
            $queryStart = $periodStarts->min() ?? Carbon::now()->startOfMonth()->toDateString();
            $queryEnd = $periodStarts->max()
                ? Carbon::parse($periodStarts->max())->endOfMonth()->toDateString()
                : Carbon::now()->endOfMonth()->toDateString();

            $attendedThisMonth = ClassSession::whereIn('StudentClassID', $monthlyClassIds)
                ->whereBetween('SessionDate', [$queryStart, $queryEnd])
                ->whereIn('Status', ['completed', 'attended', 'late'])
                ->get(['StudentClassID', 'SessionDate'])
                ->filter(function ($session) use ($monthlyBillingPeriods) {
                    $classId = (int) $session->StudentClassID;
                    $period = $monthlyBillingPeriods[$classId] ?? null;
                    if (!$period) {
                        return false;
                    }
                    return substr((string) $session->SessionDate, 0, 7) === $period;
                })
                ->groupBy('StudentClassID')
                ->map(fn ($group) => $group->count())
                ->toArray();
        }

        $sessionMetrics = static function (StudentClass $c) use ($observedUsedByClass): array {
            $mode = (string) ($c->ScheduleMode ?? 'count');
            $purchased = max(0, (int) ($c->SessionCount ?? 0));
            if ($mode !== 'count' || $purchased <= 0) {
                return [
                    'used' => max(0, (int) ($c->UsedSessions ?? 0)),
                    'remaining' => max(0, (int) ($c->RemainingSessions ?? 0)),
                ];
            }
            $observed = max(0, (int) ($observedUsedByClass[$c->ID] ?? 0));
            $used = min($purchased, $observed);

            return [
                'used' => $used,
                'remaining' => max(0, $purchased - $used),
            ];
        };

        // Learning records (approved only) — paginated
        $lrPage    = max(1, (int) $request->query('lr_page', 1));
        $lrPerPage = min(50, max(5, (int) $request->query('lr_per_page', 10)));
        $records      = [];
        $lrTotal      = 0;
        $lrHasMore    = false;
        if (!empty($classIds)) {
            $lrQuery = LearningRecord::active()
                ->whereIn('StudentClassID', $classIds)
                ->where('Status', 'approved');
            $lrTotal = $lrQuery->count();
            $recordsRaw = $lrQuery
                ->orderBy('ApprovedAt', 'desc')
                ->skip(($lrPage - 1) * $lrPerPage)
                ->take($lrPerPage)
                ->get();

            $sessionNumbers = LearningRecordController::batchSessionNumbers($recordsRaw);
            $feedbacks = LearningRecordFeedback::whereIn('learning_record_id', $recordsRaw->pluck('id'))
                ->get()
                ->keyBy('learning_record_id');

            $records = $recordsRaw->map(function ($rec) use ($classes, $sessionNumbers, $feedbacks) {
                    $teacher = User::find($rec->TeacherID);
                    $rec->teacher_name = $teacher ? $teacher->Name : null;
                    $sc = $classes->firstWhere('ID', $rec->StudentClassID);
                    $fromCourse = $sc ? $this->resolveSubjectName($sc) : null;
                    $rawSubject = trim((string) ($rec->Subject ?? ''));
                    // Prefer a meaningful course-level subject name (not the generic fallback '課程').
                    // When the course has no subject configured, normalise the record's own Subject field.
                    $meaningful = ($fromCourse && $fromCourse !== '課程') ? $fromCourse : null;
                    if ($meaningful) {
                        $rec->Subject = $meaningful;
                    } elseif ($rawSubject !== '') {
                        $mapped = $this->mapSubjectLabel($rawSubject);
                        $rec->Subject = $mapped !== '' ? $mapped : $rawSubject;
                    } else {
                        $rec->Subject = $fromCourse ?: '課程';
                    }
                    $rec->session_number = $sessionNumbers[(int) $rec->id] ?? null;
                    $fb = $feedbacks->get((int) $rec->id);
                    $rec->parent_feedback = $fb ? [
                        'id' => (int) $fb->id,
                        'content' => $fb->content,
                        'updated_at' => optional($fb->updated_at)->toIso8601String(),
                    ] : null;
                    return $rec;
                });
            $lrHasMore = ($lrPage * $lrPerPage) < $lrTotal;
        }

        // Attendance history — FR-B-003: date / time / subject / teacher / status
        $signIns = StudentSignIn::where('StudentID', $student->id)
            ->orderBy('SignInDT', 'desc')
            ->limit(100)
            ->get();
        $sessionIds = $signIns->pluck('ClassSessionID')->filter()->unique()->values()->all();
        $sessionsById = !empty($sessionIds)
            ? ClassSession::whereIn('id', $sessionIds)->get()->keyBy('id')
            : collect();
        $attendance = $signIns->map(function ($row) use ($classes, $sessionsById) {
            $status = (string) ($row->Status ?? '');
            $row->status_label = match ($status) {
                'present' => '到班',
                'late' => '遲到',
                'absent' => '缺席',
                'leave', 'excused' => '請假',
                default => $status,
            };
            $row->is_late = $status === 'late';

            $session = $row->ClassSessionID ? $sessionsById->get($row->ClassSessionID) : null;
            $studentClass = $session ? $classes->firstWhere('ID', $session->StudentClassID) : null;

            $date = null;
            $time = null;
            if ($session) {
                $date = $session->SessionDate;
                $start = $this->trimToHM($session->StartTime);
                $end = $this->trimToHM($session->EndTime);
                $time = $start !== '' && $end !== '' ? "$start-$end" : $start;
            } elseif ($row->SignInDT) {
                try {
                    $dt = Carbon::parse($row->SignInDT);
                    $date = $dt->toDateString();
                    $time = $dt->format('H:i');
                } catch (\Throwable $e) {
                }
            }
            $row->date = $date;
            $row->time = $time;
            $row->subject = $studentClass ? $this->resolveSubjectName($studentClass) : null;

            $teacherName = null;
            if ($studentClass && !empty($studentClass->TeacherID)) {
                $teacher = User::find($studentClass->TeacherID);
                $teacherName = $teacher ? $teacher->Name : null;
            }
            $row->teacher_name = $teacherName;

            return $row;
        });

        // Per-course breakdown — 家長端「進行中」：
        //   - 堂數制：剩餘 > 0 才顯示（已用完或已停課+已繳不列）
        //   - 月結制：保留已繳結案課程，前端以「已繳費・課程已結束」降低家長誤解
        $perCourse = $classes
            ->filter(function ($c) use ($sessionMetrics) {
                $paid    = (bool) $c->Paid;
                $stopped = (bool) $c->Stop;
                $isCount = (string) ($c->ScheduleMode ?? 'count') === 'count';

                if ($isCount && $stopped && $paid) {
                    return false;
                }

                if ($isCount) {
                    return (int) $sessionMetrics($c)['remaining'] > 0;
                }

                // monthly mode：持續進行，不受 RemainingSessions 影響
                return true;
            })
            ->map(function ($c) use ($sessionMetrics, $attendedThisMonth, $monthlyBillingPeriods, $monthlyDisplayLabels, $paidAtMap) {
                $metrics   = $sessionMetrics($c);
                $isMonthly = (string) ($c->ScheduleMode ?? 'count') !== 'count';
                $monthlyTarget  = (int) ($c->monthly_sessions ?? 0);
                $monthlyFee     = $isMonthly ? $this->resolveMonthlyFee($c) : 0;
                $attended       = $isMonthly ? (int) ($attendedThisMonth[$c->ID] ?? 0) : 0;
                $paid           = $this->isClassPaid($c, $paidAtMap);
                $stopped        = (bool) $c->Stop;

                return [
                    'id'                   => $c->ID,
                    'subject'              => $this->resolveSubjectName($c),
                    'schedule_mode'        => $c->ScheduleMode,
                    'sessions_purchased'   => $c->SessionCount,
                    'remaining_sessions'   => $metrics['remaining'],
                    'used_sessions'        => $metrics['used'],
                    'is_stopped'           => $stopped,
                    'paid'                 => $paid,
                    'payment_status'       => $paid ? 'paid' : 'unpaid',
                    'payment_status_label' => $paid ? '已繳費' : '未繳費',
                    'lifecycle_status'     => $stopped ? 'closed' : 'active',
                    'lifecycle_status_label' => $stopped ? '課程已結束' : '進行中',
                    'billing_period'       => $isMonthly ? ($monthlyBillingPeriods[(int) $c->ID] ?? null) : null,
                    'display_month_label'  => $isMonthly ? ($monthlyDisplayLabels[(int) $c->ID] ?? $currentMonthLabel) : null,
                    'settlement_day'       => $isMonthly ? ((int) ($c->settlement_day ?? 0) ?: null) : null,
                    'monthly_target'       => $isMonthly ? ($monthlyTarget ?: null) : null,
                    'attended_this_month'  => $isMonthly ? $attended : null,
                    'monthly_fee_estimate' => $isMonthly ? $monthlyFee : null,
                ];
            })
            ->values();
        $visibleClassIds = $perCourse->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        // 與下方「進行中的課程」卡片一致：勿把已隱藏課程（如已暫停且已繳）的剩餘加進總數，否則會比主任只看進行中時多 1 堂以上
        $remainingTotal = $perCourse
            ->filter(fn ($row) => (string) ($row['schedule_mode'] ?? 'count') === 'count')
            ->sum(fn ($row) => (int) ($row['remaining_sessions'] ?? 0));

        $remainingBySubject = $perCourse
            ->filter(fn ($row) => (string) ($row['schedule_mode'] ?? 'count') === 'count')
            ->filter(fn ($row) => (int) ($row['remaining_sessions'] ?? 0) > 0)
            ->groupBy('subject')
            ->map(fn ($group) => $group->sum(fn ($row) => (int) ($row['remaining_sessions'] ?? 0)))
            ->sortDesc()
            ->toArray();

        // Payment alerts — only show courses that still require parent action
        $paymentAlerts = $classes
            ->filter(function ($c) use ($paidAtMap) {
                if ($c->ScheduleMode !== 'count' && ($c->SessionCount ?? 0) <= 0) {
                    return false;
                }

                $paid = $this->isClassPaid($c, $paidAtMap);
                $stopped = (bool) $c->Stop;

                // Parent portal reminders are payment actions, not director renewal alerts.
                if ($paid) {
                    return false;
                }

                if ($stopped) {
                    return false;
                }

                return true;
            })
            ->map(function ($c) use ($sessionMetrics, $paidAtMap) {
                return [
                    'class_id'           => $c->ID,
                    'subject'            => $this->resolveSubjectName($c),
                    'remaining_sessions' => (int) $sessionMetrics($c)['remaining'],
                    'paid'               => $this->isClassPaid($c, $paidAtMap),
                    'is_stopped'         => (bool) $c->Stop,
                ];
            })
            ->values();

        $upcomingClassIds = $classes->filter(function ($c) use ($sessionMetrics) {
            if ((string) ($c->ScheduleMode ?? 'count') === 'count') {
                return (int) $sessionMetrics($c)['remaining'] > 0;
            }
            if ((bool) $c->Stop && (bool) $c->Paid) {
                return false;
            }

            return true;
        })->pluck('ID')->all();

        // Upcoming sessions
        $upcomingSessions = [];
        if (!empty($upcomingClassIds)) {
            $upcomingSessions = ClassSession::whereIn('StudentClassID', $upcomingClassIds)
                ->where('SessionDate', '>=', Carbon::today())
                ->whereIn('Status', ['scheduled', 'rescheduled', 'leave_requested'])
                ->orderBy('SessionDate', 'asc')
                ->orderBy('StartTime', 'asc')
                ->limit(20)
                ->get()
                ->map(function ($session) use ($classes) {
                    $c = $classes->firstWhere('ID', $session->StudentClassID);
                    $session->Subject = $c ? $this->resolveSubjectName($c) : null;
                    $session->StartTime = $this->trimToHM($session->StartTime);
                    $session->EndTime   = $this->trimToHM($session->EndTime);
                    return $session;
                });
        }

        $invoices = [];
        try {
            $invoices = !empty($visibleClassIds)
                ? Invoice::with(['items', 'payments'])
                    ->where('StudentID', $student->id)
                    ->whereIn('StudentClassID', $visibleClassIds)
                    ->notVoided()
                    ->orderBy('IssueDate', 'desc')
                    ->get()
                : collect();
        } catch (\Exception $e) {}

        $announcements = [];
        try {
            $announcements = Announcement::where('IsActive', true)
                ->where(function ($query) use ($student) {
                    $query->whereNull('BranchID')
                        ->orWhere('BranchID', $student->CampusID);
                })
                ->where(function ($query) use ($student) {
                    $query->whereNull('TargetStudentID')
                        ->orWhere('TargetStudentID', $student->id);
                })
                ->orderBy('created_at', 'desc')
                ->get();
        } catch (\Exception $e) {}

        $campusName = null;
        try {
            $campus = \App\Models\Campus::find($student->CampusID);
            $campusName = $campus ? $campus->name : null;
        } catch (\Exception $e) {}

        // PRD-B FR-B-001: Siblings 僅透過 LINE 綁定解析，不再以相同 Phone 自動帶出。
        // 只接受有效 LINE user ID 格式（U+32hex），過濾 backfill 產生的無效值。
        $lineUserIds = StudentLineBinding::where('student_id', $student->id)
            ->pluck('line_user_id')
            ->filter(fn ($id) => $this->isValidLineUserId($id));
        $siblingIdsByLine = $lineUserIds->isNotEmpty()
            ? StudentLineBinding::whereIn('line_user_id', $lineUserIds)
                ->where('student_id', '!=', $student->id)
                ->pluck('student_id')
                ->unique()
            : collect();

        $siblingStudents = $siblingIdsByLine->isNotEmpty()
            ? Student::whereIn('id', $siblingIdsByLine)->get()
            : collect();
        $allStudents = collect([['id' => $student->id, 'name' => $student->name]])
            ->concat($siblingStudents->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]))
            ->values();

        $progressSummary = $this->buildProgressSummary(
            $student,
            $classes,
            $perCourse,
            $paymentAlerts,
            $upcomingSessions,
            $records
        );

        return response()->json([
            'student' => [
                'id'          => $student->id,
                'name'        => $student->name,
                'grade'       => $student->ClassID ?? null,
                'school'      => $student->SchoolName ?? null,
                'campus_name' => $campusName,
                'campus_id'   => (int) ($student->CampusID ?? 0),
                'line_linked' => StudentLineBinding::where('student_id', $student->id)->exists(),
            ],
            'students' => $allStudents->count() > 1 ? $allStudents->toArray() : null,
            'current_month_label'      => $currentMonthLabel,
            'remaining_sessions_total' => $remainingTotal,
            'remaining_by_subject'     => $remainingBySubject,
            'classes'                  => $perCourse,
            'payment_alerts'           => $paymentAlerts,
            'learning_records'         => $records,
            'learning_records_meta'    => [
                'page'     => $lrPage,
                'per_page' => $lrPerPage,
                'total'    => $lrTotal,
                'has_more' => $lrHasMore,
            ],
            'attendance_history'       => $attendance,
            'upcoming_sessions'        => $upcomingSessions,
            'invoices'                 => $invoices,
            'announcements'            => $announcements,
            'progress_summary'         => $progressSummary,
        ]);
    }

    /**
     * 家長進度中心摘要（PRD: enterprise dashboard parent portal v2）。
     * 提供四個聚合卡片資料：本週學習、下次課程、待確認事項、繳費狀態。
     * 僅做唯讀聚合，不改寫既有資料。
     */
    private function buildProgressSummary(
        Student $student,
        $classes,
        $perCourse,
        $paymentAlerts,
        $upcomingSessions,
        $records
    ): array {
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();
        $todayStr = Carbon::now()->toDateString();

        $weekRecords = collect($records)->filter(function ($rec) use ($weekStart, $weekEnd) {
            $date = (string) ($rec->SessionDate ?? '');
            if ($date === '') {
                return false;
            }
            try {
                $d = Carbon::parse($date);
                return $d->betweenIncluded($weekStart, $weekEnd);
            } catch (\Throwable $e) {
                return false;
            }
        });

        $weekClassIds = $classes->pluck('ID')->all() ?: [0];
        $weeklyAttended = ClassSession::query()
            ->whereIn('StudentClassID', $weekClassIds)
            ->whereBetween('SessionDate', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereIn('Status', ['attended', 'completed', 'late'])
            ->count();

        $weeklyScheduled = ClassSession::query()
            ->whereIn('StudentClassID', $weekClassIds)
            ->whereBetween('SessionDate', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->count();

        $nextSession = collect($upcomingSessions)->first();
        $nextSessionPayload = null;
        if ($nextSession) {
            $nextSessionPayload = [
                'session_id'  => (int) ($nextSession->id ?? 0),
                'date'        => (string) ($nextSession->SessionDate ?? ''),
                'start_time'  => (string) ($nextSession->StartTime ?? ''),
                'end_time'    => (string) ($nextSession->EndTime ?? ''),
                'subject'     => (string) ($nextSession->Subject ?? '課程'),
                'status'      => (string) ($nextSession->Status ?? 'scheduled'),
                'is_today'    => ((string) ($nextSession->SessionDate ?? '')) === $todayStr,
            ];
        }

        $pendingActions = [];
        if (!empty($paymentAlerts) && count($paymentAlerts) > 0) {
            $pendingActions[] = [
                'key'   => 'payment',
                'title' => '繳費提醒',
                'count' => count($paymentAlerts),
                'cta_target' => 'billing',
            ];
        }
        if ($nextSessionPayload && $nextSessionPayload['is_today']) {
            $pendingActions[] = [
                'key'   => 'today_session',
                'title' => '今日有課程',
                'count' => 1,
                'cta_target' => 'schedule',
            ];
        }
        $unreadFeedback = collect($records)->filter(fn ($r) => empty($r->parent_feedback))->count();
        if ($unreadFeedback > 0) {
            $pendingActions[] = [
                'key'   => 'feedback',
                'title' => '評量待回饋',
                'count' => $unreadFeedback,
                'cta_target' => 'learning',
            ];
        }

        $unpaidCount = is_countable($paymentAlerts) ? count($paymentAlerts) : 0;
        $totalCourses = is_countable($perCourse) ? count($perCourse) : 0;
        $paidCount = max(0, $totalCourses - $unpaidCount);
        $paymentStatus = $unpaidCount === 0 ? 'all_clear' : ($unpaidCount >= $totalCourses ? 'all_pending' : 'partial');

        return [
            'week_label' => $weekStart->format('m/d') . '–' . $weekEnd->format('m/d'),
            'week_progress' => [
                'attended' => (int) $weeklyAttended,
                'scheduled' => (int) $weeklyScheduled,
                'records_filled' => $weekRecords->count(),
            ],
            'next_session' => $nextSessionPayload,
            'pending_actions' => array_values($pendingActions),
            'pending_total' => array_sum(array_map(fn ($p) => (int) ($p['count'] ?? 0), $pendingActions)),
            'payment' => [
                'status' => $paymentStatus,
                'paid_courses' => $paidCount,
                'unpaid_courses' => $unpaidCount,
                'total_courses' => $totalCourses,
            ],
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    // ── Director: generate copyable payment notification text ─────────────

    public function paymentMessage(Request $request, int $studentId)
    {
        $student = Student::find($studentId);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $role = (string) $request->attributes->get('auth_role', '');
        $campusIds = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        if ($role !== 'super_admin' && !empty($campusIds)) {
            $studentCampusId = (int) ($student->CampusID ?? 0);
            if ($studentCampusId > 0 && !in_array($studentCampusId, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $classes = StudentClass::where('StudentID', $student->id)
            ->where('Stop', 0)
            ->where('ScheduleMode', 'count')
            ->where('Paid', 0)
            ->orderBy('StartDate')
            ->orderBy('ID')
            ->get();

        if ($classes->isEmpty()) {
            return response()->json(['message' => '此學生目前無待繳費課程']);
        }

        $lineItems = [];
        $totalAmount = 0;
        foreach ($classes as $c) {
            $subject = $this->resolveSubjectName($c);
            $sessionCount = (int) ($c->SessionCount ?? 0);
            if ($sessionCount <= 0) {
                $sessionCount = max((int) ($c->RemainingSessions ?? 0), 0);
            }
            if ($sessionCount <= 0) {
                continue;
            }

            $unitPrice = $this->resolveUnitPrice($c, $sessionCount);
            $subtotal = $this->resolveSubtotal($c, $unitPrice, $sessionCount);
            $totalAmount += $subtotal;

            $lineItems[] = [
                'subject' => $subject,
                'start_date' => $this->formatRocDate($c->StartDate),
                'session_count' => $sessionCount,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ];
        }

        if (empty($lineItems)) {
            return response()->json(['message' => '此學生目前無待繳費課程']);
        }

        $parts = [
            '媽媽你好:',
            '本期學費',
        ];

        foreach ($lineItems as $item) {
            $parts[] = (string) $item['subject'];
            $parts[] = "{$item['start_date']}~{$item['session_count']}堂";
            $parts[] = "{$this->formatAmount($item['unit_price'], false)}*{$item['session_count']} = *{$this->formatAmount($item['subtotal'])}*";
            $parts[] = '';
        }

        $parts[] = '共' . $this->formatAmount($totalAmount) . '元';
        $parts[] = '再麻煩媽媽 幫我繳款 謝謝你';
        $message = implode("\n", $parts);

        return response()->json([
            'message' => $message,
            'student_name' => $student->name,
            'total_amount' => $totalAmount,
            'items' => $lineItems,
        ]);
    }

    // ── Leave request ─────────────────────────────────────────────────────

    public function requestLeave(Request $request, $sessionId)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $classSession = ClassSession::with('studentClass')->findOrFail($sessionId);
        $studentClass = $classSession->studentClass;
        if (!$studentClass) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $ownsClass = (int) $studentClass->StudentID === (int) $session->StudentID;

        if (!$ownsClass) {
            return response()->json(['message' => 'Forbidden: This class does not belong to the authenticated student.'], 403);
        }

        if (!in_array($classSession->Status, ['scheduled', 'rescheduled', 'leave_requested'], true)) {
            return response()->json(['message' => 'Session cannot be altered.'], 422);
        }

        $workflow = app(ExceptionWorkflowService::class)->createOrGet([
            'source_key' => "parent_leave:class_session:{$classSession->id}",
            'campus_id' => (int) ($studentClass->student->CampusID ?? $this->studentCampusId($session->StudentID)),
            'student_id' => (int) $session->StudentID,
            'student_class_id' => (int) $studentClass->ID,
            'class_session_id' => (int) $classSession->id,
            'type' => 'student_leave',
            'status' => 'open',
            'severity' => 'medium',
            'source_type' => 'parent_portal',
            'source_id' => (string) $classSession->id,
            'parent_session_id' => (int) $session->id,
            'due_at' => now()->addDay(),
            'payload' => [
                'reason' => trim((string) ($data['reason'] ?? '')),
                'requested_at' => now()->toIso8601String(),
                'session_date' => (string) $classSession->SessionDate,
                'start_time' => $this->trimToHM($classSession->StartTime),
                'end_time' => $this->trimToHM($classSession->EndTime),
            ],
        ]);

        if ($classSession->Status !== 'leave_requested') {
            $classSession->Status = 'leave_requested';
            $classSession->save();
        }

        return response()->json([
            'message' => 'Leave requested successfully.',
            'workflow' => [
                'id' => $workflow->id,
                'type' => $workflow->type,
                'status' => $workflow->status,
            ],
            'session' => [
                'id' => $classSession->id,
                'status' => $classSession->Status,
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function createSession(Student $student): array
    {
        $token = Str::random(48);
        $hash = hash('sha256', $token);

        ParentSession::create([
            'StudentID' => $student->id,
            'TokenHash' => $hash,
            'ExpiresAt' => Carbon::now()->addDays(30),
        ]);

        return [
            'token' => $token,
            'student' => [
                'id'   => $student->id,
                'name' => $student->name,
            ],
        ];
    }

    private function resolveSession(Request $request): ?ParentSession
    {
        $auth = $request->header('Authorization');
        if (!$auth || !str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($auth, 7));
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);

        return ParentSession::where('TokenHash', $hash)
            ->where('ExpiresAt', '>', Carbon::now())
            ->first();
    }

    private function trimToHM(?string $time): string
    {
        if (!$time) {
            return '';
        }
        // "HH:mm:ss" or "H:mm:ss" → "HH:mm" or "H:mm"
        return preg_replace('/^(\d{1,2}:\d{2}).*$/', '$1', $time);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * 驗證是否為有效的 LINE user ID 格式（U + 32 位小寫 hex）。
     * 防止 backfill 的無效值（電話、placeholder 等）產生跨家庭兄弟姐妹群組。
     */
    private function isValidLineUserId(string $id): bool
    {
        return (bool) preg_match('/^U[0-9a-f]{32}$/i', $id);
    }

    /**
     * 家長入口驗證用的聯絡手機：優先用 parent_phone（UI「家長手機」欄），
     * 若空則 fallback 到 Phone（舊資料相容）。
     */
    private function resolveContactPhone(Student $student): string
    {
        $parentPhone = trim($student->parent_phone ?? '');
        if ($parentPhone !== '') {
            return $parentPhone;
        }
        return trim($student->Phone ?? '');
    }

    private function studentCampusId(int $studentId): int
    {
        return (int) (Student::where('id', $studentId)->value('CampusID') ?? 0);
    }

    /**
     * PRD-H：月結課程的預估「每月應繳」金額，給家長端學習情況卡片顯示參考。
     * 計算邏輯：
     *   1) 若有 `Charge` 欄位（通常 = 月費或整期總額），先把它當成月費回傳；
     *   2) 否則 rate × monthly_sessions（rate_unit=session 直接乘；hour 要換算 SessionDuration）。
     * 僅用於「預估顯示」，不影響實際對帳 / invoice 金額。
     */
    private function resolveMonthlyFee(StudentClass $class): int
    {
        // 月結課程的「每月應繳」估算：
        //   1) 若設定了 monthly_sessions 且有 Rate → monthly_sessions × 單堂單價（優先，最直覺）
        //   2) 否則使用 Charge 欄位（當該欄儲存月費時）
        //   3) 最後退而求其次用 Pay
        $monthlySessions = (int) ($class->monthly_sessions ?? 0);
        if ($monthlySessions > 0) {
            $unitPrice = $this->resolveUnitPrice($class, $monthlySessions);
            if ($unitPrice > 0) {
                return (int) round($unitPrice * $monthlySessions);
            }
        }

        $charge = (float) ($class->Charge ?? 0);
        if ($charge > 0) {
            return (int) round($charge);
        }

        $pay = (float) ($class->Pay ?? 0);
        if ($pay > 0) {
            return (int) round($pay);
        }

        return 0;
    }

    private function isClassPaid(StudentClass $class, array $paidAtMap): bool
    {
        return (bool) ($class->Paid ?? false) || array_key_exists((int) $class->ID, $paidAtMap);
    }

    private function resolveMonthlyDisplayPeriod(StudentClass $class, $invoiceRows): string
    {
        $invoiceRows = collect($invoiceRows ?? []);
        $invoice = $invoiceRows->first(function ($row) {
            return preg_match('/^\d{4}-\d{2}$/', (string) ($row->billing_period ?? ''));
        });

        if ($invoice) {
            return (string) $invoice->billing_period;
        }

        if (!empty($class->StartDate)) {
            try {
                return Carbon::parse($class->StartDate)->format('Y-m');
            } catch (\Throwable $e) {
            }
        }

        return Carbon::now()->format('Y-m');
    }

    private function formatBillingPeriodLabel(?string $period): string
    {
        if ($period && preg_match('/^\d{4}-(\d{2})$/', $period, $m)) {
            return ((int) $m[1]) . '月';
        }

        return Carbon::now()->format('n') . '月';
    }

    private function resolveUnitPrice(StudentClass $class, int $sessionCount): float
    {
        $rateUnit = (string) ($class->rate_unit ?? 'session');
        $rate = (float) ($class->Rate ?? 0);

        if ($rateUnit === 'hour' && $rate > 0) {
            $durationMinutes = max(30, (int) ($class->SessionDuration ?? 120));
            return $rate * ($durationMinutes / 60.0);
        }

        if ($rate > 0) {
            return $rate;
        }

        $charge = (float) ($class->Charge ?? 0);
        if ($charge > 0 && $sessionCount > 0) {
            return $charge / $sessionCount;
        }

        $pay = (float) ($class->Pay ?? 0);
        if ($pay > 0 && $sessionCount > 0) {
            return $pay / $sessionCount;
        }

        return 0;
    }

    private function resolveSubtotal(StudentClass $class, float $unitPrice, int $sessionCount): int
    {
        $charge = (float) ($class->Charge ?? 0);
        if ($charge > 0) {
            return (int) round($charge);
        }

        if ($unitPrice > 0 && $sessionCount > 0) {
            return (int) round($unitPrice * $sessionCount);
        }

        $pay = (float) ($class->Pay ?? 0);
        if ($pay > 0) {
            return (int) round($pay);
        }

        return 0;
    }

    private function formatRocDate($value): string
    {
        if (!$value) {
            return '日期待確認';
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable $e) {
            return '日期待確認';
        }

        $rocYear = $date->year - 1911;
        return sprintf('%d.%d.%d', $rocYear, $date->month, $date->day);
    }

    private function formatAmount($amount, bool $withThousands = true): string
    {
        $numeric = (float) $amount;
        $isInt = abs($numeric - round($numeric)) < 0.00001;
        if ($isInt) {
            return $withThousands
                ? number_format((int) round($numeric))
                : (string) ((int) round($numeric));
        }

        $formatted = number_format($numeric, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function resolveSubjectName(StudentClass $class): string
    {
        $raw = trim((string) ($class->Subject ?? ''));
        if ($raw !== '') {
            if (!ctype_digit($raw)) {
                $mapped = $this->mapSubjectLabel($raw);
                return $mapped !== '' ? $mapped : $raw;
            }

            $subjectFromRawId = Subject::query()->where('id', (int) $raw)->value('Subject_Name');
            if (!empty($subjectFromRawId)) {
                return trim((string) $subjectFromRawId);
            }
        }

        $subjectId = trim((string) ($class->SubjectID ?? ''));
        if ($subjectId !== '') {
            $subjectIdInt = (int) $subjectId;
            if ($subjectIdInt > 0) {
                static $subjectNameCache = [];
                if (!array_key_exists($subjectIdInt, $subjectNameCache)) {
                    // Check BaseData table first (legacy course mapping), then Subject table
                    $name = \Illuminate\Support\Facades\DB::table('BaseData')
                        ->where('Name', '課程')
                        ->where('id', $subjectIdInt)
                        ->value('Val');
                    if (empty($name)) {
                        $name = Subject::query()->where('id', $subjectIdInt)->value('Subject_Name');
                    }
                    $subjectNameCache[$subjectIdInt] = trim((string) ($name ?? ''));
                }
                if ($subjectNameCache[$subjectIdInt] !== '') {
                    return $subjectNameCache[$subjectIdInt];
                }
            }
        }

        $mappedById = $this->mapSubjectLabel($subjectId);
        if ($mappedById !== '') {
            return $mappedById;
        }

        return '課程';
    }

    private function mapSubjectLabel(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }

        $map = [
            '1' => '國文',  '2' => '英文',  '3' => '數學',
            '4' => '理化',  '5' => '社會',
            // 英文別名
            'Chinese'  => '國文', '國文課' => '國文',
            'English'  => '英文', '英文課' => '英文',
            'Math'     => '數學', '數學課' => '數學',
            'Science'  => '理化', '理化課' => '理化', '理化' => '理化',
            'Social'   => '社會', '社會課' => '社會',
            'Physics'  => '物理', '物理課' => '物理',
            'Chemistry'=> '化學', '化學課' => '化學',
            'Biology'  => '生物', '生物課' => '生物',
        ];

        return $map[$v] ?? '';
    }
}
