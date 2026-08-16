<?php

namespace App\Console\Commands;

use App\Services\BackfillScheduleOccurrenceIdentityService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * TD-076 Phase 3. Default dry-run. Production execute needs --force + ALLOW_PROD_REPAIR
 * + I_APPROVE_TD076_OCCURRENCE_BACKFILL=1. Does not enable FEATURE_SCHEDULE_OCCURRENCE_V2.
 */
class BackfillScheduleOccurrenceIdentityCommand extends Command
{
    protected $signature = 'schedules:backfill-occurrence-identity
                            {--student-course-id= : Limit to one StudentClass.ID}
                            {--execute : Stamp identity columns (gated in production)}
                            {--force : Required with --execute in production}
                            {--snapshot= : Snapshot JSON path (required for execute)}
                            {--rollback : Restore original_* to null from snapshot}
                            {--actor= : Audit actor label}';

    protected $description = 'TD-076 Phase 3: dry-run (default) or gated stamp of frozen schedule occurrence identity';

    public function handle(BackfillScheduleOccurrenceIdentityService $service): int
    {
        $courseOpt = $this->option('student-course-id');
        $courseId = ($courseOpt !== null && $courseOpt !== '') ? (int) $courseOpt : null;
        $snapshot = trim((string) ($this->option('snapshot') ?: ''));

        if ($this->option('rollback')) {
            if ($snapshot === '') {
                $this->error('--snapshot is required for rollback');
                return self::FAILURE;
            }
            if ($this->option('execute') && !$this->productionWriteAllowed()) {
                return self::FAILURE;
            }
            if (!$this->option('execute')) {
                $this->info('ROLLBACK_DRY_RUN');
                return self::SUCCESS;
            }
            try {
                $result = $service->rollback($snapshot);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->info('ROLLBACK_APPLIED actor=' . (string) ($this->option('actor') ?: 'artisan:backfill-occurrence-identity'));
            return self::SUCCESS;
        }

        $plan = $service->plan($courseId);
        $this->line(json_encode([
            'mode' => $this->option('execute') ? 'execute' : 'dry-run',
            'actor' => (string) ($this->option('actor') ?: 'artisan:backfill-occurrence-identity'),
            'summary' => [
                'ok' => $plan['ok'],
                'blocked' => $plan['blocked'],
                'reason' => $plan['reason'],
                'stampable' => count($plan['candidates'] ?? []),
                'collisions' => count($plan['collisions'] ?? []),
                'superseded' => count($plan['superseded'] ?? []),
                'extras_skipped' => $plan['extras_skipped'] ?? 0,
                'already_ok' => $plan['already_ok'] ?? 0,
                'drift' => count($plan['drift'] ?? []),
            ],
            'plan' => $plan,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($plan['blocked']) {
            return self::FAILURE;
        }
        if (!$this->option('execute')) {
            $this->info('DRY_RUN_VERIFIED_NO_MUTATION');
            return self::SUCCESS;
        }
        if (!$this->productionWriteAllowed()) {
            return self::FAILURE;
        }
        if ($snapshot === '') {
            $this->error('--snapshot is required with --execute');
            return self::FAILURE;
        }

        try {
            $result = $service->execute($courseId, $snapshot);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('STAMPED_COUNT=' . (int) ($result['stamped_count'] ?? 0));
        return self::SUCCESS;
    }

    private function productionWriteAllowed(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force')) {
            $this->error('Production requires --force');
            return false;
        }
        if (env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires ALLOW_PROD_REPAIR=1');
            return false;
        }
        if (env('I_APPROVE_TD076_OCCURRENCE_BACKFILL') !== '1') {
            $this->error('Production requires I_APPROVE_TD076_OCCURRENCE_BACKFILL=1');
            return false;
        }
        return true;
    }
}
