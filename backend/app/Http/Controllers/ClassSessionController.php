<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\LearningRecordTeacherChange;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\EnrollmentService;
use App\Services\ScheduleGuardService;
use App\Services\SessionDeductionService;
use App\Services\SubstituteService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ClassSessionController extends Controller
{
    public function batchStore(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:Student,id',
            'teacher_id' => 'required|integer|exists:User,id',
            'subject' => 'required|string|max:64',
            'class_type' => 'required|in:one_on_one,one_on_two,one_on_three,tutoring,trial',
            'total_classes' => 'nullable|integer|min:1|max:500',
            'confirmed_dates' => 'present|array|max:500',
            'confirmed_dates.*' => 'date',
            'future_dates' => 'present|array|max:500',
            'future_dates.*' => 'required|date',
            'session_plan' => 'nullable|array|max:500',
            'session_plan.*.session_date' => 'required_with:session_plan|date',
            'session_plan.*.start_time' => 'required_with:session_plan|date_format:H:i',
            'session_plan.*.kind' => 'required_with:session_plan|in:confirmed,future',
            'session_plan.*.subject' => 'nullable|string|max:64',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:1|max:7',
            'start_time' => 'required_without:day_time_slots|date_format:H:i',
            'day_time_slots' => 'nullable|array|max:56',
            'day_time_slots.*.day' => 'required_with:day_time_slots|integer|min:1|max:7',
            'day_time_slots.*.start_time' => 'required_with:day_time_slots|date_format:H:i',
            'day_time_slots.*.duration_minutes' => 'nullable|integer|min:30|max:480',
            'day_time_slots.*.subject' => 'nullable|string|max:64',
            'day_time_slots.*.teacher_id' => 'nullable|integer|exists:User,id',
            'allow_multi_teacher' => 'nullable|boolean',
            'duration_minutes' => 'required|integer|min:30|max:480',
            'rate_unit' => 'nullable|in:session,hour',
            'price_per_session' => 'required|numeric|min:0',
            'payment_type' => 'required|in:session,monthly',
            'settlement_day' => 'nullable|integer|min:1|max:31',
            'monthly_sessions' => 'nullable|integer|min:1|max:500',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'memo' => 'nullable|string|max:512',
            'paid_at' => 'nullable|date',
            'branch_id' => 'nullable|integer|min:1',
            'mode' => 'nullable|in:create,backfill',
            'force' => 'nullable|boolean',
            'course_start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        return app(EnrollmentService::class)->store($request, $data);
    }

    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);

        if ($role === 'teacher' && $teacherId <= 0) {
            return response()->json(['message' => 'Teacher not linked'], 403);
        }

        $requestedCampus = (int) ($request->input('branch_id') ?? $request->input('campus_id') ?? 0);
        if ($requestedCampus > 0) {
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($requestedCampus, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
            }
            $campusIds = [$requestedCampus];
        }

        if ($role === 'teacher') {
            $this->autoMaterializeTeacherMonthlySessionsForRange($request, $teacherId, $campusIds);
        }

        $query = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            // Substitute teacher: pick latest scheduled row whose teacher differs
            // from the course teacher. Stale scheduled rows pointing back to the
            // regular teacher must not override the real substitute.
            // Substitute teacher: pick latest scheduled exception row whose teacher differs
            // from the course teacher, per (course, date, HH:MM) slot.
            // TD-058：原本以 per-row correlated subquery `sub_sched.id = (SELECT MAX(sub2.id) …)`
            // 解析，且 `DATE()`/`SUBSTRING()` 包裹欄位使索引失效 → 主查詢 1–3.5s 主因。
            // 改為預先彙總的 derived-table join（鏡像下方 lr/si 的 MAX(id) 衍生表寫法）：
            //   inner aggregate 取每 (student_course_id, schedule_date, HH:MM) 的 MAX(id)，
            //   且 `teacher_id <> StudentClass.TeacherID`、`status='scheduled'`、
            //   `original_schedule_id IS NOT NULL` 都在彙總內過濾——與原 subquery 等價。
            // `schedule_date` 為 DATE 欄位、`start_time` 為字串，故 GROUP BY 該兩鍵
            // 等同原本的 DATE()/SUBSTRING() 正規化，不會多出列（characterization：
            // SubstituteTeacherTest / ClassSessionsSubstituteStartTimeFormatTest）。
            // Bug fix C1 (2026-04-21)：兩側 SUBSTRING(...,1,5) 容錯 HH:MM:SS 歷史資料
            // （schedules.id=611 遺留狀況）保留於 ON 條件。
            ->leftJoin(DB::raw('(
                SELECT ss.*
                FROM `schedules` ss
                INNER JOIN (
                    SELECT sub2.student_course_id,
                           sub2.schedule_date,
                           SUBSTRING(sub2.start_time, 1, 5) AS st_hm,
                           MAX(sub2.id) AS max_id
                    FROM `schedules` sub2
                    INNER JOIN `StudentClass` sc2 ON sc2.ID = sub2.student_course_id
                    WHERE sub2.status = "scheduled"
                      AND sub2.original_schedule_id IS NOT NULL
                      AND sub2.teacher_id <> sc2.TeacherID
                    GROUP BY sub2.student_course_id, sub2.schedule_date, SUBSTRING(sub2.start_time, 1, 5)
                ) sub_latest ON ss.id = sub_latest.max_id
            ) as sub_sched'), function ($join) {
                $join->on('sub_sched.student_course_id', '=', 'sc.ID')
                    ->whereRaw('DATE(sub_sched.schedule_date) = DATE(cs.SessionDate)')
                    ->whereRaw('SUBSTRING(sub_sched.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)');
            })
            ->leftJoin('Teacher as subt', 'subt.id', '=', 'sub_sched.teacher_id')
            ->leftJoin('User as subu', 'subu.id', '=', 'sub_sched.teacher_id')
            ->leftJoin(DB::raw('(SELECT lr_inner.* FROM `LearningRecord` lr_inner INNER JOIN (SELECT ClassSessionID, MAX(id) AS max_id FROM `LearningRecord` WHERE VoidedAt IS NULL GROUP BY ClassSessionID) lr_latest ON lr_inner.id = lr_latest.max_id) AS lr'), 'lr.ClassSessionID', '=', 'cs.id')
            ->leftJoin(DB::raw('(SELECT si_inner.* FROM `StudentSingIn` si_inner INNER JOIN (SELECT ClassSessionID, MAX(id) AS max_id FROM `StudentSingIn` WHERE VoidedAt IS NULL GROUP BY ClassSessionID) si_latest ON si_inner.id = si_latest.max_id) AS si'), 'si.ClassSessionID', '=', 'cs.id')
            ->leftJoin('Teacher as t', 't.id', '=', 'sc.TeacherID')
            ->leftJoin('User as u', 'u.id', '=', 'sc.TeacherID')
            // lr_teacher: 評量紀錄上記錄的老師（儲存授課當下的老師，不隨契約換師而變動）
            ->leftJoin('Teacher as lrt', 'lrt.id', '=', 'lr.TeacherID')
            ->leftJoin('User as lru', 'lru.id', '=', 'lr.TeacherID')
            ->leftJoin('Teacher as sit', 'sit.id', '=', 'si.TeacherID')
            ->leftJoin('User as siu', 'siu.id', '=', 'si.TeacherID')
            ->leftJoin('User as rbu', 'rbu.id', '=', 'si.RecordedByUserID')
            ->leftJoin('Subject as sub', 'sub.id', '=', 'sc.SubjectID')
            ->select([
                'cs.id',
                'cs.StudentClassID',
                'cs.SessionDate',
                'cs.StartTime',
                'cs.EndTime',
                'cs.Status',
                'cs.IsContractException',
                // PRD-A (2026-04-18): Reconcile displayed status against the latest
                // active StudentSignIn. When an attendance record exists but
                // ClassSession.Status is still `scheduled` or `absent` (a known
                // inconsistency from prior write paths), surface the sign-in's
                // effective attendance state so the today-schedule UI never shows
                // "缺席" while a present/late sign-in exists.
                DB::raw("CASE
                    WHEN si.id IS NOT NULL AND LOWER(cs.Status) IN ('scheduled','absent') AND LOWER(si.Status) = 'present' THEN 'attended'
                    WHEN si.id IS NOT NULL AND LOWER(cs.Status) IN ('scheduled','absent') AND LOWER(si.Status) = 'late' THEN 'late'
                    WHEN si.id IS NOT NULL AND LOWER(cs.Status) IN ('scheduled','absent') AND LOWER(si.Status) IN ('leave','excused') THEN 'leave'
                    ELSE cs.Status
                END AS effective_status"),
                'cs.Note',
                'cs.session_charge',
                'sc.StudentID',
                'sc.TeacherID',
                'sc.Rate as sc_rate',
                'sc.SessionDuration as sc_session_duration',
                'sc.rate_unit as sc_rate_unit',
                'sub_sched.teacher_id as substitute_teacher_id',
                's.CampusID',
                's.name as student_name',
                // 行事曆/點名顯示應與 teacher_id 一致：代課老師 > 現任課程老師。
                // 評量老師是歷史歸屬，保留在 learning_record_teacher_id，不覆蓋堂次顯示名稱。
                DB::raw('COALESCE(subt.T_Name, subu.Name, t.T_Name, u.Name, lrt.T_Name, lru.Name, "") as teacher_name'),
                DB::raw('COALESCE(sub.Subject_Name, "") as subject_name'),
                'lr.id as learning_record_id',
                'lr.Status as learning_record_status',
                'lr.TeacherID as learning_record_teacher_id',
                'lr.Progress as learning_record_progress',
                'si.SignInDT as attendance_sign_in_at',
                'si.Memo as attendance_memo',
                DB::raw('COALESCE(rbu.Name, sit.T_Name, siu.Name, "") as recorded_by_name'),
            ]);

        if ($role === 'teacher') {
            // Bug fix (2026-04-21)：代課後原老師仍看到待點名課程
            // 正確語意：若該堂有代課記錄 (sub_sched.teacher_id IS NOT NULL)，僅代課老師看得到；
            // 若無代課記錄，才由契約老師 (sc.TeacherID) 看到。
            // 舊版以 `sc.TeacherID = ? OR sub_sched.teacher_id = ?` 合併，導致代課後原老師仍命中第一分支。
            $query->where(function ($q) use ($teacherId) {
                $q->where(function ($inner) use ($teacherId) {
                    $inner->whereNull('sub_sched.teacher_id')
                        ->where('sc.TeacherID', $teacherId);
                })->orWhere('sub_sched.teacher_id', $teacherId);
            });
        }

        if (!empty($campusIds)) {
            $query->whereIn('s.CampusID', $campusIds);
        }

        if ($request->filled('teacher_id')) {
            $filterTid = (int) $request->input('teacher_id');
            $query->where(function ($q) use ($filterTid) {
                $q->where(function ($inner) use ($filterTid) {
                    $inner->whereNull('sub_sched.teacher_id')
                        ->where('sc.TeacherID', $filterTid);
                })->orWhere('sub_sched.teacher_id', $filterTid);
            });
        }

        if ($request->filled('student_id')) {
            $query->where('sc.StudentID', (int) $request->input('student_id'));
        }

        if ($request->filled('student_class_id')) {
            $query->where('sc.ID', (int) $request->input('student_class_id'));
        }

        if ($request->filled('student_class_ids')) {
            $ids = $this->normalizeIds($request->input('student_class_ids'));
            if (!empty($ids)) {
                $query->whereIn('sc.ID', $ids);
            }
        }

        if ($request->filled('status')) {
            $statuses = $this->normalizeStringList($request->input('status'));
            if (!empty($statuses)) {
                $query->whereIn('cs.Status', $statuses);
            }
        }

        if ($request->filled('learning_record_status')) {
            $lrStatuses = $this->normalizeStringList($request->input('learning_record_status'));
            if (!empty($lrStatuses)) {
                $query->whereIn('lr.Status', $lrStatuses);
            }
        }

        // TD-062 Phase 2：`cs.SessionDate` 為 DATE 欄位，故裸欄位比較與 whereDate() 結果
        // byte-identical，但不再以 DATE() 包裹欄位 → 可命中 (StudentClassID, SessionDate)
        // 複合索引的 range 段（characterization：ClassSessionDateWindowFilterTest）。
        if ($request->filled('start')) {
            $query->where('cs.SessionDate', '>=', $request->input('start'));
        }

        if ($request->filled('end')) {
            $query->where('cs.SessionDate', '<=', $request->input('end'));
        }

        // Bug #496 / in-app #124：cancelAutoMaterializedDuplicateSession() 會把調課同槽
        // 的 auto-materialized placeholder 標 cancelled + Note .= 'cancelled-duplicate-
        // reschedule-placeholder'。這些列屬內部 bookkeeping，預設不外露給課程管理／
        // 日曆／出缺勤等任何 UI 消費端。提供 include_internal_placeholder=1 給 audit/QA。
        if (!$request->boolean('include_internal_placeholder')) {
            $query->where(function ($q) {
                $q->where('cs.Status', '<>', 'cancelled')
                    ->orWhere(function ($inner) {
                        $inner->where('cs.Note', 'NOT LIKE', '%cancelled-duplicate-reschedule-placeholder%')
                            ->orWhereNull('cs.Note');
                    });
            });
        }

        $perPage = min(max((int) $request->input('per_page', 1000), 1), 2000);
        $rows = $query
            ->orderBy('cs.SessionDate', 'asc')
            ->orderBy('cs.StartTime', 'asc')
            ->orderBy('cs.id', 'asc')
            ->paginate($perPage);

        $rows->getCollection()->transform(function ($row) {
            $row->id = (int) $row->id;
            $row->student_class_id = (int) $row->StudentClassID;
            $row->student_id = (int) $row->StudentID;
            $subTid = isset($row->substitute_teacher_id) && $row->substitute_teacher_id !== null
                ? (int) $row->substitute_teacher_id : 0;
            $row->substitute_teacher_id = $subTid > 0 ? $subTid : null;
            $row->teacher_id = $subTid > 0 ? $subTid : (int) ($row->TeacherID ?? 0);
            $row->branch_id = (int) ($row->CampusID ?? 0);
            $row->session_date = $row->SessionDate ? substr((string) $row->SessionDate, 0, 10) : null;
            $row->start_time = $row->StartTime ? substr((string) $row->StartTime, 0, 5) : null;
            $row->end_time = $row->EndTime ? substr((string) $row->EndTime, 0, 5) : null;
            $row->status = (string) ($row->effective_status ?? $row->Status ?? '');
            $row->is_contract_exception = (bool) ($row->IsContractException ?? false);
            $row->learning_record_id = $row->learning_record_id !== null ? (int) $row->learning_record_id : null;
            $row->learning_record_status = $row->learning_record_status ?? 'missing';
            $row->learning_record_body_filled = $row->learning_record_id !== null && trim((string) ($row->learning_record_progress ?? '')) !== '';
            $row->learning_record_teacher_id = $row->learning_record_teacher_id !== null ? (int) $row->learning_record_teacher_id : null;
            unset($row->learning_record_progress);
            $row->attendance_sign_in_at = $row->attendance_sign_in_at ?: null;
            $row->attendance_memo = $row->attendance_memo ?: '';
            $row->recorded_by_name = (string) ($row->recorded_by_name ?? '');
            $row->note = $row->Note !== null ? (string) $row->Note : null;
            $row->session_charge = isset($row->session_charge) && $row->session_charge !== null
                ? (int) $row->session_charge : null;
            $row->contract_rate = isset($row->sc_rate) && $row->sc_rate !== null
                ? (float) $row->sc_rate : null;
            $row->contract_session_duration = isset($row->sc_session_duration) && $row->sc_session_duration !== null
                ? (int) $row->sc_session_duration : null;
            $row->contract_rate_unit = isset($row->sc_rate_unit) && $row->sc_rate_unit !== null
                ? (string) $row->sc_rate_unit : null;
            unset(
                $row->StudentClassID,
                $row->StudentID,
                $row->TeacherID,
                $row->CampusID,
                $row->SessionDate,
                $row->StartTime,
                $row->EndTime,
                $row->Status,
                $row->IsContractException,
                $row->effective_status,
                $row->Note,
                $row->sc_rate,
                $row->sc_session_duration,
                $row->sc_rate_unit
            );
            return $row;
        });

        $byClass = [];
        foreach ($rows->items() as $item) {
            $key = (string) $item->student_class_id;
            if (!isset($byClass[$key])) {
                $byClass[$key] = [];
            }
            $byClass[$key][] = $item;
        }

        if (config('perfflags.log_session_count_mismatch')) {
            $this->logSessionCountMismatches($byClass, $request);
        }

        return response()->json([
            'data' => $rows->items(),
            'by_class' => $byClass,
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
        ]);
    }

    /**
     * Teacher today list relies on ClassSession rows; monthly contracts may only
     * exist as projected slots. Materialize missing projected rows for same-day
     * teacher queries so pending attendance lists do not drop students.
     *
     * @param  array<int>  $campusIds
     */
    private function autoMaterializeTeacherMonthlySessionsForRange(Request $request, int $teacherId, array $campusIds): void
    {
        if ($teacherId <= 0 || !$request->filled('start') || !$request->filled('end')) {
            return;
        }

        try {
            $start = Carbon::parse((string) $request->input('start'))->toDateString();
            $end = Carbon::parse((string) $request->input('end'))->toDateString();
        } catch (\Throwable $e) {
            return;
        }

        // Keep scope tight for safety/perf: only same-day requests are auto-materialized.
        if ($start !== $end) {
            return;
        }

        $targetDate = $start;
        $isoWeekday = (int) Carbon::parse($targetDate)->dayOfWeekIso;

        $classes = StudentClass::query()
            ->join('Student as st', 'st.id', '=', 'StudentClass.StudentID')
            ->where('StudentClass.TeacherID', $teacherId)
            ->where('StudentClass.ScheduleMode', 'date')
            ->where(function ($q) {
                $q->where('StudentClass.Stop', 0)->orWhereNull('StudentClass.Stop');
            })
            ->when(!empty($campusIds), fn ($q) => $q->whereIn('st.CampusID', $campusIds))
            ->select('StudentClass.*')
            ->get();

        // #546/TD-018：批次預載「抑制例外（請假/調課等）」與「既有堂次」，
        // 取代原本逐 slot 兩次 exists()（N+1，會隨老師當日課數線性增長）。
        // 以 classId|HH:MM 為鍵；TIME 欄位比較本就忽略秒，與原 SQL 語意一致。
        $classIds = $classes->pluck('ID')->map(fn ($v) => (int) $v)->all();
        $suppressedSet = [];
        $existingSet = [];
        if (!empty($classIds)) {
            Schedule::whereIn('student_course_id', $classIds)
                ->whereDate('schedule_date', $targetDate)
                ->whereIn('status', ['leave', 'leave_adjusted', 'excused', 'rescheduled', 'cancelled'])
                ->get(['student_course_id', 'start_time'])
                ->each(function ($r) use (&$suppressedSet) {
                    $suppressedSet[(int) $r->student_course_id . '|' . substr((string) $r->start_time, 0, 5)] = true;
                });
            ClassSession::whereIn('StudentClassID', $classIds)
                ->whereDate('SessionDate', $targetDate)
                ->get(['StudentClassID', 'StartTime'])
                ->each(function ($r) use (&$existingSet) {
                    $existingSet[(int) $r->StudentClassID . '|' . substr((string) $r->StartTime, 0, 5)] = true;
                });
        }

        foreach ($classes as $studentClass) {
            $startDate = $studentClass->StartDate ? Carbon::parse($studentClass->StartDate)->toDateString() : null;
            $endDate = $studentClass->EndDate ? Carbon::parse($studentClass->EndDate)->toDateString() : null;
            if ($startDate && $targetDate < $startDate) {
                continue;
            }
            if ($endDate && $targetDate > $endDate) {
                continue;
            }

            $slots = $this->resolveProjectedMonthlySlotsForWeekday($studentClass, $isoWeekday);
            if (empty($slots)) {
                continue;
            }

            foreach ($slots as $slot) {
                $startHm = substr((string) ($slot['start_time'] ?? ''), 0, 5);
                if ($startHm === '') {
                    continue;
                }

                $key = (int) $studentClass->ID . '|' . $startHm;
                if (isset($suppressedSet[$key])) {
                    continue;
                }
                if (isset($existingSet[$key])) {
                    continue;
                }

                ClassSession::create([
                    'StudentClassID'      => (int) $studentClass->ID,
                    'SubjectID'           => $studentClass->SubjectID ?: null,
                    'SessionDate'         => $targetDate,
                    'StartTime'           => (string) $slot['start_time'],
                    'EndTime'             => (string) $slot['end_time'],
                    'Status'              => 'scheduled',
                    'Note'                => 'projected-monthly-materialized-auto',
                    'IsContractException' => 0,
                ]);
                // 同一請求內避免重複建立（多 slot 同時段時的防呆，等同原 exists() 在建立後轉真）。
                $existingSet[$key] = true;
            }
        }
    }

    /**
     * Materialize one projected monthly schedule date so the normal single-session
     * edit flow can operate on a real ClassSession row.
     */
    public function ensureProjected(Request $request)
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer|exists:StudentClass,ID',
            'session_date'     => 'required|date',
            'start_time'       => 'nullable|date_format:H:i',
            'branch_id'        => 'nullable|integer|min:1',
        ]);

        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $studentClass = StudentClass::where('ID', (int) $data['student_class_id'])->first();
        if (!$studentClass) {
            return response()->json(['message' => '找不到對應課程'], 404);
        }

        if ((int) ($studentClass->Stop ?? 0) === 1) {
            return response()->json(['message' => '課程已結案或停用，不能新增堂次'], 422);
        }

        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'date') {
            return response()->json(['message' => '僅月結固定時段課程可建立推算堂次'], 422);
        }

        $studentCampusId = (int) (Student::where('id', (int) $studentClass->StudentID)->value('CampusID') ?? 0);
        $requestedBranchId = (int) ($data['branch_id'] ?? 0);
        if ($requestedBranchId > 0 && $studentCampusId > 0 && $requestedBranchId !== $studentCampusId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($studentCampusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $sessionDate = Carbon::parse($data['session_date'])->toDateString();
        $startDate = $studentClass->StartDate ? Carbon::parse($studentClass->StartDate)->toDateString() : null;
        $endDate = $studentClass->EndDate ? Carbon::parse($studentClass->EndDate)->toDateString() : null;
        if ($startDate && $sessionDate < $startDate) {
            return response()->json(['message' => '堂次日期早於課程開始日'], 422);
        }
        if ($endDate && $sessionDate > $endDate) {
            return response()->json(['message' => '堂次日期超過課程到期日'], 422);
        }

        $slot = $this->resolveProjectedMonthlySlot(
            $studentClass,
            (int) Carbon::parse($sessionDate)->dayOfWeekIso,
            isset($data['start_time']) ? substr((string) $data['start_time'], 0, 5) : null
        );
        if (!$slot) {
            return response()->json(['message' => '指定日期不符合此課程固定時段'], 422);
        }

        $created = false;
        $session = DB::transaction(function () use ($studentClass, $sessionDate, $slot, &$created) {
            $existing = ClassSession::where('StudentClassID', (int) $studentClass->ID)
                ->whereDate('SessionDate', $sessionDate)
                ->where('StartTime', $slot['start_time'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $created = true;
            return ClassSession::create([
                'StudentClassID'       => (int) $studentClass->ID,
                'SubjectID'            => $studentClass->SubjectID ?: null,
                'SessionDate'          => $sessionDate,
                'StartTime'            => $slot['start_time'],
                'EndTime'              => $slot['end_time'],
                'Status'               => 'scheduled',
                'Note'                 => 'projected-monthly-materialized',
                'IsContractException'  => 0,
            ]);
        });

        return response()->json([
            'message' => $created ? '已建立可編輯堂次' : '已取得既有堂次',
            'created' => $created,
            'session' => $this->sessionPayload($session),
        ]);
    }

    /**
     * State-machine transitions for ClassSession.Status.
     * Key = current status, value = allowed next statuses.
     */
    private const STATUS_TRANSITIONS = [
        'scheduled'      => ['attended', 'late', 'absent', 'leave', 'cancelled'],
        'attended'       => ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'cancelled'],
        'completed'      => ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'cancelled'],
        'late'           => ['leave', 'leave_adjusted', 'scheduled', 'attended', 'absent', 'cancelled'],
        'absent'         => ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'cancelled'],
        'leave'          => ['scheduled', 'attended', 'late', 'absent', 'cancelled'],
        'leave_adjusted' => ['cancelled'],
        'cancelled'      => ['scheduled'],
    ];

    private const ATTENDED_STATUSES = ['attended', 'completed', 'late', 'absent'];
    private const LEAVE_ADJUSTED_REQUIRES = ['director', 'admin', 'super_admin'];

    /**
     * PATCH /api/v1/class-sessions/{id}
     * Single-session status update with state machine validation.
     */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'status'     => 'required|string|max:32',
            'start_time' => 'nullable|string|max:8',
            'end_time'   => 'nullable|string|max:8',
            'note'       => 'nullable|string|max:500',
            'reason'     => 'nullable|string|max:255',
        ]);

        $role = $request->attributes->get('auth_role');
        $authUser = $request->attributes->get('auth_user');
        $authUserId = (int) ($authUser->id ?? 0);
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $session = ClassSession::find($id);
        if (!$session) {
            return response()->json(['message' => '找不到該堂次'], 404);
        }

        $studentClass = StudentClass::where('ID', $session->StudentClassID)->first();
        if (!$studentClass) {
            return response()->json(['message' => '找不到對應課程'], 404);
        }

        $studentCampusId = (int) (Student::where('id', (int) $studentClass->StudentID)->value('CampusID') ?? 0);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($studentCampusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $currentStatus = strtolower(trim((string) ($session->Status ?? 'scheduled')));
        $newStatus = strtolower(trim((string) $data['status']));

        if ($currentStatus === $newStatus) {
            $this->applyTimeAndNoteUpdates($session, $data);
            $this->syncLearningRecordTime($session, $data);
            return $this->sessionUpdateResponse($session, '堂次已更新');
        }

        // Validate state machine transition
        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            return response()->json([
                'message' => "狀態轉移不允許：{$currentStatus} → {$newStatus}",
                'allowed' => $allowed,
            ], 422);
        }

        // leave_adjusted requires director+
        if ($newStatus === 'leave_adjusted') {
            if (!in_array($role, self::LEAVE_ADJUSTED_REQUIRES, true)) {
                return response()->json(['message' => '僅主任/管理員可執行補請假'], 403);
            }
        }

        if ($role === 'teacher') {
            $teacherAllowed = ['attended', 'late', 'absent', 'leave'];
            if (!in_array($newStatus, $teacherAllowed, true)) {
                return response()->json(['message' => '老師僅可標記出缺勤或請假狀態'], 403);
            }
        }

        try {
            return DB::transaction(function () use ($session, $studentClass, $currentStatus, $newStatus, $data, $authUserId, $role) {
                $session = ClassSession::where('id', $session->id)->lockForUpdate()->first();
                $reason = trim((string) ($data['reason'] ?? ''));

                $wasAttended = in_array($currentStatus, self::ATTENDED_STATUSES, true);
                $willAttend = in_array($newStatus, self::ATTENDED_STATUSES, true);

                // --- Transition: anything attended-like → leave/leave_adjusted ---
                if ($newStatus === 'leave_adjusted' || ($wasAttended && $newStatus === 'leave')) {
                    return $this->handleRetroLeaveTransition($session, $studentClass, $authUserId, $reason);
                }

                // --- Transition: scheduled → attended (mark attendance) ---
                if ($currentStatus === 'scheduled' && $willAttend) {
                    $session->Status = $newStatus;
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();

                    if (in_array($newStatus, ['attended', 'late'], true)) {
                        SessionDeductionService::deductOnAttendance($studentClass, null, (int) $session->id);
                    }

                    // TD-005: sync active StudentSignIn.Status if one exists (e.g. RFID swipe already in)
                    $this->syncStudentSignInStatus($session->id, $newStatus);

                    return $this->sessionUpdateResponse($session, '狀態已更新為' . $newStatus);
                }

                // --- Transition: attended swap (e.g. attended → late) ---
                if ($wasAttended && $willAttend) {
                    $session->Status = $newStatus;
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();
                    // TD-005: sync active StudentSignIn.Status so fetchRecords reflects true state
                    $this->syncStudentSignInStatus($session->id, $newStatus);
                    return $this->sessionUpdateResponse($session, '狀態已更新為' . $newStatus);
                }

                // --- Transition: attended-like → scheduled/cancelled ---
                if ($wasAttended && in_array($newStatus, ['scheduled', 'cancelled'], true)) {
                    $this->voidAttendanceArtifacts($session, $authUserId, $reason ?: '由已上調整狀態');
                    SessionDeductionService::reverseForSession(
                        (int) $studentClass->ID,
                        (int) $session->id,
                        'status_adjust',
                        $authUserId,
                        $reason ?: '狀態調整沖回'
                    );
                    $session->Status = $newStatus;
                    if ($newStatus === 'cancelled') {
                        $session->Note = $this->appendSessionNote($session->Note, 'cancelled-after-attended');
                    } else {
                        $session->Note = $this->appendSessionNote($session->Note, 'revert-to-scheduled');
                    }
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();
                    SessionDeductionService::recomputeCounters((int) $studentClass->ID);

                    $msg = '已更新為' . $newStatus . '，並完成堂數沖回';
                    if ($newStatus === 'cancelled') {
                        $extended = $this->tryExtendOnLeave($studentClass, $session);
                        if ($extended) {
                            $msg .= '，已自動補建一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
                        }
                    }
                    return $this->sessionUpdateResponse($session, $msg);
                }

                // --- Transition: scheduled → leave ---
                if ($currentStatus === 'scheduled' && $newStatus === 'leave') {
                    LearningRecord::where('ClassSessionID', $session->id)
                        ->active()
                        ->update([
                            'VoidedAt' => now(), 'VoidedByUserID' => $authUserId ?: null,
                            'VoidReason' => $reason ?: '單堂標記請假',
                        ]);
                    StudentSignIn::where('ClassSessionID', $session->id)
                        ->active()
                        ->update([
                            'VoidedAt' => now(), 'VoidedByUserID' => $authUserId ?: null,
                            'VoidReason' => $reason ?: '單堂標記請假',
                        ]);

                    $session->Status = 'leave';
                    $session->Note = $this->appendSessionNote($session->Note, 'leave');
                    $this->applyTimeAndNoteUpdates($session, $data);
                    $session->save();

                    $extended = $this->tryExtendOnLeave($studentClass, $session);
                    $msg = '已標記請假';
                    if ($extended) {
                        $msg .= '，並已自動順延一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
                    }
                    return $this->sessionUpdateResponse($session, $msg);
                }

                // --- Generic safe transitions ---
                $session->Status = $newStatus;
                $this->applyTimeAndNoteUpdates($session, $data);
                $session->save();

                SessionDeductionService::syncCounters($studentClass);

                // If leave → attended/late/absent/completed: the LR was voided by the leave
                // cascade. Restore it so teachers can fill it in.
                if (
                    $currentStatus === 'leave' &&
                    in_array($newStatus, ['attended', 'late', 'absent', 'completed'], true)
                ) {
                    $this->restoreVoidedLearningRecord($session);
                }

                $msg = '狀態已更新為' . $newStatus;
                if ($newStatus === 'cancelled') {
                    $extended = $this->tryExtendOnLeave($studentClass, $session);
                    if ($extended) {
                        $msg .= '，已自動補建一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
                    }
                }
                return $this->sessionUpdateResponse($session, $msg);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function handleRetroLeaveTransition(ClassSession $session, StudentClass $studentClass, int $authUserId, string $reason)
    {
        $this->voidAttendanceArtifacts($session, $authUserId, $reason ?: '補請假：已上課改請假');

        SessionDeductionService::reverseForSession(
            (int) $studentClass->ID,
            (int) $session->id,
            'retro_leave',
            $authUserId,
            $reason ?: '補請假沖回'
        );

        $session->Status = 'leave_adjusted';
        $session->Note = $this->appendSessionNote($session->Note, 'retro-leave');
        $session->save();

        SessionDeductionService::recomputeCounters((int) $studentClass->ID);

        $extended = $this->tryExtendOnLeave($studentClass, $session);
        $msg = '補請假完成：堂數已沖回';
        if ($extended) {
            $msg .= '，並已自動順延一堂至 ' . substr((string) $extended->SessionDate, 0, 10);
        }
        return $this->sessionUpdateResponse($session, $msg);
    }

    private function voidAttendanceArtifacts(ClassSession $session, int $authUserId, string $reason): void
    {
        StudentSignIn::where('ClassSessionID', $session->id)
            ->active()
            ->get()
            ->each(function ($si) use ($authUserId, $reason) {
                $si->VoidedAt = now();
                $si->VoidedByUserID = $authUserId ?: null;
                $si->VoidReason = $reason;
                $si->save();
            });

        LearningRecord::where('ClassSessionID', $session->id)
            ->active()
            ->get()
            ->each(function ($lr) use ($authUserId, $reason) {
                $lr->VoidedAt = now();
                $lr->VoidedByUserID = $authUserId ?: null;
                $lr->VoidReason = $reason;
                $lr->save();
            });
    }

    /**
     * When a session transitions from leave back to an attended-like status,
     * restore any previously voided LearningRecord so teachers can fill it in.
     */
    private function restoreVoidedLearningRecord(ClassSession $session): void
    {
        $lr = LearningRecord::where('ClassSessionID', $session->id)->first();
        if (!$lr || !$lr->isVoided()) {
            return;
        }
        $lr->VoidedAt       = null;
        $lr->VoidedByUserID = null;
        $lr->VoidReason     = null;
        $lr->Status         = 'pending';
        $lr->SessionDate    = $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null;
        $lr->StartTime      = $session->StartTime   ? substr((string) $session->StartTime, 0, 5)   : null;
        $lr->EndTime        = $session->EndTime      ? substr((string) $session->EndTime, 0, 5)     : null;
        $lr->save();
    }

    private function applyTimeAndNoteUpdates(ClassSession $session, array $data): void
    {
        $hasTimeChange = !empty($data['start_time']) || !empty($data['end_time']);
        $oldSessionCharge = $session->session_charge;

        // 保留舊時間以便精準匹配 schedules exception 列（避免多堂同日時誤傷他堂）
        $oldStartHm = substr((string) $session->StartTime, 0, 5);
        $oldEndHm   = substr((string) $session->EndTime, 0, 5);

        if (!empty($data['start_time'])) {
            $session->StartTime = substr($data['start_time'], 0, 5);
        }
        if (!empty($data['end_time'])) {
            $session->EndTime = substr($data['end_time'], 0, 5);
        }
        if (array_key_exists('note', $data)) {
            $session->Note = $data['note'] ?? '';
        }

        if ($hasTimeChange) {
            $this->syncSessionChargeForTimeChange($session, $oldSessionCharge);
        }

        $session->save();

        // ClassSession 是權威來源；同步更新對應 schedules exception 列的時間，
        // 避免「課程管理 18:30，行事曆還顯示 18:00」的顯示漂移。
        if ($hasTimeChange) {
            $this->syncScheduleExceptionTime($session, $oldStartHm, $oldEndHm);
            // #556/TD-055：單堂手動改時間後，依新時段是否仍吻合契約標記 IsContractException，
            // 否則該堂會被 schedule_drift 誤判為漂移、並被 force_partial_rebuild realign 還原回契約時段。
            $this->syncContractExceptionFlag($session);
        }
    }

    /**
     * #556/TD-055：依「新時段是否仍吻合課程契約固定時段」設定 IsContractException。
     * 不吻合（主任刻意調課）→ 1，使其從 drift 偵測與 realign 排除；吻合（改回契約）→ 0。
     */
    private function syncContractExceptionFlag(ClassSession $session): void
    {
        $sessionDate = $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null;
        if (!$sessionDate) {
            return;
        }
        $studentClass = StudentClass::where('ID', $session->StudentClassID)->first();
        if (!$studentClass) {
            return;
        }
        $startHm = substr((string) $session->StartTime, 0, 5);
        $endHm = $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null;
        $isException = !$this->sessionMatchesContract($sessionDate, $startHm, $endHm, $studentClass);
        if ((bool) $session->IsContractException !== $isException) {
            $session->IsContractException = $isException;
            $session->save();
        }
    }

    /**
     * 判斷某堂 (日期, 開始時間, 結束時間) 是否吻合課程的固定排課契約時段。
     * 鏡像 StudentClassController::sessionMatchesContract（避免跨 controller 私有耦合）。
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

    /**
     * 當 ClassSession 時間有異動時，同步更新對應 schedules exception 列的 start_time / end_time。
     *
     * 匹配策略：只更新 (student_course_id, schedule_date, OLD start_time, OLD end_time) 精準吻合
     * 的 schedules 列，避免同日多堂課時誤傷其他堂次。
     */
    private function syncScheduleExceptionTime(ClassSession $session, string $oldStartHm, string $oldEndHm): void
    {
        $newStart = substr((string) $session->StartTime, 0, 5);
        $newEnd   = substr((string) $session->EndTime, 0, 5);
        if ($newStart === $oldStartHm && $newEnd === $oldEndHm) {
            return;
        }
        if ($oldStartHm === '' || $oldEndHm === '') {
            return;
        }

        $scheduleDate = $session->SessionDate
            ? substr((string) $session->SessionDate, 0, 10)
            : null;
        if (!$scheduleDate) {
            return;
        }

        DB::table('schedules')
            ->where('student_course_id', (int) $session->StudentClassID)
            ->whereDate('schedule_date', $scheduleDate)
            ->whereIn('status', ['scheduled', 'rescheduled'])
            ->where('start_time', $oldStartHm)
            ->where('end_time', $oldEndHm)
            ->update([
                'start_time' => $newStart,
                'end_time'   => $newEnd,
                'updated_at' => now(),
            ]);
    }

    /**
     * Recompute per-session charge based on actual duration vs contract's SessionDuration,
     * and sync the delta into StudentClass.Charge.
     *
     * Billing rules:
     *   session mode: session_charge = Rate × (actual_minutes / SessionDuration)
     *   hour mode:    session_charge = Rate × (actual_minutes / 60)
     *
     * StudentClass.Charge diff = new_session_charge - (old_session_charge || standard_charge)
     * Standard charge (used as baseline when session has no prior override):
     *   session mode: Rate (1 unit)
     *   hour mode:    Rate × (SessionDuration / 60)
     *
     * No-op when Rate/SessionDuration not configured or resulting actual minutes invalid.
     */
    private function syncSessionChargeForTimeChange(ClassSession $session, $oldSessionCharge): void
    {
        $sc = StudentClass::where('ID', $session->StudentClassID)->first();
        if (!$sc) {
            return;
        }

        $rate = (float) ($sc->Rate ?? 0);
        $rateUnit = strtolower(trim((string) ($sc->rate_unit ?? 'session')));
        if (!in_array($rateUnit, ['session', 'hour'], true)) {
            $rateUnit = 'session';
        }

        if ($rate <= 0) {
            return;
        }

        // 優先採用堂次當日的 per-day duration（duration1~duration6），
        // 避免「Mon 120min / Wed 90min」這種每日不同時長的課程被 SessionDuration 一概而論。
        $iso = 0;
        try {
            $iso = (int) \Carbon\Carbon::parse((string) $session->SessionDate)->isoWeekday();
        } catch (\Throwable $e) {
            $iso = 0;
        }
        $standardDuration = $iso > 0
            ? $sc->resolveSessionDurationForWeekday($iso)
            : (int) ($sc->SessionDuration ?? 0);

        if ($standardDuration <= 0) {
            return;
        }

        $actualMinutes = $this->minutesBetween((string) $session->StartTime, (string) $session->EndTime);
        if ($actualMinutes <= 0) {
            return;
        }

        if ($rateUnit === 'hour') {
            $newSessionCharge = (int) round($rate * ($actualMinutes / 60));
        } else {
            $newSessionCharge = (int) round($rate * ($actualMinutes / $standardDuration));
        }

        $baseline = $oldSessionCharge !== null ? (int) $oldSessionCharge : null;
        if ($baseline === null) {
            if ($rateUnit === 'hour') {
                $baseline = (int) round($rate * ($standardDuration / 60));
            } else {
                $baseline = (int) round($rate);
            }
        }

        $delta = $newSessionCharge - $baseline;

        $session->session_charge = $newSessionCharge;

        if ($delta !== 0) {
            $newCharge = max(0, (int) ($sc->Charge ?? 0) + $delta);
            $sc->Charge = $newCharge;
            $sc->save();
        }
    }

    /**
     * Compute minutes between two HH:mm strings. Returns 0 on invalid input.
     * Does not handle cross-midnight; lesson times are expected within a single day.
     */
    private function minutesBetween(string $start, string $end): int
    {
        $s = substr($start, 0, 5);
        $e = substr($end, 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e)) {
            return 0;
        }
        [$sh, $sm] = array_map('intval', explode(':', $s));
        [$eh, $em] = array_map('intval', explode(':', $e));
        $startMin = $sh * 60 + $sm;
        $endMin = $eh * 60 + $em;
        $diff = $endMin - $startMin;
        return $diff > 0 ? $diff : 0;
    }

    /**
     * 當 end_time 有異動時，同步更新同一堂次所有未作廢的評量記錄 EndTime。
     * 讓家長看到的評量表時間與實際上課時間一致。
     * 不論評量狀態（pending/submitted/approved）皆更新，因為這是事實性的時間修正。
     */
    private function syncLearningRecordTime(ClassSession $session, array $data): void
    {
        if (empty($data['end_time'])) {
            return;
        }
        $newEndTime = substr($data['end_time'], 0, 5);
        LearningRecord::where('ClassSessionID', $session->id)
            ->whereNull('VoidedAt')
            ->update(['EndTime' => $newEndTime]);
    }

    private function tryExtendOnLeave(StudentClass $studentClass, ClassSession $leaveSession): ?ClassSession
    {
        // 暫停中的課程（Stop=1）不補建堂次，避免「取消了又補回」症狀
        if ((int) ($studentClass->Stop ?? 0) === 1) {
            return null;
        }

        $mode = strtolower(trim((string) ($studentClass->ScheduleMode ?? '')));
        if ($mode === 'date') {
            return null;
        }

        // 只在「有效堂次數不足」時才順延。
        // 有效堂次 = 非 leave/leave_adjusted/cancelled 的 session（這些堂次仍會被上課或待上）。
        // 若有效堂次 >= SessionCount，代表課程已有足夠堂次，不需再補建，
        // 防止同一堂次反覆 absent→leave_adjusted→cancelled→scheduled 產生無限累加。
        $sessionCount = (int) ($studentClass->SessionCount ?? 0);
        if ($sessionCount > 0) {
            $effectiveCount = ClassSession::where('StudentClassID', $studentClass->ID)
                ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                ->count();
            if ($effectiveCount >= $sessionCount) {
                return null;
            }
        }

        $lastSession = ClassSession::where('StudentClassID', $studentClass->ID)
            ->whereNotIn('Status', ['cancelled'])
            ->orderByDesc('SessionDate')
            ->orderByDesc('StartTime')
            ->first();

        if (!$lastSession) {
            return null;
        }

        // 使用 CourseLeaveCascadeService 的多星期解析邏輯，支援週一+週四等雙日課程
        $weekdays = \App\Services\CourseLeaveCascadeService::resolveCourseWeekdays(
            $studentClass,
            (int) Carbon::parse($lastSession->SessionDate)->dayOfWeekIso
        );

        // 收集所有現有 session 日期作為 occupied，避免撞期
        $occupiedDates = ClassSession::where('StudentClassID', $studentClass->ID)
            ->whereNotIn('Status', ['cancelled'])
            ->pluck('SessionDate')
            ->map(fn($d) => substr((string) $d, 0, 10))
            ->flip()
            ->map(fn() => true)
            ->all();

        $appendDate = \App\Services\CourseLeaveCascadeService::nextRecurringDate(
            Carbon::parse($lastSession->SessionDate)->startOfDay(),
            $weekdays,
            $occupiedDates
        );

        return ClassSession::create([
            'StudentClassID' => $studentClass->ID,
            'SessionDate'    => $appendDate,
            'StartTime'      => $leaveSession->StartTime,
            'EndTime'        => $leaveSession->EndTime,
            'Status'         => 'scheduled',
            'Note'           => '請假自動順延',
        ]);
    }

    /**
     * TD-005: 更新 ClassSession status 時同步 active StudentSignIn.Status。
     * 只更新未作廢（VoidedAt IS NULL）的記錄。
     * ClassSession status → StudentSignIn status 對照：
     *   attended/completed → present | late → late | absent → absent
     */
    private function syncStudentSignInStatus(int $classSessionId, string $csStatus): void
    {
        $siStatus = match ($csStatus) {
            'attended', 'completed' => 'present',
            'late'                  => 'late',
            'absent'                => 'absent',
            default                 => null,
        };

        if ($siStatus === null) {
            return;
        }

        StudentSignIn::where('ClassSessionID', $classSessionId)
            ->whereNull('VoidedAt')
            ->update(['Status' => $siStatus, 'MDT' => now()]);
    }

    private function sessionUpdateResponse(ClassSession $session, string $message)
    {
        $session->refresh();
        return response()->json([
            'message' => $message,
            'session' => $this->sessionPayload($session),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(ClassSession $session): array
    {
        $session->refresh();
        return [
            'id'               => (int) $session->id,
            'student_class_id' => (int) $session->StudentClassID,
            'session_date'     => $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null,
            'start_time'       => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
            'end_time'         => $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null,
            'status'           => (string) ($session->Status ?? ''),
            'note'             => $session->Note,
            'session_charge'   => $session->session_charge !== null ? (int) $session->session_charge : null,
            'learning_record_status' => 'missing',
        ];
    }

    /**
     * @return array{start_time:string,end_time:string}|null
     */
    private function resolveProjectedMonthlySlot(StudentClass $studentClass, int $isoWeekday, ?string $requestedStart): ?array
    {
        $slots = $this->resolveProjectedMonthlySlotsForWeekday($studentClass, $isoWeekday);
        if (empty($slots)) {
            return null;
        }
        if (!$requestedStart) {
            return $slots[0];
        }
        foreach ($slots as $slot) {
            if (substr((string) $slot['start_time'], 0, 5) === substr($requestedStart, 0, 5)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{start_time:string,end_time:string}>
     */
    private function resolveProjectedMonthlySlotsForWeekday(StudentClass $studentClass, int $isoWeekday): array
    {
        $globalDuration = max(30, (int) ($studentClass->SessionDuration ?? 120));
        $candidates = [
            ['week', 'time', null],
            ['week1', 'time1', 'duration1'],
            ['week2', 'time2', 'duration2'],
            ['week3', 'time3', 'duration3'],
            ['week4', 'time4', 'duration4'],
            ['week5', 'time5', 'duration5'],
            ['week6', 'time6', 'duration6'],
        ];

        $slots = [];
        foreach ($candidates as [$weekField, $timeField, $durationField]) {
            $weekday = (int) ($studentClass->{$weekField} ?? 0);
            if ($weekday !== $isoWeekday) {
                continue;
            }
            $time = trim((string) ($studentClass->{$timeField} ?? ''));
            if ($time === '') {
                continue;
            }
            $start = $this->normalizeTime($time);
            $duration = $durationField ? (int) ($studentClass->{$durationField} ?? 0) : 0;
            if ($duration < 30) {
                $duration = $globalDuration;
            }

            $slots[] = [
                'start_time' => $start,
                'end_time'   => $this->computeEndTime($start, $duration),
            ];
        }

        usort($slots, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));

        return $slots;
    }

    private function appendSessionNote($existing, string $suffix): string
    {
        $base = trim((string) ($existing ?? ''));
        if ($base === '') return $suffix;
        if (str_contains($base, $suffix)) return $base;
        return $base . '; ' . $suffix;
    }

    /**
     * @return array<int>
     */
    private function normalizeIds($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('intval', $raw), fn ($v) => $v > 0));
        }
        if (!is_string($raw)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $raw)), fn ($v) => $v > 0));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $raw), fn ($v) => $v !== ''));
        }
        if (!is_string($raw)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<int, string>
     */
    private function normalizeDateArray(array $dates): array
    {
        $normalized = [];
        foreach ($dates as $date) {
            try {
                $normalized[] = Carbon::parse($date)->toDateString();
            } catch (\Throwable $e) {
                // validation already catches date format
            }
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $days
     * @return array<int, int>
     */
    private function normalizeWeekdayArray(array $days): array
    {
        $normalized = array_values(array_unique(array_map('intval', $days)));
        $normalized = array_values(array_filter($normalized, fn ($d) => $d >= 1 && $d <= 7));
        sort($normalized);
        return $normalized;
    }

    private function normalizeTime(string $time): string
    {
        $parsed = Carbon::createFromFormat('H:i', substr($time, 0, 5));
        return $parsed->format('H:i:s');
    }

    private function computeEndTime(string $startTime, int $durationMinutes): string
    {
        return Carbon::createFromFormat('H:i:s', $startTime)
            ->addMinutes($durationMinutes)
            ->format('H:i:s');
    }

    private function sessionEndedByEndTime(string $sessionDate, string $endTime, ?Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now();
        $sessionEndAt = Carbon::parse($sessionDate . ' ' . $endTime);
        return $sessionEndAt->lte($now);
    }


    /**
     * Retry StudentClass::create by removing unknown columns for mixed schemas.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createStudentClassResilient(array $payload): StudentClass
    {
        $attempts = 0;
        while ($attempts < 8) {
            try {
                return StudentClass::create($payload);
            } catch (QueryException $e) {
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

    /**
     * @return array{created: bool, approved: bool, deducted: bool}
     */
    private function syncApprovedLearningRecord(
        StudentClass $studentClass,
        ClassSession $classSession,
        int $teacherId,
        string $subjectName,
        ?int $approvedByUserId,
        bool $hasSessionDeductedColumn
    ): array {
        $created = false;
        $approved = false;
        $deducted = false;

        $record = LearningRecord::where('ClassSessionID', $classSession->id)->active()->first();
        if (!$record) {
            $payload = [
                'StudentClassID' => $studentClass->ID,
                'ClassSessionID' => $classSession->id,
                'TeacherID' => $teacherId,
                'CreatedByUserID' => $approvedByUserId ?: null,
                'Content' => '（系統自動核准）',
                'Subject' => $subjectName,
                'SessionDate' => $classSession->SessionDate,
                'StartTime' => $classSession->StartTime,
                'EndTime' => $classSession->EndTime,
                'Status' => 'approved',
                'ApprovedBy' => $approvedByUserId ?: null,
                'ApprovedAt' => now(),
            ];
            if ($hasSessionDeductedColumn) {
                $payload['SessionDeducted'] = false;
            }
            $record = LearningRecord::create($payload);
            $created = true;
            $approved = true;
        } else {
            if ((string) $record->Status !== 'approved') {
                $approved = true;
            }
            $record->StudentClassID = $studentClass->ID;
            $record->TeacherID = $teacherId;
            $record->Subject = $subjectName;
            $record->SessionDate = $classSession->SessionDate;
            $record->StartTime = $classSession->StartTime;
            $record->EndTime = $classSession->EndTime;
            $record->Status = 'approved';
            if (empty((string) $record->Content)) {
                $record->Content = '（系統自動核准）';
            }
            if ($approvedByUserId) {
                $record->ApprovedBy = $approvedByUserId;
                if (empty($record->CreatedByUserID)) {
                    $record->CreatedByUserID = $approvedByUserId;
                }
            }
            $record->ApprovedAt = now();
            $record->save();
        }

        $alreadyDeducted = $hasSessionDeductedColumn && (bool) $record->SessionDeducted;
        if (!$alreadyDeducted && $this->deductSessionForApprovedRecord($studentClass, $classSession->id)) {
            $deducted = true;
            if ($hasSessionDeductedColumn) {
                $record->SessionDeducted = true;
                $record->save();
            }
        }

        return [
            'created' => $created,
            'approved' => $approved,
            'deducted' => $deducted,
        ];
    }

    private function deductSessionForApprovedRecord(StudentClass $studentClass, int $classSessionId): bool
    {
        return SessionDeductionService::deductOnAttendance(
            $studentClass,
            null,
            $classSessionId > 0 ? $classSessionId : null
        );
    }

    private function recalculateSessionCounters(StudentClass $studentClass): void
    {
        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'count') {
            $studentClass->Stop = 0;
            $studentClass->save();
            return;
        }

        $sessionCount = max(0, (int) ($studentClass->SessionCount ?? 0));
        if ($sessionCount <= 0) {
            return;
        }

        $completedCount = ClassSession::query()
            ->where('StudentClassID', $studentClass->ID)
            ->where('Status', 'completed')
            ->count();

        // Backward compatibility: some historical records were written as "attended".
        $legacyAttendedCount = ClassSession::query()
            ->where('StudentClassID', $studentClass->ID)
            ->where('Status', 'attended')
            ->count();

        $usedSessions = min($sessionCount, $completedCount + $legacyAttendedCount);
        $remainingSessions = max(0, $sessionCount - $usedSessions);

        $studentClass->UsedSessions = $usedSessions;
        $studentClass->RemainingSessions = $remainingSessions;
        // Remaining 0 does not imply Stop; director pauses explicitly.
        $studentClass->save();
    }

    /**
     * POST /api/v1/class-sessions/{id}/substitute
     *
     * Atomic single-session substitute teacher: writes schedules (rescheduled + scheduled
     * with new teacher_id on same date/time) and updates LearningRecord.TeacherID.
     */
    public function substitute(Request $request, int $id)
    {
        $data = $request->validate([
            'substitute_teacher_id' => 'required|integer|exists:User,id',
            'reason' => 'nullable|string|max:255',
            // PRD f0cce4d5：合併「代課 + 換時間」選填參數（FR-001 ~ FR-005）
            // 三個欄位必須「全部填」或「全部省略」；填時以新時段為準做衝堂檢查與 DB 寫入。
            'new_date' => 'nullable|date_format:Y-m-d',
            'new_start_time' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'new_end_time' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
        ]);

        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $session = ClassSession::find($id);
        if (!$session) {
            return response()->json(['message' => '找不到該堂次'], 404);
        }

        $studentClass = StudentClass::find($session->StudentClassID);
        if (!$studentClass) {
            return response()->json(['message' => '找不到對應課程'], 404);
        }

        $student = Student::find($studentClass->StudentID);
        if (!$student) {
            return response()->json(['message' => '找不到該課程的學生資料'], 422);
        }
        $campusId = (int) ($student->CampusID ?? 0);
        if ($campusId <= 0) {
            return response()->json(['message' => '學生未設定分校，無法寫入排程與代課'], 422);
        }
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds) && !in_array($campusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $newTeacherId = (int) $data['substitute_teacher_id'];
        $oldTeacherId = (int) ($studentClass->TeacherID ?? 0);

        try {
            $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
        } catch (\Throwable $e) {
            return response()->json(['message' => '堂次日期格式無效'], 422);
        }
        $startTime = $this->normalizeSessionTimeForSchedule($session->StartTime ?? '');
        $endTime = $this->normalizeSessionTimeForSchedule($session->EndTime ?? '');
        if ($startTime === '' || $endTime === '') {
            return response()->json(['message' => '堂次起迄時間不完整，無法寫入排程'], 422);
        }

        // PRD f0cce4d5：解析選填換時參數
        // FR-003：三欄必須同填同省；FR-004：後續衝堂檢查以新時段為準
        $origSessionDate = $sessionDate;
        $origStartTime = $startTime;
        $origEndTime = $endTime;
        $newDateRaw = isset($data['new_date']) ? trim((string) $data['new_date']) : '';
        $newStartRaw = isset($data['new_start_time']) ? $this->normalizeSessionTimeForSchedule($data['new_start_time']) : '';
        $newEndRaw = isset($data['new_end_time']) ? $this->normalizeSessionTimeForSchedule($data['new_end_time']) : '';
        $rescheduleFields = [$newDateRaw, $newStartRaw, $newEndRaw];
        $rescheduleFilled = count(array_filter($rescheduleFields, static fn ($v) => $v !== ''));
        $hasReschedule = $rescheduleFilled === 3;
        if ($rescheduleFilled > 0 && $rescheduleFilled < 3) {
            return response()->json([
                'message' => '請同時填寫新日期與新開始時間，或收合換時間區塊',
                'errors' => [
                    'new_date' => $newDateRaw === '' ? ['請同時填寫新日期'] : [],
                    'new_start_time' => $newStartRaw === '' ? ['請同時填寫新開始時間'] : [],
                ],
            ], 422);
        }
        if ($hasReschedule) {
            try {
                $parsedNewDate = Carbon::parse($newDateRaw)->startOfDay();
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => '新日期格式無效',
                    'errors' => ['new_date' => ['新日期格式無效']],
                ], 422);
            }
            if ($parsedNewDate->lt(Carbon::today())) {
                return response()->json([
                    'message' => '新日期不可為過去日期',
                    'errors' => ['new_date' => ['新日期不可為過去日期']],
                ], 422);
            }
            if (strcmp($newStartRaw, $newEndRaw) >= 0) {
                return response()->json([
                    'message' => '新時段的開始時間需早於結束時間',
                    'errors' => ['new_start_time' => ['新開始時間需早於新結束時間']],
                ], 422);
            }
            // 以新時段覆寫「生效」變數，後續衝堂檢查與 transaction 寫入皆以新時段為準
            $sessionDate = $parsedNewDate->toDateString();
            $startTime = $newStartRaw;
            $endTime = $newEndRaw;
        }

        $courseId = (int) $studentClass->ID;
        $studentId = (int) $studentClass->StudentID;
        $subject = DB::table('Subject')->where('id', $studentClass->SubjectID)->value('Subject_Name') ?? '';
        $classType = (string) ($studentClass->ClassType ?? 'one_on_one');
        $dayOfWeek = (int) Carbon::parse($sessionDate)->dayOfWeekIso;
        $roomId = (int) ($studentClass->room_id ?? 0);
        if ($roomId <= 0) {
            $legacyRoom = $studentClass->RoomID ?? null;
            $roomId = is_numeric($legacyRoom) ? (int) $legacyRoom : 0;
        }
        if ($roomId <= 0) {
            $roomId = null;
        }

        // Pre-read + conflict check OUTSIDE the DB transaction so a 409 does not commit partial writes.
        // 注意：existingRescheduled / existingScheduled 仍需以「原日期」搜尋，因為目前資料庫中這兩列
        // 仍位於舊日期；合併路徑會在 transaction 內將它們遷移至新日期再套用代課老師。
        $rescheduledCandidates = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', $origSessionDate)
            ->where('status', 'rescheduled')
            ->orderByRaw('CASE WHEN teacher_id = ? THEN 0 ELSE 1 END', [$oldTeacherId])
            ->orderByDesc('id')
            ->get();
        $existingRescheduled = null;
        $existingScheduled = null;

        foreach ($rescheduledCandidates as $candidate) {
            $candidateId = (int) $candidate->id;
            // Same course/date may contain multiple ClassSession rows. Only reuse the
            // anchor whose paired substitute row matches this ClassSession start time.
            $candidateScheduled = Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $origSessionDate)
                ->where('status', 'scheduled')
                ->where('original_schedule_id', $candidateId)
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$origStartTime])
                ->first();
            if ($candidateScheduled) {
                $existingRescheduled = $candidate;
                $existingScheduled = $candidateScheduled;
                break;
            }

            if (substr((string) $candidate->start_time, 0, 5) === $origStartTime) {
                $existingRescheduled = $candidate;
                break;
            }

            // Historical repair path (#364/#108): chained reschedule -> substitute used to
            // leave a substitute scheduled row with NULL original_schedule_id. Pair that
            // exact-time ghost row with an existing anchor instead of creating a second
            // substitute and tripping the capacity guard.
            $ghostScheduled = Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $origSessionDate)
                ->where('status', 'scheduled')
                ->whereNull('original_schedule_id')
                ->where('teacher_id', '!=', $oldTeacherId)
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$origStartTime])
                ->orderByDesc('id')
                ->first();
            if ($ghostScheduled) {
                $existingRescheduled = $candidate;
                $existingScheduled = $ghostScheduled;
                break;
            }
        }

        if ($existingRescheduled && !$existingScheduled) {
            $rescheduledIdForGuard = (int) $existingRescheduled->id;
            if (!$existingScheduled) {
                // Historical repair path (#364/#108): chained reschedule -> substitute used to
                // leave a substitute scheduled row with NULL original_schedule_id. Treat it as
                // the existing row so the guard excludes it and the transaction can repair it.
                $existingScheduled = Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $origSessionDate)
                    ->where('status', 'scheduled')
                    ->whereNull('original_schedule_id')
                    ->where('teacher_id', '!=', $oldTeacherId)
                    ->where('start_time', $origStartTime)
                    ->orderByDesc('id')
                    ->first();
            }
        }

        if ($newTeacherId === $oldTeacherId) {
            return $this->restoreOriginalTeacherFromSubstitute(
                $request,
                $session,
                $studentClass,
                $existingRescheduled,
                $existingScheduled,
                $sessionDate,
                $startTime,
                $oldTeacherId,
                $data
            );
        }

        // PRD FR-003：候選池為 operator 管理分校的聯集（managed_campus_ids）。
        // 老師只要綁定任一 operator 管理分校即可被指派（跨分校協調）。
        $substituteSvc = app(SubstituteService::class);
        if (!$substituteSvc->teacherBoundToAny($newTeacherId, $campusIds)) {
            return response()->json([
                'message' => '所選老師未綁定任一您管理的分校',
                'errors' => ['substitute_teacher_id' => ['所選老師未綁定任一您管理的分校。']],
            ], 422);
        }
        $teacherHasThisCampus = UserCampus::where('UserID', $newTeacherId)
            ->where('CampusID', $campusId)
            ->exists();
        $crossCampus = !$teacherHasThisCampus;

        // Determine whether the session is in the past (already ended or attended-like status).
        // Past sessions bypass the capacity guard -- the class already happened, so swapping the
        // teacher on record is a bookkeeping correction, not a scheduling conflict.
        $sessionStatus = strtolower(trim((string) ($session->Status ?? 'scheduled')));
        $isPastSession = in_array($sessionStatus, ['attended', 'completed', 'late', 'absent'], true);
        if (!$isPastSession) {
            try {
                $sessionEndDt = Carbon::parse($sessionDate . ' ' . ($endTime ?: '23:59'));
                $isPastSession = $sessionEndDt->lte(Carbon::now());
            } catch (\Throwable $e) {
                $isPastSession = false;
            }
        }

        // PRD FR-004a：跨分校（物理不可分身）衝堂檢查
        // 過去堂次（補記）跳過；否則 operator 不可把一個已在別分校上課的老師再指派。
        // 只攔截「其他分校」的物理衝突；同分校衝堂由 ScheduleGuardService 於下方以 409 處理。
        if (!$isPastSession) {
            $excludeSchedIds = [];
            if ($existingScheduled) {
                $excludeSchedIds[] = (int) $existingScheduled->id;
            }
            $allBusy = $substituteSvc->detectCrossCampusConflict(
                $newTeacherId,
                $sessionDate,
                $startTime,
                $endTime,
                $excludeSchedIds
            );
            $crossConflicts = array_values(array_filter(
                $allBusy,
                static fn ($c) => (int) ($c['campus_id'] ?? 0) > 0 && (int) $c['campus_id'] !== (int) $campusId
            ));
            if (!empty($crossConflicts)) {
                Log::info('[substitute] cross_campus_conflict', [
                    'class_session_id' => $id,
                    'new_teacher_id' => $newTeacherId,
                    'session_date' => $sessionDate,
                    'conflicts' => $crossConflicts,
                ]);

                return response()->json([
                    'message' => '代課老師於此時段在其他分校另有課程，無法指派',
                    'conflicts' => $crossConflicts,
                    'cross_campus' => true,
                ], 422);
            }
        }

        if (!$isPastSession) {
            $guard = app(ScheduleGuardService::class);
            $conflicts = $guard->validateScheduleOccurrence([
                'teacher_id' => $newTeacherId,
                'class_type' => $classType,
                'room_id' => $roomId,
                'branch_id' => $campusId,
                'schedule_date' => $sessionDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'exclude_schedule_id' => $existingScheduled ? (int) $existingScheduled->id : null,
            ]);
            if (!empty($conflicts)) {
                $conflictMessage = $conflicts[0]['message'] ?? '代課老師此時段與既有課程衝突';
                $overlapSummary = $conflicts[0]['overlap_summary'] ?? '';
                if ($overlapSummary !== '') {
                    $conflictMessage .= '（' . $overlapSummary . '）';
                }

                Log::info('[substitute] capacity_conflict', [
                    'class_session_id' => $id,
                    'new_teacher_id' => $newTeacherId,
                    'session_date' => $sessionDate,
                    'conflicts' => $conflicts,
                ]);

                return response()->json([
                    'message' => $conflictMessage,
                    'conflicts' => $conflicts,
                ], 409);
            }
        } else {
            $this->logSubstituteDiag('guard_bypassed_past_session', [
                'class_session_id' => $id,
                'session_status' => $sessionStatus,
                'session_date' => $sessionDate,
                'new_teacher_id' => $newTeacherId,
            ]);
        }

        $this->logSubstituteDiag('pre_transaction', [
            'class_session_id' => $id,
            'student_class_id' => $courseId,
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'session_date' => $sessionDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'old_teacher_id' => $oldTeacherId,
            'new_teacher_id' => $newTeacherId,
            'class_type' => $classType,
            'room_id' => $roomId,
            'has_existing_rescheduled' => (bool) $existingRescheduled,
            'has_existing_scheduled' => (bool) $existingScheduled,
        ]);

        try {
            return $this->runSubstituteTransaction(
                $request,
                $session,
                $data,
                $newTeacherId,
                $oldTeacherId,
                $sessionDate,
                $startTime,
                $endTime,
                $courseId,
                $studentId,
                $subject,
                $classType,
                $dayOfWeek,
                $campusId,
                $roomId,
                $existingRescheduled,
                $existingScheduled,
                $crossCampus,
                $hasReschedule,
                $origSessionDate,
                $origStartTime,
                $origEndTime
            );
        } catch (\Throwable $e) {
            $this->logSubstituteDiag('failed', [
                'class_session_id' => $id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : '代課設定失敗，請稍後再試或聯絡管理員',
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function runSubstituteTransaction(
        Request $request,
        ClassSession $session,
        array $data,
        int $newTeacherId,
        int $oldTeacherId,
        string $sessionDate,
        string $startTime,
        string $endTime,
        int $courseId,
        int $studentId,
        string $subject,
        string $classType,
        int $dayOfWeek,
        int $campusId,
        ?int $roomId,
        $existingRescheduled,
        $existingScheduled,
        bool $crossCampus = false,
        bool $hasReschedule = false,
        string $origSessionDate = '',
        string $origStartTime = '',
        string $origEndTime = ''
    ) {
        return DB::transaction(function () use (
            $request, $session, $data, $newTeacherId, $oldTeacherId,
            $sessionDate, $startTime, $endTime, $courseId, $studentId, $subject,
            $classType, $dayOfWeek, $campusId, $roomId, $existingRescheduled, $existingScheduled,
            $crossCampus, $hasReschedule, $origSessionDate, $origStartTime, $origEndTime
        ) {
            $durationHours = 2;
            if ($startTime && $endTime) {
                $mins = abs(Carbon::parse($startTime)->diffInMinutes(Carbon::parse($endTime)));
                if ($mins > 0) {
                    $durationHours = max(0.5, round($mins / 60, 1));
                }
            }

            $this->logSubstituteDiag('transaction_begin', [
                'class_session_id' => $session->id,
                'duration_hours' => $durationHours,
                'has_reschedule' => $hasReschedule,
                'orig_session_date' => $origSessionDate,
                'orig_start_time' => $origStartTime,
            ]);

            // PRD f0cce4d5 FR-005：合併代課+換時—在同一交易內先行遷移時間，再套用代課。
            // - 更新 ClassSession.{SessionDate,StartTime,EndTime}
            // - 同步對應 LearningRecord 的時間欄位
            // - 將原日期的 rescheduled / scheduled schedule 列遷移至新日期與新時段
            // 後續既有代課邏輯便可以新日期為 canonical 寫入，不需額外調整。
            if ($hasReschedule) {
                $session->SessionDate = $sessionDate;
                $session->StartTime = $startTime;
                $session->EndTime = $endTime;
                $session->save();

                $lrTableEarly = (new LearningRecord())->getTable();
                $lrUpdateEarly = [
                    'SessionDate' => $sessionDate,
                    'StartTime' => $startTime,
                    'EndTime' => $endTime,
                ];
                if (Schema::hasColumn($lrTableEarly, 'updated_at')) {
                    $lrUpdateEarly['updated_at'] = now();
                }
                DB::table($lrTableEarly)
                    ->where('ClassSessionID', $session->id)
                    ->update($lrUpdateEarly);

                if ($existingRescheduled) {
                    $existingRescheduled->schedule_date = $sessionDate;
                    $existingRescheduled->start_time = $startTime;
                    $existingRescheduled->end_time = $endTime;
                    $existingRescheduled->day_of_week = $dayOfWeek;
                    $existingRescheduled->duration_hours = $durationHours;
                    $existingRescheduled->save();
                }
                if ($existingScheduled) {
                    $existingScheduled->schedule_date = $sessionDate;
                    $existingScheduled->start_time = $startTime;
                    $existingScheduled->end_time = $endTime;
                    $existingScheduled->day_of_week = $dayOfWeek;
                    $existingScheduled->duration_hours = $durationHours;
                    $existingScheduled->save();
                }

                $this->logSubstituteDiag('reschedule_migrated', [
                    'class_session_id' => $session->id,
                    'new_session_date' => $sessionDate,
                    'new_start_time' => $startTime,
                    'new_end_time' => $endTime,
                    'migrated_rescheduled_id' => $existingRescheduled ? (int) $existingRescheduled->id : null,
                    'migrated_scheduled_id' => $existingScheduled ? (int) $existingScheduled->id : null,
                ]);
            }

            // 1) Upsert rescheduled record (hides original teacher's slot)
            $existingRescheduledRow = $existingRescheduled ?: Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'rescheduled')
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->orderByRaw('CASE WHEN teacher_id = ? THEN 0 ELSE 1 END', [$oldTeacherId])
                ->orderByDesc('id')
                ->first();

            $rescheduledId = null;
            if (!$existingRescheduledRow) {
                // Align with ScheduleController@store: avoid ghost scheduled rows on the same course/date.
                DB::table('schedules')
                    ->where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->delete();

                $rescheduled = Schedule::create([
                    'student_id' => $studentId,
                    'teacher_id' => $oldTeacherId > 0 ? $oldTeacherId : null,
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
                ]);
                $rescheduledId = $rescheduled->id;
            } else {
                $rescheduledId = $existingRescheduledRow->id;
            }

            $this->logSubstituteDiag('after_rescheduled', [
                'class_session_id' => $session->id,
                'rescheduled_id' => $rescheduledId,
                'reused_rescheduled_row' => (bool) $existingRescheduledRow,
            ]);

            // 2) Upsert substitute scheduled record (new teacher's slot on same day).
            // Prefer the row matching the ClassSession's current start_time to avoid operating
            // on a stale row from before a same-day reschedule.
            $existingScheduledRow = $existingScheduled;
            if (!$existingScheduledRow) {
                $existingScheduledRow = Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->where('original_schedule_id', $rescheduledId)
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->first();
            }

            if ($existingScheduledRow) {
                $rowUpdate = [
                    'teacher_id' => $newTeacherId,
                    'original_schedule_id' => $rescheduledId,
                ];
                // Sync time if the ClassSession was rescheduled after the substitute was set
                if ($existingScheduledRow->start_time !== $startTime) {
                    $rowUpdate['start_time'] = $startTime;
                }
                if ($existingScheduledRow->end_time !== $endTime) {
                    $rowUpdate['end_time'] = $endTime;
                }
                $existingScheduledRow->update($rowUpdate);
                $scheduledId = $existingScheduledRow->id;
                // Remove any other stale scheduled rows for this rescheduled anchor on the same date
                // (can accumulate when reschedule + substitute are done in different orders)
                Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->where('original_schedule_id', $rescheduledId)
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->where('id', '!=', $scheduledId)
                    ->delete();
            } else {
                $scheduled = Schedule::create([
                    'student_id' => $studentId,
                    'teacher_id' => $newTeacherId,
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
                    'original_schedule_id' => $rescheduledId,
                ]);
                $scheduledId = $scheduled->id;
            }

            Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'scheduled')
                ->whereNull('original_schedule_id')
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->where('teacher_id', '!=', $oldTeacherId)
                ->where('id', '!=', $scheduledId)
                ->delete();

            $this->logSubstituteDiag('after_scheduled', [
                'class_session_id' => $session->id,
                'scheduled_id' => $scheduledId,
                'original_schedule_id' => $rescheduledId,
            ]);

            // 3) Update LearningRecord if one exists for this session
            // Use query builder (not Eloquent active()+save) so production DB quirks
            // (casts, hydration, partial migrations) cannot break substitute after schedules succeed.
            $lrTable = (new LearningRecord())->getTable();
            $lrRowQuery = DB::table($lrTable)->where('ClassSessionID', $session->id);
            if (Schema::hasColumn($lrTable, 'VoidedAt')) {
                $lrRowQuery->whereNull('VoidedAt');
            }
            $lrRow = $lrRowQuery->first();

            $this->logSubstituteDiag('lr_lookup', [
                'class_session_id' => $session->id,
                'lr_table' => $lrTable,
                'voided_at_column' => Schema::hasColumn($lrTable, 'VoidedAt'),
                'learning_record_found' => (bool) $lrRow,
                'learning_record_id' => $lrRow ? (int) $lrRow->id : null,
            ]);

            $lrId = null;
            if ($lrRow) {
                $lrOldTeacher = (int) ($lrRow->TeacherID ?? 0);
                $lrStatus = (string) ($lrRow->Status ?? '');
                if ($lrOldTeacher !== $newTeacherId) {
                    $lrUpdate = ['TeacherID' => $newTeacherId];
                    if (Schema::hasColumn($lrTable, 'updated_at')) {
                        $lrUpdate['updated_at'] = now();
                    }
                    DB::table($lrTable)->where('id', (int) $lrRow->id)->update($lrUpdate);

                    $authUser = $request->attributes->get('auth_user');
                    $changedBy = (int) ($authUser->id ?? 0);
                    if ($changedBy <= 0) {
                        $changedBy = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
                    }

                    if (Schema::hasTable('learning_record_teacher_changes')) {
                        try {
                            $auditReason = $this->scrubSubstituteUtf8($data['reason'] ?? '代課') ?: '代課';
                            if (function_exists('mb_strlen') && mb_strlen($auditReason, 'UTF-8') > 255) {
                                $auditReason = mb_substr($auditReason, 0, 255, 'UTF-8');
                            } elseif (strlen($auditReason) > 255) {
                                $auditReason = substr($auditReason, 0, 255);
                            }
                            LearningRecordTeacherChange::create([
                                'learning_record_id' => (int) $lrRow->id,
                                'old_teacher_id' => $lrOldTeacher > 0 ? $lrOldTeacher : null,
                                'new_teacher_id' => $newTeacherId,
                                'changed_by' => $changedBy,
                                'reason' => $auditReason,
                            ]);
                        } catch (\Throwable $auditEx) {
                            Log::warning('substitute: learning_record_teacher_changes insert skipped', [
                                'learning_record_id' => $lrRow->id,
                                'message' => $auditEx->getMessage(),
                            ]);
                        }
                    }

                    if ($lrStatus === 'approved' && Schema::hasColumn('User', 'TeachingSessionCount')) {
                        try {
                            if ($lrOldTeacher > 0) {
                                User::where('id', $lrOldTeacher)
                                    ->where('TeachingSessionCount', '>', 0)
                                    ->decrement('TeachingSessionCount');
                            }
                            User::where('id', $newTeacherId)->increment('TeachingSessionCount');
                        } catch (\Throwable $kpiEx) {
                            Log::warning('substitute: TeachingSessionCount update skipped', [
                                'message' => $kpiEx->getMessage(),
                            ]);
                        }
                    }
                }
                $lrId = (int) $lrRow->id;
            }

            $this->logSubstituteDiag('pre_teacher_display', [
                'class_session_id' => $session->id,
                'learning_record_id' => $lrId,
            ]);

            $teacherRaw = DB::table('Teacher')->where('id', $newTeacherId)->value('T_Name')
                ?? DB::table('User')->where('id', $newTeacherId)->value('Name');
            $teacherName = $this->scrubSubstituteUtf8($teacherRaw);
            if ($teacherName === '') {
                $teacherName = '未指派';
            }

            $reasonForLog = $this->scrubSubstituteUtf8($data['reason'] ?? null);
            $this->logSubstituteDiag('applied', [
                'class_session_id' => $session->id,
                'student_class_id' => $courseId,
                'old_teacher_id' => $oldTeacherId,
                'new_teacher_id' => $newTeacherId,
                'session_date' => $sessionDate,
                'reason' => $reasonForLog !== '' ? $reasonForLog : null,
            ]);

            $jsonFlags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }

            // PRD FR-010：同一交易建立家長代課通知
            $oldTeacherName = '';
            if ($oldTeacherId > 0) {
                $oldRaw = DB::table('Teacher')->where('id', $oldTeacherId)->value('T_Name')
                    ?? DB::table('User')->where('id', $oldTeacherId)->value('Name');
                $oldTeacherName = $this->scrubSubstituteUtf8($oldRaw);
            }
            $studentName = $this->scrubSubstituteUtf8(DB::table('Student')->where('id', $studentId)->value('Name') ?? '');
            $authUser = $request->attributes->get('auth_user');
            $operatorId = (int) ($authUser->id ?? 0);

            $substituteSvc = app(SubstituteService::class);
            $notification = null;
            try {
                $notification = $substituteSvc->createParentNotification([
                    'campus_id' => $campusId,
                    'class_session_id' => $session->id,
                    'student_id' => $studentId,
                    'student_class_id' => $courseId,
                    'student_name' => $studentName,
                    'subject' => $subject,
                    'session_date' => $sessionDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'old_teacher_id' => $oldTeacherId,
                    'old_teacher_name' => $oldTeacherName,
                    'new_teacher_id' => $newTeacherId,
                    'new_teacher_name' => $teacherName,
                    'reason' => $reasonForLog ?: null,
                    'cross_campus' => $crossCampus,
                    'operator_id' => $operatorId,
                    // PRD f0cce4d5 FR-006 / FR-008：合併換時訊息 + Undo 還原所需原始時間
                    'operation_type' => $hasReschedule ? 'substitute_with_reschedule' : 'substitute',
                    'original_session_date' => $hasReschedule ? $origSessionDate : $sessionDate,
                    'original_start_time' => $hasReschedule ? $origStartTime : $startTime,
                    'original_end_time' => $hasReschedule ? $origEndTime : $endTime,
                ]);
            } catch (\Throwable $ne) {
                Log::error('[substitute] parent_notification_failed_rollback', [
                    'class_session_id' => $session->id,
                    'message' => $ne->getMessage(),
                ]);
                throw $ne;
            }

            Log::info('[substitute_applied]', [
                'class_session_id' => $session->id,
                'student_class_id' => $courseId,
                'student_id' => $studentId,
                'campus_id' => $campusId,
                'old_teacher_id' => $oldTeacherId,
                'new_teacher_id' => $newTeacherId,
                'cross_campus' => $crossCampus,
                'operator_id' => $operatorId,
                'notification_id' => $notification ? (int) $notification->id : null,
                'rescheduled_id' => $rescheduledId,
                'scheduled_id' => $scheduledId,
                // PRD f0cce4d5：稽核合併換時操作（第 9 節資安）
                'operation_type' => $hasReschedule ? 'substitute_with_reschedule' : 'substitute',
                'rescheduled_from_date' => $hasReschedule ? $origSessionDate : null,
                'rescheduled_from_start_time' => $hasReschedule ? $origStartTime : null,
                'rescheduled_to_date' => $hasReschedule ? $sessionDate : null,
                'rescheduled_to_start_time' => $hasReschedule ? $startTime : null,
            ]);

            $this->logSubstituteDiag('before_json_response', [
                'class_session_id' => $session->id,
                'rescheduled_id' => $rescheduledId,
                'scheduled_id' => $scheduledId,
                'learning_record_id' => $lrId,
                'teacher_name_len' => strlen($teacherName),
            ]);

            return response()->json([
                'message' => $hasReschedule ? '代課與換時設定完成' : '代課設定完成',
                'class_session_id' => $session->id,
                'substitute_teacher_id' => $newTeacherId,
                'substitute_teacher_name' => $teacherName,
                'rescheduled_schedule_id' => $rescheduledId,
                'scheduled_schedule_id' => $scheduledId,
                'learning_record_id' => $lrId,
                'notification_id' => $notification ? (int) $notification->id : null,
                'cross_campus' => $crossCampus,
                // PRD f0cce4d5：合併代課+換時的新時段資訊（純代課時回原時段）
                'operation_type' => $hasReschedule ? 'substitute_with_reschedule' : 'substitute',
                'rescheduled' => $hasReschedule,
                'session_date' => $sessionDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'original_session_date' => $hasReschedule ? $origSessionDate : $sessionDate,
                'original_start_time' => $hasReschedule ? $origStartTime : $startTime,
                'original_end_time' => $hasReschedule ? $origEndTime : $endTime,
                // 業界對齊 Gmail Undo Send：UI 倒數用 undo_window_seconds，後端 deadline 保留 60s grace
                'undo_window_seconds' => \App\Http\Controllers\SubstituteController::resolveUiUndoWindow(),
                'undo_deadline_ms' => (int) round(microtime(true) * 1000)
                    + \App\Http\Controllers\SubstituteController::resolveServerUndoWindow() * 1000,
            ], 200, [], $jsonFlags);
        });
    }

    /**
     * Revert a single-session substitute back to the contract teacher.
     *
     * This is intentionally not the time-limited "Undo" flow: directors need a
     * durable correction path when a session was assigned to the wrong teacher.
     */
    private function restoreOriginalTeacherFromSubstitute(
        Request $request,
        ClassSession $session,
        StudentClass $studentClass,
        $existingRescheduled,
        $existingScheduled,
        string $sessionDate,
        string $startTime,
        int $originalTeacherId,
        array $data
    ) {
        return DB::transaction(function () use (
            $request,
            $session,
            $studentClass,
            $existingRescheduled,
            $existingScheduled,
            $sessionDate,
            $startTime,
            $originalTeacherId,
            $data
        ) {
            $courseId = (int) $studentClass->ID;
            $authUser = $request->attributes->get('auth_user');
            $changedBy = (int) ($authUser->id ?? 0);

            $rescheduled = $existingRescheduled ?: Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'rescheduled')
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->first();

            $scheduled = $existingScheduled;
            if (!$scheduled && $rescheduled) {
                $scheduled = Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->where('original_schedule_id', (int) $rescheduled->id)
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->first();
            }

            $scheduledDeleted = 0;
            $anchorIds = [];
            if ($rescheduled) {
                $anchorIds[] = (int) $rescheduled->id;
            }
            if ($scheduled && (int) ($scheduled->original_schedule_id ?? 0) > 0) {
                $anchorIds[] = (int) $scheduled->original_schedule_id;
            }
            // Defensive recovery for historical data: contract teacher may already change,
            // but stale substitute rows (teacher_id != current contract teacher) still remain.
            $fallbackAnchors = Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'scheduled')
                ->whereNotNull('original_schedule_id')
                ->where('teacher_id', '!=', $originalTeacherId)
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->pluck('original_schedule_id')
                ->filter(fn ($id) => (int) $id > 0)
                ->map(fn ($id) => (int) $id)
                ->all();
            $anchorIds = array_values(array_unique(array_merge($anchorIds, $fallbackAnchors)));

            if (!empty($anchorIds)) {
                $scheduledDeleted += Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->whereIn('original_schedule_id', $anchorIds)
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->delete();
            } elseif ($scheduled) {
                $scheduledDeleted += Schedule::where('id', (int) $scheduled->id)->delete();
            }

            $rescheduledDeleted = 0;
            if (!empty($anchorIds)) {
                $rescheduledDeleted = Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'rescheduled')
                    ->whereIn('id', $anchorIds)
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('schedules as sibling')
                            ->whereColumn('sibling.original_schedule_id', 'schedules.id')
                            ->where('sibling.status', 'scheduled');
                    })
                    ->delete();
            } elseif ($rescheduled) {
                $rescheduledDeleted = Schedule::where('id', (int) $rescheduled->id)->delete();
            }

            $lrId = null;
            $lrTable = (new LearningRecord())->getTable();
            $lrRowQuery = DB::table($lrTable)->where('ClassSessionID', $session->id);
            if (Schema::hasColumn($lrTable, 'VoidedAt')) {
                $lrRowQuery->whereNull('VoidedAt');
            }
            $lrRow = $lrRowQuery->first();
            if ($lrRow) {
                $lrId = (int) $lrRow->id;
                $lrOldTeacher = (int) ($lrRow->TeacherID ?? 0);
                if ($lrOldTeacher !== $originalTeacherId) {
                    $lrUpdate = ['TeacherID' => $originalTeacherId];
                    if (Schema::hasColumn($lrTable, 'updated_at')) {
                        $lrUpdate['updated_at'] = now();
                    }
                    DB::table($lrTable)->where('id', $lrId)->update($lrUpdate);

                    if (Schema::hasTable('learning_record_teacher_changes')) {
                        try {
                            $auditReason = $this->scrubSubstituteUtf8($data['reason'] ?? '回復正班老師') ?: '回復正班老師';
                            LearningRecordTeacherChange::create([
                                'learning_record_id' => $lrId,
                                'old_teacher_id' => $lrOldTeacher > 0 ? $lrOldTeacher : null,
                                'new_teacher_id' => $originalTeacherId,
                                'changed_by' => $changedBy > 0 ? $changedBy : null,
                                'reason' => $auditReason,
                            ]);
                        } catch (\Throwable $auditEx) {
                            Log::warning('substitute_restore: learning_record_teacher_changes insert skipped', [
                                'learning_record_id' => $lrId,
                                'message' => $auditEx->getMessage(),
                            ]);
                        }
                    }

                    if (($lrRow->Status ?? '') === 'approved' && Schema::hasColumn('User', 'TeachingSessionCount')) {
                        try {
                            if ($lrOldTeacher > 0) {
                                User::where('id', $lrOldTeacher)
                                    ->where('TeachingSessionCount', '>', 0)
                                    ->decrement('TeachingSessionCount');
                            }
                            User::where('id', $originalTeacherId)->increment('TeachingSessionCount');
                        } catch (\Throwable $kpiEx) {
                            Log::warning('substitute_restore: TeachingSessionCount update skipped', [
                                'message' => $kpiEx->getMessage(),
                            ]);
                        }
                    }
                }
            }

            if (Schema::hasTable('Notifications') && Schema::hasColumn('Notifications', 'ResolvedAt')) {
                DB::table('Notifications')
                    ->where('Type', 'substitute')
                    ->where('SourceType', 'ClassSession')
                    ->where('SourceID', $session->id)
                    ->whereNull('ResolvedAt')
                    ->update(['ResolvedAt' => now()]);
            }

            Log::info('[substitute_restore_original]', [
                'class_session_id' => $session->id,
                'student_class_id' => $courseId,
                'restored_teacher_id' => $originalTeacherId,
                'scheduled_deleted' => $scheduledDeleted,
                'rescheduled_deleted' => $rescheduledDeleted,
                'operator_id' => $changedBy ?: null,
            ]);

            return response()->json([
                'message' => '已回復正班老師',
                'class_session_id' => (int) $session->id,
                'restored_teacher_id' => $originalTeacherId,
                'substitute_cleared' => ($scheduledDeleted + $rescheduledDeleted) > 0,
                'deleted_scheduled_count' => $scheduledDeleted,
                'deleted_rescheduled_count' => $rescheduledDeleted,
                'learning_record_id' => $lrId,
            ]);
        });
    }

    /**
     * 代課除錯：寫入 laravel.log，grep `[substitute]` 即可依 step 對照中斷點。
     *
     * @param  array<string, mixed>  $context
     */
    private function logSubstituteDiag(string $step, array $context = []): void
    {
        try {
            Log::info('[substitute]', array_merge([
                'step' => $step,
                't' => now()->format('Y-m-d H:i:s.v'),
            ], $context));
        } catch (\Throwable $e) {
            // 不因 log 失敗影響代課
        }
    }

    /**
     * Strip / replace invalid UTF-8 so Monolog and JsonResponse do not throw.
     *
     * @param  mixed  $value
     */
    private function scrubSubstituteUtf8($value): string
    {
        if ($value === null) {
            return '';
        }
        $s = is_string($value) ? $value : (string) $value;
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_scrub')) {
            return mb_scrub($s, 'UTF-8');
        }
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $s);

        return $converted !== false ? $converted : '';
    }

    /**
     * Normalize ClassSession StartTime/EndTime (time, datetime string, or Carbon) to HH:MM for schedules.
     */
    private function normalizeSessionTimeForSchedule($value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('H:i');
        }
        $s = trim((string) $value);
        if ($s === '') {
            return '';
        }
        if (preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?/', $s, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $s;
    }

    private function resolveStudentGradeId(int $studentId): int
    {
        try {
            $gradeId = Student::where('id', $studentId)->value('GradeID');
            return $gradeId ? (int) $gradeId : 1;
        } catch (\Throwable $e) {
            return 1;
        }
    }

    /**
     * 主任／超管：近 N 天各授課老師已到班堂次的評量內容填寫率（LearningRecord.Progress 非空視為已填）。
     *
     * 代課堂次計入取代課老師名下（與行事曆顯示邏輯一致）。
     */
    public function directorTeacherLearningFillRates(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if ($role !== 'director' && $role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'branch_id' => 'required|integer|min:1',
            'days' => 'nullable|integer|min:1|max:31',
        ]);
        $branchId = (int) $validated['branch_id'];
        $days = (int) ($validated['days'] ?? 14);

        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
        }

        $end = Carbon::today();
        $start = $end->copy()->subDays($days - 1);

        $lrSubSql = '(SELECT lr_inner.* FROM `LearningRecord` lr_inner INNER JOIN (SELECT ClassSessionID, MAX(id) AS max_id FROM `LearningRecord` WHERE VoidedAt IS NULL GROUP BY ClassSessionID) lr_latest ON lr_inner.id = lr_latest.max_id)';

        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('schedules as sub_sched', function ($join) {
                $join->on('sub_sched.student_course_id', '=', 'sc.ID')
                    ->where('sub_sched.status', '=', 'scheduled')
                    ->whereNotNull('sub_sched.original_schedule_id')
                    ->whereRaw('DATE(sub_sched.schedule_date) = DATE(cs.SessionDate)')
                    ->whereRaw('SUBSTRING(sub_sched.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)')
                    ->whereColumn('sub_sched.teacher_id', '!=', 'sc.TeacherID')
                    ->whereRaw('sub_sched.id = (
                        SELECT MAX(sub2.id)
                        FROM schedules sub2
                        WHERE sub2.student_course_id = sc.ID
                          AND sub2.status = "scheduled"
                          AND sub2.original_schedule_id IS NOT NULL
                          AND DATE(sub2.schedule_date) = DATE(cs.SessionDate)
                          AND SUBSTRING(sub2.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)
                          AND sub2.teacher_id <> sc.TeacherID
                    )');
            })
            ->leftJoin(DB::raw($lrSubSql . ' AS lr'), 'lr.ClassSessionID', '=', 'cs.id')
            ->where('s.CampusID', $branchId)
            ->whereBetween(DB::raw('DATE(cs.SessionDate)'), [$start->toDateString(), $end->toDateString()])
            ->whereRaw('LOWER(cs.Status) IN ("attended", "late")')
            ->select([
                DB::raw('COALESCE(sub_sched.teacher_id, sc.TeacherID) AS teacher_id'),
                DB::raw('COUNT(*) AS session_total'),
                DB::raw('SUM(CASE WHEN lr.id IS NOT NULL AND TRIM(IFNULL(lr.Progress, "")) != "" THEN 1 ELSE 0 END) AS filled'),
            ])
            ->groupBy(DB::raw('COALESCE(sub_sched.teacher_id, sc.TeacherID)'))
            ->orderByDesc('session_total')
            ->get()
            ->filter(static function ($row) {
                return (int) ($row->teacher_id ?? 0) > 0;
            })
            ->values();

        $ids = $rows->pluck('teacher_id')->map(fn ($v) => (int) $v)->filter(fn ($id) => $id > 0)->unique()->values();
        $nameMap = $ids->isNotEmpty()
            ? DB::table('User')->whereIn('id', $ids->all())->pluck('Name', 'id')
            : collect();

        $teachers = $rows->map(static function ($row) use ($nameMap) {
            $tid = (int) ($row->teacher_id ?? 0);
            $total = (int) ($row->session_total ?? 0);
            $filled = (int) ($row->filled ?? 0);
            $pct = $total > 0 ? (int) round(100 * $filled / $total) : 0;

            return [
                'teacher_id'               => $tid,
                'teacher_name'             => $nameMap->get($tid, '未知'),
                'sessions_attended'        => $total,
                'learning_records_filled'  => $filled,
                'fill_rate_pct'            => $pct,
            ];
        })->values();

        return response()->json([
            'start'                  => $start->toDateString(),
            'end'                    => $end->toDateString(),
            'days'                   => $days,
            'branch_id'               => $branchId,
            'teachers'                => $teachers,
        ]);
    }

    /**
     * Diagnostic: log when effective session count != purchased for any course in response.
     * Only runs when perfflags.log_session_count_mismatch is true.
     * Logs only course_id, branch_id, and status counts (no PII).
     */
    private function logSessionCountMismatches(array $byClass, $request): void
    {
        static $nonQuota = ['cancelled', 'leave', 'leave_adjusted', 'excused'];
        $branchId = (int) ($request->input('branch_id') ?? 0);

        // #546/TD-018：一次撈齊各課程的 SessionCount，取代每課程一次 value() 查詢（N+1）。
        $courseIds = array_map('intval', array_keys($byClass));
        $purchasedMap = !empty($courseIds)
            ? DB::table('StudentClass')->whereIn('ID', $courseIds)->pluck('SessionCount', 'ID')
            : collect();

        foreach ($byClass as $courseId => $sessions) {
            $purchased = $purchasedMap[(int) $courseId] ?? null;
            if (!$purchased || $purchased <= 0) {
                continue;
            }
            $statusCounts = [];
            $effective = 0;
            foreach ($sessions as $s) {
                $st = strtolower($s->status ?? '');
                $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
                if (!in_array($st, $nonQuota, true)) {
                    $effective++;
                }
            }
            if ($effective !== (int) $purchased) {
                \Log::channel('daily')->info('session_count_mismatch', [
                    'course_id' => $courseId,
                    'branch_id' => $branchId,
                    'purchased' => (int) $purchased,
                    'effective' => $effective,
                    'status_breakdown' => $statusCounts,
                ]);
            }
        }
    }
}

