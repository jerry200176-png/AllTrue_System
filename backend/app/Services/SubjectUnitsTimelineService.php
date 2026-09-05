<?php

namespace App\Services;

use App\Support\AttendanceStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read model for the subject-units workspace.
 *
 * The source order is deliberately the same as the payroll subject-unit
 * calculation: approved learning records are authoritative for regular
 * lessons; attendance is the fallback for sessions without a learning record;
 * tutoring/trial is read from attended attendance/session facts. A session is
 * represented at most once in a category, so a record and its attendance row
 * cannot inflate the daily result.
 */
final class SubjectUnitsTimelineService
{
    private const LEAVE_SESSION_STATUSES = [
        'cancelled', 'voided', 'leave', 'leave_adjusted', 'leave_requested', 'excused',
    ];

    /**
     * @return array{entries: list<array<string,mixed>>, days: list<array<string,mixed>>, totals: array<string,mixed>}
     */
    public function build(Carbon $start, Carbon $end, array $campusIds, ?int $teacherId = null): array
    {
        $entries = [];
        $coveredRegularSessions = [];
        $blockedRegularSessions = [];
        $subjectIds = [];

        $learningRecords = DB::table('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->where('lr.Status', 'approved')
            ->whereNull('lr.VoidedAt')
            ->whereBetween('lr.SessionDate', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('sc.ClassType', ['trial', 'tutoring'])
            ->when($campusIds !== [], fn ($q) => $q->whereIn('s.CampusID', $campusIds))
            ->when($teacherId !== null, fn ($q) => $q->where('lr.TeacherID', $teacherId))
            ->select([
                'lr.id as record_id', 'lr.ClassSessionID as class_session_id',
                'lr.TeacherID as teacher_id', 'lr.SessionDate as event_date',
                'lr.StartTime as start_time', 'lr.EndTime as end_time',
                'sc.ClassType as class_type', 'sc.SessionDuration as session_duration',
                'sc.week1', 'sc.week2', 'sc.week3', 'sc.week4', 'sc.week5', 'sc.week6',
                'sc.duration1', 'sc.duration2', 'sc.duration3', 'sc.duration4', 'sc.duration5', 'sc.duration6',
                'sc.SubjectID as course_subject_id', 'cs.SubjectID as session_subject_id',
                's.CampusID as campus_id',
            ]);

        if (Schema::hasColumn('LearningRecord', 'ExcludeFromSubjectCount')) {
            $learningRecords->addSelect('lr.ExcludeFromSubjectCount as excluded_from_subject_count');
        }

        foreach ($learningRecords->get() as $row) {
            $sessionId = (int) ($row->class_session_id ?? 0);
            if ($sessionId > 0) {
                $coveredRegularSessions[$sessionId] = true;
                if ((bool) ($row->excluded_from_subject_count ?? false)) {
                    $blockedRegularSessions[$sessionId] = true;
                    continue;
                }
            } elseif ((bool) ($row->excluded_from_subject_count ?? false)) {
                continue;
            }

            $subjectId = (int) ($row->session_subject_id ?: $row->course_subject_id ?: 0);
            $subjectIds[$subjectId] = true;
            $this->addEntry($entries, $row, 'regular', $this->regularWeight($row->class_type), $subjectId);
        }

        $attendanceBase = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->join('StudentSingIn as si', 'si.ClassSessionID', '=', 'cs.id')
            ->whereBetween('cs.SessionDate', [$start->toDateString(), $end->toDateString()])
            ->whereNull('si.VoidedAt')
            ->whereNotIn('cs.Status', self::LEAVE_SESSION_STATUSES)
            ->when($campusIds !== [], fn ($q) => $q->whereIn('s.CampusID', $campusIds))
            ->select([
                'cs.id as class_session_id', 'cs.SessionDate as event_date',
                'cs.StartTime as start_time', 'cs.EndTime as end_time',
                'sc.TeacherID as teacher_id', 'sc.ClassType as class_type',
                'sc.SessionDuration as session_duration',
                'sc.week1', 'sc.week2', 'sc.week3', 'sc.week4', 'sc.week5', 'sc.week6',
                'sc.duration1', 'sc.duration2', 'sc.duration3', 'sc.duration4', 'sc.duration5', 'sc.duration6',
                'sc.SubjectID as course_subject_id', 'cs.SubjectID as session_subject_id',
                's.CampusID as campus_id', 'si.Status as attendance_status',
            ]);

        $weeklyStatuses = array_values(array_unique(array_merge(
            AttendanceStatus::payableCodes(), ['attended', 'completed', 'trial', 'tutoring']
        )));

        $regularAttendance = (clone $attendanceBase)
            ->whereIn('si.Status', $weeklyStatuses)
            ->whereNotIn('sc.ClassType', ['trial', 'tutoring'])
            ->when($teacherId !== null, fn ($q) => $q->where('sc.TeacherID', $teacherId))
            ->whereNotIn('cs.id', array_keys($coveredRegularSessions + $blockedRegularSessions));

        foreach ($regularAttendance->get()->unique('class_session_id') as $row) {
            $subjectId = (int) ($row->session_subject_id ?: $row->course_subject_id ?: 0);
            $subjectIds[$subjectId] = true;
            $this->addEntry($entries, $row, 'regular', $this->regularWeight($row->class_type), $subjectId);
        }

        $attendedSpecialSessions = [];
        $specialAttendance = (clone $attendanceBase)
            ->whereIn('si.Status', $weeklyStatuses)
            ->whereIn('sc.ClassType', ['trial', 'tutoring'])
            ->when($teacherId !== null, fn ($q) => $q->where('sc.TeacherID', $teacherId));

        foreach ($specialAttendance->get()->unique('class_session_id') as $row) {
            $sessionId = (int) $row->class_session_id;
            $attendedSpecialSessions[$sessionId] = true;
            $subjectId = (int) ($row->session_subject_id ?: $row->course_subject_id ?: 0);
            $subjectIds[$subjectId] = true;
            $this->addEntry($entries, $row, 'tutoring_trial', 0.5, $subjectId);
        }

        // Legacy tutoring/trial sessions may have a completed ClassSession but
        // no StudentSingIn row. Count them once as the same production fact.
        $legacySpecial = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereBetween('cs.SessionDate', [$start->toDateString(), $end->toDateString()])
            ->whereIn('sc.ClassType', ['trial', 'tutoring'])
            ->whereIn('cs.Status', array_values(array_unique(array_merge(
                AttendanceStatus::payableSessionStatuses(), ['trial', 'tutoring_attend']
            ))))
            ->when($campusIds !== [], fn ($q) => $q->whereIn('s.CampusID', $campusIds))
            ->when($teacherId !== null, fn ($q) => $q->where('sc.TeacherID', $teacherId))
            ->whereNotIn('cs.id', array_keys($attendedSpecialSessions))
            ->select([
                'cs.id as class_session_id', 'cs.SessionDate as event_date',
                'cs.StartTime as start_time', 'cs.EndTime as end_time',
                'sc.TeacherID as teacher_id', 'sc.ClassType as class_type',
                'sc.SessionDuration as session_duration',
                'sc.week1', 'sc.week2', 'sc.week3', 'sc.week4', 'sc.week5', 'sc.week6',
                'sc.duration1', 'sc.duration2', 'sc.duration3', 'sc.duration4', 'sc.duration5', 'sc.duration6',
                'sc.SubjectID as course_subject_id', 'cs.SubjectID as session_subject_id',
                's.CampusID as campus_id',
            ])->get()->unique('class_session_id');

        foreach ($legacySpecial as $row) {
            $subjectId = (int) ($row->session_subject_id ?: $row->course_subject_id ?: 0);
            $subjectIds[$subjectId] = true;
            $this->addEntry($entries, $row, 'tutoring_trial', 0.5, $subjectId);
        }

        $teacherNames = DB::table('User')->whereIn('id', array_keys($this->teacherIds($entries)))
            ->pluck('Name', 'id')->all();
        $campusNames = DB::table('Campus')->whereIn('id', array_keys($this->campusIds($entries)))
            ->pluck('name', 'id')->all();
        $subjectNames = DB::table('Subject')->whereIn('id', array_keys($subjectIds))
            ->pluck('Subject_Name', 'id')->all();

        $normalised = array_values(array_map(function (array $entry) use ($teacherNames, $campusNames, $subjectNames) {
            $entry['teacher_name'] = $teacherNames[$entry['teacher_id']] ?? '未知老師';
            $entry['campus_name'] = $campusNames[$entry['campus_id']] ?? ('分校 #' . $entry['campus_id']);
            $entry['subject_name'] = $subjectNames[$entry['subject_id']] ?? '未命名科目';
            $entry['regular_subject_count'] = round($entry['regular_weighted'] / 8, 4);
            $entry['tutoring_trial_subject_count'] = round($entry['tutoring_trial_weighted'] / 8, 4);
            $entry['payroll_subject_count'] = round(($entry['regular_weighted'] + $entry['tutoring_trial_weighted']) / 8, 4);
            $entry['regular_weighted'] = round($entry['regular_weighted'], 4);
            $entry['tutoring_trial_weighted'] = round($entry['tutoring_trial_weighted'], 4);
            $entry['regular_hours'] = round($entry['regular_hours'], 2);
            $entry['tutoring_trial_hours'] = round($entry['tutoring_trial_hours'], 2);
            $entry['total_hours'] = round($entry['regular_hours'] + $entry['tutoring_trial_hours'], 2);
            return $entry;
        }, $entries));

        usort($normalised, fn ($a, $b) => $a['date'] <=> $b['date'] ?: strcmp($a['teacher_name'], $b['teacher_name']) ?: strcmp($a['campus_name'], $b['campus_name']) ?: strcmp($a['subject_name'], $b['subject_name']));

        $days = [];
        foreach ($normalised as $entry) {
            $date = $entry['date'];
            $days[$date] ??= $this->emptyAggregate($date);
            $this->mergeAggregate($days[$date], $entry);
        }

        $totals = $this->emptyAggregate(null);
        foreach ($normalised as $entry) $this->mergeAggregate($totals, $entry);

        return [
            'entries' => $normalised,
            'days' => array_values(array_map(fn (array $day) => $this->publicAggregate($day), $days)),
            'totals' => $this->publicAggregate($totals),
        ];
    }

    private function addEntry(array &$entries, object $row, string $category, float $weight, int $subjectId): void
    {
        $date = substr((string) $row->event_date, 0, 10);
        $key = implode('|', [(int) $row->teacher_id, $date, (int) $row->campus_id, $subjectId]);
        if (!isset($entries[$key])) {
            $entries[$key] = [
                'teacher_id' => (int) $row->teacher_id, 'date' => $date,
                'campus_id' => (int) $row->campus_id, 'subject_id' => $subjectId,
                'regular_hours' => 0.0, 'tutoring_trial_hours' => 0.0,
                'regular_weighted' => 0.0, 'tutoring_trial_weighted' => 0.0,
                'session_count' => 0, 'regular_session_count' => 0, 'tutoring_trial_session_count' => 0,
            ];
        }
        $hours = $this->hours($row);
        $entries[$key][$category . '_hours'] += $hours;
        $entries[$key][$category . '_weighted'] += $hours * $weight;
        $entries[$key]['session_count']++;
        $entries[$key][$category . '_session_count']++;
    }

    private function hours(object $row): float
    {
        try {
            $weekday = Carbon::parse((string) $row->event_date)->isoWeekday();
            $weekField = 'week' . $weekday;
            $durationField = 'duration' . $weekday;
            if ((int) ($row->{$weekField} ?? 0) === $weekday && (int) ($row->{$durationField} ?? 0) >= 30) {
                return (int) $row->{$durationField} / 60;
            }
        } catch (\Throwable) {
            // Fall through to the same duration fallbacks as the existing report.
        }
        if ((int) ($row->session_duration ?? 0) >= 30) return (int) $row->session_duration / 60;
        try {
            $start = Carbon::parse((string) $row->start_time);
            $end = Carbon::parse((string) $row->end_time);
            if ($end->gt($start)) return $start->diffInMinutes($end) / 60;
        } catch (\Throwable) {
            // Keep the established two-hour fallback for legacy rows.
        }
        return 2.0;
    }

    private function regularWeight(?string $classType): float
    {
        return match (strtolower((string) $classType)) {
            'one_on_two' => 0.75,
            'one_on_three' => 0.5,
            default => 1.5,
        };
    }

    private function teacherIds(array $entries): array
    {
        return array_fill_keys(array_map(fn ($row) => (int) $row['teacher_id'], $entries), true);
    }

    private function campusIds(array $entries): array
    {
        return array_fill_keys(array_map(fn ($row) => (int) $row['campus_id'], $entries), true);
    }

    private function emptyAggregate(?string $date): array
    {
        return [
            'date' => $date, 'regular_hours' => 0.0, 'tutoring_trial_hours' => 0.0,
            'regular_weighted' => 0.0, 'tutoring_trial_weighted' => 0.0,
            'regular_subject_count' => 0.0, 'tutoring_trial_subject_count' => 0.0,
            'payroll_subject_count' => 0.0, 'session_count' => 0,
        ];
    }

    private function mergeAggregate(array &$aggregate, array $entry): void
    {
        foreach (['regular_hours', 'tutoring_trial_hours', 'regular_weighted', 'tutoring_trial_weighted'] as $field) {
            $aggregate[$field] += (float) $entry[$field];
        }
        $aggregate['session_count'] += (int) $entry['session_count'];
        $aggregate['regular_subject_count'] = round($aggregate['regular_weighted'] / 8, 4);
        $aggregate['tutoring_trial_subject_count'] = round($aggregate['tutoring_trial_weighted'] / 8, 4);
        $aggregate['payroll_subject_count'] = round(($aggregate['regular_weighted'] + $aggregate['tutoring_trial_weighted']) / 8, 4);
    }

    private function publicAggregate(array $aggregate): array
    {
        foreach (['regular_hours', 'tutoring_trial_hours', 'regular_weighted', 'tutoring_trial_weighted'] as $field) {
            $aggregate[$field] = round($aggregate[$field], 2);
        }
        return $aggregate;
    }
}
