<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\StudentClass;

/**
 * Read-only invoice amount projection used by every billing surface.
 *
 * Persisted TotalAmount remains the audit value. For an unpaid legacy
 * monthly invoice with no applied payment, the canonical display amount is
 * calculated from the invoice's own billing period and billable sessions.
 * Paid or partially-paid invoices keep their persisted amount until an
 * explicitly approved accounting repair is performed.
 */
class InvoiceAmountReconciliationService
{
    public function __construct(private MonthlyBillingService $monthlyBilling)
    {
    }

    /**
     * @return array{
     *   total_amount:int,
     *   stored_total_amount:int,
     *   computed_total_amount:int|null,
     *   amount_source:string,
     *   amount_discrepancy:bool,
     *   period_sessions:int|null,
     *   period_start:string|null,
     *   period_end:string|null,
     *   billing_period:string|null,
     *   net_applied:int
     * }
     */
    public function resolve(Invoice $invoice, ?StudentClass $course = null): array
    {
        $course ??= $invoice->relationLoaded('studentClass')
            ? $invoice->getRelationValue('studentClass')
            : StudentClass::query()->where('ID', (int) $invoice->getAttribute('StudentClassID'))->first();

        $storedTotalAmount = max(0, (int) ($invoice->getAttribute('TotalAmount') ?? 0));
        $payments = $invoice->relationLoaded('payments')
            ? $invoice->getRelationValue('payments')
            : $invoice->payments()->get(['Amount', 'Method']);
        $positiveTotal = (int) $payments
            ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) > 0 && (string) ($payment->Method ?? '') !== 'void')
            ->sum(fn ($payment) => (int) ($payment->Amount ?? 0));
        $voidedTotal = abs((int) $payments
            ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void')
            ->sum(fn ($payment) => (int) ($payment->Amount ?? 0)));
        $netApplied = max(0, $positiveTotal - $voidedTotal);

        $totalAmount = $storedTotalAmount;
        $computedTotalAmount = null;
        $amountSource = 'invoice_total';
        $amountDiscrepancy = false;
        $periodSessions = null;
        $periodStart = null;
        $periodEnd = null;
        $billingPeriod = $invoice->getAttribute('billing_period')
            ?: substr((string) ($invoice->getAttribute('IssueDate') ?? ''), 0, 7);

        if (
            $course
            && (string) ($course->ScheduleMode ?? 'count') === 'date'
            && preg_match('/^\d{4}-\d{2}$/', (string) $billingPeriod)
        ) {
            $billing = $this->monthlyBilling->summarizePeriod($course, (string) $billingPeriod);
            $computedTotalAmount = (int) $billing['charge'];
            $periodSessions = (int) $billing['period_sessions'];
            $periodStart = $billing['period_start'];
            $periodEnd = $billing['period_end'];
            $amountDiscrepancy = $billing['source'] === 'billable_sessions'
                && $computedTotalAmount !== $storedTotalAmount;

            if (
                $amountDiscrepancy
                && (string) ($invoice->getAttribute('Status') ?? '') === 'unpaid'
                && $netApplied === 0
            ) {
                $totalAmount = $computedTotalAmount;
                $amountSource = 'billable_sessions';
            }
        }

        return [
            'total_amount' => $totalAmount,
            'stored_total_amount' => $storedTotalAmount,
            'computed_total_amount' => $computedTotalAmount,
            'amount_source' => $amountSource,
            'amount_discrepancy' => $amountDiscrepancy,
            'period_sessions' => $periodSessions,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'billing_period' => preg_match('/^\d{4}-\d{2}$/', (string) $billingPeriod)
                ? (string) $billingPeriod
                : null,
            'net_applied' => $netApplied,
        ];
    }
}
