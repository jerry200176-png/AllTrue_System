<?php

namespace App\Console\Commands;

use App\Services\StaffIdentityMergeService;
use Illuminate\Console\Command;

/** Dual-account identity merge, phase 1: READ-ONLY (candidates + dry-run); apply/revert are later, Founder-gated phases. */
class StaffIdentityMergeCommand extends Command
{
    protected $signature = 'staff:identity-merge {--candidates} {--survivor=} {--retired=} {--cutover=} {--dry-run}';

    protected $description = 'Staff identity merge phase 1: candidates + dry-run plan (READ-ONLY).';

    public function handle(StaffIdentityMergeService $service): int
    {
        $cand = (bool) $this->option('candidates');
        $ids = [(string) $this->option('survivor'), (string) $this->option('retired')];
        $numeric = preg_match('/^[1-9]\d{0,9}$/', $ids[0]) && preg_match('/^[1-9]\d{0,9}$/', $ids[1]);
        if ($cand ? (bool) $this->option('dry-run') : !($this->option('dry-run') && $numeric)) {
            $this->error('Use exactly one of --candidates or --dry-run (needs numeric --survivor and --retired).');
            return 2;
        }
        $lines = [];
        if ($this->option('candidates')) {
            foreach ($service->candidates() as $c) {
                $lines[] = "candidate d={$c['d']} t={$c['t']} confidence={$c['confidence']} signals=" . implode(',', $c['signals']) . " t_status={$c['t_status']}";
            }
            $lines[] = 'candidates-total=' . count($lines);
            $lines[] = 'READ_ONLY=true';
        } else {
            $lines = $service->plan((int) $ids[0], (int) $ids[1], (string) $this->option('cutover'))['lines'];
        }
        array_map($this->line(...), $lines);
        return 0;
    }
}
