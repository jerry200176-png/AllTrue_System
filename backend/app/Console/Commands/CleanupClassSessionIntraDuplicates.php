<?php

namespace App\Console\Commands;

use App\Services\ClassSessionIntraDuplicateFinder;
use App\Services\Scheduling\ClassSessionDeleteGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D1b (#957): remove redundant intra-course ClassSession rows (Type A active conflicts only).
 * Scope aligned with classsession:audit-duplicates intra_course_duplicates.
 * Default: --dry-run. Production writes require --force and ALLOW_PROD_REPAIR=1.
 *
 * #4455: a row with a course or package ledger net is never deleted (no ledger has a foreign key, so
 * its debit would be stranded). The keeper prefers such a row on a tie; any other ledger-bearing row
 * is skipped and listed, to be corrected by a reversal rather than a delete.
 */
class CleanupClassSessionIntraDuplicates extends Command
{
    protected $signature = 'classsession:cleanup-intra-duplicates
                            {--dry-run : Preview only (default behaviour)}
                            {--execute : Apply deletions}
                            {--force : Required with --execute on production}
                            {--student_class_id= : Limit to one StudentClass}
                            {--snapshot= : Write JSON snapshot before changes}';

    protected $description = 'Remove Type-A intra-course duplicate sessions (active conflicts only; audit-aligned)';

    private const NOTE_PREFIX = 'D1 cleanup #957 — removed active duplicate; keeper id=';

    public function handle(ClassSessionIntraDuplicateFinder $finder): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = !$execute;

        if ($execute && !$dryRun && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }

        $studentClassId = $this->option('student_class_id') !== null
            ? (int) $this->option('student_class_id')
            : null;

        $groups = $finder->findActiveDuplicateGroups($studentClassId);
        $placeholderCount = count($finder->findCancelledPlaceholderCollisions($studentClassId));

        if (empty($groups)) {
            $this->info('No Type-A intra-course duplicate groups found.');
            $this->line("cancelled_placeholder_collisions={$placeholderCount} (analysis only — not modified)");

            return self::SUCCESS;
        }

        $actions = [];
        $skipped = [];
        foreach ($groups as $group) {
            $ledgerBearing = ClassSessionDeleteGuard::ledgerBearingSessionIds($group['session_ids']);
            $keeperId = $this->pickKeeperId($group['session_ids'], $ledgerBearing);
            foreach ($group['session_ids'] as $sessionId) {
                if ($sessionId === $keeperId) {
                    continue;
                }
                $row = DB::table('ClassSession')->where('id', $sessionId)->first(['id', 'Status', 'Note']);
                if (!$row || strtolower((string) $row->Status) === 'cancelled') {
                    continue;
                }
                if (isset($ledgerBearing[$sessionId])) {
                    $skipped[] = [
                        'student_class_id' => $group['student_class_id'],
                        'session_id' => $sessionId,
                        'keeper_id' => $keeperId,
                        'session_date' => $group['session_date'],
                        'start_time' => $group['start_time'],
                        'status' => (string) $row->Status,
                    ];
                    continue;
                }
                $actions[] = [
                    'student_class_id' => $group['student_class_id'],
                    'session_id' => $sessionId,
                    'keeper_id' => $keeperId,
                    'session_date' => $group['session_date'],
                    'start_time' => $group['start_time'],
                    'before_status' => (string) $row->Status,
                    'after_status' => 'deleted',
                ];
            }
        }

        $this->line($dryRun ? '=== DRY RUN classsession:cleanup-intra-duplicates (Type A only) ===' : '=== EXECUTE classsession:cleanup-intra-duplicates (Type A only) ===');
        $this->line('scope=active_conflicts groups=' . count($groups) . ' deletions=' . count($actions));
        $this->line("cancelled_placeholder_collisions={$placeholderCount} (not in scope)");
        $this->line('skipped_ledger_bearing=' . count($skipped));

        foreach ($actions as $action) {
            $this->line(sprintf(
                '%s ClassSession id=%d (SC %d %s %s) %s → deleted [keeper=%d]',
                $dryRun ? 'WOULD' : 'DID',
                $action['session_id'],
                $action['student_class_id'],
                $action['session_date'],
                $action['start_time'],
                $action['before_status'],
                $action['keeper_id']
            ));
        }

        foreach ($skipped as $skip) {
            $this->warn(sprintf(
                'SKIP ClassSession id=%d (SC %d %s %s) %s has a course or package ledger net; not deleted [keeper=%d]',
                $skip['session_id'],
                $skip['student_class_id'],
                $skip['session_date'],
                $skip['start_time'],
                $skip['status'],
                $skip['keeper_id']
            ));
        }

        if ($dryRun || empty($actions)) {
            return self::SUCCESS;
        }

        $snapshotPath = $this->option('snapshot') ?: storage_path('app/repair-snapshots/d1-intra-' . now()->format('YmdHis') . '.json');
        $this->writeSnapshot($snapshotPath, $groups, $actions, $placeholderCount, $skipped);

        foreach ($actions as $action) {
            DB::table('ClassSession')->where('id', $action['session_id'])->delete();
        }

        $keeperNotes = [];
        foreach ($actions as $action) {
            $keeperNotes[$action['keeper_id']][] = $action['session_id'];
        }
        foreach ($keeperNotes as $keeperId => $removedIds) {
            $existing = (string) DB::table('ClassSession')->where('id', $keeperId)->value('Note');
            $note = self::NOTE_PREFIX . $keeperId . '; removed=' . implode(',', $removedIds);
            DB::table('ClassSession')->where('id', $keeperId)->update([
                'Note' => trim($existing . ' ' . $note),
                'updated_at' => now(),
            ]);
        }

        $this->info("Snapshot: {$snapshotPath}");

        return self::SUCCESS;
    }

    private function assertProductionAllowed(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force')) {
            $this->error('Production requires --force');

            return false;
        }
        if (env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires ALLOW_PROD_REPAIR=1 in .env');

            return false;
        }

        return true;
    }

    /**
     * @param  list<int>  $sessionIds
     * @param  array<int, true>  $ledgerBearing
     */
    private function pickKeeperId(array $sessionIds, array $ledgerBearing = []): int
    {
        $priority = ['attended' => 5, 'completed' => 5, 'late' => 4, 'present' => 4, 'leave' => 3, 'leave_requested' => 3, 'scheduled' => 2];

        $rows = DB::table('ClassSession')
            ->whereIn('id', $sessionIds)
            ->where('Status', '<>', 'cancelled')
            ->get(['id', 'Status']);

        $lrBound = DB::table('LearningRecord')
            ->whereIn('ClassSessionID', $sessionIds)
            ->pluck('ClassSessionID')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $bestId = (int) $rows->first()->id;
        $bestScore = -1;
        $bestHasLedger = false;

        foreach ($rows as $row) {
            $status = strtolower((string) $row->Status);
            $score = $priority[$status] ?? 1;
            if (isset($lrBound[(int) $row->id])) {
                $score += 10;
            }
            $hasLedger = isset($ledgerBearing[(int) $row->id]);
            // On a tie the ledger-bearing row is kept, so its debit stays on a live lesson (#4455).
            $better = $score > $bestScore
                || ($score === $bestScore && $hasLedger && !$bestHasLedger)
                || ($score === $bestScore && $hasLedger === $bestHasLedger && (int) $row->id < $bestId);
            if ($better) {
                $bestScore = $score;
                $bestId = (int) $row->id;
                $bestHasLedger = $hasLedger;
            }
        }

        return $bestId;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $skipped
     */
    private function writeSnapshot(string $path, array $groups, array $actions, int $placeholderCount, array $skipped = []): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $sessionIds = array_column($actions, 'session_id');
        $before = DB::table('ClassSession')->whereIn('id', $sessionIds)->get();

        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'scope' => 'active_conflicts_only',
            'groups' => $groups,
            'actions' => $actions,
            'skipped_ledger_bearing' => $skipped,
            'cancelled_placeholder_collisions' => $placeholderCount,
            'rows_before' => $before,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }
}
