<?php

namespace App\Services\Scheduling;

use App\Models\ClassSession;
use App\Models\ClassSessionReassignment;
use App\Models\LearningRecord;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\SessionDeductionService;
use App\Support\LearningRecordMutableOwnership;
use Illuminate\Support\Facades\DB;

/**
 * Moves one session (+ sign-in / ledger / learning record) to another contract of the same
 * student+subject, closed ones included (raw query builder: Eloquent guards block settled
 * contracts). Counters via SessionDeductionService; money untouched. `$dryRun` rolls back after
 * computing before/after. Moving back is the undo. Shared by the calendar's 改到其他合約 and
 * exact-case POP repairs (#4554); the caller checks access.
 */
final class ClassSessionContractReassigner
{
    public const STATUSES = ['attended', 'completed', 'late', 'absent', 'leave', 'scheduled', 'cancelled'];

    /**
     * @return array{error: string}|array{locked: true}|array{old_student_class_id: int, new_student_class_id: int, before: array<string, mixed>, after: array<string, mixed>, warnings: list<string>}
     */
    public function reassign(int $sessionId, int $newId, string $reason, ?int $actorId, bool $dryRun): array
    {
        DB::beginTransaction();
        try {
            $session = ClassSession::query()->where('id', $sessionId)->lockForUpdate()->first();
            $oldId = (int) ($session->StudentClassID ?? 0);
            $contracts = StudentClass::query()->whereIn('ID', [$oldId, $newId])->orderBy('ID')->lockForUpdate()->get()->keyBy('ID');
            $old = $contracts->get($oldId);
            $new = $contracts->get($newId);

            $error = null;
            if (!$old) {
                $error = '找不到堂次目前所屬的課程合約';
            } elseif (!$new) {
                $error = '找不到目標課程合約';
            } elseif ($newId === $oldId) {
                $error = '目標課程合約與目前課程合約相同，無需改派。';
            } elseif (in_array('waived', [(string) $old->getAttribute('closed_reason'), (string) $new->getAttribute('closed_reason')], true)) {
                $error = '確認不收的合約不能改派堂次。';
            } elseif ((int) $new->getAttribute('StudentID') !== (int) $old->getAttribute('StudentID') || (int) $new->getAttribute('SubjectID') !== (int) $old->getAttribute('SubjectID')) {
                $error = '新舊課程合約的學生或科目不一致，拒絕改派。';
            } elseif (($lockedLr = LearningRecordMutableOwnership::protectedRecordForSession((int) $session->id, true))
                && (int) $lockedLr->TeacherID !== (int) $new->getAttribute('TeacherID')) {
                // #3824: compare the assessment's recorded teacher, not the two contract defaults (review r4247040032).
                DB::rollBack();

                return ['locked' => true]; // #3824
            } elseif (!in_array(strtolower((string) $session->Status), self::STATUSES, true)) {
                $error = '此堂次狀態（' . $session->Status . '）不支援改派合約。';
            } elseif (strtolower((string) $session->Status) !== 'cancelled' && ClassSession::query()->where('StudentClassID', $newId)
                ->whereDate('SessionDate', $session->SessionDate)
                ->where('StartTime', $session->StartTime)
                ->where('id', '!=', $session->id)
                ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'cancelled'))
                ->exists()) {
                $error = '目標合約同一日期／時段已有其他有效堂次，拒絕改派。';
            }
            if ($error) {
                DB::rollBack();

                return ['error' => $error];
            }

            $before = ['old' => self::summary($old), 'new' => self::summary($new)];
            $stops = [$oldId => $old->getAttribute('Stop'), $newId => $new->getAttribute('Stop')];

            DB::table('ClassSession')->where('id', $session->id)->update(['StudentClassID' => $newId, 'updated_at' => now()]);
            DB::table('StudentSingIn')->where('ClassSessionID', $session->id)->update(['StudentClassID' => $newId]);
            DB::table('session_deduction_ledger')->where('class_session_id', $session->id)->update(['student_class_id' => $newId]);
            // #3824 Founder GO: a filled-but-unapproved assessment follows the new contract's teacher via snapshot + refill.
            $lrBefore = LearningRecord::query()->where('ClassSessionID', $session->id)->whereNull('VoidedAt')->lockForUpdate()->first();
            $newContractTeacher = (int) $new->getAttribute('TeacherID');
            // An outstanding refill (the LR was snapshotted, body still empty) follows every later transfer. Matching any snapshot,
            // not one naming the current teacher, keeps A→B→C→D working when B and C never refilled (#4663).
            $pendingRefill = $lrBefore && (int) $lrBefore->TeacherID !== $newContractTeacher && strtolower((string) $lrBefore->Status) !== 'approved'
                && !LearningRecordMutableOwnership::hasSubstantiveTeacherContent($lrBefore) // B already wrote: fall through to a new snapshot
                && DB::table('learning_record_author_snapshots')->where('learning_record_id', $lrBefore->id)->exists();
            if ($lrBefore && ($pendingRefill || \App\Services\AssessmentAuthorSnapshot::sendBackForRefill((int) $session->id, (int) $new->getAttribute('TeacherID'), (int) $actorId ?: null, $reason))) {
                DB::table('LearningRecord')->where('id', $lrBefore->id)->update(['TeacherID' => (int) $new->getAttribute('TeacherID')]);
                DB::table('learning_record_teacher_changes')->insert([
                    'learning_record_id' => (int) $lrBefore->id, 'old_teacher_id' => (int) $lrBefore->TeacherID ?: null,
                    'new_teacher_id' => (int) $new->getAttribute('TeacherID'), 'changed_by' => (int) $actorId,
                    'reason' => 'reassign_contract: ' . mb_substr($reason, 0, 200), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('LearningRecord')->where('ClassSessionID', $session->id)->update(['StudentClassID' => $newId]);
            ClassSession::resetSettlementLockCache();

            SessionDeductionService::recomputeCounters($oldId);
            SessionDeductionService::recomputeCounters($newId);
            foreach ($stops as $cid => $stop) {
                DB::table('StudentClass')->where('ID', $cid)->update(['Stop' => $stop]);
            }
            $after = [
                'old' => self::summary(StudentClass::query()->where('ID', $oldId)->first()),
                'new' => self::summary(StudentClass::query()->where('ID', $newId)->first()),
            ];

            $warnings = [];
            foreach (['old' => '原', 'new' => '目標'] as $k => $label) {
                $rem = $after[$k]['remaining_sessions'];
                if (($after[$k]['closed'] && $rem > 0) || (!$after[$k]['closed'] && $rem < 0)) {
                    $warnings[] = "{$label}合約 #{$after[$k]['id']} " . ($rem > 0 ? "已結束但仍剩 {$rem} 堂" : '剩餘堂數為負') . '，請確認。';
                }
            }

            if ($dryRun) {
                DB::rollBack();
                ClassSession::resetSettlementLockCache();
            } else {
                ClassSessionReassignment::create([
                    'class_session_id' => $session->id,
                    'old_student_class_id' => $oldId,
                    'new_student_class_id' => $newId,
                    'reason' => $reason,
                    'performed_by' => $actorId,
                    'created_at' => now(),
                ]);
                SecurityAuditEvent::append('class_session.contract_reassigned', 'success', [
                    'actor_type' => 'user', 'actor_id' => $actorId,
                    'subject_type' => 'class_session', 'subject_id' => $session->id,
                    'campus_id' => (int) Student::query()->where('id', $old->getAttribute('StudentID'))->value('CampusID'),
                ], ['reason_hash' => sha1($reason), 'source' => "sc{$oldId}->sc{$newId}"]);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            ClassSession::resetSettlementLockCache();
            throw $e;
        }

        return [
            'old_student_class_id' => $oldId,
            'new_student_class_id' => $newId,
            'before' => $before,
            'after' => $after,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(StudentClass $c): array
    {
        $closed = (int) $c->getAttribute('Stop') === 1 || $c->isUsageSettlementLocked() || trim((string) $c->getAttribute('closed_reason')) !== '';

        return [
            'id' => (int) $c->getKey(),
            'start_date' => substr((string) $c->getAttribute('StartDate'), 0, 10) ?: null,
            'end_date' => substr((string) $c->getAttribute('EndDate'), 0, 10) ?: null,
            'session_count' => (int) $c->getAttribute('SessionCount'),
            'used_sessions' => (int) $c->getAttribute('UsedSessions'),
            'remaining_sessions' => (int) $c->getAttribute('RemainingSessions'),
            'closed' => $closed,
        ];
    }
}
