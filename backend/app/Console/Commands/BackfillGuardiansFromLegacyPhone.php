<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\ParentBinding\GuardianSyncService;
use Illuminate\Console\Command;

/**
 * Backfill primary guardians from legacy Student.parent_* / Phone.
 * Default --dry-run. No production mutation unless explicitly run without dry-run
 * after Founder migration activation.
 */
class BackfillGuardiansFromLegacyPhone extends Command
{
    protected $signature = 'guardians:backfill-from-legacy-phone
                            {--dry-run : Report only (default)}
                            {--apply : Actually write guardian rows}
                            {--limit=500 : Max students to process}';

    protected $description = 'Backfill primary StudentGuardian rows from legacy parent_phone (dry-run by default).';

    public function handle(GuardianSyncService $sync): int
    {
        if (!GuardianSyncService::dualWriteEnabled()) {
            $this->error('guardian tables missing — run migrations first');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $dryRun = !$apply || (bool) $this->option('dry-run');
        if ($apply && $this->option('dry-run')) {
            $dryRun = true;
        }
        // Explicit: without --apply always dry-run
        if (!$this->option('apply')) {
            $dryRun = true;
        }

        $limit = max(1, (int) $this->option('limit'));
        $query = Student::query()
            ->where(function ($q) {
                $q->whereNotNull('parent_phone')->where('parent_phone', '!=', '')
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('Phone')->where('Phone', '!=', '');
                    });
            })
            ->orderBy('id')
            ->limit($limit);

        $scanned = 0;
        $wouldWrite = 0;
        $written = 0;

        foreach ($query->cursor() as $student) {
            $scanned++;
            if ($dryRun) {
                $wouldWrite++;
                continue;
            }
            $link = $sync->syncPrimaryFromStudent($student);
            if ($link) {
                $written++;
            }
        }

        $this->info(sprintf(
            'mode=%s scanned=%d would_write=%d written=%d',
            $dryRun ? 'dry-run' : 'apply',
            $scanned,
            $wouldWrite,
            $written
        ));

        return self::SUCCESS;
    }
}
