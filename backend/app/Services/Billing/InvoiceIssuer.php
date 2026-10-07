<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;

/**
 * Single owner of "a new unpaid Invoice (+ items) comes into existence".
 *
 * Callers pass the business fields; the issuer owns the unpaid defaults (PaidAmount 0, Status
 * 'unpaid', Note '') and the optional same-period duplicate guard. The guard is opt-in because only
 * BillingController::store enforces it today; turning it on elsewhere would change behavior.
 * Run inside the caller's DB transaction. Paid-at-birth backfill invoices are out of scope.
 */
final class InvoiceIssuer
{
    /**
     * Lock the student row first (lock order: student, courses, invoices; purge locks the student too, #3593).
     * Returns false when the student no longer exists.
     */
    public function lockStudent(int $studentId): bool
    {
        return (bool) Student::query()->whereKey($studentId)->lockForUpdate()->first(['id']);
    }

    /**
     * @param array<string, mixed> $attrs StudentID, StudentClassID, IssueDate, TotalAmount required-ish; DueDate, Note, billing_period, ScheduleModeAtIssue optional
     * @param list<array<string, mixed>> $items Description, Amount; StudentClassID (falls back to the invoice's), PeriodStart, PeriodEnd optional
     * @throws BillingPeriodInvoiceExists when $guardPeriod and a non-voided invoice already covers the course+period
     */
    public function issue(array $attrs, array $items = [], bool $guardPeriod = false): Invoice
    {
        $attrs += ['StudentClassID' => null, 'DueDate' => null, 'Note' => '', 'billing_period' => null, 'ScheduleModeAtIssue' => null];
        if ($guardPeriod && $attrs['StudentClassID'] && $attrs['billing_period']
            && (new Invoice)->scopeNotVoided(Invoice::query()->where('StudentClassID', $attrs['StudentClassID'])
                ->where('billing_period', $attrs['billing_period']))->lockForUpdate()->exists()) {
            throw new BillingPeriodInvoiceExists();
        }

        $invoice = new Invoice($attrs + ['PaidAmount' => 0, 'Status' => 'unpaid']);
        $invoice->save();
        foreach ($items as $item) {
            (new InvoiceItem([
                'InvoiceID' => $invoice->getKey(),
                'StudentClassID' => $item['StudentClassID'] ?? $attrs['StudentClassID'],
                'Description' => $item['Description'],
                'Amount' => $item['Amount'],
                'PeriodStart' => $item['PeriodStart'] ?? null,
                'PeriodEnd' => $item['PeriodEnd'] ?? null,
            ]))->save();
        }

        return $invoice;
    }
}
