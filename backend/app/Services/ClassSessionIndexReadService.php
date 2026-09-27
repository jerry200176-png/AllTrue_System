<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pure-read ClassSession index query shared by ClassSessionController and TrueFit.
 * Must not perform auto-materialization or any writes.
 */
class ClassSessionIndexReadService
{
    /**
     * @return array<int, object>
     */
    public function fetchTransformedRows(Request $request, int $limit = 500): array
    {
        $limit = min(max($limit, 1), 500);
        $rows = $this->buildQuery($request)
            ->orderBy('cs.SessionDate', 'asc')
            ->orderBy('cs.StartTime', 'asc')
            ->orderBy('cs.id', 'asc')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => $this->transformRow($row))->all();
    }

    public function buildQuery(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);

        $requestedCampus = (int) ($request->input('branch_id') ?? $request->input('campus_id') ?? 0);
        if ($requestedCampus > 0) {
            $campusIds = [$requestedCampus];
        }

        $attendanceAsOf = Carbon::now();
        $attendanceAsOfDate = $attendanceAsOf->toDateString();
        $attendanceAsOfTime = $attendanceAsOf->format('H:i:s');

        $query = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            // TD-058: sub_sched exposes start_time_hm (pre-normalized once per row here,
            // over the small substitute-schedule result set) so the outer ON clause below
            // can compare it directly to cs.StartTimeHM without wrapping either side in
            // SUBSTRING()/DATE() — function-wrapped join columns defeat index usage.
            // cs.SessionDate and sub_sched.schedule_date are both native DATE columns, so
            // comparing them directly (no DATE() wrap) is behaviorally identical.
            ->leftJoin(DB::raw('(
                SELECT ss.*, SUBSTRING(ss.start_time, 1, 5) AS start_time_hm
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
                    ->on('sub_sched.schedule_date', '=', 'cs.SessionDate')
                    ->on('sub_sched.start_time_hm', '=', 'cs.StartTimeHM');
            })
            ->leftJoin('User as subu', 'subu.id', '=', 'sub_sched.teacher_id')
            ->leftJoin(DB::raw('(SELECT lr_inner.* FROM `LearningRecord` lr_inner INNER JOIN (SELECT ClassSessionID, MAX(id) AS max_id FROM `LearningRecord` WHERE VoidedAt IS NULL GROUP BY ClassSessionID) lr_latest ON lr_inner.id = lr_latest.max_id) AS lr'), 'lr.ClassSessionID', '=', 'cs.id')
            ->leftJoin(DB::raw('(SELECT si_inner.* FROM `StudentSingIn` si_inner INNER JOIN (SELECT ClassSessionID, MAX(id) AS max_id FROM `StudentSingIn` WHERE VoidedAt IS NULL GROUP BY ClassSessionID) si_latest ON si_inner.id = si_latest.max_id) AS si'), 'si.ClassSessionID', '=', 'cs.id')
            ->leftJoin('User as u', 'u.id', '=', 'sc.TeacherID')
            ->leftJoin('User as lru', 'lru.id', '=', 'lr.TeacherID')
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
                'cs.Note',
                'cs.session_charge',
                'sc.StudentID',
                'sc.TeacherID',
                'sc.Stop as course_stop',
                'sc.SessionCount as course_session_count',
                'sc.Rate as sc_rate',
                'sc.SessionDuration as sc_session_duration',
                'sc.rate_unit as sc_rate_unit',
                'sub_sched.teacher_id as substitute_teacher_id',
                's.CampusID',
                's.name as student_name',
                DB::raw('COALESCE(subu.Name, u.Name, lru.Name, "") as teacher_name'),
                DB::raw('COALESCE(sub.Subject_Name, "") as subject_name'),
                'lr.id as learning_record_id',
                'lr.Status as learning_record_status',
                'lr.TeacherID as learning_record_teacher_id',
                'lr.Progress as learning_record_progress',
                'si.SignInDT as attendance_sign_in_at',
                'si.Memo as attendance_memo',
                DB::raw('COALESCE(rbu.Name, siu.Name, "") as recorded_by_name'),
                DB::raw('EXISTS (SELECT 1 FROM LearningRecord lr_history WHERE lr_history.ClassSessionID = cs.id) as learning_record_history'),
                DB::raw('EXISTS (SELECT 1 FROM StudentSingIn si_history WHERE si_history.ClassSessionID = cs.id) as attendance_history'),
            ]);

        // Use the application's Taipei clock rather than the DB server clock.
        // Production DBs may run in UTC; CURRENT_DATE/CURRENT_TIME therefore
        // made a Taiwan "today" session look like a future reservation. Future
        // scheduled rows still remain scheduled until their slot has ended.
        $query->selectRaw(
            "CASE
                WHEN si.id IS NOT NULL AND LOWER(cs.Status) IN ('scheduled','absent')
                    AND (
                        cs.SessionDate < ?
                        OR (cs.SessionDate = ? AND cs.EndTime <= ?)
                        OR COALESCE(si.SessionDeducted, 0) = 0
                    )
                THEN CASE
                    WHEN LOWER(si.Status) = 'present' THEN 'attended'
                    WHEN LOWER(si.Status) = 'late' THEN 'late'
                    WHEN LOWER(si.Status) IN ('leave','excused') THEN 'leave'
                    ELSE cs.Status
                END
                ELSE cs.Status
            END AS effective_status",
            [$attendanceAsOfDate, $attendanceAsOfDate, $attendanceAsOfTime]
        );

        if ($role === 'teacher') {
            $query->where(function ($q) use ($teacherId) {
                $q->where(function ($inner) use ($teacherId) {
                    $inner->whereNull('sub_sched.teacher_id')
                        ->where('sc.TeacherID', $teacherId);
                })->orWhere('sub_sched.teacher_id', $teacherId);
            });
        }

        if (!empty($campusIds)) {
            $this->applyClassSessionCampusBranchFilter($query, $campusIds);
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

        if ($request->filled('start')) {
            $query->where('cs.SessionDate', '>=', $request->input('start'));
        }

        if ($request->filled('end')) {
            $query->where('cs.SessionDate', '<=', $request->input('end'));
        }

        if (!$request->boolean('include_internal_placeholder')) {
            $query->where(function ($q) {
                $q->where('cs.Status', '<>', 'cancelled')
                    ->orWhere(function ($inner) {
                        $inner->where('cs.Note', 'NOT LIKE', '%cancelled-duplicate-reschedule-placeholder%')
                            ->orWhereNull('cs.Note');
                    });
            });
        }

        // R20 / 2026-07-18：Stop=1 課程殘留的 scheduled 會讓出缺勤「今日待點名」出現雙列。
        // 預設隱藏；稽核可用 include_stopped_scheduled=1 帶回。
        if (!$request->boolean('include_stopped_scheduled')) {
            $query->whereRaw("NOT (COALESCE(sc.Stop, 0) = 1 AND LOWER(cs.Status) = 'scheduled')");
        }

        if ($request->boolean('exclude_history_future')) {
            $query->whereRaw(
                "NOT (
                    ((COALESCE(sc.Stop, 0) = 1 OR LOWER(COALESCE(sc.closed_reason, '')) IN ('settled', 'completed', 'usage_settled'))
                        AND cs.SessionDate > ? AND LOWER(cs.Status) IN ('scheduled', 'rescheduled'))
                    OR (LOWER(COALESCE(sc.ScheduleMode, 'count')) = 'count'
                        AND COALESCE(sc.SessionCount, 0) > 0
                        AND cs.SessionDate > ?
                        AND LOWER(cs.Status) IN ('scheduled', 'rescheduled')
                        AND (SELECT COUNT(*) FROM ClassSession AS used_cs
                             WHERE used_cs.StudentClassID = cs.StudentClassID
                               AND LOWER(used_cs.Status) IN ('completed', 'attended', 'late')) >= sc.SessionCount)
                )",
                [Carbon::today()->toDateString(), Carbon::today()->toDateString()]
            );
        }

        return $query;
    }

    public function transformRow($row)
    {
        $row->id = (int) $row->id;
        $row->student_class_id = (int) $row->StudentClassID;
        $row->student_id = (int) $row->StudentID;
        $row->course_stop = (int) ($row->course_stop ?? 0) === 1 ? 1 : 0;
        $row->course_session_count = (int) ($row->course_session_count ?? 0);
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
        $row->has_learning_record_history = (bool) ($row->learning_record_history ?? false);
        $row->has_attendance_history = (bool) ($row->attendance_history ?? false);
        $row->recoverable_cancelled = strtolower((string) ($row->Status ?? '')) === 'cancelled'
            && ($row->has_learning_record_history || $row->has_attendance_history);
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
            $row->learning_record_history,
            $row->attendance_history,
            $row->Note,
            $row->sc_rate,
            $row->sc_session_duration,
            $row->sc_rate_unit
        );

        return $row;
    }

    private function applyClassSessionCampusBranchFilter($query, array $campusIds): void
    {
        $query->leftJoin('rooms as cs_branch_room', 'cs_branch_room.id', '=', 'sc.room_id');
        $query->where(function ($q) use ($campusIds) {
            $q->where(function ($inner) use ($campusIds) {
                $inner->whereNotNull('sc.room_id')
                    ->whereIn('cs_branch_room.campus_id', $campusIds);
            })->orWhere(function ($inner) use ($campusIds) {
                $inner->whereNull('sc.room_id')
                    ->whereIn('s.CampusID', $campusIds);
            });
        });
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

}
