<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Announcement;
use App\Models\Subject;
use App\Models\User;
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

        $student = null;

        if (!empty($data['StudentID']) && (int) $data['StudentID'] > 0) {
            $student = Student::find((int) $data['StudentID']);
            if ($student && empty(trim($student->Phone ?? ''))) {
                return response()->json(['message' => '此學生尚未設定聯絡手機，請聯繫分校補登後再登入'], 401);
            }
        } elseif (!empty(trim($data['Name'] ?? ''))) {
            $name = trim($data['Name']);
            $candidates = Student::where('name', $name)->get();
            foreach ($candidates as $s) {
                if (!empty($s->Phone) && $this->normalizePhone($s->Phone) === $phoneNorm) {
                    $student = $s;
                    break;
                }
            }
            // 有找到同名學生但手機都不符時，若其中有人未填手機，提示請分校補登
            if (!$student && $candidates->isNotEmpty()) {
                $hasEmptyPhone = $candidates->contains(fn ($s) => empty(trim($s->Phone ?? '')));
                if ($hasEmptyPhone) {
                    return response()->json(['message' => '此學生尚未設定聯絡手機，請聯繫分校補登後再登入'], 401);
                }
            }
        }

        if (!$student || empty($student->Phone)) {
            return response()->json(['message' => '查無此學生或手機號碼不符，請確認姓名與手機是否正確'], 401);
        }

        if ($this->normalizePhone($student->Phone) !== $phoneNorm) {
            return response()->json(['message' => '查無此學生或手機號碼不符，請確認姓名與手機是否正確'], 401);
        }

        $result = $this->createSession($student);

        // Find siblings: other students with the same phone number
        $siblings = Student::where('id', '!=', $student->id)
            ->get()
            ->filter(fn ($s) => !empty($s->Phone) && $this->normalizePhone($s->Phone) === $phoneNorm);
        $allStudents = collect([['id' => $student->id, 'name' => $student->name]])
            ->concat($siblings->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]))
            ->values();
        if ($allStudents->count() > 1) {
            $result['students'] = $allStudents;
        }

        return response()->json($result);
    }

    // ── Login: LINE userId ────────────────────────────────────────────────

    public function loginWithLine(Request $request)
    {
        $data = $request->validate([
            'line_user_id' => 'required|string',
        ]);

        $students = Student::where('LineID', $data['line_user_id'])->get();
        if ($students->isEmpty()) {
            return response()->json(['message' => '尚未綁定學生帳號（此入口僅供家長/學生）。請透過 LINE 官方帳號輸入「綁定 學生姓名 手機號碼」完成綁定'], 404);
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

        // Verify the parent has access: same phone or same LineID
        $currentStudent = Student::find($session->StudentID);
        $allowed = false;

        if ($currentStudent) {
            // Same LineID
            if (!empty($currentStudent->LineID) && $currentStudent->LineID === $targetStudent->LineID) {
                $allowed = true;
            }
            // Same phone
            if (!empty($currentStudent->Phone) && !empty($targetStudent->Phone)
                && $this->normalizePhone($currentStudent->Phone) === $this->normalizePhone($targetStudent->Phone)) {
                $allowed = true;
            }
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

        // Learning records (approved only) — paginated
        $lrPage    = max(1, (int) $request->query('lr_page', 1));
        $lrPerPage = min(50, max(5, (int) $request->query('lr_per_page', 10)));
        $records      = [];
        $lrTotal      = 0;
        $lrHasMore    = false;
        if (!empty($classIds)) {
            $lrQuery = LearningRecord::whereIn('StudentClassID', $classIds)
                ->where('Status', 'approved');
            $lrTotal = $lrQuery->count();
            $records = $lrQuery
                ->orderBy('ApprovedAt', 'desc')
                ->skip(($lrPage - 1) * $lrPerPage)
                ->take($lrPerPage)
                ->get()
                ->map(function ($rec) use ($classes) {
                    $teacher = User::find($rec->TeacherID);
                    $rec->teacher_name = $teacher ? $teacher->Name : null;
                    $sc = $classes->firstWhere('ID', $rec->StudentClassID);
                    if ($sc) {
                        $rec->Subject = $this->resolveSubjectName($sc);
                    }
                    return $rec;
                });
            $lrHasMore = ($lrPage * $lrPerPage) < $lrTotal;
        }

        // Attendance history
        $attendance = StudentSignIn::where('StudentID', $student->id)
            ->orderBy('SignInDT', 'desc')
            ->limit(100)
            ->get();

        // Per-course remaining session breakdown — only truly active courses
        $perCourse = $classes
            ->filter(function ($c) {
                $paid      = (bool) $c->Paid;
                $remaining = (int) ($c->RemainingSessions ?? 0);
                $stopped   = (bool) $c->Stop;

                // Fully used + paid → course complete, hide
                if ($paid && $remaining <= 0) {
                    return false;
                }

                // Stopped + paid → no longer active, hide
                if ($stopped && $paid) {
                    return false;
                }

                return true;
            })
            ->map(function ($c) {
                return [
                    'id'                 => $c->ID,
                    'subject'            => $this->resolveSubjectName($c),
                    'schedule_mode'      => $c->ScheduleMode,
                    'sessions_purchased' => $c->SessionCount,
                    'remaining_sessions' => $c->RemainingSessions,
                    'used_sessions'      => $c->UsedSessions,
                    'is_stopped'         => (bool) $c->Stop,
                    'paid'               => (bool) $c->Paid,
                ];
            })
            ->values();

        $remainingTotal = $classes
            ->where('ScheduleMode', 'count')
            ->sum(fn ($c) => (int) ($c->RemainingSessions ?? 0));

        $remainingBySubject = $classes
            ->where('ScheduleMode', 'count')
            ->filter(fn ($c) => (int) ($c->RemainingSessions ?? 0) > 0)
            ->groupBy(fn ($c) => $this->resolveSubjectName($c))
            ->map(fn ($group) => $group->sum(fn ($c) => (int) ($c->RemainingSessions ?? 0)))
            ->sortDesc()
            ->toArray();

        // Payment alerts — only show courses that still require parent action
        $paymentAlerts = $classes
            ->filter(function ($c) {
                if ($c->ScheduleMode !== 'count' && ($c->SessionCount ?? 0) <= 0) {
                    return false;
                }

                $remaining = (int) ($c->RemainingSessions ?? 0);
                $paid      = (bool) $c->Paid;
                $stopped   = (bool) $c->Stop;

                // Fully used + already paid → course complete, nothing to act on
                if ($paid && $remaining <= 0) {
                    return false;
                }

                // Stopped + already paid → no further payment needed
                if ($stopped && $paid) {
                    return false;
                }

                return $remaining <= 2 || !$paid;
            })
            ->map(function ($c) {
                return [
                    'class_id'           => $c->ID,
                    'subject'            => $this->resolveSubjectName($c),
                    'remaining_sessions' => (int) ($c->RemainingSessions ?? 0),
                    'paid'               => (bool) $c->Paid,
                    'is_stopped'         => (bool) $c->Stop,
                ];
            })
            ->values();

        // Upcoming sessions
        $upcomingSessions = [];
        if (!empty($classIds)) {
            $upcomingSessions = ClassSession::whereIn('StudentClassID', $classIds)
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
            $invoices = Invoice::with(['items', 'payments'])
                ->where('StudentID', $student->id)
                ->orderBy('IssueDate', 'desc')
                ->get();
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

        // Find all students this parent can access (same LineID or same phone)
        $siblingStudents = Student::where('id', '!=', $student->id)
            ->where(function ($q) use ($student) {
                if (!empty($student->LineID)) {
                    $q->orWhere('LineID', $student->LineID);
                }
                if (!empty($student->Phone)) {
                    $phoneNorm = $this->normalizePhone($student->Phone);
                    if ($phoneNorm !== '') {
                        $q->orWhere('Phone', $student->Phone)
                          ->orWhere('Phone', $phoneNorm);
                    }
                }
            })
            ->get();
        $allStudents = collect([['id' => $student->id, 'name' => $student->name]])
            ->concat($siblingStudents->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]))
            ->values();

        return response()->json([
            'student' => [
                'id'          => $student->id,
                'name'        => $student->name,
                'grade'       => $student->ClassID ?? null,
                'school'      => $student->SchoolName ?? null,
                'campus_name' => $campusName,
                'line_linked' => !empty($student->LineID),
            ],
            'students' => $allStudents->count() > 1 ? $allStudents->toArray() : null,
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
        ]);
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

        $classSession = ClassSession::findOrFail($sessionId);

        $ownsClass = StudentClass::where('ID', $classSession->StudentClassID)
            ->where('StudentID', $session->StudentID)
            ->exists();

        if (!$ownsClass) {
            return response()->json(['message' => 'Forbidden: This class does not belong to the authenticated student.'], 403);
        }

        if (!in_array($classSession->Status, ['scheduled', 'rescheduled'])) {
            return response()->json(['message' => 'Session cannot be altered.'], 422);
        }

        $classSession->Status = 'leave_requested';
        $classSession->save();

        return response()->json(['message' => 'Leave requested successfully.']);
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
            '1' => '國文',
            '2' => '英文',
            '3' => '數學',
            '4' => '理化',
            '5' => '社會',
            '理化' => '理化',
            'Chinese' => '國文',
            'English' => '英文',
            'Math' => '數學',
            'Science' => '理化',
            'Social' => '社會',
            'Physics' => '物理',
            'Chemistry' => '化學',
            'Biology' => '生物',
        ];

        return $map[$v] ?? '';
    }
}
