<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\PendingSwipe;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('StudentSingIn as si')
            ->join('StudentClass as sc', 'sc.ID', '=', 'si.StudentClassID')
            ->leftJoin('Student as st', 'st.id', '=', 'si.StudentID')
            ->leftJoin('Teacher as t', 't.id', '=', 'si.TeacherID')
            ->leftJoin('User as u', 'u.id', '=', 'si.TeacherID')
            ->leftJoin('Campus as c', 'c.id', '=', 'si.CampusID')
            ->leftJoin('Subject as sub', 'sub.id', '=', 'sc.SubjectID')
            ->leftJoin('Teacher as sct', 'sct.id', '=', 'sc.TeacherID')
            ->leftJoin('User as scu', 'scu.id', '=', 'sc.TeacherID')
            ->select([
                'si.*',
                'st.name as student_name',
                DB::raw("COALESCE(t.T_Name, u.Name, '') as teacher_name"),
                'st.CampusID as student_campus_id',
                'c.name as campus_name',
                'sub.Subject_Name as subject_name',
                DB::raw("COALESCE(sct.T_Name, scu.Name, '') as course_teacher_name"),
                'sc.ClassType as class_type',
            ]);
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $selectedCampusId = null;

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $selectedCampusId = $bid;
        }

        if ($role === 'teacher') {
            $teacherId = $request->attributes->get('auth_teacher_id');
            if (!$teacherId) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
            $query->where('si.TeacherID', $teacherId);
        }

        // Use the CampusID stored on the attendance record itself (snapshot at
        // sign-in time) instead of the student's current CampusID. This ensures
        // records stay with the correct branch even after a student transfers.
        if ($selectedCampusId !== null) {
            $query->where(function ($q) use ($selectedCampusId) {
                $q->where('si.CampusID', $selectedCampusId)
                  ->orWhere(function ($q2) use ($selectedCampusId) {
                      // Fallback for legacy records that don't have CampusID set
                      $q2->whereNull('si.CampusID')
                         ->whereIn('si.StudentID', Student::where('CampusID', $selectedCampusId)->pluck('id'));
                  });
            });
        } elseif (!empty($campusIds)) {
            $query->where(function ($q) use ($campusIds) {
                $q->whereIn('si.CampusID', $campusIds)
                  ->orWhere(function ($q2) use ($campusIds) {
                      // Fallback for legacy records that don't have CampusID set
                      $q2->whereNull('si.CampusID')
                         ->whereIn('si.StudentID', Student::whereIn('CampusID', $campusIds)->pluck('id'));
                  });
            });
        }

        if ($request->filled('student_id')) {
            $query->where('si.StudentID', $request->input('student_id'));
        }

        if ($request->filled('student_class_id')) {
            $query->where('si.StudentClassID', $request->input('student_class_id'));
        }

        if ($request->filled('date')) {
            $query->whereDate('si.SignInDT', $request->input('date'));
        }

        $records = $query->orderByDesc('si.id')->paginate((int) ($request->input('per_page', 20)));
        $statusLabelMap = [
            'present' => '到班',
            'late' => '遲到',
            'excused' => '請假',
            'absent' => '缺席',
            'leave' => '離班',
        ];

        $records->getCollection()->transform(function ($row) use ($statusLabelMap) {
            $personType = strtolower((string) ($row->PersonType ?? 'student'));
            $isTeacher = $personType === 'teacher';
            $personName = $isTeacher
                ? ($row->teacher_name ?: ($row->student_name ?: '—'))
                : ($row->student_name ?: ($row->teacher_name ?: '—'));

            $row->person_name = $personName;
            $row->person_type_label = $isTeacher ? '老師' : '學生';
            $row->status_label = $statusLabelMap[$row->Status] ?? $row->Status;
            if (empty($row->campus_name) && !empty($row->student_campus_id)) {
                $row->campus_name = '分校 #' . $row->student_campus_id;
            }
            $row->subject_name = $row->subject_name ?? '';
            $row->course_teacher_name = $row->course_teacher_name ?? '';
            $row->class_type = $row->class_type ?? '';
            return $row;
        });

        return response()->json($records);
    }

    /**
     * GET 已結束且尚未點名的節次（供「依節次點名」使用）
     * Query: branch_id (optional), date (optional, default today and past)
     */
    public function endedSessions(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $campusIds = [$bid];
        }

        $studentIds = Student::whereIn('CampusID', $campusIds)->pluck('id');
        $classQuery = StudentClass::whereIn('StudentID', $studentIds);
        if ($role === 'teacher') {
            $teacherId = $request->attributes->get('auth_teacher_id');
            if ($teacherId) {
                $classQuery->where('TeacherID', $teacherId);
            }
        }
        $classIds = $classQuery->pluck('ID');

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $sessions = ClassSession::with(['studentClass.student'])
            ->whereIn('StudentClassID', $classIds)
            ->whereDoesntHave('signIns')
            ->whereRaw("CONCAT(ClassSession.SessionDate, ' ', COALESCE(ClassSession.EndTime, '23:59:59')) <= ?", [$now])
            ->orderBy('SessionDate', 'desc')
            ->orderBy('StartTime', 'desc')
            ->limit(100)
            ->get();

        $subjectIds = $sessions->pluck('studentClass.SubjectID')->filter()->unique()->values();
        $subjectNames = [];
        if ($subjectIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('Subject')) {
            $subjectNames = \Illuminate\Support\Facades\DB::table('Subject')
                ->whereIn('id', $subjectIds)
                ->pluck('Subject_Name', 'id')
                ->all();
        }

        $teacherIds = $sessions->pluck('studentClass.TeacherID')->filter()->unique()->values();
        $teacherNames = [];
        if ($teacherIds->isNotEmpty()) {
            $teacherNames = DB::table('User')->whereIn('id', $teacherIds)->pluck('Name', 'id')->all();
        }

        $list = $sessions->map(function (ClassSession $cs) use ($subjectNames, $teacherNames) {
            $sc = $cs->studentClass;
            $student = $sc ? $sc->student : null;
            $subjectId = $sc ? ($sc->SubjectID ?? 0) : 0;
            $teacherId = $sc ? (int) ($sc->TeacherID ?? 0) : 0;
            return [
                'id' => $cs->id,
                'class_session_id' => $cs->id,
                'student_class_id' => $cs->StudentClassID,
                'student_id' => $sc ? (int) $sc->StudentID : null,
                'teacher_id' => $teacherId,
                'session_date' => $cs->SessionDate ? Carbon::parse($cs->SessionDate)->toDateString() : null,
                'start_time' => $cs->StartTime,
                'end_time' => $cs->EndTime,
                'student_name' => $student ? $student->name : '—',
                'subject_name' => $subjectNames[$subjectId] ?? '—',
                'teacher_name' => $teacherNames[$teacherId] ?? '—',
            ];
        })->values()->all();

        return response()->json($list);
    }

    /**
     * 手動點名：依「該節」登記出缺勤。可帶 ClassSessionID 或 StudentClassID + SessionDate + StartTime + EndTime 唯一對應一節。
     * 預設僅該節結束後才可點名；老師可用 mark_mode=arrival 在到班時即點名核課。
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'StudentID' => 'required|integer',
            'StudentClassID' => 'required|integer',
            'TeacherID' => 'nullable|integer',
            'ClassSessionID' => 'nullable|integer',
            'SessionDate' => 'required_without:ClassSessionID|date',
            'StartTime' => 'required_without:ClassSessionID|date_format:H:i',
            'EndTime' => 'required_without:ClassSessionID|date_format:H:i',
            'SignInDT' => 'nullable|date',
            'SignOutDT' => 'nullable|date',
            'Hours' => 'nullable|integer',
            'Memo' => 'nullable|string|max:512',
            'Status' => 'nullable|in:present,absent,late,excused',
            'mark_mode' => 'nullable|in:arrival,ended',
        ]);

        return DB::transaction(function () use ($data) {
            $studentClass = StudentClass::findOrFail($data['StudentClassID']);
            if ((int) $studentClass->StudentID !== (int) $data['StudentID']) {
                return response()->json(['message' => 'Student does not match class'], 422);
            }

            $role = request()->attributes->get('auth_role');
            $authUser = request()->attributes->get('auth_user');
            $recordedByUserId = (int) ($authUser->id ?? 0);
            $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);
            $authTeacherId = 0;
            if ($role === 'teacher') {
                $teacherId = (int) (request()->attributes->get('auth_teacher_id') ?? 0);
                $authTeacherId = $teacherId;
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
            $classSession = null;

            if (!empty($data['ClassSessionID'])) {
                $classSession = ClassSession::findOrFail($data['ClassSessionID']);
                if ((int) $classSession->StudentClassID !== (int) $studentClass->ID) {
                    return response()->json(['message' => 'Session does not match class'], 422);
                }
            } else {
                $classSession = ClassSession::create([
                    'StudentClassID' => $studentClass->ID,
                    'SessionDate' => $data['SessionDate'],
                    'StartTime' => $data['StartTime'],
                    'EndTime' => $data['EndTime'],
                    'Status' => 'scheduled',
                    'Note' => '',
                ]);
            }

            $markMode = (string) ($data['mark_mode'] ?? 'ended');
            $allowArrivalRoles = ['teacher', 'director', 'admin', 'super_admin'];
            $allowArrivalMark = in_array((string) $role, $allowArrivalRoles, true) && $markMode === 'arrival';
            // 預設規則：該節結束後才能點名；老師在 arrival 模式可到班即核課
            $sessionEnd = Carbon::parse($classSession->SessionDate . ' ' . $classSession->EndTime);
            if (!$allowArrivalMark && $sessionEnd->gt(now())) {
                return response()->json(['message' => '該節尚未結束，無法點名'], 422);
            }

            if ($classSession->id) {
                $existing = StudentSignIn::where('ClassSessionID', $classSession->id)->first();
                if ($existing) {
                    return response()->json(['message' => 'Attendance already recorded'], 409);
                }
            }

            [$signInDT, $signOutDT, $hours] = $this->resolveTimes($data, $classSession);

            $status = $data['Status'] ?? 'present';
            $effectiveTeacherId = (int) ($data['TeacherID'] ?? $studentClass->TeacherID);
            if ($authTeacherId > 0) {
                // 老師端一律以登入教師身分寫入，避免 payload 偽造
                $effectiveTeacherId = $authTeacherId;
            }

            // Snapshot the student's current CampusID so the record stays
            // correctly attributed even if the student later transfers.
            $student = Student::find($data['StudentID']);

            $signIn = StudentSignIn::create([
                'StudentClassID' => $studentClass->ID,
                'StudentID' => $data['StudentID'],
                'TeacherID' => $effectiveTeacherId,
                'RecordedByUserID' => $recordedByUserId > 0 ? $recordedByUserId : null,
                'GradeID' => $studentClass->GradeID,
                'SubjectID' => $studentClass->SubjectID,
                'Get1byID' => $studentClass->by1,
                'Hours' => $data['Hours'] ?? $hours,
                'Memo' => $data['Memo'] ?? '',
                'SignInDT' => $signInDT,
                'SignOutDT' => $signOutDT,
                'MDT' => now(),
                'ClassSessionID' => $classSession->id,
                'Status' => $status,
                'CampusID' => $student->CampusID ?? null,
                'PersonType' => 'student',
                'SessionDeducted' => false,
            ]);

            $this->applyAttendanceEffects($classSession, $status);

            // 點名成功才扣堂：僅 present / late 觸發扣堂
            if (in_array($status, ['present', 'late'], true) && !$signIn->SessionDeducted) {
                SessionDeductionService::deductOnAttendance($studentClass, $signIn);
            }

            $payload = $signIn->toArray();
            $payload['mark_mode'] = $allowArrivalMark ? 'arrival' : 'ended';
            $payload['person_name'] = $student->name ?? '';
            $payload['person_type_label'] = '學生';
            $payload['status_label'] = match ($status) {
                'present' => '到班',
                'late' => '遲到',
                'excused' => '請假',
                'absent' => '缺席',
                'leave' => '離班',
                default => (string) $status,
            };
            $payload['campus_name'] = null;
            if (!empty($student->CampusID)) {
                $payload['campus_name'] = optional(Campus::find((int) $student->CampusID))->name;
            }
            $payload['recorded_by_name'] = $recordedByUserId > 0 ? ($authUser->Name ?? '') : '';
            return response()->json($payload, 201);
        });
    }

    public function swipe(Request $request)
    {
        $data = $request->validate([
            'RFID' => 'required|string|max:32',
            'SwipeAt' => 'nullable|date',
        ]);

        $swipeAt = !empty($data['SwipeAt']) ? Carbon::parse($data['SwipeAt']) : now();
        $apiCampusId = $request->attributes->get('api_campus_id');
        $student = Student::where('RFID', $data['RFID'])->first();

        if (!$student) {
            $this->recordPendingSwipe($data['RFID'], $swipeAt, null, $apiCampusId, 'student_not_found', $request->all());
            return response()->json(['status' => 'pending', 'reason' => 'student_not_found'], 202);
        }

        if ($apiCampusId && (int) $student->CampusID !== (int) $apiCampusId) {
            $this->recordPendingSwipe($data['RFID'], $swipeAt, $student->id, $apiCampusId, 'campus_mismatch', $request->all());
            return response()->json(['status' => 'pending', 'reason' => 'campus_mismatch'], 202);
        }

        $campusId = $apiCampusId ?: $student->CampusID;
        $windowMinutes = $this->resolveSwipeWindowMinutes($campusId);
        $sessions = ClassSession::with('studentClass')
            ->whereDate('SessionDate', $swipeAt->toDateString())
            ->whereHas('studentClass', function ($query) use ($student) {
                $query->where('StudentID', $student->id)
                    ->where('Stop', 0);
            })
            ->get();

        if ($sessions->isEmpty()) {
            $this->recordPendingSwipe($data['RFID'], $swipeAt, $student->id, $campusId, 'no_session', $request->all());
            return response()->json(['status' => 'pending', 'reason' => 'no_session'], 202);
        }

        [$matchedSession, $matchStatus] = $this->matchClosestSession($sessions, $swipeAt, $windowMinutes);

        if ($matchStatus !== 'matched' || !$matchedSession) {
            $this->recordPendingSwipe($data['RFID'], $swipeAt, $student->id, $campusId, $matchStatus, $request->all());
            return response()->json(['status' => 'pending', 'reason' => $matchStatus], 202);
        }

        // Wrap the attendance creation in a transaction with proper error
        // handling to prevent duplicate records from concurrent swipes.
        try {
            return DB::transaction(function () use ($matchedSession, $student, $swipeAt, $campusId) {
                $studentClass = $matchedSession->studentClass;

                // Lock the session row to prevent concurrent duplicate check race
                $existing = StudentSignIn::where('ClassSessionID', $matchedSession->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return response()->json(['message' => 'Attendance already recorded'], 409);
                }

                $status = $this->resolveSwipeStatus($matchedSession, $swipeAt);

                $signIn = StudentSignIn::create([
                    'StudentClassID' => $studentClass->ID,
                    'StudentID' => $student->id,
                    'TeacherID' => $studentClass->TeacherID,
                    'RecordedByUserID' => null,
                    'GradeID' => $studentClass->GradeID,
                    'SubjectID' => $studentClass->SubjectID,
                    'Get1byID' => $studentClass->by1,
                    'Hours' => null,
                    'Memo' => 'swipe',
                    'SignInDT' => $swipeAt,
                    'SignOutDT' => null,
                    'MDT' => now(),
                    'ClassSessionID' => $matchedSession->id,
                    'Status' => $status,
                    'CampusID' => $campusId,
                    'PersonType' => 'student',
                    'SessionDeducted' => false,
                ]);

                $this->applyAttendanceEffects($matchedSession, $status);

                // Swipe is also a valid attendance source; keep deduction
                // behavior consistent with manual attendance.
                if (in_array($status, ['present', 'late'], true) && !$signIn->SessionDeducted) {
                    SessionDeductionService::deductOnAttendance($studentClass, $signIn);
                }

                return response()->json($signIn, 201);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle unique constraint violation gracefully (concurrent swipe)
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
                return response()->json(['message' => 'Attendance already recorded'], 409);
            }
            throw $e;
        }
    }

    private function resolveTimes(array $data, ClassSession $classSession): array
    {
        if (!empty($data['SignInDT'])) {
            $signIn = Carbon::parse($data['SignInDT']);
            $signOut = !empty($data['SignOutDT']) ? Carbon::parse($data['SignOutDT']) : null;
        } else {
            $signIn = Carbon::parse($classSession->SessionDate . ' ' . $classSession->StartTime);
            $signOut = Carbon::parse($classSession->SessionDate . ' ' . $classSession->EndTime);
        }

        $minutes = $signOut ? max($signOut->diffInMinutes($signIn), 0) : 0;
        $hours = $minutes > 0 ? (int) ceil($minutes / 60) : null;

        return [$signIn, $signOut, $hours];
    }

    private function applyAttendanceEffects(ClassSession $classSession, string $status): void
    {
        $sessionStatus = match ($status) {
            'present' => 'attended',
            'late' => 'late',
            'excused' => 'excused',
            'absent' => 'absent',
            default => 'attended',
        };

        $classSession->Status = $sessionStatus;
        $classSession->save();
    }

    private function resolveSwipeStatus(ClassSession $classSession, Carbon $swipeAt): string
    {
        $startTime = Carbon::parse($classSession->SessionDate . ' ' . $classSession->StartTime);
        $graceMinutes = 15;

        return $swipeAt->greaterThan($startTime->copy()->addMinutes($graceMinutes)) ? 'late' : 'present';
    }

    private function resolveSwipeWindowMinutes(?int $campusId): int
    {
        if (!$campusId) {
            return 30;
        }

        $campus = Campus::find($campusId);
        if (!$campus || !$campus->SwipeWindowMinutes) {
            return 30;
        }

        return (int) $campus->SwipeWindowMinutes;
    }

    private function matchClosestSession($sessions, Carbon $swipeAt, int $windowMinutes): array
    {
        $closest = null;
        $closestDiff = null;
        $tie = false;

        foreach ($sessions as $session) {
            $startTime = Carbon::parse($session->SessionDate . ' ' . $session->StartTime);
            $diff = abs($startTime->diffInMinutes($swipeAt));

            if ($diff > $windowMinutes) {
                continue;
            }

            if ($closestDiff === null || $diff < $closestDiff) {
                $closest = $session;
                $closestDiff = $diff;
                $tie = false;
            } elseif ($diff === $closestDiff) {
                $tie = true;
            }
        }

        if (!$closest) {
            return [null, 'no_match_in_window'];
        }

        if ($tie) {
            return [null, 'ambiguous_session'];
        }

        return [$closest, 'matched'];
    }

    private function recordPendingSwipe(
        string $rfid,
        Carbon $swipeAt,
        ?int $studentId,
        ?int $campusId,
        string $reason,
        array $payload
    ): void {
        PendingSwipe::create([
            'RFID' => $rfid,
            'StudentID' => $studentId,
            'CampusID' => $campusId,
            'SwipeAt' => $swipeAt,
            'Reason' => $reason,
            'Payload' => json_encode($payload),
        ]);
    }
}
