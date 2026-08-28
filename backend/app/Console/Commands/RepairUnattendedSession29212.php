<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\ScheduleAuditLog;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\CourseLeaveCascadeService;
use App\Services\SessionDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Allowlisted, auditable repair for the 2026-08-28 翟君和 session.
 *
 * This is deliberately a fixed incident command. It cannot be turned into a
 * general production status editor by passing arbitrary IDs. The operation
 * follows the same invariants as the normal status transition: a session that
 * is not attended must have no live attendance/evaluation artifacts, no net
 * deduction for that session, and counters recomputed from the ledger.
 */
class RepairUnattendedSession29212 extends Command
{
    protected $signature = 'repair:unattended-session-29212
                            {--dry-run}
                            {--execute}
                            {--force}
                            {--snapshot=}
                            {--actor=}
                            {--actor-user-id=4}';

    protected $description = 'Revert the allowlisted 2026-08-28 翟君和 session to scheduled';

    private const REF = 'in-app #target-29212-unattended-2026-08-28';

    private const TARGET = [
        'student_id' => 9,
        'class_id' => 3112,
        'session_id' => 29212,
        'subject_id' => 70,
        'date' => '2026-08-28',
        'start' => '13:00',
        'end' => '15:00',
        'from_status' => 'attended',
        'to_status' => 'scheduled',
    ];

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Choose exactly one of --dry-run or --execute.');
            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        if ($execute && ! $this->productionAllowed()) {
            return self::FAILURE;
        }

        $plan = $this->plan();
        $this->line($execute
            ? '=== EXECUTE repair:unattended-session-29212 ==='
            : '=== DRY RUN repair:unattended-session-29212 ===');
        $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (! $execute || ($plan['error'] !== null) || $plan['already_aligned']) {
            return $plan['error'] === null ? self::SUCCESS : self::FAILURE;
        }

        $snapshotPath = (string) ($this->option('snapshot') ?: storage_path(
            'app/repair-snapshots/unattended-session-29212-' . now()->format('YmdHis') . '.json'
        ));
        if (! is_dir(dirname($snapshotPath))) {
            mkdir(dirname($snapshotPath), 0755, true);
        }
        file_put_contents($snapshotPath, json_encode([
            'case' => self::REF,
            'generated_at' => now()->toIso8601String(),
            'plan' => $plan,
            'before' => $this->snapshotTargetRows(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        $this->line("Snapshot: {$snapshotPath}");

        try {
            $result = DB::transaction(function (): array {
                $target = self::TARGET;
                /** @var ClassSession|null $session */
                $session = ClassSession::query()->whereKey($target['session_id'])->lockForUpdate()->first();
                /** @var StudentClass|null $course */
                $course = StudentClass::query()->whereKey($target['class_id'])->lockForUpdate()->first();
                if (! $session || ! $course || ! $this->matchesTarget($session, $course)) {
                    throw new \RuntimeException('TARGET_CHANGED_BEFORE_APPLY');
                }
                $currentStatus = strtolower((string) $session->getAttribute('Status'));
                if (! in_array($currentStatus, [$target['from_status'], $target['to_status']], true)) {
                    throw new \RuntimeException('TARGET_STATUS_CHANGED_BEFORE_APPLY');
                }

                $before = $this->snapshotRows($session, $course);
                $reason = self::REF . ' — 主任確認該堂未上課';
                CourseLeaveCascadeService::voidLiveArtifactsForNonAttendance(
                    (int) $session->id,
                    '由已上調整狀態',
                    $this->actorId()
                );
                SessionDeductionService::reverseForSession(
                    (int) $course->getKey(),
                    (int) $session->id,
                    'status_adjust',
                    $this->actorId(),
                    $reason
                );

                $session->setAttribute('Status', $target['to_status']);
                $session->setAttribute('Note', $this->appendNote($session->getAttribute('Note'), 'revert-to-scheduled'));
                $session->save();
                SessionDeductionService::recomputeCounters((int) $course->getKey());

                $branchId = (int) (DB::table('Student')->where('id', $target['student_id'])->value('CampusID') ?? 0);
                ScheduleAuditLog::query()->create([
                    'session_id' => $session->id,
                    'action_type' => 'update',
                    'description' => '資料修復：' . $reason,
                    'operator_id' => $this->actorId(),
                    'branch_id' => $branchId ?: null,
                    'old_data' => $before['session'],
                    'new_data' => $this->sessionSnapshot($session->fresh()),
                ]);

                $after = $this->plan();
                if ($after['error'] !== null || ! $after['already_aligned']) {
                    throw new \RuntimeException('POSTCONDITION_FAILED');
                }

                return [
                    'status' => 'repaired',
                    'session_id' => $session->id,
                    'active_learning_records_after' => $after['active_learning_records'],
                    'active_sign_ins_after' => $after['active_sign_ins'],
                    'session_ledger_net_after' => $after['session_ledger_net'],
                    'used_sessions_after' => (int) $course->fresh()->getAttribute('UsedSessions'),
                    'remaining_sessions_after' => (int) $course->fresh()->getAttribute('RemainingSessions'),
                ];
            });
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function plan(): array
    {
        $target = self::TARGET;
        /** @var ClassSession|null $session */
        $session = ClassSession::query()->find($target['session_id']);
        /** @var StudentClass|null $course */
        $course = StudentClass::query()->find($target['class_id']);
        $identityMatches = $session && $course && $this->matchesTarget($session, $course);
        $status = $session ? strtolower((string) $session->getAttribute('Status')) : null;
        $activeLearningRecords = (int) LearningRecord::query()
            ->where('ClassSessionID', $target['session_id'])->whereNull('VoidedAt')->count();
        $activeSignIns = (int) StudentSignIn::query()
            ->where('ClassSessionID', $target['session_id'])->whereNull('VoidedAt')->count();
        $sessionLedgerNet = (int) (DB::table('session_deduction_ledger')
            ->where('student_class_id', $target['class_id'])
            ->where('class_session_id', $target['session_id'])
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type = 'deduct' THEN 1 WHEN event_type = 'reverse' THEN -1 ELSE 0 END), 0) as net")
            ->value('net') ?? 0);

        $alreadyAligned = $identityMatches
            && $status === $target['to_status']
            && $activeLearningRecords === 0
            && $activeSignIns === 0
            && $sessionLedgerNet === 0;
        $error = null;
        if (! $session || ! $course || ! $identityMatches) {
            $error = 'TARGET_IDENTITY_DRIFT';
        } elseif (! $alreadyAligned && $status !== $target['from_status'] && $status !== $target['to_status']) {
            $error = 'TARGET_STATUS_UNEXPECTED';
        }

        return [
            'target' => $target,
            'error' => $error,
            'already_aligned' => $alreadyAligned,
            'current_status' => $status,
            'active_learning_records' => $activeLearningRecords,
            'active_sign_ins' => $activeSignIns,
            'session_ledger_net' => $sessionLedgerNet,
            'course_used_sessions' => $course ? (int) $course->getAttribute('UsedSessions') : null,
            'course_remaining_sessions' => $course ? (int) $course->getAttribute('RemainingSessions') : null,
        ];
    }

    private function matchesTarget(ClassSession $session, StudentClass $course): bool
    {
        $target = self::TARGET;
        return (int) $course->getAttribute('StudentID') === $target['student_id']
            && (int) $course->getAttribute('SubjectID') === $target['subject_id']
            && (int) $session->getAttribute('StudentClassID') === $target['class_id']
            && substr((string) $session->getAttribute('SessionDate'), 0, 10) === $target['date']
            && substr((string) $session->getAttribute('StartTime'), 0, 5) === $target['start']
            && substr((string) $session->getAttribute('EndTime'), 0, 5) === $target['end'];
    }

    /** @return array<string,mixed> */
    private function snapshotRows(ClassSession $session, StudentClass $course): array
    {
        $target = self::TARGET;
        return [
            'session' => $this->sessionSnapshot($session),
            'course' => (array) $course->getAttributes(),
            'learning_records' => DB::table('LearningRecord')->where('ClassSessionID', $target['session_id'])->get()->map(fn ($row) => (array) $row)->all(),
            'student_sign_ins' => DB::table('StudentSingIn')->where('ClassSessionID', $target['session_id'])->get()->map(fn ($row) => (array) $row)->all(),
            'ledger' => DB::table('session_deduction_ledger')->where('class_session_id', $target['session_id'])->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotTargetRows(): array
    {
        $target = self::TARGET;
        /** @var ClassSession|null $session */
        $session = ClassSession::query()->find($target['session_id']);
        /** @var StudentClass|null $course */
        $course = StudentClass::query()->find($target['class_id']);

        if (! $session || ! $course) {
            return [];
        }

        return $this->snapshotRows($session, $course);
    }

    /** @return array<string,mixed> */
    private function sessionSnapshot(ClassSession $session): array
    {
        return $session->only([
            'id', 'StudentClassID', 'SubjectID', 'SessionDate', 'StartTime', 'EndTime',
            'Status', 'Note', 'IsContractException', 'session_charge',
        ]);
    }

    private function appendNote(?string $existing, string $suffix): string
    {
        $base = trim((string) $existing);
        if ($base === '') return $suffix;
        if (str_contains($base, $suffix)) return $base;
        return $base . '; ' . $suffix;
    }

    private function actorId(): ?int
    {
        $id = (int) $this->option('actor-user-id');
        return $id > 0 ? $id : null;
    }

    private function productionAllowed(): bool
    {
        if (! app()->environment('production')) return true;
        if (! $this->option('force') || env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires --force and ALLOW_PROD_REPAIR=1');
            return false;
        }
        return true;
    }
}
