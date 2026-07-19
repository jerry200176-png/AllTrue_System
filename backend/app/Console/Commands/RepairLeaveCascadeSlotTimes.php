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

        $plan = $this->buildPlan();
        $this->line($dryRun ? '=== DRY RUN repair:leave-cascade-slot-times ===' : '=== EXECUTE repair:leave-cascade-slot-times ===');
        $this->line('candidates=' . count($plan));

        foreach ($plan as $row) {
            $this->line(sprintf(
                'sc=%d cs=%d date=%s %s-%s -> %s-%s (iso=%d)',
                $row['student_class_id'],
                $row['class_session_id'],
                $row['session_date'],
                $row['old_start'],
                $row['old_end'],
                $row['new_start'],
                $row['new_end'],
                $row['iso_dow']
            ));
        }

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
     * @return list<array<string, mixed>>
     */
    private function buildPlan(): array
    {
        $limit = max(1, (int) $this->option('limit'));
        $courseId = (int) ($this->option('course-id') ?? 0);

        $coursesQuery = StudentClass::query()
            ->where('Stop', 0)
            ->where(function ($q) {
                // At least two weekday fields populated → multi-weekday candidate
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
        $hasException = Schema::hasColumn('ClassSession', 'IsContractException');

        foreach ($coursesQuery->orderBy('ID')->cursor() as $course) {
            $slotByDow = $this->contractSlotsByDow($course);
            if (count($slotByDow) < 2) {
                continue;
            }
            // Distinct clocks across weekdays required (same time every day → no drift symptom)
            $starts = array_unique(array_map(fn ($s) => $s['start'], $slotByDow));
            if (count($starts) < 2) {
                continue;
            }

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

                // Only remediates the leave-cascade signature: current clock equals
                // some *other* weekday's contract slot (row carried its old weekday times).
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
                if (count($plan) >= $limit) {
                    return $plan;
                }
            }
        }

        return $plan;
    }

    /**
     * @return array<int, array{start:string,end:string}>
     */
    private function contractSlotsByDow(StudentClass $course): array
    {
        $out = [];
        foreach (CourseLeaveCascadeService::resolveCourseWeekdays($course, 1) as $dow) {
            $times = CourseLeaveCascadeService::resolveContractSlotTimes($course, $this->sampleDateForIsoDow((int) $dow));
            if (($times['start'] ?? '') === '' || ($times['end'] ?? '') === '') {
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
