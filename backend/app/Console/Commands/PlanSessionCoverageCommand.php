<?php

namespace App\Console\Commands;

use App\Services\Scheduling\PoolCoveragePlanService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** ADR-006 Phase 3 — AllocateSessionCoverage dry-run (no coverage writes). */
class PlanSessionCoverageCommand extends Command
{
    protected $signature = 'sessions:plan-coverage
                            {package_id : course_packages.id}
                            {--through= : Inclusive through_date}
                            {--as-of= : Asia/Taipei as-of}
                            {--branch_id= : Fail closed if package campus differs}
                            {--summary : Compact summary}';

    protected $description = 'ADR-006 Phase 3 pool coverage plan (dry-run; no coverage/ledger writes).';

    public function handle(PoolCoveragePlanService $planner): int
    {
        $pkgId = (int) $this->argument('package_id');
        $asOf = $this->option('as-of')
            ? Carbon::parse((string) $this->option('as-of'), 'Asia/Taipei')->startOfDay()
            : Carbon::today('Asia/Taipei');
        $through = $this->option('through')
            ? Carbon::parse((string) $this->option('through'), 'Asia/Taipei')->startOfDay()
            : null;
        $branchOpt = $this->option('branch_id');
        $branchId = ($branchOpt !== null && $branchOpt !== '') ? (int) $branchOpt : null;

        $dto = $planner->planForPackage($pkgId, $through, $asOf, $branchId);

        if ($this->option('summary')) {
            if (empty($dto['ok'])) {
                $this->error('plan failed: '.($dto['error'] ?? 'unknown'));

                return self::FAILURE;
            }
            $pp = $dto['pool_projection'];
            $this->info('AllocateSessionCoverage DRY-RUN');
            $this->line(sprintf(
                'pkg=%d members=%d expected=%d coverable=%d uncovered=%d block=%s',
                $pkgId,
                $dto['member_count'],
                $pp['horizon_expected_new'],
                $pp['horizon_coverable'],
                $pp['horizon_uncovered'],
                $pp['ensure_would_block'] ? 'yes' : 'no'
            ));

            return self::SUCCESS;
        }

        $this->line(json_encode($dto, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
