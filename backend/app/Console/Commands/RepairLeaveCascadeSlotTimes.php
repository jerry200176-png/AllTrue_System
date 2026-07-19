<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Services\CourseLeaveCascadeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Realign ClassSession StartTime/EndTime that drifted across weekdays after
 * leave-cascade date shifts (Wed times landing on Sat slots).
 *
 * Default: dry-run. Production writes require --execute --force and ALLOW_PROD_REPAIR=1.
 * Idempotent: only rows whose clock matches another weekday's contract slot
 * (and not the target weekday) are candidates.
 */
class RepairLeaveCascadeSlotTimes extends Command
{
    protected $signature = 'repair:leave-cascade-slot-times
                            {--course-id= : Limit to one StudentClass ID}
                            {--limit=500 : Max candidate rows to list/apply}
                            {--dry-run : Preview only (default)}
                            {--execute : Apply remaps for --session-ids only}
                            {--session-ids= : Comma-separated ClassSession IDs (required with --execute)}
                            {--export-csv= : Write director review CSV (selected=0)}
                            {--force : Required with --execute on production}
                            {--snapshot= : JSON snapshot path before writes}';

    protected $description = 'Realign multi-weekday ClassSession times after leave-cascade weekday drift';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = !$execute;
        if ($execute && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }

        $started = microtime(true);
        $stats = $this->collectDryRunStats(max(1, (int) $this->option('limit')), (int) ($this->option('course-id') ?? 0));
        $plan = $this->classifyPlan($stats['rows']);
        $classify = $this->classifyCounts($plan);

        $this->line($dryRun ? '=== DRY RUN repair:leave-cascade-slot-times ===' : '=== EXECUTE repair:leave-cascade-slot-times ===');
        $this->line('courses_scanned=' . $stats['courses_scanned']);
        $this->line('multi_weekday_distinct_clock_courses=' . $stats['eligible_courses']);
        $this->line('candidates=' . count($plan));
        $this->line('distinct_courses=' . $stats['distinct_courses']);
        $this->line('distinct_students=' . $stats['distinct_students']);
        $this->line('includes_wed17_sat10=' . ($stats['includes_wed17_sat10'] ? '1' : '0'));
        $this->line('high_confidence=' . $classify['high_confidence']);
        $this->line('medium_pattern=' . $classify['medium_pattern']);
        $this->line('needs_review=' . $classify['needs_review']);
        $this->line('reason=foreign_weekday_clock_on_target_date');
        $this->line('false_positive_safeguard=requires_current_clock_equals_other_weekday_contract_and_not_IsContractException');

        foreach ($plan as $row) {
            $this->line(sprintf(
                'sc=%d cs=%d date=%s %s-%s -> %s-%s (iso=%d status=%s confidence=%s)',
                $row['student_class_id'],
                $row['class_session_id'],
                $row['session_date'],
                substr((string) $row['old_start'], 0, 5),
                substr((string) $row['old_end'], 0, 5),
                substr((string) $row['new_start'], 0, 5),
                substr((string) $row['new_end'], 0, 5),
                $row['iso_dow'],
                $row['status'],
                $row['confidence']
            ));
        }
        $this->line('elapsed_ms=' . (int) round((microtime(true) - $started) * 1000));
        $this->line('DRYRUN_STATS_JSON ' . json_encode([
            'courses_scanned' => $stats['courses_scanned'],
            'eligible_courses' => $stats['eligible_courses'],
            'candidates' => count($plan),
            'distinct_courses' => $stats['distinct_courses'],
            'distinct_students' => $stats['distinct_students'],
            'includes_wed17_sat10' => $stats['includes_wed17_sat10'],
            'classify' => $classify,
        ], JSON_UNESCAPED_UNICODE));

        $csvPath = (string) ($this->option('export-csv') ?? '');
        if ($csvPath !== '') {
            $this->exportDirectorCsv($csvPath, $plan);
            $this->info("Director review CSV: {$csvPath}");
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        // Founder gate: never re-scan-then-batch-write; only explicit approved IDs.
        $allowedIds = $this->parseSessionIds((string) ($this->option('session-ids') ?? ''));
        if ($allowedIds === []) {
            $this->error('--execute requires --session-ids=<approved ClassSession IDs> (no re-scan batch write)');

            return self::FAILURE;
        }
        if ($plan === []) {
            $this->warn('No candidates; idempotent noop.');

            return self::SUCCESS;
        }
        $apply = array_values(array_filter(
            $plan,
            fn ($row) => in_array((int) $row['class_session_id'], $allowedIds, true)
        ));
        if ($apply === []) {
            $this->warn('No approved session IDs matched current candidates (idempotent noop).');

            return self::SUCCESS;
        }

        $snapshotPath = $this->option('snapshot')
            ?: storage_path('app/repair-snapshots/leave-cascade-slot-times-' . now()->format('YmdHis') . '.json');
        $this->writeSnapshot($snapshotPath, $apply);

        DB::transaction(function () use ($apply): void {
            foreach ($apply as $row) {
                $session = ClassSession::query()->lockForUpdate()->find($row['class_session_id']);
                if (!$session) {
                    continue;
                }
                // Idempotent: skip if already aligned.
                if (
                    substr((string) $session->StartTime, 0, 5) === substr((string) $row['new_start'], 0, 5)
                    && substr((string) $session->EndTime, 0, 5) === substr((string) $row['new_end'], 0, 5)
                ) {
                    continue;
                }
                $session->StartTime = $row['new_start'];
                $session->EndTime = $row['new_end'];
                $session->save();
                CourseLeaveCascadeService::syncLearningRecordSessionDate($session);
            }
        });

        $this->info("Snapshot: {$snapshotPath}");
        $this->info('Applied ' . count($apply) . ' remaps (approved session IDs only).');
        $this->line('Rollback: restore StartTime/EndTime from snapshot JSON rows (old_start/old_end).');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string,mixed>>  $plan
     * @return list<array<string,mixed>>
     */
    public function classifyPlan(array $plan): array
    {
        $byCourse = [];
        foreach ($plan as $row) {
            $byCourse[(int) $row['student_class_id']][] = $row;
        }

        $out = [];
        foreach ($byCourse as $scId => $items) {
            $hasLeave = false;
            foreach ($items as $item) {
                if (str_contains(strtolower((string) $item['status']), 'leave')) {
                    $hasLeave = true;
                    break;
                }
            }
            $olds = array_unique(array_map(fn ($r) => substr((string) $r['old_start'], 0, 5) . '-' . substr((string) $r['old_end'], 0, 5), $items));
            $news = array_unique(array_map(fn ($r) => substr((string) $r['new_start'], 0, 5) . '-' . substr((string) $r['new_end'], 0, 5), $items));
            $reciprocal = count(array_intersect($olds, $news)) >= 1 && count($items) >= 2;

            foreach ($items as $row) {
                $reasons = [];
                $isLeave = str_contains(strtolower((string) $row['status']), 'leave');
                if ($isLeave) {
                    $reasons[] = 'leave_row_foreign_clock';
                }
                if ($hasLeave && !$isLeave) {
                    $reasons[] = 'scheduled_sibling_of_leave_on_same_course';
                }
                if ($reciprocal) {
                    $reasons[] = 'reciprocal_weekday_clock_swap_pattern';
                }
                if ($isLeave || $hasLeave) {
                    $confidence = 'high_confidence';
                } elseif ($reciprocal && count($items) >= 3) {
                    $confidence = 'medium_pattern';
                } else {
                    $confidence = 'needs_review';
                }
                $row['confidence'] = $confidence;
                $row['classify_reason'] = $reasons === [] ? 'foreign_clock_only' : implode(',', $reasons);
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $plan
     * @return array{high_confidence:int,medium_pattern:int,needs_review:int}
     */
    private function classifyCounts(array $plan): array
    {
        $c = ['high_confidence' => 0, 'medium_pattern' => 0, 'needs_review' => 0];
        foreach ($plan as $row) {
            $key = (string) ($row['confidence'] ?? 'needs_review');
            if (!isset($c[$key])) {
                $key = 'needs_review';
            }
            $c[$key]++;
        }

        return $c;
    }

    /** @return list<int> */
    private function parseSessionIds(string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Director review CSV — selected defaults to 0; includes student name for campus staff.
     *
     * @param  list<array<string,mixed>>  $plan
     */
    private function exportDirectorCsv(string $path, array $plan): void
    {
        $scIds = array_values(array_unique(array_map(fn ($r) => (int) $r['student_class_id'], $plan)));
        $meta = [];
        if ($scIds !== []) {
            $meta = DB::table('StudentClass as sc')
                ->join('Student as s', 's.id', '=', 'sc.StudentID')
                ->whereIn('sc.ID', $scIds)
                ->get(['sc.ID as sc_id', 's.name as student_name', 's.CampusID as campus_id'])
                ->keyBy('sc_id');
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen($path, 'w');
        fputcsv($fh, [
            'selected', 'confidence', 'class_session_id', 'student_class_id', 'campus_id', 'student_name',
            'session_date', 'iso_dow', 'status', 'current_start', 'current_end', 'contract_start', 'contract_end',
            'classify_reason', 'detection_reason',
        ]);
        foreach ($plan as $row) {
            $m = $meta[(int) $row['student_class_id']] ?? null;
            fputcsv($fh, [
                0,
                $row['confidence'] ?? 'needs_review',
                $row['class_session_id'],
                $row['student_class_id'],
                $m->campus_id ?? '',
                $m->student_name ?? '',
                $row['session_date'],
                $row['iso_dow'],
                $row['status'],
                substr((string) $row['old_start'], 0, 5),
                substr((string) $row['old_end'], 0, 5),
                substr((string) $row['new_start'], 0, 5),
                substr((string) $row['new_end'], 0, 5),
                $row['classify_reason'] ?? '',
                'foreign_weekday_clock_on_target_date',
            ]);
        }
        fclose($fh);
    }

    /**
     * @return array{
     *   courses_scanned:int,
     *   eligible_courses:int,
     *   candidates:int,
     *   distinct_courses:int,
     *   distinct_students:int,
     *   includes_wed17_sat10:bool,
     *   rows:list<array<string,mixed>>
     * }
     */
    public function collectDryRunStats(int $limit = 500, int $courseId = 0): array
    {
        $limit = max(1, $limit);
        $coursesQuery = StudentClass::query()
            ->where('Stop', 0)
            ->where(function ($q) {
                $q->whereNotNull('week')->where('week', '>', 0)
                    ->where(function ($inner) {
                        foreach (['week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $field) {
                            $inner->orWhere(function ($w) use ($field) {
                                $w->whereNotNull($field)->where($field, '>', 0);
                            });
                        }
                    });
            });
        if ($courseId > 0) {
            $coursesQuery->where('ID', $courseId);
        }

        $plan = [];
        $coursesScanned = 0;
        $eligibleCourses = 0;
        $courseIds = [];
        $includesWedSat = false;
        $hasException = Schema::hasColumn('ClassSession', 'IsContractException');

        foreach ($coursesQuery->orderBy('ID')->cursor() as $course) {
            $coursesScanned++;
            $slotByDow = $this->contractSlotsByDow($course);
            if (count($slotByDow) < 2) {
                continue;
            }
            $starts = array_unique(array_map(fn ($s) => substr($s['start'], 0, 5), $slotByDow));
            if (count($starts) < 2) {
                continue;
            }
            $eligibleCourses++;

            $wed = $slotByDow[3] ?? null;
            $sat = $slotByDow[6] ?? null;

            $sessions = ClassSession::query()
                ->where('StudentClassID', (int) $course->ID)
                ->whereRaw("LOWER(Status) IN ('scheduled','leave','leave_requested','leave_adjusted')")
                ->orderBy('SessionDate')
                ->orderBy('id')
                ->get();

            foreach ($sessions as $session) {
                if ($hasException && !empty($session->IsContractException)) {
                    continue;
                }
                $date = substr((string) $session->SessionDate, 0, 10);
                if ($date === '') {
                    continue;
                }
                $iso = (int) Carbon::parse($date)->dayOfWeekIso;
                $expected = $slotByDow[$iso] ?? null;
                if (!$expected) {
                    continue;
                }

                $oldStart = substr((string) $session->StartTime, 0, 5);
                $oldEnd = substr((string) $session->EndTime, 0, 5);
                $newStart = substr($expected['start'], 0, 5);
                $newEnd = substr($expected['end'], 0, 5);
                if ($oldStart === $newStart && $oldEnd === $newEnd) {
                    continue;
                }

                $matchesForeign = false;
                foreach ($slotByDow as $dow => $slot) {
                    if ((int) $dow === $iso) {
                        continue;
                    }
                    if (
                        substr($slot['start'], 0, 5) === $oldStart
                        && substr($slot['end'], 0, 5) === $oldEnd
                    ) {
                        $matchesForeign = true;
                        break;
                    }
                }
                if (!$matchesForeign) {
                    continue;
                }

                if (
                    $wed && $sat
                    && substr($wed['start'], 0, 5) === '17:00'
                    && substr($sat['start'], 0, 5) === '10:00'
                ) {
                    $includesWedSat = true;
                }

                $plan[] = [
                    'student_class_id' => (int) $course->ID,
                    'class_session_id' => (int) $session->id,
                    'session_date' => $date,
                    'iso_dow' => $iso,
                    'old_start' => $oldStart,
                    'old_end' => $oldEnd,
                    'new_start' => strlen($newStart) === 5 ? $newStart . ':00' : $newStart,
                    'new_end' => strlen($newEnd) === 5 ? $newEnd . ':00' : $newEnd,
                    'status' => (string) ($session->Status ?? ''),
                ];
                $courseIds[(int) $course->ID] = true;
                if (count($plan) >= $limit) {
                    break 2;
                }
            }
        }

        $studentCount = 0;
        if ($courseIds !== []) {
            $studentCount = (int) StudentClass::query()
                ->whereIn('ID', array_keys($courseIds))
                ->distinct('StudentID')
                ->count('StudentID');
        }

        return [
            'courses_scanned' => $coursesScanned,
            'eligible_courses' => $eligibleCourses,
            'candidates' => count($plan),
            'distinct_courses' => count($courseIds),
            'distinct_students' => $studentCount,
            'includes_wed17_sat10' => $includesWedSat,
            'rows' => $plan,
        ];
    }

    /**
     * @return array<int, array{start:string,end:string}>
     */
    private function contractSlotsByDow(StudentClass $course): array
    {
        $out = [];
        foreach (CourseLeaveCascadeService::resolveCourseWeekdays($course, 1) as $dow) {
            $times = CourseLeaveCascadeService::resolveContractSlotTimes($course, $this->sampleDateForIsoDow((int) $dow));
            if ($times['start'] === '' || $times['end'] === '') {
                continue;
            }
            $out[(int) $dow] = $times;
        }

        return $out;
    }

    private function sampleDateForIsoDow(int $isoDow): string
    {
        // 2026-07-06 is Monday; add (isoDow-1) days.
        return Carbon::parse('2026-07-06')->addDays($isoDow - 1)->toDateString();
    }

    private function assertProductionAllowed(): bool
    {
        if (!(bool) $this->option('force')) {
            $this->error('--execute requires --force');

            return false;
        }
        if ((string) env('ALLOW_PROD_REPAIR', '') !== '1') {
            $this->error('Set ALLOW_PROD_REPAIR=1 for production writes');

            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    private function writeSnapshot(string $path, array $plan): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'command' => 'repair:leave-cascade-slot-times',
            'count' => count($plan),
            'rows' => $plan,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
