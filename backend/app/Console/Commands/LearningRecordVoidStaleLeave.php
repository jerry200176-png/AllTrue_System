<?php

namespace App\Console\Commands;

use App\Services\CourseLeaveCascadeService;
use Illuminate\Console\Command;

/**
 * Nightly self-healing sweep for #170 (bugs:verify-reproductions'
 * leave_session_with_live_learning_record). Voids any LearningRecord/StudentSignIn still live
 * on a leave/leave_adjusted session, via the same shared logic every leave-transition code path
 * uses. Idempotent + read-safe: only ever voids rows that should already be voided.
 */
class LearningRecordVoidStaleLeave extends Command
{
    protected $signature = 'learning-records:void-stale-leave';

    protected $description = 'Void stale live LearningRecord/StudentSignIn rows left on leave sessions (self-healing guard, #170).';

    public function handle(): int
    {
        $voided = CourseLeaveCascadeService::sweepStaleLiveArtifactsForLeave();
        $this->info(sprintf('learning-records:void-stale-leave — total voided: %d.', $voided));

        return self::SUCCESS;
    }
}
