<?php

namespace App\Services;

use App\Exceptions\SessionContractRecoveryException;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SecurityAuditEvent;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Controlled repair for cancelled sessions with materialized evidence. */
final class SessionContractRecoveryService
{
    private const MOVABLE_STATUSES = ['attended', 'completed', 'late'];
    /** @param array<int, int> $sessionIds */
    public function recoverAndTransfer(
        int $sourceClassId,
        int $targetClassId,
        array $sessionIds,
        string $reason,
        ?int $actorUserId = null
    ): array {
        return DB::transaction(function () use ($sourceClassId, $targetClassId, $sessionIds, $reason, $actorUserId) {
            $source = StudentClass::query()->where('ID', $sourceClassId)->lockForUpdate()->firstOrFail();
            $target = StudentClass::query()->where('ID', $targetClassId)->lockForUpdate()->firstOrFail();
            $this->assertCoursesCompatible($source, $target);
            $sessions = ClassSession::query()
                ->where('StudentClassID', $sourceClassId)
                ->whereIn('id', $sessionIds)
                ->lockForUpdate()
                ->get();
            $foundIds = $sessions->pluck('id')->map(fn ($id) => (int) $id)->all();
            $missing = array_values(array_diff($sessionIds, $foundIds));
            if ($missing !== []) {
                throw new SessionContractRecoveryException(
                    '部分堂次不存在於來源課程，未執行任何恢復或轉移。',
                    ['errors' => ['session_ids' => ['不存在的堂次 id: ' . implode(',', $missing)]]]
                );
            }
            $evidence = $this->loadEvidence($sessions);
            $blocked = [];
            $recoveredIds = [];
            foreach ($sessions as $session) {
                $status = strtolower((string) $session->getAttribute('Status'));
                if (in_array($status, self::MOVABLE_STATUSES, true)) {
                    continue;
                }
                if ($status === 'cancelled' && $evidence[(int) $session->id]['has_evidence']) {
                    $recoveredIds[] = (int) $session->id;
                    continue;
                }
                $blocked[] = (int) $session->id;
            }
            if ($blocked !== []) {
                throw new SessionContractRecoveryException(
                    '只有已上課，或仍保留評量／點名證據的已取消堂次，才能使用恢復移轉；真正未上課或已取消且無證據的堂次仍不可移轉。',
                    ['errors' => ['session_ids' => ['不可恢復或轉移堂次：' . implode(', ', $blocked)]]]
                );
            }
            $conflicts = $this->targetSlotConflicts($targetClassId, $sessions);
            if ($conflicts->isNotEmpty()) {
                $dates = $conflicts->pluck('date')->unique()->values()->implode('、');
                throw new SessionContractRecoveryException(
                    "目標課程已有 {$dates} 的同時段堂次，未執行任何恢復或轉移。",
                    [
                        'code' => 'target_slot_conflict',
                        'conflicts' => $conflicts->values()->all(),
                    ]
                );
            }
            foreach ($sessions as $session) {
                $sessionId = (int) $session->id;
                $isCancelled = strtolower((string) $session->getAttribute('Status')) === 'cancelled';
                $session->setAttribute('StudentClassID', $targetClassId);
                if ($isCancelled) {
                    $session->setAttribute('Status', 'attended');
                }
                $session->save();
                $this->moveEvidence($sessionId, $targetClassId, $isCancelled);
                SessionDeductionLedger::query()
                    ->where('class_session_id', $sessionId)
                    ->update(['student_class_id' => $targetClassId, 'updated_at' => now()]);
            }
            SessionDeductionService::recomputeCounters($sourceClassId);
            SessionDeductionService::recomputeCounters($targetClassId);
            SecurityAuditEvent::append(
                'student_class.cancelled_session_recovered',
                'success',
                [
                    'campus_id' => $source->student?->CampusID,
                    'actor_type' => 'user',
                    'actor_id' => $actorUserId,
                    'subject_type' => 'student_class',
                    'subject_id' => $sourceClassId,
                ],
                [
                    'reason_code' => 'evidence_backed_cancelled_session_recovery',
                    'reason_hash' => hash('sha256', $reason),
                    'transferred_session_count' => count($foundIds),
                    'recovered_session_count' => count($recoveredIds),
                    'outcome' => 'success',
                ]
            );
            return [
                'transferred_session_ids' => $foundIds,
                'recovered_session_ids' => array_values($recoveredIds),
            ];
        });
    }

    /** @return array<int, array{has_evidence: bool}> */
    private function loadEvidence(Collection $sessions): array
    {
        $ids = $sessions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $learningRecordIds = LearningRecord::query()
            ->whereIn('ClassSessionID', $ids)
            ->pluck('ClassSessionID')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
        $signInIds = StudentSignIn::query()
            ->whereIn('ClassSessionID', $ids)
            ->pluck('ClassSessionID')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
        $learningSet = array_fill_keys($learningRecordIds, true);
        $signInSet = array_fill_keys($signInIds, true);
        $evidence = [];
        foreach ($ids as $id) {
            $evidence[$id] = [
                'has_evidence' => isset($learningSet[$id]) || isset($signInSet[$id]),
            ];
        }
        return $evidence;
    }
    private function moveEvidence(int $sessionId, int $targetClassId, bool $restoreVoided): void
    {
        $records = LearningRecord::query()
            ->where('ClassSessionID', $sessionId)
            ->lockForUpdate()
            ->get();
        foreach ($records as $record) {
            $record->setAttribute('StudentClassID', $targetClassId);
            if ($restoreVoided && $record->getAttribute('VoidedAt') !== null) {
                $record->setAttribute('VoidedAt', null);
                $record->setAttribute('VoidedByUserID', null);
                $record->setAttribute('VoidReason', null);
                if (!$record->getAttribute('Status')) {
                    $record->setAttribute('Status', 'pending');
                }
            }
            $record->save();
        }
        $signIns = StudentSignIn::query()
            ->where('ClassSessionID', $sessionId)
            ->lockForUpdate()
            ->get();
        foreach ($signIns as $signIn) {
            $signIn->setAttribute('StudentClassID', $targetClassId);
            if ($restoreVoided && $signIn->getAttribute('VoidedAt') !== null) {
                $signIn->setAttribute('VoidedAt', null);
                $signIn->setAttribute('VoidedByUserID', null);
                $signIn->setAttribute('VoidReason', null);
            }
            $signIn->save();
        }
    }
    private function assertCoursesCompatible(StudentClass $source, StudentClass $target): void
    {
        if ((int) $source->ID === (int) $target->ID) {
            $this->blocked('來源課程與目標課程不可相同。');
        }
        if ((int) $source->StudentID !== (int) $target->StudentID) {
            $this->blocked('目標課程與來源課程的學生不一致，拒絕恢復移轉。');
        }
        if ((int) ($source->SubjectID ?? 0) !== (int) ($target->SubjectID ?? 0)) {
            $this->blocked('目標課程與來源課程的科目不一致，拒絕恢復移轉。');
        }
        if ($source->hasDeductionHistory() && (string) $source->getAttribute('closed_reason') === 'usage_settled') {
            $this->blocked('來源課程已提前結清，堂次與紀錄已鎖定，無法恢復移轉。');
        }
    }
    private function blocked(string $message): never
    {
        throw new SessionContractRecoveryException($message);
    }

    /** @return Collection<int, array{session_id: int, date: string, start_time: string}> */
    private function targetSlotConflicts(int $targetClassId, Collection $sessions): Collection
    {
        return DB::table('ClassSession')
            ->where('StudentClassID', $targetClassId)
            ->where(function ($query) {
                $query->whereNull('Status')->orWhere('Status', '!=', 'cancelled');
            })
            ->where(function ($query) use ($sessions) {
                foreach ($sessions as $index => $session) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($slot) use ($session) {
                        $slot->whereDate('SessionDate', $session->SessionDate)
                            ->where('StartTime', $session->StartTime);
                    });
                }
            })
            ->get(['id', 'SessionDate', 'StartTime'])
            ->map(fn ($row) => [
                'session_id' => (int) $row->id,
                'date' => substr((string) $row->SessionDate, 0, 10),
                'start_time' => substr((string) $row->StartTime, 0, 5),
            ]);
    }
}
