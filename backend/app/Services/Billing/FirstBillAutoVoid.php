<?php

namespace App\Services\Billing;

use App\Http\Controllers\PaymentReportController;
use App\Models\Invoice;
use App\Models\PaymentReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * In-app #531 / #5160: when the only receipt of a bill that its own confirmation auto-issued is taken back, the bill goes with
 * it. Shared by 撤銷收據 (PaymentReportController::void) and the receipt move (#5113), so both void exactly the same bills.
 */
final class FirstBillAutoVoid
{
    /**
     * True when $invoice is an unmarked NT$0 bill emptied by this reversal (see below), or when, and only when, it is the bill that receipt $reportId's confirmation auto-issued at the contract Charge and
     * that receipt was its only money row: marker names this report, total still the stamped Charge, no items, no correction
     * link, no other pending report bound to it, and the payments before the reversal are exactly the one original receipt.
     *
     * @param Collection<int, \App\Models\Payment> $paymentsBeforeReversal
     */
    public static function applies(Invoice $invoice, int $reportId, ?int $reportPaymentId, Collection $paymentsBeforeReversal, int $courseId): bool
    {
        $snapshot = is_array($invoice->billing_snapshot) ? $invoice->billing_snapshot : [];
        $original = $paymentsBeforeReversal->first();
        $isLoneReceipt = $paymentsBeforeReversal->count() === 1
            && (int) $original->getKey() === (int) $reportPaymentId
            && (int) $original->Amount > 0 && (string) $original->Method !== 'void';

        $firstBill = ($snapshot['source'] ?? null) === PaymentReportController::FIRST_BILL_SOURCE
            && (int) ($snapshot['payment_report_id'] ?? 0) === $reportId
            && (int) $invoice->TotalAmount === (int) ($snapshot['contract_charge'] ?? -1)
            && (int) ($snapshot['contract_charge'] ?? 0) > 0
            && $isLoneReceipt;
        // Founder 2026-11-16 (course 1907, 80 such bills): an old 「0 元」 登記 left a NT$0 bill marked paid with no free/gift
        // mark. It is not payment evidence (#3991), and once its NT$0 receipt is taken back nothing is left on it, so it
        // goes with the receipt and the course returns to 未繳. Only when every row on it is NT$0 and no other confirmed
        // receipt points at it.
        $emptiedZeroBill = (int) $invoice->TotalAmount === 0
            && $invoice->zero_amount_reason === null
            && $paymentsBeforeReversal->isNotEmpty()
            && $paymentsBeforeReversal->every(fn ($p) => (int) $p->Amount === 0)
            && !PaymentReport::query()->where('InvoiceID', (int) $invoice->getKey())->where('status', 'confirmed')->where('id', '!=', $reportId)->exists();

        return ($firstBill || $emptiedZeroBill)
            && (int) $invoice->StudentClassID === $courseId
            && (string) $invoice->Status !== 'void'
            && !$invoice->items()->exists()
            // A pending report bound to this bill would hit void_invoice_cannot_confirm once it is void: leave the bill open for it.
            && !PaymentReport::query()->where('InvoiceID', (int) $invoice->getKey())->where('status', 'pending')->where('id', '!=', $reportId)->exists()
            && !DB::table('invoice_corrections')->where(fn ($q) => $q->where('original_invoice_id', (int) $invoice->getKey())
                ->orWhere('replacement_invoice_id', (int) $invoice->getKey()))->exists();
    }

    /**
     * Voids the bill when {@see applies()}. Call inside the caller's transaction with the bill locked, after the reversal row exists.
     *
     * @param Collection<int, \App\Models\Payment> $paymentsBeforeReversal
     */
    public static function apply(Invoice $invoice, int $reportId, ?int $reportPaymentId, Collection $paymentsBeforeReversal, int $courseId, mixed $userId): bool
    {
        if (!self::applies($invoice, $reportId, $reportPaymentId, $paymentsBeforeReversal, $courseId)) {
            return false;
        }
        $audit = sprintf('[void: 唯一收款撤銷，系統自動作廢; user_id=%s; at=%s]', $userId ?? 'system', now()->format('Y-m-d H:i:s'));
        $note = trim((string) ($invoice->Note ?? ''));
        $invoice->Status = 'void';
        // VARCHAR(255): the audit tag and the invoice-number tag always survive; only the free text is cut (as BillingController::voidInvoice).
        $keep = preg_match('/\[' . InvoiceNumber::TAG . ':\d{4}-\d{2}\]/', $note, $kept) ? $kept[0] : '';
        $free = trim(str_replace($keep, '', $note));
        $free = trim(mb_substr($free, 0, max(0, 253 - mb_strlen($audit) - mb_strlen($keep))));
        $invoice->Note = implode(' ', array_filter([$free, $keep, $audit], fn ($p) => $p !== ''));
        $invoice->save();
        Log::info('[FirstBillAutoVoid]', ['invoice_id' => $invoice->getKey(), 'report_id' => $reportId, 'student_class_id' => $courseId, 'user_id' => $userId]);

        return true;
    }
}
