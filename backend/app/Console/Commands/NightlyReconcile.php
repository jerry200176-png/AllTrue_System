<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Services\SessionDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NightlyReconcile extends Command
{
    protected $signature = 'reconcile:nightly
                            {--dry-run : Preview only, no DB writes or alerts}
                            {--threshold=0 : Minimum discrepancy count to trigger alert}';

    protected $description = 'Nightly session-count check: compare StudentClass.UsedSessions with SessionDeductionService (not bank/tuition). Alert only; never rewrite counters.';

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
        $diagnostics = SessionDeductionService::batchExpectedUsedSessionDiagnostics($courses->pluck('ID')->all());

        $mismatches = [];
        $causeCounts = [];
        foreach ($courses as $c) {
            $diagnostic = $diagnostics[$c->ID] ?? [];
            $actual   = (int) ($actualCounts[$c->ID] ?? 0);
            $expected = (int) ($diagnostic['expected_used'] ?? 0);
            $recorded = (int) ($c->UsedSessions ?? 0);
            $sourceConflict = (int) ($diagnostic['cancelled_usage_artifacts'] ?? 0) > 0;
            $sourceDiff = $sourceConflict
                ? abs($actual - (int) ($diagnostic['ledger_used'] ?? $diagnostic['observed_used'] ?? 0))
                : 0;
            $diff       = max(abs($expected - $recorded), $sourceDiff);
            // A source conflict with no count difference belongs to the
            // non-attendance artifact sweep, not this numeric count report.
            // Otherwise the panel shows a misleading "差異 0" row after the
            // actual counter values are already aligned.
            if ($diff > $threshold) {
                $category = $this->classifyMismatch($recorded, $diagnostic);
                $causeCounts[$category] = ($causeCounts[$category] ?? 0) + 1;
                $mismatches[] = [
                    'student_class_id' => $c->ID,
                    'student_id'       => $c->StudentID,
                    'subject_id'       => $c->SubjectID,
                    'session_count'    => $c->SessionCount,
                    'recorded_used'    => $recorded,
                    'expected_used'    => $expected,
                    'actual_attended'  => $actual,
                    'diff'             => $diff,
                    'category'         => $category,
                ];
            }
        }
        ksort($causeCounts);

        $total      = count($mismatches);
        $checkedAt  = now()->toDateTimeString();

        $payload = [
            'checked_at'  => $checkedAt,
            'mode'        => $dryRun ? 'dry-run' : 'live',
            'threshold'   => $threshold,
            'total_checked' => $courses->count(),
            'mismatch_count' => $total,
            'cause_counts' => $causeCounts,
            'mismatches'  => array_slice($mismatches, 0, 200),
        ];

        $reportPath = storage_path('logs/nightly-reconcile-' . now()->format('Ymd') . '.json');
        @file_put_contents($reportPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        Log::info('nightly_reconcile', [
            'checked_at'     => $checkedAt,
            'total_checked'  => $courses->count(),
            'mismatch_count' => $total,
            'cause_counts'   => $causeCounts,
            'report_path'    => $reportPath,
        ]);

        $this->line("Checked: {$courses->count()} courses | Mismatches: {$total} | Report: {$reportPath}");
        $this->line('Causes: ' . json_encode($causeCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($total === 0) {
            $this->info('All UsedSessions match canonical expected_used.');
            return self::SUCCESS;
        }

        $this->warn("{$total} mismatch(es) found.");

        if (!$dryRun && $total > 0) {
            $this->alertSuperAdmin($total, $causeCounts);
        }

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $diagnostic */
    private function classifyMismatch(int $recorded, array $diagnostic): string
    {
        $expected = (int) ($diagnostic['expected_used'] ?? 0);
        $sessionCount = (int) ($diagnostic['session_count'] ?? 0);

        if ((int) ($diagnostic['cancelled_usage_artifacts'] ?? 0) > 0) {
            return 'source_conflict';
        }

        if ((bool) ($diagnostic['has_partial'] ?? false)) {
            return 'partial_minutes';
        }

        if (
            (bool) ($diagnostic['is_session_mode'] ?? false)
            && $sessionCount > 0
            && (int) ($diagnostic['uncapped_used'] ?? 0) > $sessionCount
        ) {
            return 'contract_cap';
        }

        if ($recorded > $expected) {
            return 'counter_overstated';
        }

        if ((int) ($diagnostic['ledger_used'] ?? 0) > (int) ($diagnostic['observed_used'] ?? 0)) {
            return 'ledger_ahead';
        }

        return 'attendance_ahead';
    }

    private function causeLabel(string $category): string
    {
        return [
            'partial_minutes' => '部分時數換算',
            'contract_cap' => '出勤超出合約堂數',
            'counter_overstated' => '已用堂數高於現有證據',
            'ledger_ahead' => '扣堂帳本領先計數器',
            'attendance_ahead' => '出勤或評量證據領先計數器',
            'source_conflict' => '課堂狀態與扣堂證據衝突',
        ][$category] ?? '待分類';
    }

    /** @param array<string,int> $causeCounts */
    private function alertSuperAdmin(int $count, array $causeCounts): void
    {
        if (!DB::table('User')->where('type', 'S')->exists()) {
            return;
        }

        $causeSummary = collect($causeCounts)->map(function (int $causeCount, string $category): string {
            return $this->causeLabel($category) . " {$causeCount} 筆";
        })->implode('、');

        $title = "⚠ 夜間堂數對帳：{$count} 筆課程異常";
        $body  = "已用堂數與權威扣堂口徑不一致（不是學費／銀行勾稽）。分類：{$causeSummary}。請開啟「夜間堂數對帳」面板檢視；資料修復需另走核准與回滾流程。";

        try {
            Notification::query()->updateOrCreate(
                ['SourceKey' => 'nightly_reconcile:' . now()->toDateString()],
                [
                    'CampusID' => null,
                    'Type' => 'system_alert',
                    'Severity' => 'high',
                    'Title' => $title,
                    'Body' => $body,
                    'SourceType' => 'nightly_reconcile',
                    'SourceID' => now()->toDateString(),
                    'Payload' => ['cause_counts' => $causeCounts],
                    'OccurredAt' => now(),
                    'ResolvedAt' => null,
                ]
            );
        } catch (\Exception $e) {
            Log::error('nightly_reconcile_notify_failed: ' . $e->getMessage());
        }
    }
}
