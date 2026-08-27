<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SecurityAuditEvent;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\SessionDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Repair ledger ownership after a legacy session-record transfer. */
class RepairTransferredSessionLedger extends Command
{
    protected $signature = 'repair:transferred-session-ledger
        {--source-class= : Previous ledger owner}
        {--target-class= : Current ClassSession owner}
        {--session-ids= : Comma-separated transferred ClassSession IDs}
        {--reason= : Repair reason}
        {--actor= : Audit actor}
        {--actor-user-id= : Audit user ID}
        {--execute : Apply the repair}
        {--force : Required with --execute in production}
        {--snapshot= : Snapshot output path}';

    protected $description = 'Reconcile ledger and derived counters after transferring class sessions';

    public function handle(): int
    {
        $sourceId = (int) $this->option('source-class');
        $targetId = (int) $this->option('target-class');
        $sessionIds = $this->ids((string) $this->option('session-ids'));
        $plan = $this->plan($sourceId, $targetId, $sessionIds);
        $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!$plan['ok']) {
            $this->error('DRY_RUN_BLOCKED');
            return self::FAILURE;
        }
        if (!$this->option('execute')) {
            $this->info('Dry-run complete; no production data changed.');
            return self::SUCCESS;
        }
        if (!app()->environment('production') || ($this->option('force') && env('ALLOW_PROD_REPAIR') === '1')) {
            // The production workflow supplies both gates; local tests may execute directly.
        } else {
            $this->error('Production requires --force and ALLOW_PROD_REPAIR=1');
            return self::FAILURE;
        }

        $snapshot = trim((string) $this->option('snapshot'));
        if ($snapshot === '' || File::exists($snapshot) || !File::isDirectory(dirname($snapshot))) {
            $this->error('A new snapshot path in an existing directory is required');
            return self::FAILURE;
        }
        File::put($snapshot, json_encode([
            'command' => self::class,
            'captured_at' => now()->toIso8601String(),
            'plan' => $plan,
            'ledger' => SessionDeductionLedger::query()->whereIn('class_session_id', $sessionIds)->get()->toArray(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($sourceId, $targetId, $sessionIds) {
            StudentClass::query()->whereIn('ID', [$sourceId, $targetId])->lockForUpdate()->get();
            foreach (ClassSession::query()->whereIn('id', $sessionIds)->lockForUpdate()->get() as $session) {
                if ((int) $session->StudentClassID !== $targetId) {
                    throw new RuntimeException('session owner changed during repair');
                }
                LearningRecord::query()->where('ClassSessionID', $session->id)->update(['StudentClassID' => $targetId]);
                StudentSignIn::query()->where('ClassSessionID', $session->id)->update(['StudentClassID' => $targetId]);
                SessionDeductionLedger::query()->where('class_session_id', $session->id)->update([
                    'student_class_id' => $targetId,
                    'updated_at' => now(),
                ]);
            }
            SessionDeductionService::recomputeCounters($sourceId);
            SessionDeductionService::recomputeCounters($targetId);
            $diagnostics = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$sourceId, $targetId]);
            foreach ($diagnostics as $id => $diagnostic) {
                $course = StudentClass::query()->where('ID', $id)->first();
                if (!$course || (int) $course->UsedSessions !== (int) $diagnostic['expected_used']) {
                    throw new RuntimeException("counter verification failed for course {$id}");
                }
            }
            return $diagnostics;
        });

        SecurityAuditEvent::append('student_class.transferred_session_ledger_reconciled', 'success', [
            'campus_id' => StudentClass::query()->where('ID', $targetId)->first()?->student?->CampusID,
            'actor_type' => 'repair', 'actor_id' => $this->option('actor-user-id'),
            'subject_type' => 'student_class', 'subject_id' => $targetId,
        ], [
            'outcome' => 'success', 'transferred_session_count' => count($sessionIds),
            'reason_code' => 'legacy_transfer_ledger_owner_drift',
            'reason_hash' => hash('sha256', (string) $this->option('reason')),
        ]);
        $this->info('TRANSFERRED_SESSION_LEDGER_REPAIRED sessions=' . implode(',', $sessionIds));
        $this->line(json_encode(['diagnostics' => $result, 'snapshot' => $snapshot], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }

    private function plan(int $sourceId, int $targetId, array $sessionIds): array
    {
        $errors = [];
        $source = StudentClass::query()->where('ID', $sourceId)->first();
        $target = StudentClass::query()->where('ID', $targetId)->first();
        if (!$source || !$target) $errors[] = 'source or target course not found';
        if ($source && $target && (int) $source->StudentID !== (int) $target->StudentID) $errors[] = 'student mismatch';
        if ($source && $target && (int) $source->SubjectID !== (int) $target->SubjectID) $errors[] = 'subject mismatch';
        $sessions = ClassSession::query()->whereIn('id', $sessionIds)->get();
        $found = $sessions->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (array_diff($sessionIds, $found)) $errors[] = 'session allowlist contains missing IDs';
        foreach ($sessions as $session) {
            if ((int) $session->StudentClassID !== $targetId) $errors[] = "session {$session->id} is not owned by target";
            if (!in_array(strtolower((string) $session->Status), ['attended', 'completed', 'late'], true)) $errors[] = "session {$session->id} is not attended-like";
        }
        $ledger = SessionDeductionLedger::query()->whereIn('class_session_id', $sessionIds)->get();
        $drift = $ledger->filter(fn ($row) => (int) $row->student_class_id !== $targetId)->count();
        return ['ok' => $errors === [], 'errors' => $errors, 'source_class' => $sourceId, 'target_class' => $targetId,
            'session_ids' => $sessionIds, 'ledger_rows' => $ledger->count(), 'ledger_owner_drift' => $drift];
    }

    private function ids(string $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $value)), fn ($id) => $id > 0)));
        sort($ids);
        return $ids;
    }
}
