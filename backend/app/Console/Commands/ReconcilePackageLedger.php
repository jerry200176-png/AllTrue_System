<?php

namespace App\Console\Commands;

use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcilePackageLedger extends Command
{
    protected $signature = 'packages:reconcile {--fix : Auto-fix discrepancies}';
    protected $description = 'Compare package remaining_sessions cache with ledger SUM(delta) and report/fix discrepancies';

    public function handle(): int
    {
        $packages = CoursePackage::where('enabled', true)->get();
        $discrepancies = [];
        $fixed = 0;

        foreach ($packages as $pkg) {
            $ledgerRemaining = $pkg->computeRemainingFromLedger();
            $cached = (int) $pkg->remaining_sessions;

            if ($cached !== $ledgerRemaining) {
                $discrepancies[] = [
                    'package_id'       => $pkg->id,
                    'name'             => $pkg->name,
                    'cached_remaining' => $cached,
                    'ledger_remaining' => $ledgerRemaining,
                    'delta'            => $cached - $ledgerRemaining,
                ];

                if ($this->option('fix')) {
                    $pkg->remaining_sessions = $ledgerRemaining;
                    $pkg->used_sessions = max(0, $pkg->total_sessions - $ledgerRemaining);
                    $pkg->save();
                    $fixed++;
                }
            }
        }

        if (empty($discrepancies)) {
            $this->info("All {$packages->count()} packages are consistent.");
            Log::info('PackageLedger reconcile: all consistent', ['count' => $packages->count()]);
            return 0;
        }

        $this->warn(count($discrepancies) . ' discrepancies found:');
        $this->table(
            ['Package ID', 'Name', 'Cached', 'Ledger', 'Delta'],
            array_map(fn ($d) => array_values($d), $discrepancies)
        );

        Log::warning('PackageLedger reconcile: discrepancies', [
            'count'         => count($discrepancies),
            'fixed'         => $fixed,
            'discrepancies' => $discrepancies,
        ]);

        if ($this->option('fix')) {
            $this->info("Fixed {$fixed} packages.");
        } else {
            $this->info('Run with --fix to auto-correct.');
        }

        return count($discrepancies) > 0 ? 1 : 0;
    }
}
