<?php

namespace App\Console\Commands;

use App\Models\ScheduleDiscrepancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Monthly archive job — marks resolved / withdrawn reports older than 12 months
 * with `archived_at`. Archived rows remain queryable but drop out of dashboards.
 * Data-retention policy (see PRD §7 NFR).
 */
class ArchiveScheduleDiscrepancies extends Command
{
    protected $signature = 'schedule-discrepancies:archive {--months=12}';

    protected $description = 'Archive resolved/withdrawn schedule-discrepancy reports older than N months (default 12).';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $threshold = now()->subMonths($months);

        $affected = ScheduleDiscrepancy::query()
            ->whereNull('archived_at')
            ->whereIn('status', [
                ScheduleDiscrepancy::STATUS_RESOLVED,
                ScheduleDiscrepancy::STATUS_WITHDRAWN,
            ])
            ->where(function ($q) use ($threshold) {
                $q->where('resolved_at', '<', $threshold)
                  ->orWhere('withdrawn_at', '<', $threshold);
            })
            ->update(['archived_at' => now()]);

        Log::info('schedule_discrepancy.archived', [
            'affected' => $affected,
            'months' => $months,
            'threshold' => $threshold->toDateTimeString(),
        ]);

        $this->info("Archived {$affected} schedule discrepancy reports older than {$months} months.");
        return Command::SUCCESS;
    }
}
