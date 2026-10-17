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
    /** @var null|callable(int):void Testing seam: runs after the first open-invoice guard + token check, before pre-stamp current-read. */
    private $afterConfirmationTokenForTests = null;

    /** Testing-only: inject work between the first multi-open guard and the pre-stamp current-read. */
    public function setAfterConfirmationTokenForTests(?callable $callback): void
    {
        if (!app()->runningUnitTests()) {
            throw new \LogicException('BillingCorrection test seam is testing-only');
        }
        $this->afterConfirmationTokenForTests = $callback;
    }

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
        $classId = (int) $studentClass->getKey();
        $rows = $sessions ?? ClassSession::query()
            ->where('StudentClassID', $classId)
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
            ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'updated_at']);
        $snapshot = [
            'class_id' => $classId,
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
            'ledger' => $this->ledgerHistoryFingerprint($classId), // void/report cycle after preview invalidates token
        ];

        return hash_hmac('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
    }

    /** Confirmation-only fingerprint of open-invoice payments + all payment reports. @return array{payments: list<array>, reports: list<array>} */
    private function ledgerHistoryFingerprint(int $classId): array
    {
        $invoiceIds = Invoice::query()
            ->where('StudentClassID', $classId)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
            ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $payments = $invoiceIds === [] ? [] : Payment::query()
            ->whereIn('InvoiceID', $invoiceIds)->orderBy('id')
            ->get(['id', 'InvoiceID', 'Amount', 'Method', 'payment_report_id', 'PaidAt'])
            ->map(static fn (Payment $p): array => [
                (int) $p->getKey(),
                (int) $p->getAttribute('InvoiceID'),
                (int) $p->getAttribute('Amount'),
                (string) ($p->getAttribute('Method') ?? ''),
                ($rid = $p->getAttribute('payment_report_id')) === null ? null : (int) $rid,
                (string) ($p->getAttribute('PaidAt') ?? ''),
            ])->all();

        $reports = PaymentReport::query()
            ->where('StudentClassID', $classId)->orderBy('id')
            ->get(['id', 'InvoiceID', 'payment_id', 'status', 'reported_amount', 'updated_at'])
            ->map(static fn (PaymentReport $r): array => [
                (int) $r->getKey(),
                ($inv = $r->getAttribute('InvoiceID')) === null ? null : (int) $inv,
                ($pid = $r->getAttribute('payment_id')) === null ? null : (int) $pid,
                (string) ($r->getAttribute('status') ?? ''),
                (string) ($r->getAttribute('reported_amount') ?? ''),
                (string) ($r->getAttribute('updated_at') ?? ''),
            ])->all();

        return ['payments' => $payments, 'reports' => $reports];
    }

    /** Locked unpaid count-mode write; aborts with the same 404/409 responses the controller used. @return array<string, mixed> */
    public function applyCountCorrection(StudentClass $studentClass, int $newCount, int $newCharge, string $reason, int $oldCount, int $oldCharge, string $confirmationToken, ?int $actorId): array
    {
        $classId = (int) $studentClass->getKey();

        return DB::transaction(function () use ($classId, $newCount, $newCharge, $reason, $oldCount, $oldCharge, $studentClass, $confirmationToken, $actorId) {
            // Course lock whole txn — co-lock InvoiceIssuer (FOR UPDATE before INSERT).
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
            // Same rule the preview used and the token binds, evaluated once
            // under the session locks: refuse while any excess reservation is
            // past-dated and unrecorded, otherwise retire exactly the rest.
            $excess = ContractSessionSchedule::partitionExcessScheduledRows($lockedSessions, $newCount);
            if ($excess['past_unrecorded']->isNotEmpty()) {
                abort(response()->json($this->pastUnrecordedSessionsRefusal($excess['past_unrecorded'], $newCount), 422));
            }
            $retirableSessions = $excess['retirable'];
            $currentAffected = ContractSessionSchedule::describeRetirableSessions($retirableSessions);
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

            if (PaymentReport::query()->where('StudentClassID', $classId)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->exists()) {
                abort(response()->json([
                    'message' => '處理期間出現繳費回報，請重新整理後改走帳務流程。',
                    'code' => 'billing_correction_payment_report_locked',
                ], 409));
            }
            PaymentReport::query()->where('StudentClassID', $classId)->lockForUpdate()->get();

            $adjustedInvoiceCount = 0;
            $openInvoices = Invoice::query()
                ->where('StudentClassID', $classId)
                ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
                ->lockForUpdate()->get();
            $invoiceIds = $openInvoices->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if ($invoiceIds !== []) {
                Payment::query()->whereIn('InvoiceID', $invoiceIds)->lockForUpdate()->get();
            }
            if (ContractMoneyState::blocksUnpaidCorrection($classId)) {
                abort(response()->json([
                    'message' => '處理期間出現有效收款紀錄，請重新整理後改走帳務流程。',
                    'code' => 'billing_correction_payment_locked',
                ], 409));
            }
            // Payable is additive across non-void invoices — never stamp full charge onto each.
            if ($openInvoices->count() > 1) {
                abort(response()->json([
                    'message' => '此課程有多張未作廢帳單，無法用堂數更正一次改寫全部應收；請先整理帳單後再操作。',
                    'code' => 'billing_correction_multiple_open_invoices',
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

            if ($this->afterConfirmationTokenForTests !== null) {
                ($this->afterConfirmationTokenForTests)($classId);
            }

            // Pre-stamp current-read under course lock (locking read). Unindexed Invoice.StudentClassID
            // means gap locks may not block INSERT; refuse ≥2 before stamping a stale singleton.
            $openInvoices = Invoice::query()
                ->where('StudentClassID', $classId)
                ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
                ->orderBy('id')->lockForUpdate()->get();
            if ($openInvoices->count() > 1) {
                abort(response()->json([
                    'message' => '此課程有多張未作廢帳單，無法用堂數更正一次改寫全部應收；請先整理帳單後再操作。',
                    'code' => 'billing_correction_multiple_open_invoices',
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

            foreach ($retirableSessions as $session) {
                $session->Status = 'cancelled';
                $session->save();
            }
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
        $activePayment = ContractMoneyState::blocksUnpaidCorrection($classId);
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

        $openInvoiceCount = Invoice::query()->where('StudentClassID', $classId)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))->count();
        if ($openInvoiceCount > 1) {
            $audit('billing_correction_multiple_open_invoices', 409);
            return $this->reply([
                'message' => '此課程有多張未作廢帳單，無法用堂數更正一次改寫全部應收；請先整理帳單後再操作。',
                'code' => 'billing_correction_multiple_open_invoices',
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
        $excess = app(ContractSessionSchedule::class)->excessScheduledSessionsBeyondCount($classId, $newCount);
        if ($excess['past_unrecorded']->isNotEmpty()) {
            $audit('billing_correction_past_unrecorded_sessions', 422);
            return $this->reply($this->pastUnrecordedSessionsRefusal($excess['past_unrecorded'], $newCount), 422);
        }
        $affectedScheduledSessions = ContractSessionSchedule::describeRetirableSessions($excess['retirable']);
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

    /**
     * Fail closed (Founder 2026-10-16): a reduced count may not leave a
     * past-dated, never-recorded reservation outside the contract. History is
     * not cancelled automatically and the contract is not left over quota; the
     * director records attendance or cancels those sessions first, then retries.
     *
     * @return array<string, mixed>
     */
    private function pastUnrecordedSessionsRefusal($pastSessions, int $newCount): array
    {
        $sessions = ContractSessionSchedule::describeRetirableSessions($pastSessions);
        $labels = array_map(static function (array $session): string {
            $time = $session['end_time'] !== '' ? "{$session['start_time']}–{$session['end_time']}" : $session['start_time'];

            return "{$session['session_date']} {$time}";
        }, $sessions);
        $count = count($sessions);

        return [
            'message' => "更正為 {$newCount} 堂後，有 {$count} 堂已過日期、還沒點名的課會超出堂數："
                . implode('、', $labels)
                . '。請先到行事曆替這些堂次補點名，或取消這些堂次，再重新預覽更正。系統不會自動取消已過日期的堂次。',
            'code' => 'billing_correction_past_unrecorded_sessions',
            'next_step' => 'record_or_cancel_past_sessions_then_retry',
            'new_session_count' => $newCount,
            'past_unrecorded_sessions' => $sessions,
        ];
    }

    /** @param array<string, mixed> $body @return array{status: int, body: array<string, mixed>} */
    private function reply(array $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
