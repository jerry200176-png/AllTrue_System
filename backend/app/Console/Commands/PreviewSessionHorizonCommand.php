<?php

namespace App\Console\Commands;

use App\Services\Scheduling\PreviewSessionHorizonService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** ADR-006 Phase 1A — PreviewSessionHorizon (read-only; no writes). */
class PreviewSessionHorizonCommand extends Command
{
    protected $signature = 'sessions:preview-horizon
                            {student_class_id : StudentClass.ID}
                            {--through= : Inclusive through_date YYYY-MM-DD (default today+28 Asia/Taipei)}
                            {--as-of= : Asia/Taipei as-of date (default today)}
                            {--branch_id= : Fail closed if course campus differs}
                            {--summary : Compact summary instead of full JSON}';

    protected $description = 'ADR-006 PreviewSessionHorizon: dry-run covered/uncovered plan (no ClassSession writes).';

    public function handle(PreviewSessionHorizonService $preview): int
    {
        $scId = (int) $this->argument('student_class_id');
        $asOf = $this->option('as-of')
            ? Carbon::parse((string) $this->option('as-of'), 'Asia/Taipei')->startOfDay()
            : Carbon::today('Asia/Taipei');
        $through = $this->option('through')
            ? Carbon::parse((string) $this->option('through'), 'Asia/Taipei')->startOfDay()
            : null;
        $branchOpt = $this->option('branch_id');
        $branchId = ($branchOpt !== null && $branchOpt !== '') ? (int) $branchOpt : null;

        $dto = $preview->preview($scId, $through, $asOf, $branchId);

        if ($this->option('summary')) {
            $p = $dto['preview'];
            $this->info('PreviewSessionHorizon READ-ONLY');
            $this->line('sc='.$scId.' class='.($p['classification'] ?? 'n/a')
                .' reason='.($p['primary_reason'] ?? 'n/a'));
            $this->line('ensure_eligible='.(($p['auto_ensure_eligible'] ?? false) ? 'yes' : 'no'));
            if (!empty($p['occurrence_summary'])) {
                $s = $p['occurrence_summary'];
                $this->line(sprintf(
                    'occ exists=%d expected_new=%d coverable=%d uncovered=%d',
                    $s['already_exists'], $s['expected_new'], $s['coverable_new'], $s['uncovered_new']
                ));
            }
            if (!empty($p['pool_projection'])) {
                $pp = $p['pool_projection'];
                $this->line(sprintf(
                    'pool pkg=%d expected=%d coverable=%d uncovered=%d ensure_block=%s',
                    $pp['package_id'], $pp['horizon_expected_new'], $pp['horizon_coverable'],
                    $pp['horizon_uncovered'], $pp['ensure_would_block'] ? 'yes' : 'no'
                ));
            }

            return self::SUCCESS;
        }

        $this->line(json_encode($dto, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
