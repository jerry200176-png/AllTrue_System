<?php

namespace App\Console\Commands;

use App\Services\CourseLeaveCascadeService;
use Illuminate\Console\Command;

/**
 * Nightly self-healing sweep for non-attendance sessions. Voids any
 * LearningRecord/StudentSignIn still live on a leave, leave_adjusted, excused,
 * or cancelled session, via the shared cascade logic. Idempotent + read-safe:
 * only ever voids rows that should already be voided.
 */
class LearningRecordVoidStaleLeave extends Command
{
    protected $signature = 'learning-records:void-stale-leave';

    protected $description = 'Void stale live LearningRecord/StudentSignIn rows left on non-attendance sessions.';

    public function handle(): int
    {
        $voided = CourseLeaveCascadeService::sweepStaleLiveArtifactsForNonAttendance();
        $this->info(sprintf('learning-records:void-stale-leave — total voided: %d.', $voided));

        return self::SUCCESS;
    }
}
