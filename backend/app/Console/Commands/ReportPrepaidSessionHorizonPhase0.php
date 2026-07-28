<?php

namespace App\Console\Commands;

use App\Services\Scheduling\PrepaidHorizonPhase0Reporter;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** ADR-006 Phase 0 — read-only prepaid horizon evidence (no writes). */
class ReportPrepaidSessionHorizonPhase0 extends Command
{
    protected $signature = 'sessions:report-prepaid-horizon-phase0
                            {--branch_id= : Limit to one campus (Student.CampusID)}
                            {--as-of= : Asia/Taipei date YYYY-MM-DD (default today)}
                            {--limit=5000 : Max courses to scan}
                            {--json : Emit full JSON (default)}
                            {--summary : Emit human summary instead of full JSON}';

    protected $description = 'ADR-006 Phase 0 read-only report: commitment classification + horizon gaps (no writes).';

    public function handle(PrepaidHorizonPhase0Reporter $reporter): int
    {
        $branchOpt = $this->option('branch_id');
        $branchId = ($branchOpt !== null && $branchOpt !== '') ? (int) $branchOpt : null;
        $asOf = $this->option('as-of')
            ? Carbon::parse((string) $this->option('as-of'), 'Asia/Taipei')->startOfDay()
            : Carbon::today('Asia/Taipei');
        $report = $reporter->build($branchId, $asOf, max(1, (int) $this->option('limit')));

        if ($this->option('summary')) {
            $q = $report['questions'];
            $this->info('ADR-006 Phase 0 — prepaid horizon (READ-ONLY)');
            $this->line('rule='.$report['meta']['rule_version'].' as_of='.$report['meta']['as_of']
                .' courses='.$report['meta']['courses_scanned']);
            $this->line(sprintf(
                'Q1 MF courses=%d missing_7d=%d missing_28d=%d',
                $q['q1_explicit_materialization_gap']['courses'],
                $q['q1_explicit_materialization_gap']['missing_occurrences_7d'],
                $q['q1_explicit_materialization_gap']['missing_occurrences_28d'],
            ));
            $this->line('Q2='.json_encode($q['q2_no_explicit_commitment_split'], JSON_UNESCAPED_UNICODE));
            $this->line('Q3 shortage_pools='.$q['q3_shared_pool_coverage_gap']['pools_with_shortage']
                .' Q7 manual_30d='.$q['q7_manual_backfill']['manual_backfill_count']);
            $this->line('adapter='.json_encode($report['student_class_adapter_assessment'], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
