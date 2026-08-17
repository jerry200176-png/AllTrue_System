<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\StudentClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * #934: when a course converts count ↔ date billing, archive stale
 * unpaid invoices and pending reports. Confirmed money is never reversed.
 */
class BillingModeConversionArchiveService
{
    public const VOID_REASON_CODE = 'course_billing_mode_converted';

    /**
     * @return array{
     *   old_mode:string,
     *   new_mode:string,
     *   stamped_invoices:int,
     *   voided_unpaid_invoices:int,
     *   voided_pending_reports:int,
     *   skipped_paid_invoices:int,
     *   skipped_confirmed_reports:int
     * }
     */
    public function archiveAfterScheduleModeChange(
        StudentClass $studentClass,
        string $oldMode,
        string $newMode,
        ?int $actorUserId = null
    ): array {
        $oldMode = $this->normalizeMode($oldMode);
        $newMode = $this->normalizeMode($newMode);
        $empty = [
            'old_mode' => $oldMode,
            'new_mode' => $newMode,
            'stamped_invoices' => 0,
            'voided_unpaid_invoices' => 0,
            'voided_pending_reports' => 0,
            'skipped_paid_invoices' => 0,
            'skipped_confirmed_reports' => 0,
        ];

        if ($oldMode === $newMode) {
            return $empty;
        }

        $courseId = (int) $studentClass->getKey();
        $reason = sprintf(
            '課程計費模式由%s改為%s，未入帳帳單與待確認回報已作廢。',
            $this->modeLabel($oldMode),
            $this->modeLabel($newMode)
        );

        return DB::transaction(function () use ($courseId, $oldMode, $newMode, $actorUserId, $reason) {
            $stamped = Invoice::query()
                ->where('StudentClassID', $courseId)
                ->where(function ($q) {
                    $q->whereNull('ScheduleModeAtIssue')->orWhere('ScheduleModeAtIssue', '');
                })
                ->update(['ScheduleModeAtIssue' => $oldMode]);

            $voidedUnpaid = 0;
            $skippedPaid = 0;
            $invoices = Invoice::query()
                ->where('StudentClassID', $courseId)
                ->lockForUpdate()
                ->get();

            foreach ($invoices as $invoice) {
                $status = strtolower((string) ($invoice->Status ?? ''));
                if ($status === 'void') {
                    continue;
                }

                $netApplied = $this->invoiceNetAppliedAmount((int) $invoice->id);
                $hasMoney = in_array($status, ['paid', 'partial'], true)
                    || (int) ($invoice->PaidAmount ?? 0) > 0
                    || $netApplied > 0;

                if ($hasMoney) {
                    $skippedPaid++;
                    continue;
                }

                $audit = sprintf(
                    '[void: %s; user_id=%s; at=%s; code=%s]',
                    str_replace(["\r", "\n"], ' ', $reason),
                    $actorUserId ?? 'system',
                    now()->format('Y-m-d H:i:s'),
                    self::VOID_REASON_CODE
                );
                $note = trim((string) ($invoice->Note ?? ''));
                $invoice->Status = 'void';
                $invoice->Note = trim($note . ' ' . $audit);
                $invoice->save();
                $voidedUnpaid++;
            }

            $skippedConfirmed = PaymentReport::query()
                ->where('StudentClassID', $courseId)
                ->where('status', 'confirmed')
                ->count();

            $voidedPending = 0;
            $pendingReports = PaymentReport::query()
                ->where('StudentClassID', $courseId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($pendingReports as $report) {
                $report->update([
                    'status' => 'voided',
                    'voided_by' => $actorUserId,
                    'voided_at' => now(),
                    'void_reason' => $reason,
                ]);
                $voidedPending++;
            }

            $summary = [
                'old_mode' => $oldMode,
                'new_mode' => $newMode,
                'stamped_invoices' => (int) $stamped,
                'voided_unpaid_invoices' => $voidedUnpaid,
                'voided_pending_reports' => $voidedPending,
                'skipped_paid_invoices' => $skippedPaid,
                'skipped_confirmed_reports' => (int) $skippedConfirmed,
            ];

            Log::info('[BillingModeConversion] archived stale artifacts', [
                'student_class_id' => $courseId,
                'actor_user_id' => $actorUserId,
                'code' => self::VOID_REASON_CODE,
            ] + $summary);

            return $summary;
        });
    }

    private function invoiceNetAppliedAmount(int $invoiceId): int
    {
        $payments = Payment::query()->where('InvoiceID', $invoiceId)->get(['Amount', 'Method']);
        $positiveTotal = (int) $payments
            ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) > 0 && (string) ($payment->Method ?? '') !== 'void')
            ->sum(fn ($payment) => (int) ($payment->Amount ?? 0));
        $voidedTotal = abs((int) $payments
            ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void')
            ->sum(fn ($payment) => (int) ($payment->Amount ?? 0)));

        return max(0, $positiveTotal - $voidedTotal);
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return $mode === 'date' ? 'date' : 'count';
    }

    private function modeLabel(string $mode): string
    {
        return $mode === 'date' ? '月結' : '堂數制';
    }
}
