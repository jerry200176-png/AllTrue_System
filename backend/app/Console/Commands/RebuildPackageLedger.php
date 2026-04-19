<?php

namespace App\Console\Commands;

use App\Models\CoursePackage;
use App\Services\PackageDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * FR-004 — Rebuild package_session_ledger from attended ClassSession records.
 *
 * Usage:
 *   php artisan packages:rebuild-ledger                          # over-deducted, all campuses
 *   php artisan packages:rebuild-ledger 3                        # over-deducted in campus 3
 *   php artisan packages:rebuild-ledger 3 --dry-run              # preview only
 *   php artisan packages:rebuild-ledger 3 --all                  # every count-mode package
 *   php artisan packages:rebuild-ledger 3 --package-id=42        # single package, regardless of state
 *
 * Safety defaults (risk-1 mitigation):
 *   - Only touches billing_mode=count packages (月結 remaining=0 by design).
 *   - Without --all / --package-id, only selects packages where
 *     used_sessions >= total_sessions AND remaining_sessions <= 0 (suspected over-deduction).
 *   - --dry-run prints the projected diff without modifying data.
 */
class RebuildPackageLedger extends Command
{
    protected $signature = 'packages:rebuild-ledger
                            {campus_id? : Restrict to this campus (optional)}
                            {--package-id= : Rebuild exactly this package id, bypassing the safety filter}
                            {--all : Rebuild every count-mode package matching scope (bypass the over-deduction filter)}
                            {--dry-run : Preview changes without writing}';

    protected $description = 'Rebuild package_session_ledger from attended ClassSession records (FR-004)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $campusId = $this->argument('campus_id');
        $forcedId = $this->option('package-id');

        $query = CoursePackage::query()
            ->where('billing_mode', CoursePackage::BILLING_MODE_SESSION);

        if ($campusId !== null) {
            $query->where('campus_id', (int) $campusId);
        }

        if ($forcedId) {
            $query->where('id', (int) $forcedId);
        } elseif (!$this->option('all')) {
            $query->where(function ($q) {
                $q->where('remaining_sessions', '<=', 0)
                  ->whereColumn('used_sessions', '>=', 'total_sessions');
            });
        }

        $packages = $query->orderBy('id')->get();

        if ($packages->isEmpty()) {
            $this->info('No packages matched. (Tip: add --all to rebuild every count-mode package, or pass --package-id=N for a single package.)');
            return 0;
        }

        $this->line(sprintf(
            '%s %d package%s%s',
            $dryRun ? '[dry-run] Would rebuild' : 'Rebuilding',
            $packages->count(),
            $packages->count() === 1 ? '' : 's',
            $campusId !== null ? " in campus {$campusId}" : ''
        ));

        $rows = [];
        $ok = 0;
        $failed = 0;

        foreach ($packages as $pkg) {
            try {
                $result = PackageDeductionService::rebuildLedgerFromSessions((int) $pkg->id, $dryRun);
                if (isset($result['error'])) {
                    $failed++;
                    $rows[] = [$pkg->id, $pkg->name, 'ERROR', '-', '-', '-', $result['error']];
                    continue;
                }
                $ok++;
                $rows[] = [
                    $pkg->id,
                    mb_strimwidth((string) $pkg->name, 0, 24, '...'),
                    (int) $pkg->total_sessions,
                    (int) $pkg->remaining_sessions . ' → ' . (int) $result['remaining_sessions'],
                    (int) $pkg->used_sessions . ' → ' . (int) $result['used_sessions'],
                    $result['deleted_entries'] . ' → ' . $result['rebuilt_entries'],
                    $dryRun ? 'dry-run' : 'ok',
                ];
            } catch (\Throwable $e) {
                $failed++;
                $rows[] = [$pkg->id, $pkg->name, 'ERROR', '-', '-', '-', $e->getMessage()];
                Log::error('RebuildPackageLedger failed', [
                    'package_id' => $pkg->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->table(
            ['id', 'name', 'total', 'remaining', 'used', 'ledger rows', 'status'],
            $rows
        );

        if ($dryRun) {
            $this->comment('Dry-run complete. Re-run without --dry-run to persist changes.');
        } else {
            $this->info("Rebuilt {$ok} package(s); failures: {$failed}.");
        }

        return $failed > 0 ? 1 : 0;
    }
}
