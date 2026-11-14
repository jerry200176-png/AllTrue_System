<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\StudentClass;
use App\Services\MonthlyBillingService;

/**
 * The step before the first money lands on a monthly ('date') bill: price it from its period's billable sessions and
 * freeze that evidence on the bill. Shared by confirm() and the receipt move (#5113), so both write the same total the
 * resolver projected; without it the bill keeps its stale stored total once a payment makes it no longer repriceable.
 */
final class MonthlyBillFreeze
{
    public function __construct(private MonthlyBillingService $monthlyBilling)
    {
    }

    /**
     * Sets TotalAmount (in memory; the caller's next save persists it) and, on a repriceable bill without evidence yet,
     * the period snapshot (saved here). Call only for a date-mode course, under the bill's lock.
     *
     * @param array<string, mixed>|null $projection the bill's resolver projection before this payment; null = a bill just issued
     */
    public function priceBeforeFirstPayment(Invoice $invoice, StudentClass $sc, ?array $projection, mixed $paymentDate): void
    {
        $billingPeriod = preg_match('/^\d{4}-\d{2}$/', (string) $invoice->billing_period)
            ? (string) $invoice->billing_period
            : $this->monthlyBilling->defaultPeriodFor($sc, $paymentDate);
        $billing = $this->monthlyBilling->summarizePeriod($sc, $billingPeriod);

        if (
            (string) ($invoice->Status ?? 'unpaid') === 'unpaid'
            && (int) ($projection['net_applied'] ?? 0) === 0
            && (!$projection || $projection['computed_total_amount'] !== null)
            && $billing['source'] === 'billable_sessions'
            && (!$projection || $projection['repriceable'])
        ) {
            $invoice->TotalAmount = (int) $billing['charge'];
        }

        // A fixed (non-repriceable) bill keeps its own evidence: never snapshot today's sessions onto it (#3888 review).
        if (empty($invoice->billing_snapshot) && (!$projection || $projection['repriceable'])) {
            $invoice->billing_period = $billingPeriod;
            $invoice->billing_snapshot = [
                'version' => 1,
                'source' => 'monthly_billable_sessions',
                'billing_period' => $billingPeriod,
                'period_start' => $billing['period_start'],
                'period_end' => $billing['period_end'],
                'period_sessions' => (int) $billing['period_sessions'],
                'charge' => (int) $billing['charge'],
                'sessions' => $this->monthlyBilling->billableSessionDetailsForPeriod($sc, $billingPeriod),
            ];
            $invoice->save();
        }
    }
}
