<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LearningRecordDriftCheck extends Command
{
    protected $signature = 'learning-records:drift-check
                            {--fix : Auto-fix mismatches and remove orphan rows}
                            {--dry-run : Read-only preview report (no DB writes)}
                            {--report-limit=500 : Max rows per anomaly bucket in report}';

    protected $description = 'Check (and optionally fix) LearningRecord/ClassSession drift.';

    public function handle(): int
    {
        if (!DB::getSchemaBuilder()->hasTable('LearningRecord') || !DB::getSchemaBuilder()->hasTable('ClassSession')) {
            $this->warn('LearningRecord/ClassSession table missing; skip.');
            return self::SUCCESS;
        }

        $doFix = (bool) $this->option('fix');
        $forceDryRun = (bool) $this->option('dry-run');
        if ($doFix && $forceDryRun) {
            $this->error('Cannot use --fix together with --dry-run.');
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('report-limit'));
        $isDryRun = !$doFix || $forceDryRun;

        $preview = $this->collectDetailedPreview($limit);
        $counts = $this->collectCounts();
        $fixed = [
            'deleted_orphan' => 0,
            'updated_mismatch' => 0,
        ];

        if ($doFix && !$forceDryRun) {
            $fixed = $this->applyFixes();
            // Re-collect preview after fix for a truthful post-fix snapshot.
            $preview = $this->collectDetailedPreview($limit);
            $counts = $this->collectCounts();
        }

        $payload = [
            'checked_at' => now()->toDateTimeString(),
            'mode' => $isDryRun ? 'dry-run' : 'fix',
            'fixed' => $doFix && !$forceDryRun,
            'counts' => $counts,
            'fixed_rows' => $fixed,
            'report_limit' => $limit,
            'preview' => $preview,
        ];

        $reportPath = storage_path('logs/learning-record-drift-report-' . now()->format('Ymd_His') . '.json');
        @file_put_contents($reportPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        Log::info('learning_record_drift_check', $payload + ['report_path' => $reportPath]);

        $this->line('Drift counts: ' . json_encode($counts, JSON_UNESCAPED_UNICODE));
        if ($isDryRun) {
            $this->line('Mode: dry-run (read-only, DB unchanged)');
        }
        if ($doFix && !$forceDryRun) {
            $this->line('Fixed rows: ' . json_encode($fixed, JSON_UNESCAPED_UNICODE));
        }
        $this->line('Preview buckets: ' . json_encode([
            'null_class_session_id' => count($preview['null_class_session_id'] ?? []),
            'orphan_class_session_id' => count($preview['orphan_class_session_id'] ?? []),
            'mismatch_fields' => count($preview['mismatch_fields'] ?? []),
        ], JSON_UNESCAPED_UNICODE));
        $this->line('Report: ' . $reportPath);

        // Non-zero exit if unresolved issues remain and not auto-fixed.
        $remaining = array_sum($counts);
        if ($isDryRun && $remaining > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function collectCounts(): array
    {
        $nullClassSessionId = DB::table('LearningRecord')->whereNull('ClassSessionID')->count();

        $orphanClassSessionId = DB::table('LearningRecord as lr')
            ->leftJoin('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->whereNotNull('lr.ClassSessionID')
            ->whereNull('cs.id')
            ->count();

        $mismatchDate = DB::table('LearningRecord as lr')
            ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->where(function ($q) {
                $q->whereNull('lr.SessionDate')
                    ->orWhereRaw('lr.SessionDate <> cs.SessionDate');
            })
            ->count();

        $mismatchStartTime = DB::table('LearningRecord as lr')
            ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->where(function ($q) {
                $q->whereNull('lr.StartTime')
                    ->orWhereRaw('lr.StartTime <> cs.StartTime');
            })
            ->count();

        $mismatchEndTime = DB::table('LearningRecord as lr')
            ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->where(function ($q) {
                $q->whereNull('lr.EndTime')
                    ->orWhereRaw('lr.EndTime <> cs.EndTime');
            })
            ->count();

        $mismatchStudentClass = DB::table('LearningRecord as lr')
            ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->whereRaw('lr.StudentClassID <> cs.StudentClassID')
            ->count();

        return [
            'null_class_session_id' => (int) $nullClassSessionId,
            'orphan_class_session_id' => (int) $orphanClassSessionId,
            'mismatch_session_date' => (int) $mismatchDate,
            'mismatch_start_time' => (int) $mismatchStartTime,
            'mismatch_end_time' => (int) $mismatchEndTime,
            'mismatch_student_class_id' => (int) $mismatchStudentClass,
        ];
    }

    private function collectDetailedPreview(int $limit): array
    {
        $nullClassSession = DB::table('LearningRecord as lr')
            ->whereNull('lr.ClassSessionID')
            ->select([
                'lr.id as learning_record_id',
                'lr.ClassSessionID as class_session_id',
                DB::raw("'class_session_id_null' as reason"),
                DB::raw("'delete' as planned_action"),
            ])
            ->orderBy('lr.id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'learning_record_id' => (int) $row->learning_record_id,
                'class_session_id' => null,
                'reason' => (string) $row->reason,
                'planned_action' => (string) $row->planned_action,
            ])
            ->values()
            ->all();

        $orphan = DB::table('LearningRecord as lr')
            ->leftJoin('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->whereNotNull('lr.ClassSessionID')
            ->whereNull('cs.id')
            ->select([
                'lr.id as learning_record_id',
                'lr.ClassSessionID as class_session_id',
                DB::raw("'class_session_not_found' as reason"),
                DB::raw("'delete' as planned_action"),
            ])
            ->orderBy('lr.id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'learning_record_id' => (int) $row->learning_record_id,
                'class_session_id' => (int) $row->class_session_id,
                'reason' => (string) $row->reason,
                'planned_action' => (string) $row->planned_action,
            ])
            ->all();

        $mismatch = DB::table('LearningRecord as lr')
            ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->where(function ($q) {
                $q->whereNull('lr.SessionDate')
                    ->orWhereRaw('lr.SessionDate <> cs.SessionDate')
                    ->orWhereNull('lr.StartTime')
                    ->orWhereRaw('lr.StartTime <> cs.StartTime')
                    ->orWhereNull('lr.EndTime')
                    ->orWhereRaw('lr.EndTime <> cs.EndTime')
                    ->orWhereRaw('lr.StudentClassID <> cs.StudentClassID');
            })
            ->select([
                'lr.id as learning_record_id',
                'lr.ClassSessionID as class_session_id',
                'lr.StudentClassID as lr_student_class_id',
                'cs.StudentClassID as cs_student_class_id',
                'lr.SessionDate as lr_session_date',
                'cs.SessionDate as cs_session_date',
                'lr.StartTime as lr_start_time',
                'cs.StartTime as cs_start_time',
                'lr.EndTime as lr_end_time',
                'cs.EndTime as cs_end_time',
            ])
            ->orderBy('lr.id')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $reasons = [];
                if ((int) $row->lr_student_class_id !== (int) $row->cs_student_class_id) {
                    $reasons[] = 'student_class_id_mismatch';
                }
                if ($this->normalizeValue($row->lr_session_date) !== $this->normalizeValue($row->cs_session_date)) {
                    $reasons[] = 'session_date_mismatch';
                }
                if ($this->normalizeTime($row->lr_start_time) !== $this->normalizeTime($row->cs_start_time)) {
                    $reasons[] = 'start_time_mismatch';
                }
                if ($this->normalizeTime($row->lr_end_time) !== $this->normalizeTime($row->cs_end_time)) {
                    $reasons[] = 'end_time_mismatch';
                }

                return [
                    'learning_record_id' => (int) $row->learning_record_id,
                    'class_session_id' => (int) $row->class_session_id,
                    'reason' => empty($reasons) ? 'unknown_mismatch' : implode(',', $reasons),
                    'planned_action' => 'update_from_class_session',
                    'current' => [
                        'student_class_id' => (int) $row->lr_student_class_id,
                        'session_date' => $row->lr_session_date,
                        'start_time' => $row->lr_start_time,
                        'end_time' => $row->lr_end_time,
                    ],
                    'expected' => [
                        'student_class_id' => (int) $row->cs_student_class_id,
                        'session_date' => $row->cs_session_date,
                        'start_time' => $row->cs_start_time,
                        'end_time' => $row->cs_end_time,
                    ],
                ];
            })
            ->all();

        return [
            'null_class_session_id' => $nullClassSession,
            'orphan_class_session_id' => $orphan,
            'mismatch_fields' => $mismatch,
        ];
    }

    private function applyFixes(): array
    {
        $deletedOrphan = 0;
        $updatedMismatch = 0;

        $orphanIds = DB::table('LearningRecord as lr')
            ->leftJoin('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->whereNull('lr.ClassSessionID')
            ->orWhereNull('cs.id')
            ->pluck('lr.id');

        if ($orphanIds->isNotEmpty()) {
            $deletedOrphan = DB::table('LearningRecord')->whereIn('id', $orphanIds->all())->delete();
        }

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::table('LearningRecord as lr')
                ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
                ->select([
                    'lr.id as learning_record_id',
                    'cs.StudentClassID',
                    'cs.SessionDate',
                    'cs.StartTime',
                    'cs.EndTime',
                ])->get();

            foreach ($rows as $row) {
                $updatedMismatch += DB::table('LearningRecord')
                    ->where('id', (int) $row->learning_record_id)
                    ->update([
                        'StudentClassID' => $row->StudentClassID,
                        'SessionDate' => $row->SessionDate,
                        'StartTime' => $row->StartTime,
                        'EndTime' => $row->EndTime,
                    ]);
            }
        } else {
            $updatedMismatch = DB::affectingStatement(<<<'SQL'
UPDATE LearningRecord lr
JOIN ClassSession cs ON cs.id = lr.ClassSessionID
SET
  lr.StudentClassID = cs.StudentClassID,
  lr.SessionDate = cs.SessionDate,
  lr.StartTime = cs.StartTime,
  lr.EndTime = cs.EndTime
WHERE
  lr.StudentClassID <> cs.StudentClassID
  OR lr.SessionDate <> cs.SessionDate
  OR lr.StartTime <> cs.StartTime
  OR lr.EndTime <> cs.EndTime
  OR lr.SessionDate IS NULL
  OR lr.StartTime IS NULL
  OR lr.EndTime IS NULL
SQL);
        }

        return [
            'deleted_orphan' => (int) $deletedOrphan,
            'updated_mismatch' => (int) $updatedMismatch,
        ];
    }

    private function normalizeValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);
        return $str === '' ? null : $str;
    }

    private function normalizeTime($value): ?string
    {
        $str = $this->normalizeValue($value);
        if ($str === null) {
            return null;
        }
        return substr($str, 0, 5);
    }
}

