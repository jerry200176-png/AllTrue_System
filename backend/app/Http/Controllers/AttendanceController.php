<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\PendingSwipe;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\CourseLeaveCascadeService;
use App\Services\SessionDeductionService;
use App\Services\SubstituteScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('StudentSingIn as si')
            ->leftJoin('StudentClass as sc', 'sc.ID', '=', 'si.StudentClassID')
            ->leftJoin('Student as st', 'st.id', '=', 'si.StudentID')
            ->leftJoin('Teacher as t', 't.id', '=', 'si.TeacherID')
            ->leftJoin('User as u', 'u.id', '=', 'si.TeacherID')
            ->leftJoin('Campus as c', 'c.id', '=', 'si.CampusID')
            // Subject: prefer StudentClass (contract), fall back to snapshot on StudentSingIn
            // (many legacy rows have SubjectID on the sign-in only).
            ->leftJoin('Subject as sub_sc', 'sub_sc.id', '=', 'sc.SubjectID')
            ->leftJoin('Subject as sub_si', 'sub_si.id', '=', 'si.SubjectID')
            ->leftJoin('Teacher as sct', 'sct.id', '=', 'sc.TeacherID')
            ->leftJoin('User as scu', 'scu.id', '=', 'sc.TeacherID')
            ->select([
                'si.*',
                'st.name as student_name',
                DB::raw("COALESCE(t.T_Name, u.Name, '') as teacher_name"),
                'st.CampusID as student_campus_id',
                'c.name as campus_name',
                DB::raw('COALESCE(sub_sc.Subject_Name, sub_si.Subject_Name) as subject_name'),
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

        // Date filtering: single `date` (legacy/single-day) OR `start_date`/`end_date` range.
        // When nothing is provided, default to the last 7 days so admins can see recent
        // attended records without having to manually pick a date every time.
        if ($request->filled('date')) {
            $query->whereDate('si.SignInDT', $request->input('date'));
        } elseif ($request->filled('start_date') || $request->filled('end_date')) {
            $rangeStart = $request->input('start_date', Carbon::now()->subDays(6)->toDateString());
            $rangeEnd   = $request->input('end_date',   Carbon::now()->toDateString());
            $query->whereBetween(DB::raw('DATE(si.SignInDT)'), [$rangeStart, $rangeEnd]);
        } else {
            // Default: last 7 days (today inclusive).
            $query->whereBetween(DB::raw('DATE(si.SignInDT)'), [
                Carbon::now()->subDays(6)->toDateString(),
                Carbon::now()->toDateString(),
            ]);
        }

        $query->whereNull('si.VoidedAt');
        $records = $query->orderByDesc('si.id')->paginate((int) ($request->input('per_page', 20)));

        // ── Supplemental: ClassSessions with leave status that have no active StudentSignIn ──
        // Wrapped in try/catch so a SQL failure here never breaks the main response.
        // Only runs for single-date queries; range/default mode skips this supplemental.
        if ($request->filled('date')) {
            try {
                $leaveDate = $request->input('date');
                $leaveQ = DB::table('ClassSession as cs')
                    ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                    ->leftJoin('Student as st', 'st.id', '=', 'sc.StudentID')
                    ->leftJoin('Campus as c', 'c.id', '=', 'st.CampusID')
                    ->leftJoin('Subject as sub', 'sub.id', '=', 'sc.SubjectID')
                    ->leftJoin('Teacher as sct', 'sct.id', '=', 'sc.TeacherID')
                    ->leftJoin('User as scu', 'scu.id', '=', 'sc.TeacherID')
                    ->whereDate('cs.SessionDate', $leaveDate)
                    ->whereIn('cs.Status', ['leave', 'leave_adjusted'])
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('StudentSingIn as si_check')
                            ->whereColumn('si_check.ClassSessionID', 'cs.id')
                            ->whereNull('si_check.VoidedAt');
                    });

                if ($role === 'teacher') {
                    $teacherIdForLeave = $request->attributes->get('auth_teacher_id');
                    if ($teacherIdForLeave) {
                        $leaveQ->where('sc.TeacherID', $teacherIdForLeave);
                    }
                }
                if ($selectedCampusId !== null) {
                    $leaveQ->where('st.CampusID', $selectedCampusId);
                } elseif (!empty($campusIds)) {
                    $leaveQ->whereIn('st.CampusID', $campusIds);
                }

                $leaveRows = $leaveQ->select([
                    DB::raw('-cs.id as id'),
                    'sc.ID as StudentClassID',
                    'sc.StudentID',
                    'sc.TeacherID',
                    DB::raw('NULL as RecordedByUserID'),
                    DB::raw('NULL as GradeID'),
                    DB::raw('NULL as SubjectID'),
                    DB::raw('NULL as Get1byID'),
                    DB::raw('NULL as Hours'),
                    DB::raw('NULL as Memo'),
                    DB::raw("CONCAT(DATE(cs.SessionDate), ' ', COALESCE(cs.StartTime, '00:00:00')) as SignInDT"),
                    DB::raw('NULL as SignOutDT'),
                    DB::raw('NOW() as MDT'),
                    'cs.id as ClassSessionID',
                    DB::raw("'leave' as Status"),
                    DB::raw("'student' as PersonType"),
                    'st.CampusID',
                    DB::raw('0 as SessionDeducted'),
                    DB::raw('NULL as VoidedAt'),
                    DB::raw('NULL as VoidedByUserID'),
                    DB::raw('NULL as VoidReason'),
                    'st.name as student_name',
                    DB::raw("COALESCE(sct.T_Name, scu.Name, '') as teacher_name"),
                    'st.CampusID as student_campus_id',
                    'c.name as campus_name',
                    'sub.Subject_Name as subject_name',
                    DB::raw("COALESCE(sct.T_Name, scu.Name, '') as course_teacher_name"),
                    'sc.ClassType as class_type',
                ])->get();

                if ($leaveRows->isNotEmpty()) {
                    $records->getCollection()->push(...$leaveRows->all());
                }
            } catch (\Exception $e) {
                Log::warning('AttendanceController supplemental query failed: ' . $e->getMessage());
            }
        }

        $statusLabelMap = [
            'present' => '到班',
            'late' => '遲到',
            'absent' => '缺席',
            'leave' => '請假',
            'excused' => '請假',
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
     * GET 已結束且尚未點名的節次（供「事後補點名」使用）
     *
     * Query params:
     *   branch_id  (required for non-super_admin; super_admin must also provide)
     *   start_date (optional, default: 7 days ago)
     *   end_date   (optional, default: today)
     *   per_page   (optional, default 50, max 200)
     *   page       (optional, default 1)
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
        } elseif (empty($campusIds)) {
            return response()->json(['message' => '請選擇分校'], 422);
        }

        $startDate = $request->input('start_date', Carbon::now()->subDays(7)->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());
        $perPage = min((int) ($request->input('per_page', 50)), 200);

        $studentIds = Student::whereIn('CampusID', $campusIds)->pluck('id');
        $allClassIdsInCampus = StudentClass::whereIn('StudentID', $studentIds)->where('Stop', 0)->pluck('ID');
        if ($role === 'teacher') {
            $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            if ($teacherId > 0) {
                $contractClassIds = StudentClass::whereIn('StudentID', $studentIds)
                    ->where('TeacherID', $teacherId)
                    ->where('Stop', 0)
                    ->pluck('ID');
                $subClassIds = DB::table('schedules')
                    ->where('status', 'scheduled')
                    ->whereNotNull('original_schedule_id')
                    ->where('teacher_id', $teacherId)
                    ->whereBetween(DB::raw('DATE(schedule_date)'), [$startDate, $endDate])
                    ->whereIn('student_course_id', $allClassIdsInCampus)
                    ->pluck('student_course_id')
                    ->unique();
                $classIds = $contractClassIds->merge($subClassIds)->unique()->values();
            } else {
                $classIds = collect();
            }
        } else {
            $classIds = $allClassIdsInCampus;
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $sessionsBuilder = ClassSession::with(['studentClass.student'])
            ->whereIn('StudentClassID', $classIds)
            ->whereBetween('SessionDate', [$startDate, $endDate])
            ->whereDoesntHave('signIns', function ($q) {
                $q->whereNull('VoidedAt');
            })
            ->whereIn('Status', ['scheduled', 'absent'])
            ->whereRaw("CONCAT(ClassSession.SessionDate, ' ', COALESCE(ClassSession.EndTime, '23:59:59')) <= ?", [$now]);

        // Bug fix (2026-04-21)：堂次級精確過濾——代課後原老師不應在補點名看到被代課堂
        // classIds 是保守超集合（課程級）；此處補做堂次級（session × date × time）確認
        if ($role === 'teacher' && isset($teacherId) && $teacherId > 0) {
            $sessionsBuilder->where(function ($outer) use ($teacherId) {
                $outer->whereExists(function ($q) use ($teacherId) {
                    $q->select(DB::raw(1))
                        ->from('schedules as sub_s')
                        ->whereColumn('sub_s.student_course_id', 'ClassSession.StudentClassID')
                        ->whereColumn('sub_s.schedule_date', 'ClassSession.SessionDate')
                        ->whereRaw('sub_s.start_time = SUBSTRING(ClassSession.StartTime, 1, 5)')
                        ->where('sub_s.status', 'scheduled')
                        ->whereNotNull('sub_s.original_schedule_id')
                        ->where('sub_s.teacher_id', $teacherId);
                });
                $outer->orWhere(function ($noSub) use ($teacherId) {
                    $noSub->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('schedules as sub_s2')
                            ->whereColumn('sub_s2.student_course_id', 'ClassSession.StudentClassID')
                            ->whereColumn('sub_s2.schedule_date', 'ClassSession.SessionDate')
                            ->whereRaw('sub_s2.start_time = SUBSTRING(ClassSession.StartTime, 1, 5)')
                            ->where('sub_s2.status', 'scheduled')
                            ->whereNotNull('sub_s2.original_schedule_id');
                    })->whereExists(function ($q) use ($teacherId) {
                        $q->select(DB::raw(1))
                            ->from('StudentClass as sc_self')
                            ->whereColumn('sc_self.ID', 'ClassSession.StudentClassID')
                            ->where('sc_self.TeacherID', $teacherId);
                    });
                });
            });
        }

        $sessions = $sessionsBuilder
            ->orderBy('SessionDate', 'desc')
            ->orderBy('StartTime', 'desc')
            ->paginate($perPage);

        $items = $sessions->getCollection();

        $subjectIds = $items->pluck('studentClass.SubjectID')->filter()->unique()->values();
        $subjectNames = [];
        if ($subjectIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('Subject')) {
            $subjectNames = \Illuminate\Support\Facades\DB::table('Subject')
                ->whereIn('id', $subjectIds)
                ->pluck('Subject_Name', 'id')
                ->all();
        }

        $substituteBySession = $this->batchSubstituteTeacherIdsForClassSessions($items);

        $teacherIds = $items->pluck('studentClass.TeacherID')->filter()->unique()->values()
            ->merge(collect($substituteBySession)->filter()->values())
            ->unique()
            ->values();
        $teacherNames = [];
        if ($teacherIds->isNotEmpty()) {
            $teacherNames = DB::table('User')->whereIn('id', $teacherIds)->pluck('Name', 'id')->all();
        }

        $sessions->getCollection()->transform(function (ClassSession $cs) use ($subjectNames, $teacherNames, $substituteBySession) {
            $sc = $cs->studentClass;
            $student = $sc ? $sc->student : null;
            $subjectId = $sc ? ($sc->SubjectID ?? 0) : 0;
            $contractTeacherId = $sc ? (int) ($sc->TeacherID ?? 0) : 0;
            $subId = (int) ($substituteBySession[$cs->id] ?? 0);
            $teacherId = $subId > 0 ? $subId : $contractTeacherId;
            $sessionStatus = strtolower(trim((string) ($cs->Status ?? 'scheduled')));
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
                'session_status' => $sessionStatus,
            ];
        });

        return response()->json($sessions);
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
            'Status' => 'nullable|in:present,absent,late,excused,leave',
            'mark_mode' => 'nullable|in:arrival,ended',
        ]);

        return DB::transaction(function () use ($data) {
            $studentClass = StudentClass::where('ID', (int) $data['StudentClassID'])
                ->lockForUpdate()
                ->firstOrFail();
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
                if ($teacherId <= 0) {
                    return response()->json(['message' => '無法識別老師身分，請重新登入'], 403);
                }
                $sessionDateForSub = null;
                if (!empty($data['ClassSessionID'])) {
                    $tmpCs = ClassSession::find((int) $data['ClassSessionID']);
                    if ($tmpCs && (int) $tmpCs->StudentClassID === (int) $studentClass->ID) {
                        $sessionDateForSub = $tmpCs->SessionDate;
                    }
                } elseif (!empty($data['SessionDate'])) {
                    $sessionDateForSub = $data['SessionDate'];
                }
                $subTeacherId = $sessionDateForSub
                    ? $this->resolveSubstituteTeacherUserIdForSession((int) $studentClass->ID, $sessionDateForSub)
                    : null;
                $isContractTeacher = ((int) $studentClass->TeacherID === $teacherId);
                $isSubstituteTeacher = ($subTeacherId !== null && (int) $subTeacherId === $teacherId);
                if (!$isContractTeacher && !$isSubstituteTeacher) {
                    return response()->json(['message' => '非該課程的授課或代課老師，無法操作'], 403);
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
                $classSession = ClassSession::where('StudentClassID', $studentClass->ID)
                    ->whereDate('SessionDate', $data['SessionDate'])
                    ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [substr((string) $data['StartTime'], 0, 5)])
                    ->orderBy('id')
                    ->first();
                if (!$classSession) {
                    $classSession = ClassSession::create([
                        'StudentClassID' => $studentClass->ID,
                        'SessionDate' => $data['SessionDate'],
                        'StartTime' => $data['StartTime'],
                        'EndTime' => $data['EndTime'],
                        'Status' => 'scheduled',
                        'Note' => '',
                    ]);
                }
            }

            $markMode = (string) ($data['mark_mode'] ?? 'ended');
            $allowArrivalRoles = ['teacher', 'director', 'admin', 'super_admin'];
            $allowArrivalMark = in_array((string) $role, $allowArrivalRoles, true) && $markMode === 'arrival';
            // 預設規則：該節結束後才能點名；老師在 arrival 模式可到班即核課
            $sessionEnd = Carbon::parse($classSession->SessionDate . ' ' . $classSession->EndTime);
            if (!$allowArrivalMark && $sessionEnd->gt(now())) {
                return response()->json(['message' => '該節尚未結束，無法點名'], 422);
            }

            if ($classSession->id && $this->activeAttendanceExistsForSessionSlot($classSession)) {
                return response()->json(['message' => 'Attendance already recorded for this time slot'], 409);
            }

            $status = $data['Status'] ?? 'present';
            $effectiveTeacherId = (int) ($data['TeacherID'] ?? $studentClass->TeacherID);
            if ($authTeacherId > 0) {
                $effectiveTeacherId = $authTeacherId;
            }

            $student = Student::find($data['StudentID']);

            if ($status === 'excused') {
                $status = 'leave';
            }

            // ── leave + 既有堂次 → 走課程請假順延（同課程管理 POST schedules leave）
            if ($status === 'leave' && !empty($data['ClassSessionID'])) {
                try {
                    $leaveDate = Carbon::parse($classSession->SessionDate)->toDateString();
                    $branchId = (int) ($student->CampusID ?? 0);
                    $subjectName = DB::table('Subject')
                        ->where('id', (int) ($studentClass->SubjectID ?? 0))
                        ->value('Subject_Name') ?? '';

                    Schedule::create([
                        'student_id'        => (int) $data['StudentID'],
                        'teacher_id'        => $effectiveTeacherId > 0 ? $effectiveTeacherId : null,
                        'subject'           => $subjectName,
                        'day_of_week'       => Carbon::parse($leaveDate)->dayOfWeekIso,
                        'start_time'        => $classSession->StartTime ? substr((string) $classSession->StartTime, 0, 5) : '16:00',
                        'end_time'          => $classSession->EndTime ? substr((string) $classSession->EndTime, 0, 5) : '18:00',
                        'class_type'        => (string) ($studentClass->ClassType ?: 'one_on_one'),
                        'status'            => 'leave',
                        'type'              => 'normal',
                        'deduction'         => 0,
                        'branch_id'         => $branchId,
                        'schedule_date'     => $leaveDate,
                        'student_course_id' => (int) $studentClass->ID,
                    ]);

                    [$rows, $extendedEndDate, $leaveSessionDate] =
                        CourseLeaveCascadeService::applyLeaveCascade((int) $studentClass->ID, $leaveDate);

                    [$signInDT, $signOutDT] = [$classSession->StartTime
                        ? Carbon::parse($leaveDate . ' ' . $classSession->StartTime)
                        : now(), null];
                    StudentSignIn::create([
                        'StudentClassID'    => $studentClass->ID,
                        'StudentID'         => (int) $data['StudentID'],
                        'TeacherID'         => $effectiveTeacherId > 0 ? $effectiveTeacherId : null,
                        'RecordedByUserID'  => $recordedByUserId > 0 ? $recordedByUserId : null,
                        'GradeID'           => $studentClass->GradeID,
                        'SubjectID'         => $studentClass->SubjectID,
                        'CampusID'          => (int) ($student->CampusID ?? 0),
                        'SignInDT'          => $signInDT,
                        'SignOutDT'         => $signOutDT,
                        'MDT'               => now(),
                        'ClassSessionID'    => $classSession->id,
                        'Status'            => 'leave',
                        'SessionDeducted'   => 0,
                    ]);

                    return response()->json([
                        'message'            => '已請假並順延後續課程',
                        'status_label'       => '請假',
                        'person_name'        => $student->name ?? '',
                        'person_type_label'  => '學生',
                        'leave_session_date' => $leaveSessionDate,
                        'extended_end_date'  => $extendedEndDate,
                        'class_sessions'     => $rows,
                    ], 201);
                } catch (\InvalidArgumentException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
            }

            // ── 一般出缺勤（到班/遲到/缺席，或 leave 但無既有堂次）
            [$signInDT, $signOutDT, $hours] = $this->resolveTimes($data, $classSession);

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
                'absent' => '缺席',
                'leave' => '請假',
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

    /**
     * Batch mark attendance for multiple sessions in one request.
     * Each item is processed independently (partial success model).
     * Max 50 items per request. Auth/campus checks inherited from store().
     */
    public function batchMark(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.ClassSessionID' => 'required|integer',
            'items.*.StudentID' => 'required|integer',
            'items.*.StudentClassID' => 'required|integer',
            'items.*.Status' => 'required|in:present,late,excused,absent,leave',
            'items.*.TeacherID' => 'nullable|integer',
            'items.*.mark_mode' => 'nullable|in:arrival,ended',
        ]);

        $items = $request->input('items');
        $results = [];
        $savedInput = $request->all();

        Log::info('attendance.batch_mark', [
            'user_id' => $request->attributes->get('auth_user')?->id ?? null,
            'role' => $request->attributes->get('auth_role'),
            'count' => count($items),
        ]);

        foreach ($items as $item) {
            try {
                $request->replace($item);
                $response = $this->store($request);
                $body = json_decode($response->getContent(), true);
                $results[] = [
                    'class_session_id' => $item['ClassSessionID'],
                    'success' => $response->getStatusCode() < 400,
                    'status_code' => $response->getStatusCode(),
                    'data' => $body,
                ];
            } catch (\Throwable $e) {
                $statusCode = 500;
                $message = $e->getMessage();
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $statusCode = 422;
                    $message = implode('; ', collect($e->errors())->flatten()->all());
                }
                $results[] = [
                    'class_session_id' => $item['ClassSessionID'] ?? 0,
                    'success' => false,
                    'status_code' => $statusCode,
                    'data' => ['message' => $message],
                ];
            }
        }

        $request->replace($savedInput);

        $successCount = count(array_filter($results, fn($r) => $r['success']));

        return response()->json([
            'total' => count($results),
            'success_count' => $successCount,
            'fail_count' => count($results) - $successCount,
            'results' => $results,
        ], $successCount > 0 ? 200 : 422);
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
                $swipeTeacherId = (int) ($studentClass->TeacherID ?? 0);
                $subSwipeTid = $this->resolveSubstituteTeacherUserIdForSession(
                    (int) $studentClass->ID,
                    $matchedSession->SessionDate
                );
                if ($subSwipeTid !== null && $subSwipeTid > 0) {
                    $swipeTeacherId = $subSwipeTid;
                }

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
                    'TeacherID' => $swipeTeacherId > 0 ? $swipeTeacherId : null,
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
            'absent' => 'absent',
            'leave', 'excused' => 'leave',
            default => 'attended',
        };

        $classSession->Status = $sessionStatus;
        $classSession->save();
    }

    private function activeAttendanceExistsForSessionSlot(ClassSession $classSession): bool
    {
        $courseId = (int) ($classSession->StudentClassID ?? 0);
        $startTime = substr((string) ($classSession->StartTime ?? ''), 0, 5);
        if ($courseId <= 0 || $startTime === '') {
            return StudentSignIn::where('ClassSessionID', $classSession->id)
                ->whereNull('VoidedAt')
                ->lockForUpdate()
                ->exists();
        }

        try {
            $sessionDate = Carbon::parse((string) $classSession->SessionDate)->toDateString();
        } catch (\Throwable $e) {
            return StudentSignIn::where('ClassSessionID', $classSession->id)
                ->whereNull('VoidedAt')
                ->lockForUpdate()
                ->exists();
        }

        return DB::table('StudentSingIn as si')
            ->join('ClassSession as cs', 'cs.id', '=', 'si.ClassSessionID')
            ->where('si.StudentClassID', $courseId)
            ->whereNull('si.VoidedAt')
            ->whereDate('cs.SessionDate', $sessionDate)
            ->whereRaw('SUBSTRING(cs.StartTime, 1, 5) = ?', [$startTime])
            ->lockForUpdate()
            ->exists();
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

    /**
     * DELETE /api/v1/attendance/{id}
     * 主任軟刪除（Void）出缺勤記錄。
     * 若已扣堂自動沖回；若 ClassSession 為出勤狀態則退回 scheduled。
     */
    public function destroy(Request $request, int $id)
    {
        $request->validate([
            'void_reason' => 'required|string|min:2|max:500',
        ]);

        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        $authUser  = $request->attributes->get('auth_user');
        $voidReason = $request->input('void_reason');

        $signin = StudentSignIn::whereNull('VoidedAt')->find($id);
        if (! $signin) {
            return response()->json(['message' => '記錄不存在或已刪除'], 404);
        }

        // 分校隔離
        if ($role !== 'super_admin' && ! empty($campusIds)) {
            $campusId = $signin->CampusID;
            if (! $campusId || ! in_array($campusId, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $sessionReversed       = false;
        $classSessionReverted  = false;

        try {
            DB::transaction(function () use ($signin, $authUser, $voidReason, &$sessionReversed, &$classSessionReverted) {
                // ① Soft-delete
                $signin->VoidedAt        = now();
                $signin->VoidedByUserID  = $authUser?->id;
                $signin->VoidReason      = $voidReason;
                $signin->save();

                // ② 若已扣堂，沖回堂數
                if ($signin->SessionDeducted && $signin->StudentClassID) {
                    SessionDeductionService::reverseForSession(
                        (int) $signin->StudentClassID,
                        $signin->ClassSessionID ? (int) $signin->ClassSessionID : null,
                        'void_attendance',
                        $authUser?->id,
                        $voidReason
                    );
                    SessionDeductionService::recomputeCounters((int) $signin->StudentClassID);
                    $sessionReversed = true;
                }

                // ③ 若 ClassSession 為出勤狀態，退回 scheduled
                if ($signin->ClassSessionID) {
                    $session = ClassSession::find($signin->ClassSessionID);
                    if ($session && in_array($session->Status, ['attended', 'late', 'absent'], true)) {
                        $session->Status = 'scheduled';
                        $session->save();
                        $classSessionReverted = true;
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('[attendance-void] transaction failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => '刪除失敗，請稍後再試'], 500);
        }

        Log::info('[attendance-void]', [
            'voided_id'      => $id,
            'by_user'        => $authUser?->id,
            'void_reason'    => $voidReason,
            'session_reversed'      => $sessionReversed,
            'class_session_reverted' => $classSessionReverted,
        ]);

        return response()->json([
            'ok'                     => true,
            'voided_id'              => $id,
            'session_reversed'       => $sessionReversed,
            'class_session_reverted' => $classSessionReverted,
        ]);
    }

    /**
     * PATCH /api/v1/attendance/{id}
     * 直接更新出缺勤記錄的狀態（主要用於自修記錄無堂次關聯的狀態修改）。
     * 限主任／管理員；不觸發堂數扣除或 ClassSession 同步（自修無堂次）。
     */
    public function update(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|in:present,late,absent,leave,excused',
        ]);

        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);

        $signin = StudentSignIn::whereNull('VoidedAt')->find($id);
        if (! $signin) {
            return response()->json(['message' => '記錄不存在或已刪除'], 404);
        }

        if ($role !== 'super_admin' && ! empty($campusIds)) {
            $campusId = (int) ($signin->CampusID ?? 0);
            if (! $campusId || ! in_array($campusId, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $newStatus = $request->input('status');
        if ($newStatus === 'excused') {
            $newStatus = 'leave';
        }

        $signin->Status = $newStatus;
        $signin->save();

        $statusLabelMap = [
            'present' => '到班',
            'late'    => '遲到',
            'absent'  => '缺席',
            'leave'   => '請假',
        ];

        return response()->json([
            'ok'           => true,
            'id'           => $signin->id,
            'status'       => $newStatus,
            'status_label' => $statusLabelMap[$newStatus] ?? $newStatus,
        ]);
    }

    /**
     * POST /api/v1/attendance/{id}/convert-to-attended
     * 將自修記錄（Memo='self_study'）轉換為正式到班並扣堂。
     * Q2 決策：同日已有 scheduled ClassSession 則複用，無則建立新的。
     */
    public function convertToAttended(Request $request, int $id)
    {
        $request->validate([
            'student_class_id' => 'required|integer',
        ]);

        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        $authUser  = $request->attributes->get('auth_user');
        $studentClassId = (int) $request->input('student_class_id');

        $signin = StudentSignIn::whereNull('VoidedAt')
            ->where('Memo', 'self_study')
            ->find($id);

        if (! $signin) {
            return response()->json(['message' => '此記錄不存在或非自修記錄'], 422);
        }

        $studentClass = StudentClass::find($studentClassId);
        if (! $studentClass) {
            return response()->json(['message' => '課程不存在'], 404);
        }

        // 分校隔離：透過 Student → CampusID
        if ($role !== 'super_admin' && ! empty($campusIds)) {
            $studentCampusId = (int) (Student::where('id', $studentClass->StudentID)->value('CampusID') ?? 0);
            if (! in_array($studentCampusId, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        if ((int) ($studentClass->RemainingSessions ?? 0) <= 0) {
            return response()->json(['message' => '此課程剩餘堂數不足'], 422);
        }

        $sessionDate = Carbon::parse($signin->SignInDT)->toDateString();
        $startTime   = Carbon::parse($signin->SignInDT)->format('H:i:s');

        $classSessionId    = null;
        $remainingSessions = 0;

        try {
            DB::transaction(function () use (
                $signin, $studentClass, $studentClassId, $sessionDate, $startTime,
                $authUser, &$classSessionId, &$remainingSessions
            ) {
                // ① 複用同日 scheduled ClassSession，或建立新的（Q2: 選項 A）
                $existing = ClassSession::where('StudentClassID', $studentClassId)
                    ->whereDate('SessionDate', $sessionDate)
                    ->where('Status', 'scheduled')
                    ->first();

                if ($existing) {
                    $existing->Status = 'attended';
                    $existing->save();
                    $classSessionId = $existing->id;
                } else {
                    $endTime = Carbon::parse($signin->SignInDT)->addHour()->format('H:i:s');
                    $session = ClassSession::create([
                        'StudentClassID' => $studentClassId,
                        'SessionDate'    => $sessionDate,
                        'StartTime'      => $startTime,
                        'EndTime'        => $endTime,
                        'Status'         => 'attended',
                    ]);
                    $classSessionId = $session->id;
                }

                // ② 更新 StudentSingIn
                $signin->ClassSessionID  = $classSessionId;
                $signin->StudentClassID  = $studentClassId;
                $signin->Memo            = null;
                $signin->SessionDeducted = true;
                $signin->save();

                // ③ 扣堂
                SessionDeductionService::deductForSession(
                    $studentClassId,
                    $classSessionId,
                    'convert_self_study',
                    $authUser?->id,
                    '自修轉到班'
                );
                SessionDeductionService::recomputeCounters($studentClassId);

                $remainingSessions = (int) (StudentClass::find($studentClassId)?->RemainingSessions ?? 0);
            });
        } catch (\Throwable $e) {
            Log::error('[attendance-convert] transaction failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => '轉換失敗，請稍後再試'], 500);
        }

        Log::info('[attendance-convert]', [
            'signin_id'        => $id,
            'student_class_id' => $studentClassId,
            'class_session_id' => $classSessionId,
            'by_user'          => $authUser?->id,
        ]);

        return response()->json([
            'ok'                => true,
            'class_session_id'  => $classSessionId,
            'remaining_sessions' => $remainingSessions,
        ]);
    }

    /**
     * Single-session substitute: schedules row (scheduled + original_schedule_id) carries 代課 User id.
     */
    private function resolveSubstituteTeacherUserIdForSession(int $studentClassId, $sessionDate): ?int
    {
        return SubstituteScheduleService::resolveSubstituteUserId($studentClassId, $sessionDate);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClassSession>  $items
     * @return array<int, int>  class_session_id => substitute teacher user id
     */
    private function batchSubstituteTeacherIdsForClassSessions($items): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $courseIds = $items->pluck('StudentClassID')->unique()->filter()->values()->all();
        if ($courseIds === []) {
            return [];
        }
        $rows = DB::table('schedules')
            ->whereIn('student_course_id', $courseIds)
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->orderByDesc('id')
            ->get(['student_course_id', 'schedule_date', 'teacher_id']);

        $byCourseDate = [];
        foreach ($rows as $r) {
            try {
                $d = Carbon::parse((string) $r->schedule_date)->toDateString();
            } catch (\Throwable) {
                continue;
            }
            $cid = (int) ($r->student_course_id ?? 0);
            $tid = (int) ($r->teacher_id ?? 0);
            if ($cid <= 0 || $tid <= 0) {
                continue;
            }
            $key = $cid . '|' . $d;
            if (!isset($byCourseDate[$key])) {
                $byCourseDate[$key] = $tid;
            }
        }

        $out = [];
        foreach ($items as $cs) {
            try {
                $d = Carbon::parse((string) $cs->SessionDate)->toDateString();
            } catch (\Throwable) {
                continue;
            }
            $key = (int) $cs->StudentClassID . '|' . $d;
            if (!empty($byCourseDate[$key])) {
                $out[(int) $cs->id] = (int) $byCourseDate[$key];
            }
        }

        return $out;
    }
}
