<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit for ClassSession duplication / projection drift (Phase 0).
 */
class AuditClassSessionDuplicates extends Command
{
    protected $signature = 'classsession:audit-duplicates
                            {--branch_id= : Limit cross-course scan to one campus}
                            {--student_class_id= : Limit to one StudentClass}
                            {--output= : Write JSON report to file (default: stdout)}';

    protected $description = 'Read-only audit: intra-course dupes, cross-course slot overlaps, projection gaps';

    public function handle(): int
    {
        $branchId = $this->option('branch_id') !== null ? (int) $this->option('branch_id') : null;
        $studentClassId = $this->option('student_class_id') !== null ? (int) $this->option('student_class_id') : null;

        $report = [
            'generated_at' => now()->toIso8601String(),
            'filters' => array_filter([
                'branch_id' => $branchId,
                'student_class_id' => $studentClassId,
            ]),
            'summary' => [],
            'intra_course_duplicates' => $this->findIntraCourseDuplicates($studentClassId),
            'cross_course_overlaps' => $this->findCrossCourseOverlaps($branchId, $studentClassId),
            'projection_materialized_gaps' => $this->findProjectionGaps($studentClassId),
        ];

        $report['summary'] = [
            'intra_course_duplicate_groups' => count($report['intra_course_duplicates']),
            'cross_course_overlap_groups' => count($report['cross_course_overlaps']),
            'projection_gap_courses' => count($report['projection_materialized_gaps']),
        ];

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $outputPath = $this->option('output');
        if ($outputPath) {
            file_put_contents($outputPath, $json . PHP_EOL);
            $this->line("Report written to {$outputPath}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findIntraCourseDuplicates(?int $studentClassId): array
    {
        $query = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('cs.Status', '<>', 'cancelled')
            ->selectRaw('cs.StudentClassID as student_class_id')
            ->selectRaw('DATE(cs.SessionDate) as session_date')
            ->selectRaw('SUBSTRING(cs.StartTime, 1, 5) as start_hm')
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('GROUP_CONCAT(cs.id ORDER BY cs.id) as session_ids')
            ->groupBy('cs.StudentClassID', DB::raw('DATE(cs.SessionDate)'), DB::raw('SUBSTRING(cs.StartTime, 1, 5)'))
            ->having('row_count', '>', '1');

        if ($studentClassId) {
            $query->where('cs.StudentClassID', $studentClassId);
        }

        return $query->get()->map(function ($row) {
            return [
                'student_class_id' => (int) $row->student_class_id,
                'session_date' => (string) $row->session_date,
                'start_time' => (string) $row->start_hm,
                'row_count' => (int) $row->row_count,
                'session_ids' => array_map('intval', explode(',', (string) $row->session_ids)),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findCrossCourseOverlaps(?int $branchId, ?int $studentClassId): array
    {
        $query = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('cs.Status', '<>', 'cancelled')
            ->where(function ($q) {
                $q->where('sc.Stop', 0)->orWhereNull('sc.Stop');
            })
            ->selectRaw('st.id as student_id')
            ->selectRaw('st.name as student_name')
            ->selectRaw('st.CampusID as campus_id')
            ->selectRaw('sc.TeacherID as teacher_id')
            ->selectRaw('DATE(cs.SessionDate) as session_date')
            ->selectRaw('SUBSTRING(cs.StartTime, 1, 5) as start_hm')
            ->selectRaw('COUNT(DISTINCT cs.StudentClassID) as course_count')
            ->selectRaw('GROUP_CONCAT(DISTINCT cs.StudentClassID ORDER BY cs.StudentClassID) as student_class_ids')
            ->selectRaw('GROUP_CONCAT(cs.id ORDER BY cs.id) as session_ids')
            ->groupBy(
                'st.id',
                'st.name',
                'st.CampusID',
                'sc.TeacherID',
                DB::raw('DATE(cs.SessionDate)'),
                DB::raw('SUBSTRING(cs.StartTime, 1, 5)')
            )
            ->having('course_count', '>', '1');

        if ($branchId) {
            $query->where('st.CampusID', $branchId);
        }
        if ($studentClassId) {
            $studentId = (int) DB::table('StudentClass')->where('ID', $studentClassId)->value('StudentID');
            if ($studentId > 0) {
                $query->where('st.id', $studentId);
            }
        }

        return $query->get()->map(function ($row) {
            return [
                'student_id' => (int) $row->student_id,
                'student_name' => (string) $row->student_name,
                'campus_id' => (int) $row->campus_id,
                'teacher_id' => (int) $row->teacher_id,
                'session_date' => (string) $row->session_date,
                'start_time' => (string) $row->start_hm,
                'course_count' => (int) $row->course_count,
                'student_class_ids' => array_map('intval', explode(',', (string) $row->student_class_ids)),
                'session_ids' => array_map('intval', explode(',', (string) $row->session_ids)),
            ];
        })->values()->all();
    }

    /**
     * Monthly (ScheduleMode=date) courses: projected weekday slots in contract window
     * without a matching non-cancelled ClassSession row.
     *
     * @return list<array<string, mixed>>
     */
    private function findProjectionGaps(?int $studentClassId): array
    {
        $courses = DB::table('StudentClass')
            ->where('ScheduleMode', 'date')
            ->where(function ($q) {
                $q->where('Stop', 0)->orWhereNull('Stop');
            })
            ->when($studentClassId, fn ($q) => $q->where('ID', $studentClassId))
            ->get(['ID', 'StartDate', 'EndDate', 'week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6', 'time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6']);

        $gaps = [];
        $today = Carbon::today()->toDateString();

        foreach ($courses as $course) {
            $start = $this->normalizeDate($course->StartDate) ?: $today;
            $end = $this->normalizeDate($course->EndDate);
            if (!$end || $end < $start) {
                continue;
            }

            $windowStart = max($start, $today);
            if ($windowStart > $end) {
                continue;
            }

            $slots = $this->resolveWeekdaySlots($course);
            if (empty($slots)) {
                continue;
            }

            $existingKeys = DB::table('ClassSession')
                ->where('StudentClassID', (int) $course->ID)
                ->whereDate('SessionDate', '>=', $windowStart)
                ->whereDate('SessionDate', '<=', $end)
                ->where('Status', '<>', 'cancelled')
                ->get(['SessionDate', 'StartTime'])
                ->mapWithKeys(function ($row) {
                    $d = substr((string) $row->SessionDate, 0, 10);
                    $t = substr((string) $row->StartTime, 0, 5);

                    return ["{$d}|{$t}" => true];
                })
                ->all();

            $missing = [];
            for ($date = Carbon::parse($windowStart); $date->toDateString() <= $end; $date->addDay()) {
                $iso = (int) $date->dayOfWeekIso;
                foreach ($slots as $slot) {
                    if ((int) $slot['weekday'] !== $iso) {
                        continue;
                    }
                    $ymd = $date->toDateString();
                    $hm = substr((string) $slot['start_time'], 0, 5);
                    $key = "{$ymd}|{$hm}";
                    if (!isset($existingKeys[$key])) {
                        $missing[] = ['session_date' => $ymd, 'start_time' => $hm];
                    }
                }
            }

            if (!empty($missing)) {
                $gaps[] = [
                    'student_class_id' => (int) $course->ID,
                    'window_start' => $windowStart,
                    'window_end' => $end,
                    'missing_slots' => array_slice($missing, 0, 50),
                    'missing_count' => count($missing),
                ];
            }
        }

        return $gaps;
    }

    /**
     * @return list<array{weekday: int, start_time: string}>
     */
    private function resolveWeekdaySlots(object $course): array
    {
        $slots = [];
        $pairs = [
            ['week', 'time'],
            ['week1', 'time1'],
            ['week2', 'time2'],
            ['week3', 'time3'],
            ['week4', 'time4'],
            ['week5', 'time5'],
            ['week6', 'time6'],
        ];

        foreach ($pairs as [$weekField, $timeField]) {
            $weekday = (int) ($course->{$weekField} ?? 0);
            $time = trim((string) ($course->{$timeField} ?? ''));
            if ($weekday >= 1 && $weekday <= 7 && $time !== '') {
                $slots[] = [
                    'weekday' => $weekday,
                    'start_time' => substr($time, 0, 5),
                ];
            }
        }

        return $slots;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }
}
