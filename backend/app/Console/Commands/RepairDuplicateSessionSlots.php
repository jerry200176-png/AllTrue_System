<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P0 data repair for in-app #189 / #191 (batch 0 only).
 * Default: dry-run. Production writes require --force and ALLOW_PROD_REPAIR=1.
 */
class RepairDuplicateSessionSlots extends Command
{
    protected $signature = 'repair:duplicate-sessions
                            {--case= : 189, 191, batch0 (189+191), p1-ghost, or p2-list}
                            {--dry-run : Preview only (default)}
                            {--execute : Apply changes}
                            {--force : Required with --execute on production}
                            {--snapshot= : JSON snapshot path before writes}';

    protected $description = 'Repair P0 duplicate ClassSession rows for #189 / #191 / cross-SC ghosts (#1130)';

    private const NOTE = '資料修復 #189-191 — 跨約重複';

    public function handle(): int
    {
        $case = strtolower((string) $this->option('case'));
        if (!in_array($case, ['189', '191', 'batch0', 'p1-ghost', 'p2-list'], true)) {
            $this->error('--case is required: 189, 191, batch0, p1-ghost, or p2-list');

            return self::FAILURE;
        }

        // p2-list is a director review sheet — read-only by contract, even with --execute.
        if ($case === 'p2-list') {
            return $this->renderP2ReviewList();
        }

        $execute = (bool) $this->option('execute');
        $dryRun = !$execute;

        if ($execute && !$dryRun && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }

        $plan = $this->buildPlan($case);
        $this->line($dryRun ? '=== DRY RUN repair:duplicate-sessions ===' : '=== EXECUTE repair:duplicate-sessions ===');
        $this->line('case=' . $case . ' actions=' . count($plan));

        foreach ($plan as $action) {
            $this->line($this->formatActionLine($action, $dryRun));
        }

        if ($dryRun || empty($plan)) {
            return self::SUCCESS;
        }

        $snapshotPath = $this->option('snapshot') ?: storage_path('app/repair-snapshots/189-191-' . $case . '-' . now()->format('YmdHis') . '.json');
        $this->writeSnapshot($snapshotPath, $case, $plan);

        DB::transaction(function () use ($plan): void {
            foreach ($plan as $action) {
                $this->applyAction($action);
            }
        });

        $this->info("Snapshot: {$snapshotPath}");

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPlan(string $case): array
    {
        $plan = [];
        if ($case === '189' || $case === 'batch0') {
            $plan = array_merge($plan, $this->plan189());
        }
        if ($case === '191' || $case === 'batch0') {
            $plan = array_merge($plan, $this->plan191());
        }
        if ($case === 'p1-ghost') {
            $plan = array_merge($plan, $this->planP1Ghost());
        }

        return $plan;
    }

    /**
     * P1-ghost (#1130): cross-SC duplicate groups where exactly one side is a
     * ghost shell (SessionCount=0). Deterministic, same semantics as the approved
     * #189 repair: cancel the ghost-side session(s), Stop=1 the shell.
     *
     * Guards (group is skipped and flagged instead of half-repaired):
     *   - group must have exactly two StudentClass sides, one ghost / one real;
     *   - a live LearningRecord on the ghost side without one on the keeper side
     *     means the 評量 is attached to the row we would cancel → manual review;
     *   - Stop=1 only when the plan cancels ALL of the shell's non-cancelled
     *     sessions (a shell with other live sessions must not be hidden).
     *
     * @return list<array<string, mixed>>
     */
    private function planP1Ghost(): array
    {
        $plan = [];
        $ghostCancels = [];   // ghost SC id => [session ids being cancelled]

        foreach ($this->crossScDuplicateGroups() as $g) {
            $bySc = [];
            foreach ($g['rows'] as $row) {
                $bySc[(int) $row->StudentClassID][] = $row;
            }
            if (count($bySc) !== 2) {
                $this->warn(sprintf('SKIP %s %s %s — %d SCs involved (>2), manual review', $g['student_id'], $g['date'], $g['hm'], count($bySc)));
                continue;
            }

            $sides = [];
            foreach ($bySc as $scId => $rows) {
                $sides[] = ['sc_id' => $scId, 'session_count' => (int) $rows[0]->SessionCount, 'rows' => $rows];
            }
            [$a, $b] = $sides;
            if ($a['session_count'] === 0 && $b['session_count'] > 0) {
                [$ghost, $keep] = [$a, $b];
            } elseif ($b['session_count'] === 0 && $a['session_count'] > 0) {
                [$ghost, $keep] = [$b, $a];
            } else {
                continue; // not a ghost pair → P2 review scope
            }

            $ghostIds = array_map(fn ($r) => (int) $r->id, $ghost['rows']);
            $keepIds = array_map(fn ($r) => (int) $r->id, $keep['rows']);
            $liveLr = $this->liveLearningRecordSessionIds(array_merge($ghostIds, $keepIds));

            $ghostHasLr = count(array_intersect($ghostIds, $liveLr)) > 0;
            $keepHasLr = count(array_intersect($keepIds, $liveLr)) > 0;
            if ($ghostHasLr && !$keepHasLr) {
                $this->warn(sprintf('SKIP %s %s %s — 評量掛在幽靈側 SC%d（session %s），需人工移轉', $g['student_id'], $g['date'], $g['hm'], $ghost['sc_id'], implode(',', array_intersect($ghostIds, $liveLr))));
                continue;
            }

            foreach ($ghost['rows'] as $row) {
                $plan[] = [
                    'type' => 'cancel_session',
                    'bug' => '1130-p1',
                    'session_id' => (int) $row->id,
                    'student_class_id' => $ghost['sc_id'],
                    'reason' => sprintf('cross-SC ghost dup %s %s — keep SC%d session %s', $g['date'], $g['hm'], $keep['sc_id'], implode(',', $keepIds)),
                ];
                $ghostCancels[$ghost['sc_id']][] = (int) $row->id;
            }
        }

        foreach ($ghostCancels as $scId => $cancelledIds) {
            $remaining = DB::table('ClassSession')
                ->where('StudentClassID', $scId)
                ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
                ->whereNotIn('id', $cancelledIds)
                ->count();
            if ($remaining > 0) {
                $this->warn(sprintf('SKIP Stop=1 for ghost SC%d — %d other non-cancelled session(s) remain', $scId, $remaining));
                continue;
            }
            $plan[] = [
                'type' => 'stop_student_class',
                'bug' => '1130-p1',
                'student_class_id' => $scId,
                'reason' => 'ghost shell SessionCount=0 (all sessions cancelled by this plan)',
            ];
        }

        return $plan;
    }

    /**
     * P2 review sheet (#1130): the remaining cross-SC duplicate groups that are
     * NOT deterministic ghost pairs. Directors decide which contract keeps the
     * session; this output is read-only by contract.
     */
    private function renderP2ReviewList(): int
    {
        $this->line('=== P2 REVIEW LIST (read-only) — cross-SC duplicate attended/completed slots ===');
        $count = 0;

        foreach ($this->crossScDuplicateGroups() as $g) {
            $bySc = [];
            foreach ($g['rows'] as $row) {
                $bySc[(int) $row->StudentClassID][] = $row;
            }
            if (count($bySc) === 2) {
                $counts = array_map(fn ($rows) => (int) $rows[0]->SessionCount, array_values($bySc));
                sort($counts);
                if ($counts[0] === 0 && $counts[1] > 0) {
                    continue; // ghost pair → handled by p1-ghost
                }
            }

            $count++;
            $liveLr = $this->liveLearningRecordSessionIds(array_map(fn ($r) => (int) $r->id, $g['rows']));
            $sides = [];
            foreach ($bySc as $scId => $rows) {
                $ids = array_map(fn ($r) => (int) $r->id, $rows);
                $sides[] = sprintf(
                    'SC%d(count=%s stop=%s cs=%s lr=%s)',
                    $scId,
                    $rows[0]->SessionCount,
                    $rows[0]->Stop,
                    implode('/', $ids),
                    count(array_intersect($ids, $liveLr)) > 0 ? 'Y' : 'N'
                );
            }
            $this->line(sprintf('REVIEW student=%d %s %s  %s', $g['student_id'], $g['date'], $g['hm'], implode('  vs  ', $sides)));
        }

        $this->line("p2_review_groups={$count}");

        return self::SUCCESS;
    }

    /**
     * Cross-SC duplicate groups: same student, same date, same HH:MM start,
     * 2+ attended/completed sessions across 2+ different StudentClass rows.
     *
     * @return list<array{student_id:int,date:string,hm:string,rows:list<object>}>
     */
    private function crossScDuplicateGroups(): array
    {
        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereRaw("LOWER(cs.Status) IN ('attended','completed')")
            ->selectRaw('cs.id, cs.StudentClassID, cs.SessionDate, SUBSTRING(cs.StartTime,1,5) as hm, sc.StudentID, sc.SessionCount, sc.Stop')
            ->orderBy('sc.StudentID')->orderBy('cs.SessionDate')->orderBy('cs.id')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = $row->StudentID . '|' . $row->SessionDate . '|' . $row->hm;
            $groups[$key]['student_id'] = (int) $row->StudentID;
            $groups[$key]['date'] = (string) $row->SessionDate;
            $groups[$key]['hm'] = (string) $row->hm;
            $groups[$key]['rows'][] = $row;
        }

        return array_values(array_filter($groups, function ($g) {
            if (count($g['rows']) < 2) {
                return false;
            }
            $scIds = array_unique(array_map(fn ($r) => (int) $r->StudentClassID, $g['rows']));

            return count($scIds) > 1;
        }));
    }

    /**
     * @param  list<int>  $sessionIds
     * @return list<int>
     */
    private function liveLearningRecordSessionIds(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        return DB::table('LearningRecord')
            ->whereIn('ClassSessionID', $sessionIds)
            ->whereNull('VoidedAt')
            ->pluck('ClassSessionID')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plan189(): array
    {
        return [
            [
                'type' => 'cancel_session',
                'bug' => '189',
                'session_id' => 18569,
                'student_class_id' => 2264,
                'reason' => '6/13 17:00 duplicate — keep SC1946 session 15636',
            ],
            [
                'type' => 'cancel_session',
                'bug' => '189',
                'session_id' => 18602,
                'student_class_id' => 2264,
                'reason' => '6/20 17:00 duplicate — keep SC1946 session 15633',
            ],
            [
                'type' => 'stop_student_class',
                'bug' => '189',
                'student_class_id' => 2264,
                'reason' => 'ghost shell SessionCount=0',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plan191(): array
    {
        return [
            [
                'type' => 'cancel_session',
                'bug' => '191',
                'session_id' => 3215,
                'student_class_id' => 395,
                'reason' => '5/14 16:00 duplicate — keep SC1655 session 13302',
            ],
        ];
    }

    private function formatActionLine(array $action, bool $dryRun): string
    {
        $prefix = $dryRun ? 'WOULD' : 'DID';
        if ($action['type'] === 'cancel_session') {
            return sprintf(
                '%s cancel ClassSession id=%d (SC %d) — %s',
                $prefix,
                $action['session_id'],
                $action['student_class_id'],
                $action['reason']
            );
        }

        return sprintf(
            '%s StudentClass id=%d Stop=1 — %s',
            $prefix,
            $action['student_class_id'],
            $action['reason']
        );
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function applyAction(array $action): void
    {
        if ($action['type'] === 'cancel_session') {
            $row = DB::table('ClassSession')->where('id', $action['session_id'])->first();
            if (!$row) {
                throw new \RuntimeException('ClassSession ' . $action['session_id'] . ' not found');
            }
            if ((int) $row->StudentClassID !== (int) $action['student_class_id']) {
                throw new \RuntimeException('ClassSession ' . $action['session_id'] . ' SC mismatch');
            }
            $note = trim((string) $row->Note . ' ' . self::NOTE . ' — ' . $action['reason']);
            DB::table('ClassSession')->where('id', $action['session_id'])->update([
                'Status' => 'cancelled',
                'Note' => $note,
                'updated_at' => now(),
            ]);

            return;
        }

        if ($action['type'] === 'stop_student_class') {
            DB::table('StudentClass')->where('ID', $action['student_class_id'])->update([
                'Stop' => 1,
            ]);
        }
    }

    private function assertProductionAllowed(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force')) {
            $this->error('Production requires --force');

            return false;
        }
        if (env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires ALLOW_PROD_REPAIR=1 in .env');

            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    private function writeSnapshot(string $path, string $case, array $plan): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $sessionIds = array_values(array_filter(array_map(
            fn ($a) => $a['type'] === 'cancel_session' ? $a['session_id'] : null,
            $plan
        )));
        $scIds = array_values(array_unique(array_column($plan, 'student_class_id')));

        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'case' => $case,
            'plan' => $plan,
            'class_sessions_before' => DB::table('ClassSession')->whereIn('id', $sessionIds)->get(),
            'student_classes_before' => DB::table('StudentClass')->whereIn('ID', $scIds)->get(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }
}
