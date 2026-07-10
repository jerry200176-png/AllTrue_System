<?php

namespace App\Console\Commands;

use App\Services\ForwardSessionGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * #1062 Track A: forward-generate sessions for ACTIVE stranded prepaid courses.
 *
 * Default is DRY-RUN (read-only plan). Production writes require BOTH --execute and
 * --force AND ALLOW_PROD_REPAIR=1 — the write is revenue-affecting and gated behind a
 * PCR GO (docs/runbooks/1062-track-a-pcr.md). Dormant courses are excluded by design
 * (director decision, #1152); only courses with a confirmed recent weekly cadence are
 * planned.
 */
class GenerateForwardSessions extends Command
{
    protected $signature = 'sessions:generate-forward
                            {--branch_id= : Limit to one campus (Student.CampusID)}
                            {--horizon-weeks=4 : Max sessions to generate per course}
                            {--execute : Apply (default is dry-run)}
                            {--force : Required with --execute on production}
                            {--limit=500 : Max courses to process}';

    protected $description = '#1062 Track A: plan/generate forward sessions for active stranded prepaid courses (dry-run by default).';

    public function handle(ForwardSessionGenerator $generator): int
    {
        $execute = (bool) $this->option('execute');
        $horizon = max(1, (int) $this->option('horizon-weeks'));
        $branchId = $this->option('branch_id') !== null ? (int) $this->option('branch_id') : 0;

        if ($execute && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }

        $courseIds = $this->strandedCourseIds($branchId, (int) $this->option('limit'));
        $this->line(($execute ? '=== EXECUTE' : '=== DRY RUN') . " sessions:generate-forward (horizon={$horizon}w, courses=" . count($courseIds) . ') ===');

        $today = Carbon::today();
        $planned = 0; $skipped = 0; $createdTotal = 0; $slotsTotal = 0;
        $skipReasons = [];

        foreach ($courseIds as $scId) {
            $plan = $generator->planCourse($scId, $horizon, $today);
            if ($plan['status'] !== 'plan') {
                $skipped++;
                $r = $plan['reason'] ?? 'skip';
                $skipReasons[$r] = ($skipReasons[$r] ?? 0) + 1;
                continue;
            }
            $planned++; $slotsTotal += count($plan['slots']);
            $c = $plan['cadence'];
            $this->line(sprintf(
                '%s SC%d — %d slot(s) [%s %s-%s, conf %s]: %s',
                $execute ? 'DID' : 'WOULD',
                $scId, count($plan['slots']),
                $this->dowName($c['dow']), $c['start'], $c['end'], $c['confidence'],
                implode(', ', array_map(fn ($s) => substr($s['SessionDate'], 5), $plan['slots']))
            ));
            if ($execute) {
                $createdTotal += count($generator->executePlan($plan));
            }
        }

        $this->line('---');
        $this->line("courses_planned={$planned} courses_skipped={$skipped} slots_planned={$slotsTotal}" . ($execute ? " sessions_created={$createdTotal}" : ''));
        foreach ($skipReasons as $r => $n) {
            $this->line("  skip[{$n}]: {$r}");
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function strandedCourseIds(int $branchId, int $limit): array
    {
        $q = DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->where(fn ($w) => $w->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
            ->where('sc.ScheduleMode', 'count')
            ->where('sc.RemainingSessions', '>', 0)
            ->whereNotExists(function ($e) {
                $e->select(DB::raw(1))->from('ClassSession as cs')
                    ->whereColumn('cs.StudentClassID', 'sc.ID')
                    ->whereRaw('cs.SessionDate >= CURDATE()')
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            });
        if ($branchId > 0) {
            $q->where('s.CampusID', $branchId);
        }

        return $q->orderBy('sc.ID')->limit(max(1, $limit))->pluck('sc.ID')->map(fn ($v) => (int) $v)->all();
    }

    private function dowName(int $dow): string
    {
        return ['週日', '週一', '週二', '週三', '週四', '週五', '週六'][$dow] ?? "dow{$dow}";
    }

    private function assertProductionAllowed(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force')) {
            $this->error('Production requires --force with --execute');
            return false;
        }
        if (env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires ALLOW_PROD_REPAIR=1 (PCR GO gate)');
            return false;
        }

        return true;
    }
}
