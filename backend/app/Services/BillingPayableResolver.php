<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\StudentClass;
use Illuminate\Support\Collection;

/** Resolve the settlement amount that may be presented as a confirmed payable. */
class BillingPayableResolver
{
    public function __construct(private InvoiceAmountReconciliationService $invoiceAmounts)
    {
    }

    /**
     * @param int[] $studentClassIds
     * @param Collection<int, StudentClass>|array<int, StudentClass> $courses
     * @return array<int, array<string, int|string|null>>
     */
    public function byStudentClassIds(array $studentClassIds, Collection|array $courses = []): array
    {
        $ids = collect($studentClassIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $courseMap = collect($courses)->keyBy(fn (StudentClass $course) => (int) $course->ID);
        $invoicesByClass = Invoice::query()
            ->notVoided()
            ->with(['payments' => function ($query) {
                $query->select(['id', 'InvoiceID', 'Amount', 'Method']);
            }])
            ->whereIn('StudentClassID', $ids->all())
            ->get(['id', 'StudentClassID', 'IssueDate', 'TotalAmount', 'Status', 'billing_period'])
            ->groupBy('StudentClassID');

        $resolved = [];
        foreach ($ids as $classId) {
            $invoice = $this->selectRelevantInvoice($invoicesByClass->get($classId, collect()));
            if (!$invoice) {
                $resolved[$classId] = $this->unbilled();
                continue;
            }

            $projection = $this->invoiceAmounts->resolve($invoice, $courseMap->get($classId));
            $amount = max(0, (int) $projection['total_amount']);
            $applied = min($amount, max(0, (int) $projection['net_applied']));
            $resolved[$classId] = [
                'payable_amount' => $amount,
                'payable_outstanding' => max(0, $amount - $applied),
                'payable_status' => 'invoiced',
                'payable_source' => 'invoice',
                'payable_invoice_id' => (int) $invoice->id,
                'payable_billing_period' => $projection['billing_period'],
                'payable_amount_source' => $projection['amount_source'],
            ];
        }

        return $resolved;
    }

    /** @return array<string, int|string|null> */
    public function unbilled(): array
    {
        return [
            'payable_amount' => null,
            'payable_outstanding' => null,
            'payable_status' => 'unbilled',
            'payable_source' => null,
            'payable_invoice_id' => null,
            'payable_billing_period' => null,
            'payable_amount_source' => null,
        ];
    }

    /** @param Collection<int, Invoice> $invoices */
    private function selectRelevantInvoice(Collection $invoices): ?Invoice
    {
        return $invoices->sort(function (Invoice $left, Invoice $right): int {
            $leftOpen = in_array((string) ($left->Status ?? ''), ['unpaid', 'partial'], true);
            $rightOpen = in_array((string) ($right->Status ?? ''), ['unpaid', 'partial'], true);
            if ($leftOpen !== $rightOpen) {
                return $leftOpen ? -1 : 1;
            }

            $leftPeriod = (string) ($left->billing_period ?: substr((string) $left->IssueDate, 0, 7));
            $rightPeriod = (string) ($right->billing_period ?: substr((string) $right->IssueDate, 0, 7));
            if ($leftPeriod !== $rightPeriod) {
                return strcmp($rightPeriod, $leftPeriod);
            }

            return (int) $right->id <=> (int) $left->id;
        })->first();
    }
}
