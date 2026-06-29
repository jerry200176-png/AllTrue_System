<?php

namespace App\Console\Commands;

use App\Services\LearningRecordBackfillService;
use Illuminate\Console\Command;

/**
 * Scheduled backfill for missing LearningRecord rows (#1078 / in-app #186/#188).
 *
 * Creates the `pending` LearningRecord that a teacher fills for any past attended session
 * that lacks one. Without this, attended sessions whose LR was never lazily created (via the
 * frontend's `ensure-past` call) accumulate invisibly and teachers "cannot submit 評量".
 * Idempotent + read-safe: only ever creates rows that should already exist.
 */
class LearningRecordBackfillMissing extends Command
{
    protected $signature = 'learning-records:backfill-missing
                            {--branch_id= : Limit to one campus (Student.CampusID)}';

    protected $description = 'Create missing pending LearningRecords for past attended sessions (evaluation-integrity guard, #1078).';

    public function handle(LearningRecordBackfillService $service): int
    {
        $branchId = $this->option('branch_id') !== null ? (int) $this->option('branch_id') : 0;

        if ($branchId > 0) {
            $created = $service->backfillBranch($branchId);
            $this->info(sprintf('learning-records:backfill-missing — campus %d: created %d missing LR(s).', $branchId, $created));
            return self::SUCCESS;
        }

        $byBranch = $service->backfillAllBranches();
        $total = array_sum($byBranch);
        foreach ($byBranch as $bid => $count) {
            if ($count > 0) {
                $this->line(sprintf('  campus %d: %d', $bid, $count));
            }
        }
        $this->info(sprintf('learning-records:backfill-missing — total created: %d.', $total));

        return self::SUCCESS;
    }
}
