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
 */
class TeacherClassCalendar
{
    /**
     * @param  int[]|null  $campusIds  null = 不限分校
     * @return array<int, array<string, list<array{start:string,campus_id:int}>>> teacherId => Y-m-d => 課
     */
    public static function load(string $from, string $to, ?array $campusIds, ?int $teacherId = null): array
    {
        $scheduleQuery = DB::table('schedules')
            ->whereNotNull('teacher_id')
            ->whereBetween('schedule_date', [$from, $to])
            ->where('status', 'scheduled')
            ->select('teacher_id', 'schedule_date', 'start_time', 'branch_id', 'student_course_id', 'student_id', 'original_schedule_id');
        if ($campusIds !== null) {
            $scheduleQuery->whereIn('branch_id', $campusIds);
        }
        $scheduleRows = $scheduleQuery->get();

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
            ->whereNotIn('cs.Status', ['cancelled', 'leave'])
            ->select('sc.TeacherID as teacher_id', 'cs.StudentClassID as course_id', 'cs.SessionDate as d', 'cs.StartTime as start_time', 'st.CampusID as campus_id');
        if ($campusIds !== null) {
            $sessionQuery->whereIn('st.CampusID', $campusIds);
        }
        if ($teacherId) {
            $sessionQuery->where('sc.TeacherID', $teacherId);
        }

        $out = [];
        foreach ($sessionQuery->get() as $row) {
            $subs = $substitutedBy[self::key($row->course_id, $row->d, $row->start_time)] ?? [];
            unset($subs[(int) $row->teacher_id]);
            if ($subs !== [] || SubstituteScheduleService::isSubstitutedAway((int) $row->course_id, $row->d, (string) $row->start_time)) {
                continue;
            }
            self::push($out, $row->teacher_id, $row->d, $row->start_time, $row->campus_id);
        }

        $filter = app(StaleScheduleExceptionFilter::class);
        $byDate = collect($scheduleRows)
            ->filter(fn ($r) => ! $teacherId || (int) $r->teacher_id === $teacherId)
            ->groupBy(fn ($r) => substr((string) $r->schedule_date, 0, 10));
        foreach ($byDate as $date => $rows) {
            foreach ($filter->rejectStale($rows, $date) as $row) {
                self::push($out, $row->teacher_id, $date, $row->start_time, $row->branch_id);
            }
        }

        return $out;
    }

    private static function push(array &$out, $teacherId, $date, $start, $campusId): void
    {
        $hhmm = self::hhmm($start);
        if ($hhmm !== '') {
            $out[(int) $teacherId][substr((string) $date, 0, 10)][] = ['start' => $hhmm, 'campus_id' => (int) $campusId];
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
