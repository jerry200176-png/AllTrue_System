<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 老師每天有哪些課（起始時間 + 分校），給出勤判斷遲到／缺卡用。
 *
 * 規則與 SubstituteService::collectTeacherBusySlots 相同，只是一次撈一段日期：
 * - ClassSession（G-010 來源真相）+ StudentClass.TeacherID，排除取消／請假，
 *   也排除已由別的老師代課的堂次（合約老師當天不用到）。
 * - schedules 的 scheduled 例外 row（代課／加課），用 StaleScheduleExceptionFilter 剔除殘影。
 * - 同一堂（課程＋日期＋起始時間）只認最新的一筆 scheduled row（舊一代的代課 row 不算，否則同一堂算給兩位老師）。
 * - 該時段另有較新的 rescheduled row、而且那個時段沒有有效的 ClassSession：這筆 scheduled 是改期後留下的殘影，不算
 *   （有 ClassSession 時不能這樣判：改期會把代課 row 原地搬到新時段、id 不變，可能比該時段的舊 rescheduled 小）。
 *   Founder 2026-11-20。
 */
class TeacherClassCalendar
{
    /**
     * @param  int[]|null  $campusIds  null = 不限分校
     * @param  int|int[]|null  $teacherId  one teacher, several, or null for all
     * @param  bool  $forPayroll  正職段數用：學生請假的堂次照算（已安排補課的 leave_adjusted 不算，補課算在新時段），行政／不可排時段不算
     * @return array<int, array<string, list<array{start:string,end:string,campus_id:int,student_id:int,course_id:int}>>> teacherId => Y-m-d => 課
     */
    public static function load(string $from, string $to, ?array $campusIds, int|array|null $teacherId = null, bool $forPayroll = false): array
    {
        $teacherIds = $teacherId === null || $teacherId === [] ? null : array_map('intval', (array) $teacherId);
        $scheduleQuery = DB::table('schedules')
            ->whereNotNull('teacher_id')
            ->whereBetween('schedule_date', [$from, $to])
            ->whereIn('status', ['scheduled', 'rescheduled'])
            ->when($forPayroll, fn ($q) => $q->whereNotIn('type', ['admin', 'unavailable']))
            ->select('id', 'status', 'teacher_id', 'schedule_date', 'start_time', 'end_time', 'branch_id', 'student_course_id', 'student_id', 'original_schedule_id');
        if ($campusIds !== null) {
            $scheduleQuery->whereIn('branch_id', $campusIds);
        }
        $allRows = $scheduleQuery->get();
        // ClassSession statuses that are no lesson here; the same list decides below whether a slot is still held.
        $off = $forPayroll ? ['cancelled', 'voided', 'leave_adjusted'] : ['cancelled', 'leave'];
        $contractTeacher = DB::table('StudentClass')->whereIn('ID', $allRows->pluck('student_course_id')->filter()->unique()->values()->all())->pluck('TeacherID', 'ID');
        // slot key => largest id. A substitute row (another teacher, with its anchor) outranks any row of the contract
        // teacher on the slot, as in SubstituteScheduleService::substituteScheduleDerivedSql (what the course calendar shows).
        $newest = ['scheduled' => [], 'rescheduled' => [], 'substitute' => []];
        foreach ($allRows as $row) {
            $row->status = strtolower(trim((string) $row->status));
            if ((int) $row->student_course_id > 0 && isset($newest[$row->status])) {
                $key = self::key($row->student_course_id, $row->schedule_date, $row->start_time);
                $isSubstitute = $row->status === 'scheduled' && $row->original_schedule_id !== null
                    && (int) $row->teacher_id !== (int) ($contractTeacher[$row->student_course_id] ?? 0);
                $status = $isSubstitute ? 'substitute' : $row->status;
                $newest[$status][$key] = max($newest[$status][$key] ?? 0, (int) $row->id);
            }
        }
        $newest['scheduled'] = $newest['substitute'] + $newest['scheduled'];
        // Slots whose live row is older than a rescheduled row on the same slot: a leftover only if no lesson is held there.
        $movedOn = array_filter($newest['scheduled'], fn ($id, $key) => ($newest['rescheduled'][$key] ?? 0) > $id, ARRAY_FILTER_USE_BOTH);
        $held = [];
        if ($movedOn !== []) {
            $sessions = DB::table('ClassSession')
                ->whereIn('StudentClassID', array_values(array_unique(array_map('intval', array_keys($movedOn)))))
                ->whereBetween('SessionDate', [$from, $to])
                ->whereNotIn('Status', $off)
                ->get(['StudentClassID', 'SessionDate', 'StartTime']);
            foreach ($sessions as $session) {
                $held[self::key($session->StudentClassID, $session->SessionDate, $session->StartTime)] = true;
            }
        }
        $scheduleRows = $allRows->filter(function ($row) use ($newest, $movedOn, $held) {
            if ($row->status !== 'scheduled' || (int) $row->student_course_id <= 0) {
                return $row->status === 'scheduled';
            }
            $key = self::key($row->student_course_id, $row->schedule_date, $row->start_time);

            return (int) $row->id === $newest['scheduled'][$key] && (! isset($movedOn[$key]) || isset($held[$key]));
        })->values();

        // 代課 row：course|日期|HH:MM → 代課老師 id
        $substitutedBy = [];
        foreach ($scheduleRows as $row) {
            if ($row->original_schedule_id !== null) {
                $substitutedBy[self::key($row->student_course_id, $row->schedule_date, $row->start_time)][(int) $row->teacher_id] = true;
            }
        }

        $sessionQuery = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'cs.StudentClassID', '=', 'sc.ID')
            ->leftJoin('Student as st', 'sc.StudentID', '=', 'st.id')
            ->whereNotNull('sc.TeacherID')
            ->whereBetween('cs.SessionDate', [$from, $to])
            ->whereNotIn('cs.Status', $off)
            ->select('sc.TeacherID as teacher_id', 'cs.StudentClassID as course_id', 'cs.SessionDate as d', 'cs.StartTime as start_time', 'cs.EndTime as end_time', 'st.CampusID as campus_id', 'sc.StudentID as student_id');
        if ($campusIds !== null) {
            $sessionQuery->whereIn('st.CampusID', $campusIds);
        }
        if ($teacherIds) {
            $sessionQuery->whereIn('sc.TeacherID', $teacherIds);
        }

        $out = [];
        foreach ($sessionQuery->get() as $row) {
            $subs = $substitutedBy[self::key($row->course_id, $row->d, $row->start_time)] ?? [];
            unset($subs[(int) $row->teacher_id]);
            if ($subs !== [] || SubstituteScheduleService::isSubstitutedAway((int) $row->course_id, $row->d, (string) $row->start_time)) {
                continue;
            }
            self::push($out, $row->teacher_id, $row->d, $row->start_time, $row->end_time, $row->campus_id, $row->student_id, $row->course_id);
        }

        $filter = app(StaleScheduleExceptionFilter::class);
        $byDate = collect($scheduleRows)
            ->filter(fn ($r) => ! $teacherIds || in_array((int) $r->teacher_id, $teacherIds, true))
            ->groupBy(fn ($r) => substr((string) $r->schedule_date, 0, 10));
        foreach ($byDate as $date => $rows) {
            foreach ($filter->rejectStale($rows, $date, false, $forPayroll ? ['leave'] : []) as $row) {
                self::push($out, $row->teacher_id, $date, $row->start_time, $row->end_time, $row->branch_id, $row->student_id, $row->student_course_id);
            }
        }

        return $out;
    }

    /**
     * Of the given dates, those that still have a live schedule-only lesson (a makeup or substitute row whose
     * slot has no called-off ClassSession). Company-wide; used to tell a real closure day from a day that
     * only moved its lessons.
     *
     * @param  list<string>  $dates  Y-m-d
     * @return list<string>
     */
    public static function datesWithLiveExceptionLessons(array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        $rowsByDate = DB::table('schedules')
            ->whereNotNull('teacher_id')
            ->whereIn('schedule_date', $dates)
            ->where('status', 'scheduled')
            ->whereNotIn('type', ['admin', 'unavailable'])
            ->get(['schedule_date', 'start_time', 'student_course_id'])
            ->groupBy(fn ($row) => substr((string) $row->schedule_date, 0, 10))
            ->map(fn ($rows) => $rows->all())
            ->all();

        return array_keys(array_filter(app(StaleScheduleExceptionFilter::class)->rejectStaleBatch($rowsByDate)));
    }

    private static function push(array &$out, $teacherId, $date, $start, $end, $campusId, $studentId, $courseId): void
    {
        $hhmm = self::hhmm($start);
        if ($hhmm !== '') {
            $out[(int) $teacherId][substr((string) $date, 0, 10)][] = ['start' => $hhmm, 'end' => self::hhmm($end), 'campus_id' => (int) $campusId,
                'student_id' => (int) $studentId, 'course_id' => (int) $courseId];
        }
    }

    private static function key($courseId, $date, $start): string
    {
        return (int) $courseId . '|' . substr((string) $date, 0, 10) . '|' . self::hhmm($start);
    }

    private static function hhmm($value): string
    {
        $ts = strtotime((string) $value);

        return $value === null || $ts === false ? '' : date('H:i', $ts);
    }
}
