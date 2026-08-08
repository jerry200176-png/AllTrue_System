<?php

namespace App\Console\Commands;

use App\Services\ParentBinding\BindingService;
use Illuminate\Console\Command;

/**
 * W31 P1-3: Find and clean up orphaned parent bindings.
 *
 * php artisan parent-binding:cleanup-orphans          — dry-run (list orphans)
 * php artisan parent-binding:cleanup-orphans --force  — actual deletion
 */
class ParentBindingCleanupOrphansCommand extends Command
{
    protected $signature = 'parent-binding:cleanup-orphans {--force}';
    protected $description = 'Find and clean up parent bindings whose student no longer exists or is disabled (W31 P1-3).';

    public function __construct(private readonly BindingService $bindingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $orphans = $this->bindingService->findOrphanBindings();
        $force = (bool) $this->option('force');

        $this->line('');
        $this->info("Orphan bindings found: {$orphans->count()}");

        if ($orphans->isEmpty()) {
            $this->info('No orphans to clean up.');

            return self::SUCCESS;
        }

        // Display orphans
        $this->table(
            ['ID', 'Student ID', 'Student Name', 'LINE User ID', 'Campus ID', 'Bound At'],
            $orphans->map(fn ($o) => [
                $o->id,
                $o->student_id,
                $o->student_name ?? '(deleted)',
                $o->line_user_id,
                $o->campus_id ?? '—',
                $o->bound_at,
            ])->toArray(),
        );

        if (!$force) {
            $this->comment('Dry-run mode. Run with --force to actually delete these orphans.');

            return self::SUCCESS;
        }

        $count = $this->bindingService->cleanupOrphans();
        $this->info("✅ Cleaned up {$count} orphan binding(s).");

        return self::SUCCESS;
    }
}
