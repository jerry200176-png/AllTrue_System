<?php

namespace App\Console\Commands;

use App\Services\SessionDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NightlyReconcile extends Command
{
    protected $signature = 'reconcile:nightly
                            {--dry-run : Preview only, no DB writes or alerts}
                            {--threshold=0 : Minimum discrepancy count to trigger alert}';

    protected $description = 'Nightly reconcile: compare StudentClass.UsedSessions with canonical deduction semantics. Alert on mismatch.';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $threshold = max(0, (int) $this->option('threshold'));

        if (!DB::getSchemaBuilder()->hasTable('StudentClass') || !DB::getSchemaBuilder()->hasTable('ClassSession')) {
            $this->warn('Required tables missing; skipping.');
            return self::SUCCESS;
        }

        // Compute actual attended session counts per StudentClass.
        // ClassSession has no VoidedAt column; cancellation/voiding is expressed
        // via Status, so the attended/completed/late filter already excludes them.
        $actualCounts = DB::table('ClassSession')
            ->whereIn('Status', ['attended', 'completed', 'late'])
            ->select('StudentClassID', DB::raw('COUNT(*) as actual_count'))
            ->groupBy('StudentClassID')
            ->pluck('actual_count', 'StudentClassID');

        // Load all active courses with their recorded UsedSessions
        $courses = DB::table('StudentClass')
            ->where('Stop', 0)
            ->select('ID', 'StudentID', 'SubjectID', 'UsedSessions', 'SessionCount')
            ->get();
        $expectedCounts = SessionDeductionService::batchExpectedUsedSessions($courses->pluck('ID')->all());

        $mismatches = [];
        foreach ($courses as $c) {
            $actual   = (int) ($actualCounts[$c->ID] ?? 0);
            $expected = (int) ($expectedCounts[$c->ID] ?? 0);
            $recorded = (int) ($c->UsedSessions ?? 0);
            $diff     = abs($expected - $recorded);
            if ($diff > $threshold) {
                $mismatches[] = [
                    'student_class_id' => $c->ID,
                    'student_id'       => $c->StudentID,
                    'subject_id'       => $c->SubjectID,
                    'session_count'    => $c->SessionCount,
                    'recorded_used'    => $recorded,
                    'expected_used'    => $expected,
                    'actual_attended'  => $actual,
                    'diff'             => $diff,
                ];
            }
        }

        $total      = count($mismatches);
        $checkedAt  = now()->toDateTimeString();

        $payload = [
            'checked_at'  => $checkedAt,
            'mode'        => $dryRun ? 'dry-run' : 'live',
            'threshold'   => $threshold,
            'total_checked' => $courses->count(),
            'mismatch_count' => $total,
            'mismatches'  => array_slice($mismatches, 0, 200),
        ];

        $reportPath = storage_path('logs/nightly-reconcile-' . now()->format('Ymd') . '.json');
        @file_put_contents($reportPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        Log::info('nightly_reconcile', [
            'checked_at'     => $checkedAt,
            'total_checked'  => $courses->count(),
            'mismatch_count' => $total,
            'report_path'    => $reportPath,
        ]);

        $this->line("Checked: {$courses->count()} courses | Mismatches: {$total} | Report: {$reportPath}");

        if ($total === 0) {
            $this->info('All UsedSessions match actual attended counts.');
            return self::SUCCESS;
        }

        $this->warn("{$total} mismatch(es) found.");

        if (!$dryRun && $total > 0) {
            $this->alertSuperAdmin($total, $mismatches, $reportPath);
        }

        return self::SUCCESS;
    }

    private function alertSuperAdmin(int $count, array $mismatches, string $reportPath): void
    {
        // Write a system notification for super_admin users
        $superAdminIds = DB::table('User')->where('type', 'A')->pluck('id');
        if ($superAdminIds->isEmpty()) return;

        $sample = collect($mismatches)->take(3)->map(function ($m) {
            return "Course #{$m['student_class_id']}: recorded={$m['recorded_used']}, expected={$m['expected_used']} (diff={$m['diff']})";
        })->implode(' | ');

        $title = "⚠ 夜間對帳：{$count} 筆堂次異常";
        $body  = "UsedSessions 與權威扣堂口徑不一致，請查閱報告。\n範例：{$sample}\n報告路徑：{$reportPath}";

        foreach ($superAdminIds as $adminId) {
            try {
                DB::table('Notification')->insert([
                    'UserID'    => $adminId,
                    'CampusID'  => null,
                    'Type'      => 'system_alert',
                    'Title'     => $title,
                    'Body'      => $body,
                    'CreatedAt' => now(),
                ]);
            } catch (\Exception $e) {
                Log::error('nightly_reconcile_notify_failed: ' . $e->getMessage());
            }
        }
    }
}
