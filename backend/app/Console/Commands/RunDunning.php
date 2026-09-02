<?php

namespace App\Console\Commands;

use App\Services\DunningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunDunning extends Command
{
    protected $signature = 'dunning:run {--campus= : Campus ID to run for} {--dry-run : Show what would be sent without creating events}';
    protected $description = 'Evaluate dunning rules and create notification events';

    public function handle(): int
    {
        $campusId = $this->option('campus') ? (int) $this->option('campus') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no events will be created.');
        }

        $service = new DunningService();
        $events = $service->evaluateAll($campusId, !$dryRun);

        $count = count($events);
        $verb = $dryRun ? 'would create' : 'created';
        $this->info("Dunning evaluation complete: {$count} events {$verb}.");

        if ($count > 0) {
            foreach ($events as $e) {
                $this->line("  [{$e['rule_key']}] student={$e['student_id']} course={$e['student_class_id']}");
            }
        }

        Log::info("[dunning:run] {$verb} {$count} events" . ($campusId ? " for campus {$campusId}" : ''), [
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
