<?php

namespace App\Services\Billing;

use App\Models\{Invoice, InvoiceItem, Payment, StudentClass};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Narrow ledger mechanics shared by independently validated transfer policies. Never creates cash or receipts. */
final class PaidContractCreditMovement
{
    /** @return array{invoice:Invoice,out:Payment,in:Payment} */
    public function move(Invoice $sourceInvoice, StudentClass $source, StudentClass $target, int $sessions, int $amount, string $paidAt, string $label): array
    {
        $kept = (int) $sourceInvoice->TotalAmount - $amount;
        if (DB::transactionLevel() < 1 || $amount < 1 || $kept < 0 || (int) $sourceInvoice->PaidAmount !== (int) $sourceInvoice->TotalAmount) {
            throw new \LogicException('Credit movement requires a locked, validated paid invoice and transaction.');
        }
        $sourceId = (int) $source->getKey();
        $targetId = (int) $target->getKey();
        $issueDate = Carbon::parse($target->StartDate)->toDateString();
        $sourceInvoice->forceFill(['TotalAmount' => $kept, 'PaidAmount' => $kept])->save();
        InvoiceItem::where('InvoiceID', $sourceInvoice->getKey())->where('StudentClassID', $sourceId)->update(['Amount' => $kept]);
        $out = Payment::create([
            'InvoiceID' => $sourceInvoice->getKey(), 'Amount' => -$amount, 'PaidAt' => $paidAt, 'Method' => 'transfer_out',
            'Note' => "{$label}：轉出 {$amount} 元（{$sessions} 堂）至合約#{$targetId}",
        ]);
        $invoice = Invoice::create([
            'StudentID' => (int) $target->StudentID, 'StudentClassID' => $targetId,
            'IssueDate' => $issueDate, 'DueDate' => $issueDate,
            'TotalAmount' => $amount, 'PaidAmount' => $amount, 'ScheduleModeAtIssue' => 'count', 'Status' => 'paid',
            'Note' => "{$label}轉入：自合約#{$sourceId}",
        ]);
        InvoiceItem::create([
            'InvoiceID' => $invoice->getKey(), 'StudentClassID' => $targetId,
            'Description' => "{$label}轉入：{$sessions} 堂", 'Amount' => $amount,
        ]);
        $in = Payment::create([
            'InvoiceID' => $invoice->getKey(), 'Amount' => $amount, 'PaidAt' => $paidAt, 'Method' => 'transfer_in',
            'Note' => "{$label}轉入 {$amount} 元，來源合約#{$sourceId} 帳單#{$sourceInvoice->getKey()} 付款#{$out->getKey()}",
        ]);

        return ['invoice' => $invoice, 'out' => $out, 'in' => $in];
    }
}
