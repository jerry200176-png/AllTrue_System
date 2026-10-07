<?php

namespace App\Services\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\StudentClass;
use App\Services\BillingPayableResolver;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\MonthlyBillingService;

/**
 * Contract Money Verdict: the one place that answers "what does this contract owe / what is applied / what is
 * its status / which period is current / which lessons are covered". Truth (Founder 1A): invoices + Payment rows;
 * the legacy StudentClass.Paid flag only when the course has no non-void invoice at all.
 *
 * Implementation today = BillingPayableResolver (course status) + InvoiceAmountReconciliationService (per-invoice
 * kernel) + MonthlyBillingService (lesson coverage). Callers move onto this one at a time (ARCH2).
 */
final class ContractMoneyVerdict
{
    public function __construct(
        private BillingPayableResolver $resolver,
        private InvoiceAmountReconciliationService $invoiceAmounts,
        private MonthlyBillingService $monthlyBilling,
    ) {
    }

    public function forCourse(StudentClass $course): ?ContractVerdict
    {
        return $this->forCourses([$course])[(int) $course->getKey()] ?? null;
    }

    /**
     * @param iterable<StudentClass> $courses
     * @return array<int, ContractVerdict> keyed by StudentClass ID
     */
    public function forCourses(iterable $courses): array
    {
        $courses = collect($courses)->keyBy(fn (StudentClass $c) => (int) $c->getKey());
        $out = [];
        foreach ($this->resolver->courseStatusesByStudentClassIds($courses->keys()->all(), $courses) as $id => $s) {
            $periods = $s['periods'];
            $invoiceId = $s['current_invoice_id'];
            $current = $invoiceId === null ? null : collect($periods)->first(fn ($p) => in_array($invoiceId, $p['invoice_ids'], true));
            $out[$id] = new ContractVerdict(
                $id, (string) $s['status'], (int) $s['payable_total'], (int) $s['applied'], (int) $s['outstanding'],
                (int) $s['overpaid'], (string) $s['source'], $invoiceId, $current['billing_period'] ?? null, $periods,
            );
        }

        return $out;
    }

    /**
     * Every lesson of one contract tagged paid | partial | unpaid | no_invoice (best state wins when invoices overlap).
     *
     * @return list<array<string, mixed>> ClassSession::sessionsForPaymentSlip rows plus `payment`
     */
    public function lessons(StudentClass $course): array
    {
        $courseId = (int) $course->getKey();
        $invoices = Invoice::with(['items', 'studentClass'])
            ->where(fn ($q) => $q->where('StudentClassID', $courseId)
                ->orWhereHas('items', fn ($items) => $items->where('StudentClassID', $courseId)))
            ->whereNotIn('Status', ['void', 'cancelled'])
            ->get();

        $rank = ['unpaid' => 1, 'partial' => 2, 'paid' => 3];
        $paymentBySession = [];
        foreach ($invoices as $invoice) {
            $projection = $this->invoiceAmounts->resolve($invoice, $invoice->getRelationValue('studentClass'));
            $state = $projection['outstanding_amount'] <= 0 && $projection['total_amount'] > 0
                ? 'paid'
                : ($projection['net_applied'] > 0 ? 'partial' : 'unpaid');
            foreach ($this->monthlyBilling->invoiceCoveredSessions($invoice, $projection) as $row) {
                $id = (int) ($row['class_session_id'] ?? 0);
                if ($id > 0 && ($rank[$paymentBySession[$id] ?? ''] ?? 0) < $rank[$state]) {
                    $paymentBySession[$id] = $state;
                }
            }
        }

        return array_map(function (array $row) use ($paymentBySession) {
            $row['payment'] = $paymentBySession[$row['class_session_id']] ?? 'no_invoice';

            return $row;
        }, ClassSession::sessionsForPaymentSlip([$courseId]));
    }
}
