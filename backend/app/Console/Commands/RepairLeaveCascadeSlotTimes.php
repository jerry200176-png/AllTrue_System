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
                            {--execute : Apply remaps}
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
        $plan = $stats['rows'];
        $this->line($dryRun ? '=== DRY RUN repair:leave-cascade-slot-times ===' : '=== EXECUTE repair:leave-cascade-slot-times ===');
        $this->line('courses_scanned=' . $stats['courses_scanned']);
        $this->line('multi_weekday_distinct_clock_courses=' . $stats['eligible_courses']);
        $this->line('candidates=' . count($plan));
        $this->line('distinct_courses=' . $stats['distinct_courses']);
        $this->line('distinct_students=' . $stats['distinct_students']);
        $this->line('includes_wed17_sat10=' . ($stats['includes_wed17_sat10'] ? '1' : '0'));
        $this->line('reason=foreign_weekday_clock_on_target_date');
        $this->line('false_positive_safeguard=requires_current_clock_equals_other_weekday_contract_and_not_IsContractException');

        foreach ($plan as $row) {
            $this->line(sprintf(
                'sc=%d cs=%d date=%s %s-%s -> %s-%s (iso=%d status=%s)',
                $row['student_class_id'],
                $row['class_session_id'],
                $row['session_date'],
                substr((string) $row['old_start'], 0, 5),
                substr((string) $row['old_end'], 0, 5),
                substr((string) $row['new_start'], 0, 5),
                substr((string) $row['new_end'], 0, 5),
                $row['iso_dow'],
                $row['status']
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
        ], JSON_UNESCAPED_UNICODE));

        if ($dryRun || $plan === []) {
            return self::SUCCESS;
        }

        $snapshotPath = $this->option('snapshot')
            ?: storage_path('app/repair-snapshots/leave-cascade-slot-times-' . now()->format('YmdHis') . '.json');
        $this->writeSnapshot($snapshotPath, $plan);

        DB::transaction(function () use ($plan): void {
            foreach ($plan as $row) {
                $session = ClassSession::query()->lockForUpdate()->find($row['class_session_id']);
                if (!$session) {
                    continue;
                }
                $session->StartTime = $row['new_start'];
                $session->EndTime = $row['new_end'];
                $session->save();
                CourseLeaveCascadeService::syncLearningRecordSessionDate($session);
            }
        });

        $this->info("Snapshot: {$snapshotPath}");
        $this->info('Applied ' . count($plan) . ' remaps.');

        return self::SUCCESS;
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
