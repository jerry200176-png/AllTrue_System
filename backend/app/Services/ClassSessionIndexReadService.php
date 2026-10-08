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

        // Substitutes only matter on the requested dates; bounding the GROUP BY keeps it off the whole schedules table.
        // Only a real calendar Y-m-d is used (digits and dashes only, so the literal is injection-safe); anything else
        // leaves the subquery unbounded as before, so odd input never errors here.
        $subScheduleDateBound = '';
        foreach (['start' => '>=', 'end' => '<='] as $param => $op) {
            $value = $request->input($param);
            if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $subScheduleDateBound .= " AND sub2.schedule_date {$op} '{$value}'";
            }
        }

        // Flag off: plain `sub_sched.teacher_id`. Flag on: stamped live row / makeup LearningRecord teacher, NULL = contract teacher.
        $subTeacherSql = SubstituteScheduleService::substituteTeacherSql();

        $query = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            // TD-058: sub_sched exposes start_time_hm (pre-normalized once per row here,
            // over the small substitute-schedule result set) so the outer ON clause below
            // can compare it directly to cs.StartTimeHM without wrapping either side in
            // SUBSTRING()/DATE() — function-wrapped join columns defeat index usage.
            // cs.SessionDate and sub_sched.schedule_date are both native DATE columns, so
            // comparing them directly (no DATE() wrap) is behaviorally identical.
            ->leftJoin(DB::raw(SubstituteScheduleService::substituteScheduleDerivedSql($subScheduleDateBound) . ' as sub_sched'), function ($join) {
                $join->on('sub_sched.student_course_id', '=', 'sc.ID')
                    ->on('sub_sched.schedule_date', '=', 'cs.SessionDate')
                    ->on('sub_sched.start_time_hm', '=', 'cs.StartTimeHM');
            })
            // in-app #319: per-row index lookups instead of whole-table "latest per session" derived tables
            // (production EXPLAIN run 37166542492 scanned all LearningRecord/StudentSingIn rows on every request).
            // LearningRecord.ClassSessionID is UNIQUE, so "latest non-voided" is the one row when not voided.
            ->leftJoin('LearningRecord as lr', function ($join) {
                $join->on('lr.ClassSessionID', '=', 'cs.id')->whereNull('lr.VoidedAt');
            })
            // StudentSingIn has many rows per session: pick the latest non-voided via idx_ssi_classsession_id.
            ->leftJoin('StudentSingIn as si', function ($join) {
                $join->on('si.ClassSessionID', '=', 'cs.id')
                    ->whereRaw('si.id = (SELECT MAX(si2.id) FROM `StudentSingIn` si2 WHERE si2.ClassSessionID = cs.id AND si2.VoidedAt IS NULL)');
            })
            ->leftJoin('User as subu', fn ($join) => $join->whereRaw("subu.id = {$subTeacherSql}"))
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
                DB::raw("{$subTeacherSql} as substitute_teacher_id"),
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
                // #3780 P1: substitute provenance (unique SourceKey index), not "teacher differs from the contract".
                DB::raw("EXISTS (SELECT 1 FROM Notifications sub_n WHERE sub_n.SourceKey = CONCAT('substitute:', cs.id) AND sub_n.ResolvedAt IS NULL) as substitute_notice"),
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
            $query->where(function ($q) use ($teacherId, $subTeacherSql) {
                $q->where(function ($inner) use ($teacherId, $subTeacherSql) {
                    $inner->whereRaw("{$subTeacherSql} IS NULL")
                        ->where('sc.TeacherID', $teacherId);
                })->orWhereRaw("{$subTeacherSql} = ?", [$teacherId]);
            });
        }

        if (!empty($campusIds)) {
            $this->applyClassSessionCampusBranchFilter($query, $campusIds);
        }

        if ($request->filled('teacher_id')) {
            $filterTid = (int) $request->input('teacher_id');
            $query->where(function ($q) use ($filterTid, $subTeacherSql) {
                $q->where(function ($inner) use ($filterTid, $subTeacherSql) {
                    $inner->whereRaw("{$subTeacherSql} IS NULL")
                        ->where('sc.TeacherID', $filterTid);
                })->orWhereRaw("{$subTeacherSql} = ?", [$filterTid]);
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
        $row->contract_teacher_id = (int) ($row->TeacherID ?? 0) ?: null;
        $row->substitute_notice = (bool) ($row->substitute_notice ?? false);
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
