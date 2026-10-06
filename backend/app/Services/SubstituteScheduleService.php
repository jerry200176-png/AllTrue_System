<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single-session substitute is stored on `schedules`:
 * status=scheduled, original_schedule_id IS NOT NULL, teacher_id = 代課 User id.
 *
 * TD-076 Track B PR-C: with `schedule-occurrence-v2` on for the course's campus, "who teaches this occurrence"
 * is the one live identity row (teacherForOccurrence); flag off = the legacy lookup below, unchanged.
 */
class SubstituteScheduleService
{
    private const FLAG_KEY = 'FEATURE_SCHEDULE_OCCURRENCE_V2';

    public static function resolveSubstituteUserId(int $studentClassId, $sessionDate, ?string $startTime = null): ?int
    {
        if ($studentClassId <= 0) {
            return null;
        }
        $v2 = self::occurrence($studentClassId, $sessionDate, $startTime);
        if ($v2 !== null) {
            return $v2[0] > 0 && $v2[0] !== $v2[1] ? $v2[0] : null;
        }

        return self::legacySubstituteUserId($studentClassId, $sessionDate, $startTime);
    }

    /**
     * For LearningRecord.TeacherID and similar: prefer substitute when scheduled, else contract teacher.
     */
    public static function effectiveInstructorUserId(int $studentClassId, $sessionDate, int $contractTeacherUserId, ?string $startTime = null): int
    {
        return self::teacherForOccurrence($studentClassId, $sessionDate, $contractTeacherUserId, $startTime);
    }

    /**
     * The one answer to "who teaches this occurrence" (the occurrence is addressed by its current slot).
     * Flag on: the live row's teacher (see occurrence()); flag off: exactly the legacy effective instructor.
     */
    public static function teacherForOccurrence(int $studentClassId, $sessionDate, int $contractTeacherUserId, ?string $startTime = null): int
    {
        $v2 = $studentClassId > 0 ? self::occurrence($studentClassId, $sessionDate, $startTime) : null;
        if ($v2 !== null) {
            return $v2[0] > 0 ? $v2[0] : max($contractTeacherUserId, $v2[1], 0);
        }
        $sub = self::legacySubstituteUserId($studentClassId, $sessionDate, $startTime);
        if ($sub !== null) {
            return $sub;
        }

        return $contractTeacherUserId > 0 ? $contractTeacherUserId : 0;
    }

    /** Flag on for this course's campus and someone other than the contract teacher teaches the occurrence. */
    public static function isSubstitutedAway(int $studentClassId, $sessionDate, ?string $startTime = null): bool
    {
        $v2 = $studentClassId > 0 ? self::occurrence($studentClassId, $sessionDate, $startTime) : null;

        return $v2 !== null && $v2[0] > 0 && $v2[0] !== $v2[1];
    }

    /** False with no queries unless some `schedule-occurrence-v2` value (global or per campus) is on. */
    public static function anyCampusOn(): bool
    {
        foreach ((array) config('feature_flags.values', []) as $key => $value) {
            if ($value && is_string($key) && str_starts_with($key, self::FLAG_KEY)) {
                return true;
            }
        }

        return false;
    }

    /** SQL predicate (ints only) true for rows whose campus column has the flag on; mirrors FeatureFlag::enabled(). */
    public static function campusOnSql(string $campusColumn): string
    {
        $values = (array) config('feature_flags.values', []);
        $on = $off = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/^' . self::FLAG_KEY . '_CAMPUS_(\d+)$/', $key, $m) && $value !== null) {
                if ($value) {
                    $on[] = (int) $m[1];
                } else {
                    $off[] = (int) $m[1];
                }
            }
        }
        if (!empty($values[self::FLAG_KEY])) {
            return $off === [] ? '1=1' : "{$campusColumn} NOT IN (" . implode(',', $off) . ')';
        }

        return $on === [] ? '0=1' : "{$campusColumn} IN (" . implode(',', $on) . ')';
    }

    /**
     * Derived table `sub_sched` (one row per course/date/slot) for list queries; callers join it on
     * course + date + start. No flag on: the legacy "latest substitute row" table. Flag on: per slot the
     * stamped live row wins, then the legacy substitute row, then (flag-on campus) a makeup row.
     */
    public static function substituteScheduleDerivedSql(string $dateBound = '', bool $withStartHm = true): string
    {
        $hm = $withStartHm ? ', SUBSTRING(ss.start_time, 1, 5) AS start_time_hm' : '';
        if (!self::anyCampusOn()) {
            return "(
                SELECT ss.*{$hm}
                FROM `schedules` ss
                INNER JOIN (
                    SELECT sub2.student_course_id,
                           sub2.schedule_date,
                           SUBSTRING(sub2.start_time, 1, 5) AS st_hm,
                           MAX(sub2.id) AS max_id
                    FROM `schedules` sub2
                    INNER JOIN `StudentClass` sc2 ON sc2.ID = sub2.student_course_id
                    WHERE sub2.status = \"scheduled\"
                      AND sub2.original_schedule_id IS NOT NULL
                      AND sub2.teacher_id <> sc2.TeacherID
                      {$dateBound}
                    GROUP BY sub2.student_course_id, sub2.schedule_date, SUBSTRING(sub2.start_time, 1, 5)
                ) sub_latest ON ss.id = sub_latest.max_id
            )";
        }
        $on = self::campusOnSql('s2.CampusID');

        return "(
                SELECT ss.*{$hm}
                FROM `schedules` ss
                INNER JOIN (
                    SELECT sub2.student_course_id,
                           sub2.schedule_date,
                           SUBSTRING(sub2.start_time, 1, 5) AS st_hm,
                           COALESCE(
                               MAX(CASE WHEN {$on} AND sub2.status IN ('scheduled', 'leave') AND COALESCE(sub2.type, '') <> 'extra'
                                         AND sub2.original_schedule_date IS NOT NULL AND sub2.original_start_time IS NOT NULL THEN sub2.id END),
                               MAX(CASE WHEN sub2.status = 'scheduled' AND sub2.original_schedule_id IS NOT NULL
                                         AND sub2.teacher_id <> sc2.TeacherID AND (NOT ({$on}) OR COALESCE(sub2.type, '') <> 'extra') THEN sub2.id END),
                               MAX(CASE WHEN {$on} AND sub2.status = 'scheduled' AND sub2.type = 'extra' THEN sub2.id END)
                           ) AS max_id
                    FROM `schedules` sub2
                    INNER JOIN `StudentClass` sc2 ON sc2.ID = sub2.student_course_id
                    INNER JOIN `Student` s2 ON s2.id = sc2.StudentID
                    WHERE sub2.status IN ('scheduled', 'leave')
                      {$dateBound}
                    GROUP BY sub2.student_course_id, sub2.schedule_date, SUBSTRING(sub2.start_time, 1, 5)
                    HAVING max_id IS NOT NULL
                ) sub_latest ON ss.id = sub_latest.max_id
            )";
    }

    /**
     * SQL for the substitute teacher of a session row over `sub_sched` (+ `lr`, `sc`, `s`): NULL when the contract
     * teacher teaches. Makeup rows (flag-on campus) resolve to the non-voided LearningRecord teacher when it differs.
     */
    public static function substituteTeacherSql(): string
    {
        if (!self::anyCampusOn()) {
            return 'sub_sched.teacher_id';
        }
        $on = self::campusOnSql('s.CampusID');
        $eff = "CASE WHEN {$on} AND sub_sched.type = 'extra' AND lr.TeacherID > 0 AND lr.TeacherID <> sub_sched.teacher_id
                     THEN lr.TeacherID ELSE sub_sched.teacher_id END";

        return "(CASE WHEN ({$eff}) <> sc.TeacherID THEN ({$eff}) END)";
    }

    /** @return array{0: int, 1: int}|null [teacher, contractTeacher] of the occurrence for the occurrence; null = flag off for this course's campus. */
    public static function occurrence(int $courseId, $sessionDate, ?string $startTime): ?array
    {
        if (!self::anyCampusOn()) {
            return null;
        }
        $course = DB::table('StudentClass as sc')->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->where('sc.ID', $courseId)->first(['sc.TeacherID', 's.CampusID']);
        if (!$course || !OccurrenceAssignmentService::enabledFor((int) $course->CampusID)) {
            return null;
        }
        try {
            $d = Carbon::parse((string) $sessionDate)->toDateString();
        } catch (\Throwable) {
            return null;
        }
        $contract = (int) $course->TeacherID;
        $start = self::normalizeTime($startTime);
        $at = function () use ($courseId, $d, $start) {
            $q = DB::table('schedules')->where('student_course_id', $courseId)->whereDate('schedule_date', $d);

            return $start !== null ? $q->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$start]) : $q;
        };
        $notMakeup = fn ($q) => $q->where(fn ($t) => $t->whereNull('type')->orWhere('type', '<>', 'extra'));

        // 1. the single live identity row (stamped); its teacher wins. A null teacher means the contract teacher.
        $live = $notMakeup($at())->whereIn('status', ['scheduled', 'leave'])
            ->whereNotNull('original_schedule_date')->whereNotNull('original_start_time')
            ->orderByDesc('id')->first(['teacher_id']);
        if ($live) {
            return [(int) $live->teacher_id ?: $contract, $contract];
        }
        // 2. not yet stamped: today's slot match.
        $legacy = (int) ($notMakeup($at())->where('status', 'scheduled')->whereNotNull('original_schedule_id')
            ->where('teacher_id', '<>', $contract)->orderByDesc('id')->value('teacher_id') ?? 0);
        if ($legacy > 0) {
            return [$legacy, $contract];
        }
        // 3. makeup (#3590 item 8): the extra row keeps its own teacher; a substitute is recorded on the LearningRecord only.
        $makeup = $at()->where('status', 'scheduled')->where('type', 'extra')->orderByDesc('id')->first(['teacher_id']);
        if ($makeup) {
            $rowTeacher = (int) $makeup->teacher_id ?: $contract;
            $lr = DB::table('LearningRecord as lr')->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
                ->where('cs.StudentClassID', $courseId)->whereDate('cs.SessionDate', $d)->whereNull('lr.VoidedAt')
                ->where('lr.TeacherID', '>', 0);
            if ($start !== null) {
                $lr->whereRaw('SUBSTRING(cs.StartTime, 1, 5) = ?', [$start]);
            }
            $lrTeacher = (int) ($lr->orderByDesc('lr.id')->value('lr.TeacherID') ?? 0);

            return [$lrTeacher > 0 ? $lrTeacher : $rowTeacher, $contract];
        }

        return [$contract, $contract];
    }

    /**
     * #3590 item 9: makeup (extra) occurrences on $ymd that $teacherId teaches only as a substitute (recorded on the
     * non-voided LearningRecord; the makeup row keeps its own teacher), so busy-slot readers can count them.
     * Candidates are narrowed in SQL, then confirmed with teacherForOccurrence (no second rule). Flag off: [] with no query.
     *
     * @param  int[]  $excludeScheduleIds
     * @return list<object>  id, start_time, end_time, branch_id, student_course_id, student_id, class_type
     */
    public static function makeupOccurrencesTaughtBy(int $teacherId, string $ymd, array $excludeScheduleIds = [], ?int $excludeStudentId = null, ?int $branchId = null): array
    {
        if ($teacherId <= 0 || !self::anyCampusOn()) {
            return [];
        }
        $q = DB::table('schedules as m')
            ->join('StudentClass as sc', 'sc.ID', '=', 'm.student_course_id')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->whereDate('m.schedule_date', $ymd)
            ->where('m.status', 'scheduled')->where('m.type', 'extra')
            ->where(fn ($t) => $t->whereNull('m.teacher_id')->orWhere('m.teacher_id', '<>', $teacherId)) // own-teacher rows are already counted
            ->whereRaw(self::campusOnSql('st.CampusID'))
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('LearningRecord as lr')
                ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
                ->whereColumn('cs.StudentClassID', 'm.student_course_id')
                ->whereDate('cs.SessionDate', $ymd)
                ->whereNull('lr.VoidedAt')->where('lr.TeacherID', $teacherId));
        if ($excludeScheduleIds) {
            $q->whereNotIn('m.id', $excludeScheduleIds);
        }
        if ($excludeStudentId) {
            $q->whereRaw('COALESCE(m.student_id, sc.StudentID) <> ?', [$excludeStudentId]);
        }
        if ($branchId) {
            $q->where('m.branch_id', $branchId);
        }
        $rows = $q->get(['m.id', 'm.start_time', 'm.end_time', 'm.branch_id', 'm.student_course_id', 'sc.TeacherID as contract_teacher_id',
            DB::raw('COALESCE(m.student_id, sc.StudentID) as student_id'), DB::raw("COALESCE(sc.ClassType, 'one_on_one') as class_type")]);

        return $rows->filter(fn ($r) => self::teacherForOccurrence((int) $r->student_course_id, $ymd, (int) $r->contract_teacher_id, (string) $r->start_time) === $teacherId)->values()->all();
    }

    private static function legacySubstituteUserId(int $studentClassId, $sessionDate, ?string $startTime): ?int
    {
        if ($studentClassId <= 0) {
            return null;
        }
        try {
            $d = Carbon::parse((string) $sessionDate)->toDateString();
        } catch (\Throwable) {
            return null;
        }
        $query = DB::table('schedules')
            ->where('student_course_id', $studentClassId)
            ->whereDate('schedule_date', $d)
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id');

        $normalizedStart = self::normalizeTime($startTime);
        if ($normalizedStart !== null) {
            $query->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$normalizedStart]);
        }

        $tid = (int) ($query->orderByDesc('id')->value('teacher_id') ?? 0);

        return $tid > 0 ? $tid : null;
    }

    private static function normalizeTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}:\d{2})/', $value, $m)) {
            return $m[1];
        }

        return null;
    }
}
