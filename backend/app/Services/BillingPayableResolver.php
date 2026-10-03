<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\StudentClass;
use Illuminate\Support\Collection;

/** Resolve the settlement amount that may be presented as a confirmed payable. */
class BillingPayableResolver
{
    public function __construct(
        private InvoiceAmountReconciliationService $invoiceAmounts,
        private MonthlyPeriodPaymentService $monthlyPeriods,
    ) {
    }

    /**
     * F7 S1: course-level paid status over ALL non-void invoices (additive; byStudentClassIds is unchanged).
     *
     * status: free (tutoring / zero-fee) > review_required (monthly period that cannot be attributed)
     *         > oldest unsettled period (partial|unpaid) > paid; no non-void invoice => legacy flag
     *         (source=legacy_flag, paid) or unbilled (source=none). Amounts come from the per-invoice
     *         kernel (Payment rows, never stored PaidAmount); applied is capped per invoice, the excess is `overpaid`.
     *
     * @param int[] $studentClassIds
     * @return array<int, array<string, mixed>>
     */
    public function courseStatusesByStudentClassIds(array $studentClassIds, iterable $courses = []): array
    {
        $ids = collect($studentClassIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $courseMap = [];
        foreach ($courses as $course) {
            if ($course instanceof StudentClass) {
                $courseMap[(int) $course->getAttribute('ID')] = $course;
            }
        }
        $missing = $ids->reject(fn ($id) => isset($courseMap[$id]))->all();
        if ($missing !== []) {
            foreach (StudentClass::query()->whereIn('ID', $missing)->get() as $course) {
                $courseMap[(int) $course->getAttribute('ID')] = $course;
            }
        }
        // One query for package paid state (isEffectivelyPaid would otherwise query per package member).
        (new \Illuminate\Database\Eloquent\Collection($courseMap))->loadMissing('coursePackage');
        $invoicesByClass = Invoice::query()
            ->where(fn ($query) => $query->whereNull('Status')->orWhere('Status', '!=', 'void'))
            ->with(['payments' => fn ($query) => $query->select(['id', 'InvoiceID', 'Amount', 'Method']), 'items'])
            ->whereIn('StudentClassID', $ids->all())
            ->orderBy('id')
            ->get(['id', 'StudentClassID', 'IssueDate', 'TotalAmount', 'Status', 'billing_period'])
            ->groupBy('StudentClassID');
        $monthlyByClass = $this->monthlyPeriods->batch(collect($courseMap)->only($ids->all())->values());

        $out = [];
        foreach ($ids as $classId) {
            if (!isset($courseMap[$classId])) {
                continue; // unknown course: never classify missing authoritative data as free
            }
            $out[$classId] = $this->courseStatus(
                $courseMap[$classId],
                $invoicesByClass->get($classId, collect()),
                $monthlyByClass[$classId] ?? [],
            );
        }

        return $out;
    }

    private function unattributedPeriod(?string $billingPeriod): array
    {
        return ['billing_period' => $billingPeriod, 'invoice_ids' => [], 'total' => null, 'applied' => null,
            'overpaid' => null, 'amount_discrepancy' => false, 'outstanding' => null, 'status' => 'review_required'];
    }

    /** @param Collection<int, Invoice> $invoices */
    private function courseStatus(StudentClass $course, Collection $invoices, array $monthly): array
    {
        $result = fn (string $status, int $total, int $applied, int $overpaid, array $periods, string $source, ?int $invoiceId) => [
            'status' => $status, 'payable_total' => $total, 'applied' => $applied,
            'outstanding' => max(0, $total - $applied), 'overpaid' => $overpaid,
            'periods' => $periods, 'source' => $source, 'current_invoice_id' => $invoiceId,
        ];
        $charge = max(0, (int) ($course->getAttribute('Charge') ?? 0));
        $hasBillableInvoice = $invoices->contains(fn ($invoice) => (int) $invoice->getAttribute('TotalAmount') > 0);
        $tutoring = strtolower(trim((string) ($course->getAttribute('ClassType') ?? ''))) === 'tutoring';
        // B15 is the period engine for monthly courses: a period it cannot attribute is review_required,
        // even without invoices (a legacy flag must not settle unattributed months).
        $unattributed = collect($monthly['periods'] ?? [])->where('source', 'unattributed')->pluck('billing_period')->all();
        // B15's own verdict (ambiguous items/coverage gaps, out-of-contract sessions, amount discrepancy) is preserved.
        $monthlyReview = (bool) ($monthly['review_required'] ?? false);
        $isPackageMember = (int) ($course->getAttribute('PackageID') ?? 0) > 0;
        $zeroFee = !$isPackageMember && !$monthlyReview && $charge <= 0 && (float) ($course->getAttribute('Rate') ?? 0) <= 0 && !$hasBillableInvoice;
        if ($tutoring || $zeroFee) {
            return $result('free', 0, 0, 0, [], 'none', null);
        }
        if ($invoices->isEmpty() && ($unattributed !== [] || $monthlyReview)) {
            $periods = array_map(fn ($billingPeriod) => $this->unattributedPeriod($billingPeriod), $unattributed);

            return $result('review_required', $charge, 0, 0, $periods, 'none', null);
        }
        if ($invoices->isEmpty()) {
            // Legacy rule: the Paid flag (or paid package) counts only while no non-void invoice exists.
            if ($course->isEffectivelyPaid() && ($charge > 0 || $isPackageMember)) {
                return $result('paid', $charge, $charge, 0, [], 'legacy_flag', null);
            }
            // Charge 0 yet not free: package members (amount authority is the package, S3) or a positive Rate
            // with an empty Charge column. Never report a settled/zero balance for that shape.
            if ($charge <= 0) {
                return $result('review_required', 0, 0, 0, [], 'none', null);
            }

            return $result('unbilled', $charge, 0, 0, [], 'none', null);
        }

        $periods = [];
        foreach ($invoices as $invoice) {
            $projection = $this->invoiceAmounts->resolve($invoice, $course);
            $key = $projection['billing_period'] ?? 'unknown';
            $total = max(0, (int) $projection['total_amount']);
            $net = max(0, (int) $projection['net_applied']);
            $row = $periods[$key] ?? ['billing_period' => $projection['billing_period'], 'invoice_ids' => [], 'total' => 0,
                'applied' => 0, 'overpaid' => 0, 'amount_discrepancy' => false, 'open_invoice_ids' => []];
            $row['invoice_ids'][] = (int) $invoice->getAttribute('id');
            if ($total > min($total, $net)) {
                $row['open_invoice_ids'][] = (int) $invoice->getAttribute('id');
            }
            $row['total'] += $total;
            $row['applied'] += min($total, $net);
            $row['overpaid'] += max(0, $net - $total);
            $row['amount_discrepancy'] = $row['amount_discrepancy'] || $projection['amount_discrepancy'];
            $periods[$key] = $row;
        }
        ksort($periods);
        foreach ($periods as &$row) {
            $row['outstanding'] = $row['total'] - $row['applied'];
            $row['status'] = $row['outstanding'] <= 0 ? 'paid' : ($row['applied'] > 0 ? 'partial' : 'unpaid');
        }
        unset($row);
        $periods = array_values($periods);
        foreach ($unattributed as $billingPeriod) {
            $periods[] = $this->unattributedPeriod($billingPeriod);
        }

        $worst = collect($periods)->first(fn ($row) => in_array($row['status'], ['unpaid', 'partial'], true));
        $current = $worst ?? collect($periods)->filter(fn ($row) => $row['invoice_ids'] !== [])->last();
        // Prefer an invoice that still has a balance when the worst period holds several.
        $currentInvoiceId = $current ? (int) (end($current['open_invoice_ids']) ?: end($current['invoice_ids']) ?: 0) : 0;
        // A non-free course whose non-void invoices are all zero-value is not settled: review.
        $zeroValueOnly = !$hasBillableInvoice; // free courses already returned above

        return $result(
            ($unattributed !== [] || $monthlyReview || $zeroValueOnly) ? 'review_required' : ($worst['status'] ?? 'paid'),
            (int) collect($periods)->sum('total'),
            (int) collect($periods)->sum('applied'),
            (int) collect($periods)->sum('overpaid'),
            $periods,
            'invoice',
            $currentInvoiceId ?: null,
        );
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
