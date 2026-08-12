<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\SessionEntitlementTransfer;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Controlled correction for a completed prepaid lesson assigned to the wrong
 * purchase batch. This is deliberately separate from rescheduling: date/time,
 * attendance, evaluation and the deduction event remain the same lesson.
 */
final class SessionEntitlementTransferService
{
    private const MOVABLE_STATUSES = ['attended', 'completed', 'late', 'absent'];

    /** @return array<string,mixed> */
    public function preview(int $sourceClassId, int $targetClassId, int $sessionId): array
    {
        $source = $this->studentClass($sourceClassId);
        $target = $this->studentClass($targetClassId);
        $session = $this->classSession($sessionId);

        $errors = [];
        if (!$source) $errors[] = '來源課程不存在';
        if (!$target) $errors[] = '目標批次不存在';
        if (!$session) $errors[] = '堂次不存在';

        if ($source && $target) {
            if ((int) $source->getAttribute('StudentID') !== (int) $target->getAttribute('StudentID')) $errors[] = '來源與目標不是同一位學生';
            if ((int) $source->getAttribute('SubjectID') !== (int) $target->getAttribute('SubjectID')) $errors[] = '來源與目標不是同一科目';
            if ((int) ($source->getAttribute('PackageID') ?? 0) > 0 || (int) ($target->getAttribute('PackageID') ?? 0) > 0) $errors[] = '共用方案不可使用此修復路徑';
            if ((string) ($source->getAttribute('ScheduleMode') ?? 'count') !== 'count' || (string) ($target->getAttribute('ScheduleMode') ?? 'count') !== 'count') $errors[] = '只有堂數制批次可轉移';
        }

        if ($session && $source && (int) $session->getAttribute('StudentClassID') !== $sourceClassId) $errors[] = '堂次不屬於來源課程';
        if ($session && !in_array(strtolower((string) $session->getAttribute('Status')), self::MOVABLE_STATUSES, true)) $errors[] = '只有已上課堂次可轉移';

        $existing = SessionEntitlementTransfer::query()
            ->where('class_session_id', $sessionId)
            ->first();
        if ($existing) $errors[] = '此堂次已有轉移歷史，禁止重複轉移';

        if ($session && $target) {
            $collision = ClassSession::query()
                ->where('StudentClassID', $targetClassId)
                ->whereDate('SessionDate', substr((string) $session->getAttribute('SessionDate'), 0, 10))
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [substr((string) $session->getAttribute('StartTime'), 0, 5)])
                ->whereNotIn('Status', CourseLeaveCascadeService::NON_BILLABLE_STATUSES)
                ->where('id', '!=', $sessionId)
                ->first();
            if ($collision) $errors[] = '目標批次已有同日同時段堂次，禁止製造重複堂';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'source' => $source ? ['id' => $sourceClassId, 'student_id' => (int) $source->getAttribute('StudentID'), 'used' => (int) $source->getAttribute('UsedSessions'), 'remaining' => (int) $source->getAttribute('RemainingSessions')] : null,
            'target' => $target ? ['id' => $targetClassId, 'student_id' => (int) $target->getAttribute('StudentID'), 'used' => (int) $target->getAttribute('UsedSessions'), 'remaining' => (int) $target->getAttribute('RemainingSessions')] : null,
            'session' => $session ? ['id' => $sessionId, 'date' => substr((string) $session->getAttribute('SessionDate'), 0, 10), 'start_time' => substr((string) $session->getAttribute('StartTime'), 0, 5), 'status' => (string) $session->getAttribute('Status')] : null,
            'will_change' => $errors === [] ? ['ClassSession', 'LearningRecord', 'StudentSingIn', 'session_deduction_ledger'] : [],
            'will_not_change' => ['Invoice', 'Payment', 'PaymentReport', 'student identity'],
        ];
    }

    /** @return array{transfer:SessionEntitlementTransfer,source:StudentClass,target:StudentClass} */
    public function transfer(
        int $sourceClassId,
        int $targetClassId,
        int $sessionId,
        string $reason,
        string $decisionReference,
        ?int $decidedByUserId = null,
        ?string $actor = null
    ): array {
        return DB::transaction(function () use ($sourceClassId, $targetClassId, $sessionId, $reason, $decisionReference, $decidedByUserId, $actor) {
            $source = $this->lockedStudentClass($sourceClassId);
            $target = $this->lockedStudentClass($targetClassId);
            $session = $this->lockedClassSession($sessionId);

            $plan = $this->preview($sourceClassId, $targetClassId, $sessionId);
            if (!$plan['ok']) {
                throw new RuntimeException('ENTITLEMENT_TRANSFER_BLOCKED: ' . implode('; ', $plan['errors']));
            }

            $before = $this->snapshot($source, $target, $session);

            // Update all records by the stable ClassSession identity. We do not
            // recreate the lesson and do not touch billing/receipts.
            $session->setAttribute('StudentClassID', $targetClassId);
            $session->save();

            LearningRecord::query()->where('ClassSessionID', $sessionId)->update(['StudentClassID' => $targetClassId, 'updated_at' => now()]);
            StudentSignIn::query()->where('ClassSessionID', $sessionId)->update(['StudentClassID' => $targetClassId]);
            SessionDeductionLedger::query()->where('class_session_id', $sessionId)->update(['student_class_id' => $targetClassId, 'updated_at' => now()]);

            SessionDeductionService::recomputeCounters($sourceClassId);
            SessionDeductionService::recomputeCounters($targetClassId);
            $source->refresh();
            $target->refresh();

            $after = $this->snapshot($source, $target, $session->refresh());
            $transfer = new SessionEntitlementTransfer();
            $transfer->fill([
                'class_session_id' => $sessionId,
                'source_student_class_id' => $sourceClassId,
                'target_student_class_id' => $targetClassId,
                'reason' => $reason,
                'decision_reference' => $decisionReference,
                'decided_by_user_id' => $decidedByUserId,
                'decided_by_actor' => $actor,
                'transferred_at' => now(),
                'snapshot_before' => $before,
                'snapshot_after' => $after,
            ]);
            $transfer->save();

            $verification = $this->verify((int) $transfer->getKey());
            if (!$verification['ok']) {
                throw new RuntimeException('ENTITLEMENT_TRANSFER_VERIFY_FAILED: ' . implode('; ', $verification['errors']));
            }

            return compact('transfer', 'source', 'target');
        });
    }

    public function rollback(int $transferId, ?int $actorUserId = null, ?string $actor = null): SessionEntitlementTransfer
    {
        return DB::transaction(function () use ($transferId, $actorUserId, $actor) {
            $transfer = $this->lockedTransfer($transferId);
            if ($transfer->getAttribute('rolled_back_at') !== null) return $transfer;

            $sourceId = (int) $transfer->getAttribute('source_student_class_id');
            $targetId = (int) $transfer->getAttribute('target_student_class_id');
            $sessionId = (int) $transfer->getAttribute('class_session_id');
            $source = $this->lockedStudentClass($sourceId);
            $target = $this->lockedStudentClass($targetId);
            $session = $this->lockedClassSession($sessionId);
            if ((int) $session->getAttribute('StudentClassID') !== $targetId) {
                throw new RuntimeException('ENTITLEMENT_TRANSFER_ROLLBACK_BLOCKED: session ownership drifted');
            }
            $current = $this->snapshot($source, $target, $session);
            $expected = $transfer->getAttribute('snapshot_after') ?? [];
            if (!$this->linkedRowsMatch($current, $expected, 'target')) {
                throw new RuntimeException('ENTITLEMENT_TRANSFER_ROLLBACK_BLOCKED: linked attendance rows drifted');
            }

            $session->setAttribute('StudentClassID', $sourceId);
            $session->save();
            LearningRecord::query()->where('ClassSessionID', $sessionId)->update(['StudentClassID' => $sourceId, 'updated_at' => now()]);
            StudentSignIn::query()->where('ClassSessionID', $sessionId)->update(['StudentClassID' => $sourceId]);
            SessionDeductionLedger::query()->where('class_session_id', $sessionId)->update(['student_class_id' => $sourceId, 'updated_at' => now()]);

            SessionDeductionService::recomputeCounters($sourceId);
            SessionDeductionService::recomputeCounters($targetId);
            $transfer->setAttribute('rolled_back_at', now());
            $transfer->setAttribute('rolled_back_by_user_id', $actorUserId);
            $transfer->setAttribute('rolled_back_by_actor', $actor);
            $transfer->save();

            return $transfer;
        });
    }

    /** @return array<string,mixed> */
    public function verify(int $transferId): array
    {
        $transfer = $this->transferRecord($transferId);
        if (!$transfer) {
            return ['ok' => false, 'errors' => ['轉移紀錄不存在'], 'transfer_id' => $transferId];
        }

        $sourceId = (int) $transfer->getAttribute('source_student_class_id');
        $targetId = (int) $transfer->getAttribute('target_student_class_id');
        $sessionId = (int) $transfer->getAttribute('class_session_id');
        $source = $this->studentClass($sourceId);
        $target = $this->studentClass($targetId);
        $session = $this->classSession($sessionId);
        $errors = [];
        if (!$source || !$target || !$session) {
            $errors[] = '來源、目標或堂次資料不存在';
        }

        $expectedOwner = $transfer->getAttribute('rolled_back_at') === null ? $targetId : $sourceId;
        if ($session && (int) $session->getAttribute('StudentClassID') !== $expectedOwner) {
            $errors[] = 'ClassSession 所屬批次不符';
        }

        if ($session && $source && $target) {
            $current = $this->snapshot($source, $target, $session);
            $expected = $transfer->getAttribute('rolled_back_at') === null
                ? ($transfer->getAttribute('snapshot_after') ?? [])
                : ($transfer->getAttribute('snapshot_before') ?? []);
            if (!$this->linkedRowsMatch($current, $expected, $expectedOwner === $targetId ? 'target' : 'source')) {
                $errors[] = '學習紀錄、點名或扣堂帳所屬批次不一致';
            }

            $diagnostics = SessionDeductionService::batchExpectedUsedSessionDiagnostics([(int) $source->getKey(), (int) $target->getKey()]);
            foreach ([$source, $target] as $course) {
                $courseId = (int) $course->getKey();
                $expectedUsed = $diagnostics[$courseId]['expected_used'] ?? null;
                if ($expectedUsed !== null && (int) $course->getAttribute('UsedSessions') !== (int) $expectedUsed) {
                    $errors[] = "課程 {$courseId} UsedSessions 未與權威計算一致";
                }
                if ($expectedUsed !== null && (int) $course->getAttribute('RemainingSessions') !== max(0, (int) $course->getAttribute('SessionCount') - (int) $expectedUsed)) {
                    $errors[] = "課程 {$courseId} RemainingSessions 未與權威計算一致";
                }
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'transfer_id' => $transferId,
            'session_id' => $sessionId,
            'source_class' => $sourceId,
            'target_class' => $targetId,
            'rolled_back_at' => $transfer->getAttribute('rolled_back_at'),
        ];
    }

    /** @return array<string,mixed> */
    public function snapshotFor(int $sourceClassId, int $targetClassId, int $sessionId): array
    {
        $source = $this->requireStudentClass($sourceClassId);
        $target = $this->requireStudentClass($targetClassId);
        $session = $this->requireClassSession($sessionId);

        return $this->snapshot($source, $target, $session);
    }

    /** @return array<string,mixed> */
    private function snapshot(StudentClass $source, StudentClass $target, ClassSession $session): array
    {
        return [
            'source' => ['id' => (int) $source->getKey(), 'used' => (int) $source->getAttribute('UsedSessions'), 'remaining' => (int) $source->getAttribute('RemainingSessions'), 'stop' => (int) $source->getAttribute('Stop')],
            'target' => ['id' => (int) $target->getKey(), 'used' => (int) $target->getAttribute('UsedSessions'), 'remaining' => (int) $target->getAttribute('RemainingSessions'), 'stop' => (int) $target->getAttribute('Stop')],
            'session' => ['id' => (int) $session->getKey(), 'student_class_id' => (int) $session->getAttribute('StudentClassID'), 'status' => (string) $session->getAttribute('Status'), 'date' => substr((string) $session->getAttribute('SessionDate'), 0, 10), 'start_time' => substr((string) $session->getAttribute('StartTime'), 0, 5)],
            'learning_records' => LearningRecord::query()->where('ClassSessionID', $session->getKey())->pluck('StudentClassID', 'id')->map(fn ($v) => (int) $v)->all(),
            'sign_ins' => StudentSignIn::query()->where('ClassSessionID', $session->getKey())->pluck('StudentClassID', 'id')->map(fn ($v) => (int) $v)->all(),
            'ledger' => SessionDeductionLedger::query()->where('class_session_id', $session->getKey())->pluck('student_class_id', 'id')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function linkedRowsMatch(array $snapshot, array $expected, string $owner): bool
    {
        $expectedOwner = $owner === 'target'
            ? (int) ($expected['target']['id'] ?? 0)
            : (int) ($expected['source']['id'] ?? 0);
        if ($expectedOwner <= 0) return false;

        foreach (['learning_records', 'sign_ins', 'ledger'] as $key) {
            $currentRows = $snapshot[$key] ?? [];
            $expectedRows = $expected[$key] ?? [];
            $currentIds = array_map('strval', array_keys($currentRows));
            $expectedIds = array_map('strval', array_keys($expectedRows));
            sort($currentIds, SORT_STRING);
            sort($expectedIds, SORT_STRING);
            if ($currentIds !== $expectedIds) return false;
            foreach ($currentRows as $rowOwner) {
                if ((int) $rowOwner !== $expectedOwner) return false;
            }
        }

        return true;
    }

    private function studentClass(int $id): ?StudentClass
    {
        $model = StudentClass::query()->where('ID', $id)->first();
        return $model instanceof StudentClass ? $model : null;
    }

    private function classSession(int $id): ?ClassSession
    {
        $model = ClassSession::query()->where('id', $id)->first();
        return $model instanceof ClassSession ? $model : null;
    }

    private function transferRecord(int $id): ?SessionEntitlementTransfer
    {
        $model = SessionEntitlementTransfer::query()->where('id', $id)->first();
        return $model instanceof SessionEntitlementTransfer ? $model : null;
    }

    private function requireStudentClass(int $id): StudentClass
    {
        return $this->studentClass($id) ?? throw new RuntimeException("StudentClass {$id} not found");
    }

    private function requireClassSession(int $id): ClassSession
    {
        return $this->classSession($id) ?? throw new RuntimeException("ClassSession {$id} not found");
    }

    private function lockedStudentClass(int $id): StudentClass
    {
        $model = StudentClass::query()->where('ID', $id)->lockForUpdate()->first();
        if (!$model instanceof StudentClass) throw new RuntimeException("StudentClass {$id} not found");
        return $model;
    }

    private function lockedClassSession(int $id): ClassSession
    {
        $model = ClassSession::query()->where('id', $id)->lockForUpdate()->first();
        if (!$model instanceof ClassSession) throw new RuntimeException("ClassSession {$id} not found");
        return $model;
    }

    private function lockedTransfer(int $id): SessionEntitlementTransfer
    {
        $model = SessionEntitlementTransfer::query()->where('id', $id)->lockForUpdate()->first();
        if (!$model instanceof SessionEntitlementTransfer) throw new RuntimeException("SessionEntitlementTransfer {$id} not found");
        return $model;
    }
}
