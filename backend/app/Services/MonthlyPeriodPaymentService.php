<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Read-only period attribution; a legacy Paid flag cannot settle another period. */
final class MonthlyPeriodPaymentService
{
    public function __construct(private InvoiceAmountReconciliationService $amounts) {}

    public function batch(Collection $courses): array
    {
        $courses = $courses->filter(fn ($course) => (string) $course->ScheduleMode === 'date' && !(int) $course->PackageID);
        if ($courses->isEmpty()) return [];
        $ids = $courses->pluck('ID')->all();
        $invoices = Invoice::query()->notVoided()->whereIn('StudentClassID', $ids)
            ->with(['payments', 'items'])->orderBy('id')->get()->groupBy('StudentClassID');
        $sessions = ClassSession::query()->whereIn('StudentClassID', $ids)
            ->whereNotIn('Status', ['cancelled', 'voided', 'leave', 'rescheduled'])
            ->orderBy('SessionDate')->get(['StudentClassID', 'SessionDate'])->groupBy('StudentClassID');
        $result = [];
        foreach ($courses as $course) {
            $result[(int) $course->ID] = $this->summarize($course, $invoices->get($course->ID, collect()), $sessions->get($course->ID, collect()));
        }
        return $result;
    }

    private function summarize(StudentClass $course, Collection $invoices, Collection $sessions): array
    {
        $periods = [];
        $ambiguous = false;
        foreach ($invoices as $invoice) {
            $period = (string) $invoice->billing_period;
            // IssueDate is the invoice creation date, not proof of service period.
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
                $ambiguous = true;
                continue;
            }
            $month = Carbon::createFromFormat('!Y-m', $period);
            $items = $invoice->items->filter(fn ($item) => (int) $item->StudentClassID === (int) $course->ID);
            if ($invoice->items->count() !== $items->count() || $items->count() > 1) $ambiguous = true;
            $start = $items->whereNotNull('PeriodStart')->min('PeriodStart') ?: $month->copy()->startOfMonth()->toDateString();
            $end = $items->whereNotNull('PeriodEnd')->max('PeriodEnd') ?: $month->copy()->endOfMonth()->toDateString();
            if ($start > $end || ($items->isNotEmpty() && ($start < substr((string) $course->StartDate, 0, 10)
                || $end > substr((string) $course->EndDate, 0, 10)))) $ambiguous = true;
            $amount = $this->amounts->resolve($invoice, $course);
            $paid = $invoice->payments->isEmpty() ? max(0, (int) $invoice->PaidAmount) : $amount['net_applied'];
            $existing = $periods[$period] ?? ['billing_period' => $period, 'period_start' => $start, 'period_end' => $end,
                'charge' => 0, 'paid_amount' => 0, 'invoice_ids' => [], 'source' => 'invoice'];
            if ($existing['period_start'] !== $start || $existing['period_end'] !== $end) $ambiguous = true;
            $existing['charge'] += $amount['total_amount'];
            $existing['paid_amount'] += $paid;
            $existing['invoice_ids'][] = (int) $invoice->id;
            $periods[$period] = $existing;
        }
        $uncoveredMonths = [];
        foreach ($sessions as $session) {
            $date = substr((string) $session->SessionDate, 0, 10);
            $covered = collect($periods)->contains(fn ($period) => $date >= $period['period_start'] && $date <= $period['period_end']);
            if (!$covered) $uncoveredMonths[substr($date, 0, 7)] = true;
        }
        $start = substr((string) $course->StartDate, 0, 10);
        $end = substr((string) $course->EndDate, 0, 10);
        if ($invoices->isNotEmpty() && $start && $end) {
            // Check explicit range coverage, including a next period with no
            // sessions yet. Do not manufacture calendar-month invoices.
            $cursor = Carbon::parse($start);
            foreach (collect($periods)->sortBy('period_start') as $period) {
                if ($period['period_end'] < $cursor->toDateString()) continue;
                if ($period['period_start'] > $cursor->toDateString()) break;
                $cursor = Carbon::parse($period['period_end'])->addDay();
            }
            if ($cursor->toDateString() <= $end) $ambiguous = true;
        }
        $singlePeriod = $start && $end && substr($start, 0, 7) === substr($end, 0, 7);
        if ($invoices->isEmpty() && $singlePeriod && count($uncoveredMonths) <= 1
            && ($uncoveredMonths === [] || isset($uncoveredMonths[substr($start, 0, 7)]))) {
            $period = substr($start, 0, 7);
            $periods[$period] = ['billing_period' => $period, 'period_start' => $start, 'period_end' => $end,
                'charge' => max(0, (int) $course->Charge), 'paid_amount' => (int) $course->Paid === 1 ? max(0, (int) $course->Charge) : 0,
                'invoice_ids' => [], 'source' => 'single_period_legacy'];
            $uncoveredMonths = [];
        }
        if ($periods === [] && $uncoveredMonths === []) $uncoveredMonths[$start ? substr($start, 0, 7) : 'unknown'] = true;
        foreach (array_keys($uncoveredMonths) as $period) {
            if (isset($periods[$period])) { $ambiguous = true; continue; }
            $periods[$period] = ['billing_period' => $period, 'period_start' => null, 'period_end' => null,
                'charge' => null, 'paid_amount' => null, 'invoice_ids' => [], 'source' => 'unattributed'];
        }
        ksort($periods);
        foreach ($periods as &$period) {
            $period['payment_status'] = $period['source'] === 'unattributed' ? 'unknown'
                : ($period['paid_amount'] >= $period['charge'] ? 'paid' : ($period['paid_amount'] > 0 ? 'partial' : 'unpaid'));
            $period['outstanding_amount'] = $period['charge'] === null ? null : max(0, $period['charge'] - $period['paid_amount']);
        }
        unset($period);
        $rows = array_values($periods);
        $review = $ambiguous || collect($rows)->contains('payment_status', 'unknown');
        $selected = collect($rows)->first(fn ($row) => in_array($row['payment_status'], ['unpaid', 'partial'], true))
            ?? collect($rows)->firstWhere('payment_status', 'unknown') ?? end($rows);
        return ['periods' => $rows, 'billing_period' => $selected['billing_period'],
            'payment_status' => $review ? 'review_required' : $selected['payment_status'], 'review_required' => $review,
            'contract_start' => $start ?: null, 'contract_end' => $end ?: null];
    }
}
