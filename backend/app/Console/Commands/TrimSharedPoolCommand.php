<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Services\SharedPackagePlanningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * #4734 (Founder GO 2026-11-01): before #4730 every subject of a shared count pool pre-planned the whole total.
 * This removes the planned lessons past what each pool has left (SharedPackagePlanningService::sharedPoolExcess).
 * The removed rows never happened (no sign-in, ledger, note, substitute or payroll row points at them), so
 * they are deleted, not cancelled: a cancelled date stays taken and a renewal would plan past it, leaving a gap.
 * Every delete goes through the model, so ClassSessionObserver keeps the full row in schedule_audit_logs.
 *
 * Default is a dry run (ids and counts only, plus plan_sha). --execute needs the dry run's --plan-sha, and on
 * production --force and ALLOW_PROD_REPAIR=1; each pool is re-read under a row lock and skipped if it changed.
 */
class TrimSharedPoolCommand extends Command
{
    protected $signature = 'packages:trim-shared-pool
                            {--campus= : Pools of this campus (by package or member campus, as the ledger probe picks them)}
                            {--package=* : Pool ids}
                            {--execute : Delete the listed lessons}
                            {--force : Required with --execute on production}
                            {--plan-sha= : Required with --execute: the plan_sha the dry run printed}';

    protected $description = 'Remove lessons a shared count pool pre-planned past what it has left (dry run by default)';

    public function handle(SharedPackagePlanningService $planning): int
    {
        $campus = (int) $this->option('campus');
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('package'))));
        if ($campus <= 0 && $ids === []) {
            $this->error('Give --campus or --package');

            return self::FAILURE;
        }
        $packages = CoursePackage::query()->where('billing_mode', CoursePackage::BILLING_MODE_SESSION)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($campus > 0, fn ($q) => $q->where(fn ($w) => $w->where('campus_id', $campus)->orWhereIn('id', DB::table('StudentClass as sc')
                ->join('Student as s', 's.id', '=', 'sc.StudentID')->where('s.CampusID', $campus)->whereNotNull('sc.PackageID')->select('sc.PackageID'))))
            ->orderBy('id')->get();

        $plan = [];
        foreach ($packages as $package) {
            $excess = $planning->sharedPoolExcess($package);
            if ($excess['rows']->isEmpty() && $excess['blocked'] === 0) {
                continue;
            }
            $plan[(int) $package->id] = $excess['rows']->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $this->line(sprintf('package=%d remaining=%d planned=%d remove=%d blocked=%d',
                $package->id, $excess['remaining'], $excess['planned'], count($plan[(int) $package->id]), $excess['blocked']));
        }
        $sha = hash('sha256', json_encode($plan));
        $this->line(sprintf('pools=%d remove=%d plan_sha=%s', count($plan), array_sum(array_map('count', $plan)), $sha));

        if (!$this->option('execute')) {
            $this->line('result=DRY_RUN_ONLY');

            return self::SUCCESS;
        }
        if (!$this->productionAllowed() || $this->option('plan-sha') !== $sha) {
            $this->error($this->option('plan-sha') !== $sha ? 'plan_sha does not match this plan; dry-run again and review' : 'Production requires --force and ALLOW_PROD_REPAIR=1');

            return self::FAILURE;
        }

        $removed = 0;
        foreach ($plan as $packageId => $sessionIds) {
            $done = DB::transaction(function () use ($planning, $packageId, $sessionIds): int {
                $package = CoursePackage::query()->lockForUpdate()->find($packageId);
                $now = $package ? $planning->sharedPoolExcess($package)['rows']->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all() : [];
                if ($now !== $sessionIds) {
                    return -1;
                }
                ClassSession::query()->whereIn('id', $sessionIds)->lockForUpdate()->get()->each->delete();

                return count($sessionIds);
            });
            $this->line($done < 0 ? "SKIP package={$packageId} changed since the dry run" : "DID package={$packageId} removed={$done}");
            $removed += max(0, $done);
        }
        $this->line("removed={$removed} result=APPLIED");

        return self::SUCCESS;
    }

    private function productionAllowed(): bool
    {
        return !app()->environment('production') || ($this->option('force') && env('ALLOW_PROD_REPAIR') === '1');
    }
}
