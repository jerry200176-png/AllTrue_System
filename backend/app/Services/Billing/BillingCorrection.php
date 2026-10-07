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
 * Slice 8a: the state-bound confirmation token and the locked count-mode write.
 */
final class BillingCorrection
{
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
}
