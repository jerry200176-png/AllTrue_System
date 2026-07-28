<?php

namespace App\Console\Commands;

use App\Services\Scheduling\EnsureSessionHorizonService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * ADR-006 Phase 1B — EnsureSessionHorizon.
 * Default dry-run. --execute requires FEATURE_ENSURE_SESSION_HORIZON=true and non-production.
 */
class EnsureSessionHorizonCommand extends Command
{
    protected $signature = 'sessions:ensure-horizon
                            {student_class_id : StudentClass.ID}
                            {--through= : Inclusive through_date YYYY-MM-DD}
                            {--as-of= : Asia/Taipei as-of date}
                            {--branch_id= : Fail closed if campus differs}
                            {--execute : Write ClassSession (requires feature flag; blocked in production)}
                            {--summary : Compact summary}';

    protected $description = 'ADR-006 EnsureSessionHorizon: dry-run by default; gated execute via upsertSlot.';

    public function handle(EnsureSessionHorizonService $ensure): int
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
        $mode = $this->option('execute')
            ? EnsureSessionHorizonService::MODE_EXECUTE
            : EnsureSessionHorizonService::MODE_DRY_RUN;

        $dto = $ensure->ensure($scId, $through, $asOf, $branchId, $mode);

        $blocked = (bool) ($dto['ensure']['blocked'] ?? true);
        $ok = (bool) ($dto['ensure']['ok'] ?? false);
        // Machine-readable exit: execute mode must be non-zero when blocked/not ok.
        $exit = ($mode === EnsureSessionHorizonService::MODE_EXECUTE && (!$ok || $blocked))
            ? self::FAILURE
            : self::SUCCESS;

        if ($this->option('summary')) {
            $e = $dto['ensure'];
            $this->info('EnsureSessionHorizon mode='.$dto['meta']['mode']
                .' flag='.($dto['meta']['feature_enabled'] ? 'on' : 'off'));
            $this->line('ok='.(($e['ok'] ?? false) ? 'yes' : 'no')
                .' blocked='.(($e['blocked'] ?? true) ? 'yes' : 'no')
                .' reason='.($e['primary_reason'] ?? 'n/a'));
            $this->line('candidates='.count($e['candidates'] ?? [])
                .' created='.($e['created_count'] ?? 0)
                .' skipped='.($e['skipped_count'] ?? 0));

            return $exit;
        }

        $this->line(json_encode($dto, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $exit;
    }
}
