<?php

namespace App\Services\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Services\Scheduling\ContractSessionSchedule;
use App\Services\SessionDeductionService;
use Illuminate\Support\Facades\DB;

/**
 * Billing correction rules moved out of StudentClassController (ARCH2-C3, cluster B).
 * Slices 8a-b: the state-bound confirmation token, the locked count-mode write and the count-mode correction flow.
 */
final class BillingCorrection
{
    /**
     * Bind an explicit operator confirmation to the current course, payment
     * guard inputs and schedule state. It is not authorization: all guards are
     * repeated inside the transaction before any write.
     */
    public function confirmationToken(
        StudentClass $studentClass,
        int $newCount,
        int $newCharge,
        int $observedUsed,
        array $affectedScheduledSessions,
        int $actorId,
        $sessions = null
    ): string {
        $rows = $sessions ?? ClassSession::query()
            ->where('StudentClassID', (int) $studentClass->getKey())
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
            ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'updated_at']);
        $snapshot = [
            'class_id' => (int) $studentClass->getKey(),
            'actor_id' => $actorId,
            'new_count' => $newCount,
            'new_charge' => $newCharge,
            'current_count' => (int) ($studentClass->SessionCount ?? 0),
            'current_charge' => (int) ($studentClass->Charge ?? 0),
            'paid' => (int) ($studentClass->Paid ?? 0),
            'observed_used' => $observedUsed,
            'affected' => $affectedScheduledSessions,
            'sessions' => $rows->map(static fn (ClassSession $session): array => [
                (int) $session->getKey(),
                (string) $session->SessionDate,
                (string) $session->StartTime,
                (string) $session->EndTime,
                (string) $session->getAttribute('Status'),
                (string) $session->getAttribute('updated_at'),
            ])->all(),
        ];

        return hash_hmac('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
    }

    /**
     * Locked write of an unpaid count-mode correction: revalidate payment/session/confirmation state,
     * apply the new count and charge to the course and any open invoice, retire surplus scheduled
     * sessions and audit. Aborts with the same 404/409 responses the controller used.
     *
     * @return array<string, mixed>
     */
    public function applyCountCorrection(StudentClass $studentClass, int $newCount, int $newCharge, string $reason, int $oldCount, int $oldCharge, string $confirmationToken, ?int $actorId): array
    {
        $classId = (int) $studentClass->getKey();

        return DB::transaction(function () use ($classId, $newCount, $newCharge, $reason, $oldCount, $oldCharge, $studentClass, $confirmationToken, $actorId) {
            $locked = StudentClass::query()->where('ID', $classId)->lockForUpdate()->first();
            if (!$locked) {
                abort(404);
            }
            if ((int) ($locked->Paid ?? 0) === 1) {
                abort(response()->json([
                    'message' => '此課程在處理期間已被標記收款，請重新整理後再操作。',
                    'code' => 'billing_correction_paid_locked',
                ], 409));
            }

            $lockedSessions = ClassSession::query()
                ->where('StudentClassID', $classId)
                ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
                ->lockForUpdate()->get();
            $currentAffected = ContractSessionSchedule::scheduledSessionsBeyondCountFromRows($lockedSessions, $newCount);
            $currentUsageDiagnostic = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$classId])[$classId] ?? [];
            $currentObservedUsed = max(
                (int) ($currentUsageDiagnostic['expected_used'] ?? 0),
                (int) ($currentUsageDiagnostic['uncapped_used'] ?? 0)
            );
            if ($newCount < $currentObservedUsed) {
                abort(response()->json([
                    'message' => '處理期間已有新的出席或扣堂紀錄，請重新預覽後再操作。',
                    'code' => 'billing_correction_confirmation_stale',
                ], 409));
            }
            $currentToken = $this->confirmationToken(
                $locked, $newCount, $newCharge, $currentObservedUsed, $currentAffected,
                (int) ($actorId ?? 0), $lockedSessions
            );
            if (!hash_equals($currentToken, $confirmationToken)) {
                abort(response()->json([
                    'message' => '課程、付款、出席或預排狀態已變更，請重新預覽並再次確認。',
                    'code' => 'billing_correction_confirmation_stale',
                ], 409));
            }

            if (PaymentReport::query()->where('StudentClassID', $classId)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->exists()) {
                abort(response()->json([
                    'message' => '處理期間出現繳費回報，請重新整理後改走帳務流程。',
                    'code' => 'billing_correction_payment_report_locked',
                ], 409));
            }

            // An unpaid invoice may already exist even though no payment was
            // entered. Keep the future payment report and receipt on the same
            // corrected amount; paid invoices were rejected above.
            $adjustedInvoiceCount = 0;
            $openInvoices = Invoice::query()
                ->where('StudentClassID', $classId)
                ->where(function ($q) {
                    $q->whereNull('Status')->orWhere('Status', '!=', 'void');
                })
                ->lockForUpdate()
                ->get();
            $invoiceIds = $openInvoices->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if ($invoiceIds !== [] && Payment::query()
                ->whereIn('InvoiceID', $invoiceIds)
                ->where('Amount', '>', 0)
                ->where(function ($query) {
                    $query->whereNull('Method')->orWhere('Method', '!=', 'void');
                })
                ->lockForUpdate()->exists()) {
                abort(response()->json([
                    'message' => '處理期間出現有效收款紀錄，請重新整理後改走帳務流程。',
                    'code' => 'billing_correction_payment_locked',
                ], 409));
            }

            $locked->SessionCount = $newCount;
            $locked->Charge = $newCharge;
            $locked->save();
            foreach ($openInvoices as $invoice) {
                if ((int) ($invoice->PaidAmount ?? 0) !== 0) {
                    abort(response()->json([
                        'message' => '此課程已有有效收款紀錄，請先至帳務流程作廢或更正帳單。',
                        'code' => 'billing_correction_payment_locked',
                    ], 409));
                }
                $invoice->TotalAmount = $newCharge;
                $invoice->save();
                InvoiceItem::query()
                    ->where('InvoiceID', $invoice->id)
                    ->where('StudentClassID', $classId)
                    ->update(['Amount' => $newCharge]);
                $adjustedInvoiceCount++;
            }

            app(ContractSessionSchedule::class)->cancelExcessScheduledSessionsFromRows($lockedSessions, $newCount);
            SessionDeductionService::recomputeCounters($classId);
            $fresh = $locked->fresh();

            SecurityAuditEvent::append(
                'student_class.billing_contract_correction',
                'success',
                [
                    'campus_id' => $studentClass->student?->CampusID,
                    'actor_type' => 'user',
                    'actor_id' => $actorId,
                    'subject_type' => 'student_class',
                    'subject_id' => $classId,
                ],
                [
                    'old_session_count' => $oldCount,
                    'new_session_count' => $newCount,
                    'old_charge' => $oldCharge,
                    'new_charge' => $newCharge,
                    'old_remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
                    'new_remaining_sessions' => (int) ($fresh->RemainingSessions ?? 0),
                    'reason_code' => 'post_deduction_unpaid_billing_correction',
                    'outcome' => 'success',
                ]
            );

            return [
                'student_class_id' => $classId,
                'old_session_count' => $oldCount,
                'new_session_count' => $newCount,
                'old_charge' => $oldCharge,
                'new_charge' => $newCharge,
                'observed_used_sessions' => $currentObservedUsed,
                'remaining_sessions' => (int) ($fresh->RemainingSessions ?? 0),
                'payment_status' => 'unpaid',
                'reason' => $reason,
                'adjusted_invoice_count' => $adjustedInvoiceCount,
                'cancelled_scheduled_sessions' => $currentAffected,
            ];
        });
    }

    /**
     * Unpaid count-mode correction after deduction history exists: guards, preview with a state-bound
     * confirmation token, then the locked write. $audit(code, status) records the edit-blocked event.
     *
     * @param array<string, mixed> $payload validated request fields
     * @return array{status: int, body: array<string, mixed>}
     */
    public function correctCountMode(StudentClass $studentClass, array $payload, bool $preview, ?int $actorId, \Closure $audit): array
    {
        if (!empty($payload['new_end_date'])) {
            $audit('billing_correction_end_date_count_mode_only', 422);
            return $this->reply([
                'message' => '只有月結課程可以在帳務更正時調整合約結束日。',
                'code' => 'billing_correction_end_date_count_mode_only',
            ], 422);
        }

        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'count') {
            $audit('billing_correction_count_mode_only', 422);
            return $this->reply([
                'message' => '只有堂數制課程可以使用未收款堂數更正。',
                'code' => 'billing_correction_count_mode_only',
            ], 422);
        }

        if ($studentClass->isPartOfPackage()) {
            $audit('billing_correction_package_forbidden', 422);
            return $this->reply([
                'message' => '共用課程包請使用方案調整流程，不可單獨更正課程堂數。',
                'code' => 'billing_correction_package_forbidden',
            ], 422);
        }

        if ((int) ($studentClass->Paid ?? 0) === 1) {
            $audit('billing_correction_paid_locked', 409);
            return $this->reply([
                'message' => '此課程已標記收款，請先走帳務更正／作廢流程。',
                'code' => 'billing_correction_paid_locked',
            ], 409);
        }

        $classId = (int) $studentClass->getKey();
        $activePayment = ContractMoneyState::hasActivePayment($classId);
        if ($activePayment) {
            $audit('billing_correction_payment_locked', 409);
            return $this->reply([
                'message' => '此課程已有有效收款紀錄，請先至帳務流程作廢或更正帳單。',
                'code' => 'billing_correction_payment_locked',
            ], 409);
        }

        $hasPendingReport = PaymentReport::query()
            ->where('StudentClassID', $classId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();
        if ($hasPendingReport) {
            $audit('billing_correction_payment_report_locked', 409);
            return $this->reply([
                'message' => '此課程已有待處理或已確認的繳費回報，請先完成或作廢該筆回報。',
                'code' => 'billing_correction_payment_report_locked',
            ], 409);
        }

        $newCount = (int) $payload['new_session_count'];
        $newCharge = (int) $payload['new_charge'];
        $oldCount = (int) ($studentClass->SessionCount ?? 0);
        $oldCharge = (int) ($studentClass->Charge ?? 0);
        $rateUnit = strtolower(trim((string) ($studentClass->rate_unit ?? 'session')));
        if ($rateUnit !== 'session') {
            $audit('billing_correction_session_rate_only', 422);
            return $this->reply([
                'message' => '只有按堂計費課程可以使用此更正流程。',
                'code' => 'billing_correction_session_rate_only',
            ], 422);
        }

        $rate = (float) ($studentClass->getAttribute('Rate') ?? 0);
        $expectedCharge = (int) round($rate * $newCount);
        if ($newCharge !== $expectedCharge) {
            $audit('billing_correction_charge_mismatch', 422);
            return $this->reply([
                'message' => "更正金額必須等於單堂 {$rate} × {$newCount} 堂 = {$expectedCharge} 元。",
                'code' => 'billing_correction_charge_mismatch',
                'expected_charge' => $expectedCharge,
            ], 422);
        }

        $usageDiagnostic = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$classId])[$classId] ?? [];
        $observedUsed = max(
            (int) ($usageDiagnostic['expected_used'] ?? 0),
            (int) ($usageDiagnostic['uncapped_used'] ?? 0)
        );
        if ($newCount < $observedUsed) {
            $audit('billing_correction_below_observed_usage', 422);
            return $this->reply([
                'message' => "更正後堂數（{$newCount}）不可少於已使用 {$observedUsed} 堂；已發生的扣堂紀錄不會被改寫。"
                    . "如需調整收費金額，請改到一般課程編輯畫面手動下修「總費用」（堂數維持不變，不影響已發生的扣堂紀錄）。",
                'code' => 'billing_correction_below_observed_usage',
                'observed_used_sessions' => $observedUsed,
                'next_step' => 'edit_charge_only',
            ], 422);
        }

        if ($newCount >= $oldCount) {
            $audit('billing_correction_reduction_only', 422);
            return $this->reply([
                'message' => '此流程只允許未收款課程減少堂數更正；增加堂數請使用加購／續報。',
                'code' => 'billing_correction_reduction_only',
            ], 422);
        }

        // A correction can retire future scheduled rows, but never silently:
        // return their exact list first and require a state-bound confirmation.
        // The confirmation is revalidated after locking payment and session
        // state in the write transaction below.
        $affectedScheduledSessions = app(ContractSessionSchedule::class)->scheduledSessionsBeyondCount($classId, $newCount);
        $confirmationToken = $this->confirmationToken(
            $studentClass,
            $newCount,
            $newCharge,
            $observedUsed,
            $affectedScheduledSessions,
            (int) ($actorId ?? 0)
        );
        if ($preview) {
            return $this->reply([
                'requires_confirmation' => true,
                'confirmation_token' => $confirmationToken,
                'old_session_count' => $oldCount,
                'new_session_count' => $newCount,
                'old_charge' => $oldCharge,
                'new_charge' => $newCharge,
                'observed_used_sessions' => $observedUsed,
                'affected_scheduled_sessions' => $affectedScheduledSessions,
            ]);
        }
        if (!hash_equals($confirmationToken, (string) ($payload['confirmation_token'] ?? ''))) {
            $audit('billing_correction_confirmation_required', 409);
            return $this->reply([
                'message' => '預覽已過期或尚未確認，請重新檢視新舊堂數、金額與受影響未來堂次後再送出。',
                'code' => 'billing_correction_confirmation_required',
                'affected_scheduled_sessions' => $affectedScheduledSessions,
                'new_session_count' => $newCount,
                'observed_used_sessions' => $observedUsed,
            ], 409);
        }

        $result = $this->applyCountCorrection(
            $studentClass, $newCount, $newCharge, (string) $payload['reason'], $oldCount, $oldCharge, $confirmationToken,
            $actorId
        );

        return $this->reply($result);
    }

    /** @param array<string, mixed> $body @return array{status: int, body: array<string, mixed>} */
    private function reply(array $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
