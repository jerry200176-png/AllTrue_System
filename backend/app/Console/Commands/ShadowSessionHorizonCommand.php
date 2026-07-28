<?php

namespace App\Console\Commands;

use App\Services\Scheduling\ShadowSessionHorizonService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** ADR-006 Phase 2 — shadow horizon (always read-only). */
class ShadowSessionHorizonCommand extends Command
{
    protected $signature = 'sessions:shadow-horizon
                            {--branch_id= : Limit to one campus}
                            {--as-of= : Asia/Taipei date}
                            {--limit=500 : Max courses}
                            {--summary : Compact summary}';

    protected $description = 'ADR-006 Phase 2 shadow: Preview vs Ensure dry-run drift (no writes).';

    public function handle(ShadowSessionHorizonService $shadow): int
    {
        $branchOpt = $this->option('branch_id');
        $branchId = ($branchOpt !== null && $branchOpt !== '') ? (int) $branchOpt : null;
        $asOf = $this->option('as-of')
            ? Carbon::parse((string) $this->option('as-of'), 'Asia/Taipei')->startOfDay()
            : Carbon::today('Asia/Taipei');
        $report = $shadow->run($branchId, $asOf, max(1, (int) $this->option('limit')));

        if ($this->option('summary')) {
            $m = $report['metrics'];
            $this->info('ADR-006 Phase 2 shadow (READ-ONLY)');
            $this->line('courses='.$report['meta']['courses_scanned']
                .' eligible='.$m['ensure_eligible_courses']
                .' candidates='.$m['ensure_candidate_slots']
                .' uncovered='.$m['preview_uncovered_slots']
                .' shortage_blocks='.$m['blocked_pool_shortage_courses']
                .' drift='.$m['preview_vs_ensure_drift_courses']);

            return self::SUCCESS;
        }

        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
