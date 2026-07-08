<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D1b (#957): cancel redundant intra-course ClassSession rows so unique slot index can apply.
 * Default: --dry-run. Production writes require --force and ALLOW_PROD_REPAIR=1.
 */
class CleanupClassSessionIntraDuplicates extends Command
{
    protected $signature = 'classsession:cleanup-intra-duplicates
                            {--dry-run : Preview only (default behaviour)}
                            {--execute : Apply cancellations}
                            {--force : Required with --execute on production}
                            {--student_class_id= : Limit to one StudentClass}
                            {--snapshot= : Write JSON snapshot before changes}';

    protected $description = 'Cancel duplicate ClassSession rows within the same course slot (D1 pre-migration cleanup)';

    private const NOTE_PREFIX = 'D1 cleanup #957 — removed duplicate slot; keeper id=';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = !$execute;

        if ($execute && !$dryRun && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }

        $studentClassId = $this->option('student_class_id') !== null
            ? (int) $this->option('student_class_id')
            : null;

        $groups = $this->findDuplicateGroups($studentClassId);
        if (empty($groups)) {
            $this->info('No intra-course duplicate groups found.');

            return self::SUCCESS;
        }

        $actions = [];
        foreach ($groups as $group) {
            $keeperId = $this->pickKeeperId($group['session_ids']);
            foreach ($group['session_ids'] as $sessionId) {
                if ($sessionId === $keeperId) {
                    continue;
                }
                $row = DB::table('ClassSession')->where('id', $sessionId)->first(['id', 'Status', 'Note']);
                if (!$row) {
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

        $this->line($dryRun ? '=== DRY RUN classsession:cleanup-intra-duplicates ===' : '=== EXECUTE classsession:cleanup-intra-duplicates ===');
        $this->line('groups=' . count($groups) . ' cancellations=' . count($actions));

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

        if ($dryRun || empty($actions)) {
            return self::SUCCESS;
        }

        $snapshotPath = $this->option('snapshot') ?: storage_path('app/repair-snapshots/d1-intra-' . now()->format('YmdHis') . '.json');
        $this->writeSnapshot($snapshotPath, $groups, $actions);

        foreach ($actions as $action) {
            if ($action['after_status'] === 'deleted') {
                DB::table('ClassSession')->where('id', $action['session_id'])->delete();

                continue;
            }
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
     * @return list<array{student_class_id: int, session_date: string, start_time: string, session_ids: list<int>}>
     */
    private function findDuplicateGroups(?int $studentClassId): array
    {
        $query = DB::table('ClassSession as cs')
            ->selectRaw('cs.StudentClassID as student_class_id')
            ->selectRaw('DATE(cs.SessionDate) as session_date')
            ->selectRaw('SUBSTRING(cs.StartTime, 1, 5) as start_time')
            ->selectRaw('GROUP_CONCAT(cs.id ORDER BY cs.id) as session_ids')
            ->selectRaw('COUNT(*) as row_count')
            ->groupBy('cs.StudentClassID', DB::raw('DATE(cs.SessionDate)'), DB::raw('SUBSTRING(cs.StartTime, 1, 5)'))
            ->having('row_count', '>', 1);

        if ($studentClassId) {
            $query->where('cs.StudentClassID', $studentClassId);
        }

        return $query->get()->map(function ($row) {
            return [
                'student_class_id' => (int) $row->student_class_id,
                'session_date' => (string) $row->session_date,
                'start_time' => (string) $row->start_time,
                'session_ids' => array_map('intval', explode(',', (string) $row->session_ids)),
            ];
        })->values()->all();
    }

    /**
     * @param  list<int>  $sessionIds
     */
    private function pickKeeperId(array $sessionIds): int
    {
        $priority = ['attended' => 5, 'completed' => 5, 'late' => 4, 'present' => 4, 'leave' => 3, 'leave_requested' => 3, 'scheduled' => 2];

        $rows = DB::table('ClassSession')
            ->whereIn('id', $sessionIds)
            ->get(['id', 'Status']);

        $lrBound = DB::table('LearningRecord')
            ->whereIn('ClassSessionID', $sessionIds)
            ->pluck('ClassSessionID')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $bestId = $sessionIds[0];
        $bestScore = -1;

        foreach ($rows as $row) {
            $status = strtolower((string) $row->Status);
            $score = $priority[$status] ?? 1;
            if (isset($lrBound[(int) $row->id])) {
                $score += 10;
            }
            if ($score > $bestScore || ($score === $bestScore && (int) $row->id < $bestId)) {
                $bestScore = $score;
                $bestId = (int) $row->id;
            }
        }

        return $bestId;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @param  list<array<string, mixed>>  $actions
     */
    private function writeSnapshot(string $path, array $groups, array $actions): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $sessionIds = array_column($actions, 'session_id');
        $before = DB::table('ClassSession')->whereIn('id', $sessionIds)->get();

        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'groups' => $groups,
            'actions' => $actions,
            'rows_before' => $before,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }
}
