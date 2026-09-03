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

final class ContractAmendmentService
{
    public const CLOSED_REASON = 'contract_amended';

    public function preview(StudentClass $course, int $newCount): array
    {
        $this->assertRequest($course, $newCount);
        $classId = (int) $course->getKey();
        $diagnostic = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$classId])[$classId] ?? [];
        $used = max((int) ($diagnostic['expected_used'] ?? 0), (int) ($diagnostic['uncapped_used'] ?? 0));
        $future = $this->futureScheduled($classId);
        $futureSchedules = $this->futureSchedules($classId);
        return [
            'student_class_id' => $classId,
            'student_id' => (int) $course->getAttribute('StudentID'),
            'subject_id' => (int) ($course->SubjectID ?? 0),
            'original_session_count' => (int) ($course->SessionCount ?? 0),
            'new_session_count' => $newCount,
            'completed_sessions' => $used,
            'original_remaining_sessions' => (int) ($course->RemainingSessions ?? 0),
            'new_remaining_sessions' => 0,
            'affected_future_scheduled' => $future,
            'affected_future_scheduled_count' => count($future),
            'affected_future_schedules' => $futureSchedules,
            'affected_future_schedules_count' => count($futureSchedules),
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
            $cancelledIds = [];
            $sessions = ClassSession::query()
                ->where('StudentClassID', $locked->getKey())
                ->where('Status', 'scheduled')
                ->whereDate('SessionDate', '>=', Carbon::today()->toDateString())
                ->lockForUpdate()->get();
            foreach ($sessions as $session) {
                $session->Status = 'cancelled';
                $note = trim((string) ($session->Note ?? ''));
                $session->Note = trim($note . ' [合約提前結束取消]');
                $session->save();
                $cancelledIds[] = (int) $session->getKey();
            }
            $scheduleIds = Schedule::query()
                ->where('student_course_id', $locked->getKey())
                ->where('status', 'scheduled')
                ->whereDate('schedule_date', '>=', Carbon::today()->toDateString())
                ->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($scheduleIds !== []) {
                Schedule::query()->whereIn('id', $scheduleIds)->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);
            }

            $locked->setAttribute('SessionCount', $newCount);
            $locked->setAttribute('UsedSessions', (int) $preview['completed_sessions']);
            $locked->setAttribute('RemainingSessions', 0);
            $locked->setAttribute('Stop', 1);
            $locked->setAttribute('closed_reason', self::CLOSED_REASON);
            $locked->setAttribute('EndDate', Carbon::today()->toDateString());
            $locked->setAttribute('settlement_snapshot', json_encode([
                'kind' => self::CLOSED_REASON,
                'before' => $before,
                'after' => [
                    'session_count' => $newCount,
                    'used_sessions' => (int) $preview['completed_sessions'],
                    'remaining_sessions' => 0,
                    'stop' => 1,
                    'closed_reason' => self::CLOSED_REASON,
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
                    'new_remaining_sessions' => 0,
                    'reason_code' => self::CLOSED_REASON,
                    'reason_hash' => hash('sha256', $reason),
                    'outcome' => 'success',
                ]
            );

            return [
                'message' => "合約已調整為 {$newCount} 堂、剩餘 0 堂；已上課紀錄保留，未來預排已取消。帳務資料未變更。",
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
        ];
    }
}
