<?php
namespace App\Services;

use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ContractAmendmentService
{
    public const CLOSED_REASON = 'contract_amended';
    private const CANCEL_NOTE = '[合約提前結束取消]';
    public function preview(StudentClass $course, int $newCount): array
    {
        $this->assertRequest($course, $newCount);
        $classId = (int) $course->getKey();
        $diagnostic = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$classId])[$classId] ?? [];
        $used = max((int) ($diagnostic['expected_used'] ?? 0), (int) ($diagnostic['uncapped_used'] ?? 0));
        $newRemaining = max(0, $newCount - $used);
        $future = $this->futureScheduled($classId);
        $futureSchedules = $this->futureSchedules($classId);
        $futureScheduledCount = count($future);
        $futureSchedulesCount = count($futureSchedules);
        return [
            'student_class_id' => $classId,
            'student_id' => (int) $course->getAttribute('StudentID'),
            'subject_id' => (int) ($course->SubjectID ?? 0),
            'original_session_count' => (int) ($course->SessionCount ?? 0),
            'new_session_count' => $newCount,
            'completed_sessions' => $used,
            'original_remaining_sessions' => (int) ($course->RemainingSessions ?? 0),
            'new_remaining_sessions' => $newRemaining,
            'forfeited_sessions' => max(0, (int) ($course->RemainingSessions ?? 0) - $newRemaining),
            'affected_future_scheduled_count' => max(0, $futureScheduledCount - $newRemaining),
            'affected_future_schedules_count' => max(0, $futureSchedulesCount - $newRemaining),
            'affected_future_scheduled' => array_slice($future, $newRemaining),
            'affected_future_schedules' => array_slice($futureSchedules, $newRemaining),
            'closes_contract' => $newRemaining === 0,
            'financial' => $this->financialSummary($classId),
            'financial_mutation' => 'none',
            'financial_note' => '本流程不修改 Charge、Invoice、Payment、PaymentReport、退款或收據；請沿用既有帳務流程處理差額。',
            'executable' => true,
        ];
    }
    public function execute(StudentClass $course, int $newCount, int $actorId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => '提前結束／堂數調整必須填寫原因。']);
        }
        return DB::transaction(function () use ($course, $newCount, $actorId, $reason): array {
            $locked = StudentClass::query()->where('ID', $course->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                throw (new ModelNotFoundException())->setModel(StudentClass::class, [$course->getKey()]);
            }
            $preview = $this->preview($locked, $newCount);
            $before = $this->contractSnapshot($locked);
            $newRemaining = (int) ($preview['new_remaining_sessions'] ?? 0);
            $cancelledIds = [];
            $sessions = ClassSession::query()
                ->where('StudentClassID', $locked->getKey())
                ->where('Status', 'scheduled')
                ->whereDate('SessionDate', '>=', Carbon::today()->toDateString())
                ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
                ->lockForUpdate()->get();
            foreach ($sessions->slice($newRemaining) as $session) {
                $session->Status = 'cancelled';
                $note = trim((string) ($session->Note ?? ''));
                $session->Note = trim($note . ' ' . self::CANCEL_NOTE);
                $session->save();
                $cancelledIds[] = (int) $session->getKey();
            }
            $futureSchedules = Schedule::query()
                ->where('student_course_id', $locked->getKey())
                ->where('status', 'scheduled')
                ->whereDate('schedule_date', '>=', Carbon::today()->toDateString())
                ->orderBy('schedule_date')->orderBy('start_time')->orderBy('id')
                ->lockForUpdate()->get();
            $scheduleIds = $futureSchedules->slice($newRemaining)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($scheduleIds !== []) {
                Schedule::query()->whereIn('id', $scheduleIds)->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);
            }
            $locked->setAttribute('SessionCount', $newCount);
            $locked->setAttribute('UsedSessions', (int) $preview['completed_sessions']);
            $locked->setAttribute('RemainingSessions', $newRemaining);
            if ($newRemaining === 0) {
                $locked->setAttribute('Stop', 1);
                $locked->setAttribute('closed_reason', self::CLOSED_REASON);
                $locked->setAttribute('EndDate', Carbon::today()->toDateString());
            } else {
                $locked->setAttribute('Stop', 0);
                $locked->setAttribute('closed_reason', null);
            }
            $locked->setAttribute('settlement_snapshot', json_encode([
                'kind' => self::CLOSED_REASON,
                'before' => $before,
                'after' => [
                    'session_count' => $newCount,
                    'used_sessions' => (int) $preview['completed_sessions'],
                    'remaining_sessions' => $newRemaining,
                    'stop' => $newRemaining === 0 ? 1 : 0,
                    'closed_reason' => $newRemaining === 0 ? self::CLOSED_REASON : null,
                ],
                'cancelled_session_ids' => $cancelledIds,
                'cancelled_schedule_ids' => $scheduleIds,
                'reason_hash' => hash('sha256', $reason),
                'actor_user_id' => $actorId ?: null,
                'at' => now()->toIso8601String(),
                'financial_mutation' => 'none',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $locked->save();
            SecurityAuditEvent::append(
                'student_class.contract_amendment',
                'success',
                [
                    'actor_type' => 'user',
                    'actor_id' => $actorId ?: null,
                    'subject_type' => 'student_class',
                    'subject_id' => $locked->getKey(),
                    'campus_id' => (int) ($locked->student?->CampusID ?: 0) ?: null,
                ],
                [
                    'old_session_count' => $before['session_count'],
                    'new_session_count' => $newCount,
                    'old_remaining_sessions' => $before['remaining_sessions'],
                    'new_remaining_sessions' => $newRemaining,
                    'reason_code' => self::CLOSED_REASON,
                    'reason_hash' => hash('sha256', $reason),
                    'outcome' => 'success',
                ]
            );
            $message = $newRemaining === 0
                ? "合約已調整為 {$newCount} 堂、剩餘 0 堂；已上課紀錄保留，未來預排已取消。帳務資料未變更。"
                : "合約已調整為 {$newCount} 堂、剩餘 {$newRemaining} 堂；已上課紀錄保留，超額未來預排已取消。帳務資料未變更。";
            return [
                'message' => $message,
                'preview' => $preview,
                'cancelled_session_ids' => $cancelledIds,
                'cancelled_schedule_ids' => $scheduleIds,
                'after' => $locked->fresh()->only(['ID', 'SessionCount', 'UsedSessions', 'RemainingSessions', 'Stop', 'closed_reason', 'EndDate']),
                'financial_mutation' => 'none',
            ];
        });
    }
    private function assertRequest(StudentClass $course, int $newCount): void
    {
        if ((string) ($course->ScheduleMode ?? 'count') !== 'count') {
            throw ValidationException::withMessages(['student_class_id' => '只有按堂課程可使用合約堂數調整。']);
        }
        if ($course->isPartOfPackage()) {
            throw ValidationException::withMessages(['student_class_id' => '共用方案必須使用方案專用流程，不可單獨調整。']);
        }
        if ((int) ($course->Stop ?? 0) === 1 || (string) ($course->closed_reason ?? '') !== '') {
            throw ValidationException::withMessages(['student_class_id' => '此合約已結束或停用，不可再次調整。']);
        }
        $oldCount = (int) ($course->SessionCount ?? 0);
        if ($newCount < 1 || $newCount >= $oldCount) {
            throw ValidationException::withMessages(['new_session_count' => '新總堂數必須小於原總堂數且至少為 1 堂。']);
        }
        $diagnostic = SessionDeductionService::batchExpectedUsedSessionDiagnostics([(int) $course->getKey()])[(int) $course->getKey()] ?? [];
        $used = max((int) ($diagnostic['expected_used'] ?? 0), (int) ($diagnostic['uncapped_used'] ?? 0));
        if ($newCount < $used) {
            throw ValidationException::withMessages(['new_session_count' => "新總堂數不可低於已完成 {$used} 堂。"]);
        }
    }
    private function futureScheduled(int $classId): array
    {
        return ClassSession::query()->where('StudentClassID', $classId)->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', Carbon::today()->toDateString())
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')->get(['id', 'SessionDate', 'StartTime', 'EndTime'])
            ->map(static fn (ClassSession $s): array => [
                'session_id' => (int) $s->getKey(),
                'date' => substr((string) $s->SessionDate, 0, 10),
                'start_time' => substr((string) $s->StartTime, 0, 5),
                'end_time' => substr((string) $s->EndTime, 0, 5),
            ])->values()->all();
    }
    private function futureSchedules(int $classId): array
    {
        return Schedule::query()->where('student_course_id', $classId)->where('status', 'scheduled')
            ->whereDate('schedule_date', '>=', Carbon::today()->toDateString())
            ->orderBy('schedule_date')->orderBy('start_time')->orderBy('id')->get(['id', 'schedule_date', 'start_time', 'end_time'])
            ->map(static fn (Schedule $schedule): array => [
                'schedule_id' => (int) $schedule->getKey(),
                'date' => substr((string) $schedule->getAttribute('schedule_date'), 0, 10),
                'start_time' => substr((string) $schedule->getAttribute('start_time'), 0, 5),
                'end_time' => substr((string) $schedule->getAttribute('end_time'), 0, 5),
            ])->values()->all();
    }

    private function financialSummary(int $classId): array
    {
        return [
            'invoice_count' => (int) DB::table('Invoice')->where('StudentClassID', $classId)->count(),
            'payment_count' => (int) DB::table('Payment')->join('Invoice', 'Invoice.id', '=', 'Payment.InvoiceID')->where('Invoice.StudentClassID', $classId)->count(),
            'payment_report_count' => (int) DB::table('payment_reports')->where('StudentClassID', $classId)->count(),
        ];
    }

    private function contractSnapshot(StudentClass $course): array
    {
        return [
            'student_class_id' => (int) $course->getKey(),
            'session_count' => (int) ($course->SessionCount ?? 0),
            'remaining_sessions' => (int) ($course->RemainingSessions ?? 0),
            'used_sessions' => (int) ($course->UsedSessions ?? 0),
            'charge' => (int) ($course->Charge ?? 0),
            'paid' => (int) ($course->Paid ?? 0),
            'stop' => (int) ($course->Stop ?? 0),
            'closed_reason' => $course->getAttribute('closed_reason'),
            'end_date' => $course->getAttribute('EndDate') ? substr((string) $course->getAttribute('EndDate'), 0, 10) : null,
        ];
    }

    public function revertPreview(StudentClass $course): array
    {
        $snap = $this->assertRevertible($course);
        $before = $snap['before'];
        $sessions = $this->revertibleSessions($snap);
        $schedules = $this->revertibleSchedules($snap);
        $futureCovered = count($this->futureScheduled((int) $course->getKey())) + $sessions->count();
        return [
            'student_class_id' => (int) $course->getKey(),
            'current_session_count' => (int) $course->SessionCount,
            'restored_session_count' => (int) $before['session_count'],
            'current_remaining_sessions' => (int) $course->RemainingSessions,
            'restored_remaining_sessions' => (int) $before['remaining_sessions'],
            'restorable_sessions_count' => $sessions->count(),
            'restorable_schedules_count' => $schedules->count(),
            'unscheduled_remaining_sessions' => max(0, (int) $before['remaining_sessions'] - $futureCovered),
            'reopens_contract' => (int) $before['stop'] === 0,
            'financial_mutation' => 'none',
        ];
    }

    public function revert(StudentClass $course, int $actorId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => '撤銷調整必須填寫原因。']);
        }
        return DB::transaction(function () use ($course, $actorId, $reason): array {
            $locked = StudentClass::query()->where('ID', $course->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                throw (new ModelNotFoundException())->setModel(StudentClass::class, [$course->getKey()]);
            }
            $snap = $this->assertRevertible($locked);
            $before = $snap['before'];
            $sessions = $this->revertibleSessions($snap, true);
            $schedules = $this->revertibleSchedules($snap, true);
            foreach ($schedules as $schedule) {
                $clash = Schedule::query()->where('student_id', $schedule->student_id)
                    ->whereDate('schedule_date', $schedule->schedule_date)
                    ->where('status', 'scheduled')->where('id', '!=', $schedule->id)
                    ->where('start_time', '<', $schedule->end_time)->where('end_time', '>', $schedule->start_time)->exists();
                if ($clash) {
                    throw new HttpException(409, '原時段已被其他排程占用，無法撤銷調整，請先處理衝突時段。');
                }
            }

            $locked->setAttribute('SessionCount', $before['session_count']);
            $locked->setAttribute('UsedSessions', $before['used_sessions']);
            $locked->setAttribute('RemainingSessions', $before['remaining_sessions']);
            $locked->setAttribute('Stop', $before['stop']);
            $locked->setAttribute('closed_reason', $before['closed_reason']);
            if (array_key_exists('end_date', $before)) {
                $locked->setAttribute('EndDate', $before['end_date']);
            } elseif ((int) $before['stop'] === 0 && (int) $snap['after']['stop'] === 1) {
                // ponytail: pre-end_date snapshots lack the old EndDate; approximate with the last live session date.
                $last = ClassSession::query()->where('StudentClassID', $locked->getKey())->where('Status', '!=', 'cancelled')->max('SessionDate');
                if ($last) {
                    $locked->setAttribute('EndDate', substr((string) $last, 0, 10));
                }
            }
            $locked->setAttribute('settlement_snapshot', json_encode([
                'kind' => 'contract_amendment_reverted',
                'reverted' => $snap,
                'reason_hash' => hash('sha256', $reason),
                'actor_user_id' => $actorId ?: null,
                'at' => now()->toIso8601String(),
                'financial_mutation' => 'none',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $locked->save();
            ClassSession::resetSettlementLockCache();

            try {
                foreach ($sessions as $session) {
                    $session->Status = 'scheduled';
                    $session->Note = trim(str_replace(self::CANCEL_NOTE, '', (string) $session->Note));
                    $session->save();
                }
            } catch (ValidationException $e) {
                throw new HttpException(409, '原時段已被其他堂次占用，無法撤銷調整，請先處理衝突時段。');
            }
            if ($schedules->isNotEmpty()) {
                Schedule::query()->whereIn('id', $schedules->pluck('id'))->update(['status' => 'scheduled', 'updated_at' => now()]);
            }

            SecurityAuditEvent::append(
                'student_class.contract_amendment_reverted',
                'success',
                [
                    'actor_type' => 'user',
                    'actor_id' => $actorId ?: null,
                    'subject_type' => 'student_class',
                    'subject_id' => $locked->getKey(),
                    'campus_id' => (int) ($locked->student?->CampusID ?: 0) ?: null,
                ],
                [
                    'old_session_count' => $snap['after']['session_count'],
                    'new_session_count' => $before['session_count'],
                    'reason_code' => 'contract_amendment_reverted',
                    'reason_hash' => hash('sha256', $reason),
                    'outcome' => 'success',
                ]
            );
            $unscheduled = max(0, (int) $before['remaining_sessions'] - count($this->futureScheduled((int) $locked->getKey())));
            return [
                'message' => "已撤銷調整，合約恢復為 {$before['session_count']} 堂、剩餘 {$before['remaining_sessions']} 堂。帳務資料未變更。"
                    . ($unscheduled > 0 ? "尚有 {$unscheduled} 堂未排課，請自行排課。" : ''),
                'restored_session_ids' => $sessions->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'restored_schedule_ids' => $schedules->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'unscheduled_remaining_sessions' => $unscheduled,
                'after' => $locked->fresh()->only(['ID', 'SessionCount', 'UsedSessions', 'RemainingSessions', 'Stop', 'closed_reason', 'EndDate']),
                'financial_mutation' => 'none',
            ];
        });
    }

    /** Returns the amendment snapshot, or 409s when it is missing or the contract drifted since. */
    private function assertRevertible(StudentClass $course): array
    {
        $snap = json_decode((string) $course->getAttribute('settlement_snapshot'), true);
        if (!is_array($snap) || ($snap['kind'] ?? null) !== self::CLOSED_REASON) {
            throw new HttpException(409, '此合約沒有可撤銷的調整。');
        }
        $after = $snap['after'] ?? [];
        $drift = (int) $course->SessionCount !== (int) ($after['session_count'] ?? -1)
            || (int) $course->UsedSessions !== (int) ($after['used_sessions'] ?? -1)
            || (int) $course->RemainingSessions !== (int) ($after['remaining_sessions'] ?? -1)
            || (int) $course->Stop !== (int) ($after['stop'] ?? -1)
            || (string) $course->getAttribute('closed_reason') !== (string) ($after['closed_reason'] ?? '');
        if ($drift) {
            throw new HttpException(409, '合約在調整後已有變動，無法自動撤銷，請聯絡管理員。');
        }
        return $snap;
    }

    private function revertibleSessions(array $snap, bool $lock = false)
    {
        $q = ClassSession::query()->whereIn('id', $snap['cancelled_session_ids'] ?? [])
            ->where('Status', 'cancelled')->where('Note', 'like', '%' . self::CANCEL_NOTE . '%')->orderBy('id');
        return ($lock ? $q->lockForUpdate() : $q)->get();
    }

    private function revertibleSchedules(array $snap, bool $lock = false)
    {
        $q = Schedule::query()->whereIn('id', $snap['cancelled_schedule_ids'] ?? [])->where('status', 'cancelled')->orderBy('id');
        return ($lock ? $q->lockForUpdate() : $q)->get();
    }
}
