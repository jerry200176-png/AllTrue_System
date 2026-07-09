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
                            {--case= : 189, 191, or batch0 (189+191)}
                            {--dry-run : Preview only (default)}
                            {--execute : Apply changes}
                            {--force : Required with --execute on production}
                            {--snapshot= : JSON snapshot path before writes}';

    protected $description = 'Repair P0 duplicate ClassSession rows for #189 / #191';

    private const NOTE = '資料修復 #189-191 — 跨約重複';

    public function handle(): int
    {
        $case = strtolower((string) $this->option('case'));
        if (!in_array($case, ['189', '191', 'batch0'], true)) {
            $this->error('--case is required: 189, 191, or batch0');

            return self::FAILURE;
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

        foreach ($plan as $action) {
            $this->applyAction($action);
        }

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

        return $plan;
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
