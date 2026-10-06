<?php

namespace App\Services\Billing;

use App\Models\StudentClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Single owner of contract-level money state (ADR-003 rule 3; F4/F7 recurrence families).
 *
 * Founder-approved display rules (2026-10-06, option A): waived reads 確認不收 and partial reads
 * 部分繳 on every classifier, and a Charge-0 parent billing record reads free, not paid. What counts
 * as paid (G-009) and every amount are unchanged; only the displayed status differs.
 *
 * Course-level settlement amounts for new code belong to {@see \App\Services\BillingPayableResolver}.
 */
final class ContractMoneyState
{
    // ---- payment-status classifiers (one per legacy call site) -------------------------------

    /**
     * Alerts/tuition 7-step ladder (was AlertController::computePaymentStatus).
     * pending_report > pending_reconciliation > partial > unpaid > renew_needed > monthly_due_soon > paid.
     */
    public static function alertStatus(?StudentClass $sc, int $paidAmount, int $charge, bool $hasPendingReport): string
    {
        if (!$sc) {
            return 'unpaid';
        }
        if (self::isWaived($sc)) {
            return 'waived';
        }
        if ($hasPendingReport) {
            return 'pending_report';
        }

        $closedReason = (string) ($sc->getAttribute('closed_reason') ?? '');
        $isPaid = StudentClass::isFullyPaid((int) ($sc->Paid ?? 0) === 1 || $sc->isEffectivelyPaid(), $paidAmount, $charge);

        if (!$isPaid && in_array($closedReason, ['settled_pending', 'contract_amended'], true)) {
            return 'pending_reconciliation';
        }
        if (!$isPaid && $paidAmount > 0 && $charge > 0 && $paidAmount < $charge) {
            return 'partial';
        }
        if (!$isPaid) {
            return 'unpaid';
        }

        $mode = $sc->ScheduleMode ?? 'count';
        if ($mode === 'count' && (int) ($sc->RemainingSessions ?? 0) <= 2) {
            return 'renew_needed';
        }
        if ($mode === 'date') {
            return 'monthly_due_soon';
        }

        return 'paid';
    }

    /**
     * Course-management list status (was inline in StudentClassController::index).
     * A fully-paid contract wins over an older pending report (#249); part-paid reads partial like the alert ladder.
     * Waived stays `paid` here (isEffectivelyPaid drives notices/renewals); the client labels it 確認不收 from closed_reason.
     */
    public static function listStatus(bool $effectivePaid, int $invoicePaidAmount, int $charge, bool $hasPendingReport): string
    {
        if (StudentClass::isFullyPaid($effectivePaid, $invoicePaidAmount, $charge)) {
            return 'paid';
        }

        return $hasPendingReport ? 'pending_report' : ($invoicePaidAmount > 0 && $charge > 0 ? 'partial' : 'unpaid');
    }

    /** Parent-portal course card status + label (was inline in ParentPortalController). @return array{0:string,1:string} */
    public static function parentCardStatus(bool $isTutoring, bool $isWaived, bool $isPaid): array
    {
        return $isTutoring ? ['free', '免費（不適用）']
            : ($isWaived ? ['waived', '已確認不收']
            : ($isPaid ? ['paid', '已繳費'] : ['unpaid', '未繳費']));
    }

    /** Parent-portal billing record status, from the stored Pay column. Nothing owed (Charge 0) is `free`, not `paid`. */
    public static function parentRecordStatus(bool $isWaived, int $pay, int $charge): string
    {
        return $isWaived ? 'waived' : ($charge <= 0 ? 'free' : ($pay >= $charge ? 'paid' : ($pay > 0 ? 'partial' : 'unpaid')));
    }

    // ---- waived (確認不收) terminal state ----------------------------------------------------
    // Real enforcement stays in StudentClass::booted(); these are the controller-side pre-checks.

    public static function isWaived(?StudentClass $course): bool
    {
        return $course !== null && (string) ($course->getAttribute('closed_reason') ?? '') === 'waived';
    }

    /** 422 refusal for a waived contract, or null when the contract is not waived. */
    public static function waivedRefusal(?StudentClass $course, string $message, ?string $code = null): ?JsonResponse
    {
        if (!self::isWaived($course)) {
            return null;
        }

        return response()->json($code === null ? ['message' => $message] : ['message' => $message, 'code' => $code], 422);
    }

    // ---- invoice/payment queries -------------------------------------------------------------

    /** True when a non-void invoice has PaidAmount>0 or a non-void Payment>0 (blocks free billing corrections). */
    public static function hasActivePayment(int $studentClassId): bool
    {
        return DB::table('Invoice')
            ->leftJoin('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
            ->where('Invoice.StudentClassID', $studentClassId)
            ->where(fn ($q) => $q->whereNull('Invoice.Status')->orWhere('Invoice.Status', '!=', 'void'))
            ->where(function ($q) {
                $q->where('Invoice.PaidAmount', '>', 0)
                    ->orWhere(function ($payment) {
                        $payment->where('Payment.Amount', '>', 0)
                            ->where(fn ($method) => $method->whereNull('Payment.Method')->orWhere('Payment.Method', '!=', 'void'));
                    });
            })
            ->exists();
    }

    /**
     * Batch-fetch Invoice paid aggregates per StudentClass ID.
     *
     * @param  int[]  $studentClassIds
     * @return array<int, array{paid_amount: int, total_amount: int, active_invoice_count: int, outstanding_amount: int}>
     */
    public static function invoiceAggregateByStudentClassIds(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }

        $rows = DB::table('Invoice')
            ->whereIn('StudentClassID', $studentClassIds)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
            ->select(
                'StudentClassID',
                DB::raw('COALESCE(SUM(PaidAmount), 0) as paid_amount'),
                DB::raw('COALESCE(SUM(TotalAmount), 0) as total_amount'),
                DB::raw('COUNT(*) as active_invoice_count')
            )
            ->groupBy('StudentClassID')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->StudentClassID] = [
                'paid_amount' => (int) $row->paid_amount,
                'total_amount' => (int) $row->total_amount,
                'active_invoice_count' => (int) $row->active_invoice_count,
                'outstanding_amount' => max(0, (int) $row->total_amount - (int) $row->paid_amount),
            ];
        }

        return $map;
    }

    /**
     * Batch-fetch the latest Payment.PaidAt (Y-m-d) per StudentClass ID via Invoice.StudentClassID -> Payment.InvoiceID.
     *
     * @param  int[]  $studentClassIds
     * @return array<int, string>  only classes that have a dated payment
     */
    public static function lastPaidAtByStudentClassIds(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }

        $rows = DB::table('Invoice')
            ->join('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
            ->whereIn('Invoice.StudentClassID', $studentClassIds)
            ->where(fn ($q) => $q->whereNull('Invoice.Status')->orWhere('Invoice.Status', '!=', 'void'))
            ->select('Invoice.StudentClassID', DB::raw('MAX(Payment.PaidAt) as last_paid_at'))
            ->groupBy('Invoice.StudentClassID')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            if ($row->last_paid_at) {
                $map[(int) $row->StudentClassID] = substr($row->last_paid_at, 0, 10);
            }
        }

        return $map;
    }
}
