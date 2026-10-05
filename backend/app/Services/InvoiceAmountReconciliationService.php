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
     *   repriceable:bool,
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
        // Reversals come as a negative row OR a positive Method=void row: abs each one, never net them first.
        $voidedTotal = (int) $payments
            ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void')
            ->sum(fn ($payment) => abs((int) ($payment->Amount ?? 0)));
        $netApplied = max(0, $positiveTotal - $voidedTotal);

        $totalAmount = $storedTotalAmount;
        $computedTotalAmount = null;
        $amountSource = 'invoice_total';
        $amountDiscrepancy = false;
        // Whether this invoice follows the held-session pricing policy at all
        // (unpaid, nothing applied, single-month date course priced per lesson).
        // When false its stored amount is fixed (paid, partly paid, void,
        // cross-month cycle, or no lesson price to compute from).
        $repriceable = false;
        $periodSessions = null;
        $periodStart = null;
        $periodEnd = null;
        $billingPeriod = $invoice->getAttribute('billing_period')
            ?: substr((string) ($invoice->getAttribute('IssueDate') ?? ''), 0, 7);
        $items = $course && (string) $course->ScheduleMode === 'date'
            ? ($invoice->relationLoaded('items') ? $invoice->getRelationValue('items') : $invoice->items()->get())
            : collect();
        // An explicit service cycle spanning calendar months cannot be priced
        // from only the billing_period's calendar month. Retain its agreed
        // invoice amount until a reviewed accounting correction is requested.
        $hasCrossMonthServiceRange = $items->contains(fn ($item) => $item->PeriodStart && $item->PeriodEnd
            && $course->StartDate && $course->EndDate
            && (string) $item->PeriodStart >= substr((string) $course->StartDate, 0, 10)
            && (string) $item->PeriodEnd <= substr((string) $course->EndDate, 0, 10)
            && (string) $item->PeriodStart <= (string) $item->PeriodEnd
            && substr((string) $item->PeriodStart, 0, 7) !== substr((string) $item->PeriodEnd, 0, 7));
        if ($course && !$hasCrossMonthServiceRange && $items->isNotEmpty()) {
            // MonthlySplit stores one item per month: judge the course's whole span.
            [$spanStart, $spanEnd] = $this->monthlyBilling->serviceRangeForCourse($invoice, (int) $course->getKey());
            $hasCrossMonthServiceRange = $spanStart !== null && $spanEnd !== null
                && $course->StartDate && $course->EndDate
                && $spanStart >= substr((string) $course->StartDate, 0, 10)
                && $spanEnd <= substr((string) $course->EndDate, 0, 10)
                && substr($spanStart, 0, 7) !== substr($spanEnd, 0, 7);
        }

        if (
            $course
            && !$hasCrossMonthServiceRange
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

            $repriceable = $billing['source'] === 'billable_sessions'
                && (string) ($invoice->getAttribute('Status') ?? '') === 'unpaid'
                && $netApplied === 0;
            if ($amountDiscrepancy && $repriceable) {
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
            'repriceable' => $repriceable,
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
