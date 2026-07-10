<?php

namespace App\Console\Commands;

use App\Services\BusinessDigestService;
use Illuminate\Console\Command;

/**
 * ops:business-digest — read-only daily business-intelligence & anomaly digest.
 *
 * Surfaces owner-facing signals no single screen shows today:
 *   - Revenue at risk: prepaid sessions owed but not scheduled ahead (#1062).
 *   - Retention risk: active students with no upcoming session (silent churn).
 *   - Data-quality anomalies: the nightly reproduction-gate divergences.
 *   - Forward coverage: sessions materialized in the next 7 days.
 *
 * 100% read-only. Thin presentation layer over {@see BusinessDigestService}
 * (ADR-003). First step of the AI-native ops direction
 * (docs/POLICY_AI_NATIVE_ROADMAP.md): a stable, testable metric surface a dashboard or
 * anomaly model can later read.
 */
class OpsBusinessDigest extends Command
{
    protected $signature = 'ops:business-digest {--json : Emit JSON instead of a table}';

    protected $description = 'Read-only daily BI + anomaly digest (revenue at risk, retention risk, data-quality, coverage).';

    public function handle(BusinessDigestService $digest): int
    {
        $m = $digest->metrics();

        if ($this->option('json')) {
            $this->line(json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info('=== AllTrue Business Digest — ' . now()->toDateTimeString() . ' ===');
        $this->table(['Signal', 'Value', 'Meaning'], [
            ['revenue_at_risk_sessions', $m['revenue']['stranded_sessions'], 'prepaid sessions owed, not scheduled ahead (#1062)'],
            ['revenue_at_risk_amount', $m['revenue']['stranded_amount'], 'estimated NT$ owed (Rate x remaining)'],
            ['unpaid_active_courses', $m['revenue']['unpaid_active_courses'], 'active courses flagged unpaid / low-remaining'],
            ['retention_risk_students', $m['retention']['no_upcoming_students'], 'active students with NO session in next 14 days'],
            ['dq_attended_no_LR', $m['data_quality']['attended_without_lr'], 'attended sessions with no learning record (target 0)'],
            ['dq_cross_sc_dup', $m['data_quality']['cross_sc_duplicate'], 'same-student cross-contract duplicate slots (#1130)'],
            ['dq_remaining_divergent', $m['data_quality']['remaining_divergent'], 'RemainingSessions <> SessionCount-Used (#964)'],
            ['coverage_next_7d', $m['coverage']['sessions_next_7d'], 'materialized sessions in next 7 days'],
        ]);

        foreach ($digest->anomalies($m) as $flag) {
            $this->warn('⚠ ' . $flag);
        }

        return self::SUCCESS;
    }
}
