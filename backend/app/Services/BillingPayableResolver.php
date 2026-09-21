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
     * @return array<int, array<string, int|string|null>>
     */
    public function byStudentClassIds(array $studentClassIds, iterable $courses = []): array
    {
        $ids = collect($studentClassIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        /** @var array<int, StudentClass> $courseMap */
        $courseMap = [];
        foreach ($courses as $course) {
            if ($course instanceof StudentClass) {
                $courseMap[(int) $course->getAttribute('ID')] = $course;
            }
        }
        $invoicesByClass = Invoice::query()
            ->where(function ($query) {
                $query->whereNull('Status')->orWhere('Status', '!=', 'void');
            })
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

            $projection = $this->invoiceAmounts->resolve($invoice, $courseMap[$classId] ?? null);
            $amount = max(0, (int) $projection['total_amount']);
            $applied = min($amount, max(0, (int) $projection['net_applied']));
            $resolved[$classId] = [
                'payable_amount' => $amount,
                'payable_outstanding' => max(0, $amount - $applied),
                'payable_status' => 'invoiced',
                'payable_source' => 'invoice',
                'payable_invoice_id' => (int) $invoice->getAttribute('id'),
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
            $leftOpen = in_array((string) ($left->getAttribute('Status') ?? ''), ['unpaid', 'partial'], true);
            $rightOpen = in_array((string) ($right->getAttribute('Status') ?? ''), ['unpaid', 'partial'], true);
            if ($leftOpen !== $rightOpen) {
                return $leftOpen ? -1 : 1;
            }

            $leftPeriod = (string) ($left->getAttribute('billing_period') ?: substr((string) $left->getAttribute('IssueDate'), 0, 7));
            $rightPeriod = (string) ($right->getAttribute('billing_period') ?: substr((string) $right->getAttribute('IssueDate'), 0, 7));
            if ($leftPeriod !== $rightPeriod) {
                return strcmp($rightPeriod, $leftPeriod);
            }

            return (int) $right->getAttribute('id') <=> (int) $left->getAttribute('id');
        })->first();
    }
}
