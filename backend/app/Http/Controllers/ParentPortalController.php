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

        return response()->json($this->createSession($student));
    }

    // ── Login: LINE userId ────────────────────────────────────────────────

    public function loginWithLine(Request $request)
    {
        $data = $request->validate([
            'line_user_id' => 'required|string',
        ]);

        $student = Student::where('LineID', $data['line_user_id'])->first();
        if (!$student) {
            return response()->json(['message' => '尚未綁定學生帳號，請透過 LINE 官方帳號輸入「綁定 學生姓名 手機號碼」完成綁定'], 404);
        }

        return response()->json($this->createSession($student));
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

        // Learning records (approved only)
        $records = [];
        if (!empty($classIds)) {
            $records = LearningRecord::whereIn('StudentClassID', $classIds)
                ->where('Status', 'approved')
                ->orderBy('ApprovedAt', 'desc')
                ->limit(50)
                ->get()
                ->map(function ($rec) {
                    $teacher = User::find($rec->TeacherID);
                    $rec->teacher_name = $teacher ? $teacher->Name : null;
                    return $rec;
                });
        }

        // Attendance history
        $attendance = StudentSignIn::where('StudentID', $student->id)
            ->orderBy('SignInDT', 'desc')
            ->limit(100)
            ->get();

        // Per-course remaining session breakdown
        $perCourse = $classes->map(function ($c) {
            return [
                'id'                 => $c->ID,
                'subject'            => $c->Subject,
                'schedule_mode'      => $c->ScheduleMode,
                'sessions_purchased' => $c->SessionCount,
                'remaining_sessions' => $c->RemainingSessions,
                'used_sessions'      => $c->UsedSessions,
                'is_stopped'         => (bool) $c->Stop,
                'paid'               => (bool) $c->Paid,
            ];
        });

        $remainingTotal = $classes
            ->where('ScheduleMode', 'count')
            ->sum(fn ($c) => (int) ($c->RemainingSessions ?? 0));

        // Payment alerts (session-based with ≤2 remaining or paid=0 and stop=1)
        $paymentAlerts = $classes
            ->filter(function ($c) {
                if ($c->ScheduleMode !== 'count' && ($c->SessionCount ?? 0) <= 0) {
                    return false;
                }
                $remaining = (int) ($c->RemainingSessions ?? 0);
                return $remaining <= 2 || $c->Paid == 0;
            })
            ->map(function ($c) {
                return [
                    'class_id'           => $c->ID,
                    'subject'            => $c->Subject,
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
                    $session->Subject = $c ? $c->Subject : null;
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

        return response()->json([
            'student' => [
                'id'   => $student->id,
                'name' => $student->name,
            ],
            'remaining_sessions_total' => $remainingTotal,
            'classes'                  => $perCourse,
            'payment_alerts'           => $paymentAlerts,
            'learning_records'         => $records,
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

        $classes = StudentClass::where('StudentID', $student->id)
            ->where(function ($q) {
                $q->where('RemainingSessions', '<=', 2)
                  ->orWhere('Paid', 0);
            })
            ->get();

        if ($classes->isEmpty()) {
            return response()->json(['message' => '此學生目前無待繳費課程']);
        }

        $lines = [];
        foreach ($classes as $c) {
            $remaining = (int) ($c->RemainingSessions ?? 0);
            $subject = $c->Subject ?? '課程';
            if ($c->Paid == 0 && $remaining <= 0) {
                $lines[] = "・{$subject}：已用完，請盡快繳費續課";
            } elseif ($remaining <= 2) {
                $lines[] = "・{$subject}：剩餘 {$remaining} 堂，請盡快繳費";
            }
        }

        $courseText = implode("\n", $lines);
        $message = "親愛的家長您好，\n\n{$student->name} 同學的課程即將用完，請盡速繳費，以免影響上課。\n\n{$courseText}\n\n如有疑問，歡迎聯繫補習班，謝謝！";

        return response()->json(['message' => $message, 'student_name' => $student->name]);
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

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }
}
