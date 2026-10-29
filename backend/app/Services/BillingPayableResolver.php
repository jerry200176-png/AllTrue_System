<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\StudentClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Resolve the settlement amount that may be presented as a confirmed payable. */
class BillingPayableResolver
{
    public function __construct(
        private InvoiceAmountReconciliationService $invoiceAmounts,
        private MonthlyPeriodPaymentService $monthlyPeriods,
    ) {
    }

    /**
     * F7 S5 outbound reminders: ids of courses that owe nothing (paid/free). review_required / unknown are NOT
     * returned (callers keep their legacy decision). Resolver failure => [] (legacy fallback) plus a warning.
     * Flag billing.paid_status_outbound_notifications=false => [] (callers then run the shadow comparison).
     *
     * @param iterable<StudentClass> $courses
     * @return array<int, true> keyed by StudentClass ID
     */
    public function settledCourseIds(iterable $courses, string $site): array
    {
        if (!config('billing.paid_status_outbound_notifications', true)) {
            return [];
        }
        $courses = collect($courses);
        if ($courses->isEmpty()) {
            return [];
        }
        try {
            $statuses = $this->courseStatusesByStudentClassIds(
                $courses->map(fn (StudentClass $c) => (int) $c->getAttribute('ID'))->all(),
                $courses
            );
        } catch (\Throwable $e) {
            Log::warning($site . '_resolver_failed', ['error' => $e::class]);

            return [];
        }

        return collect($statuses)->filter(fn ($s) => in_array($s['status'] ?? null, ['paid', 'free'], true))->map(fn () => true)->all();
    }

    /**
     * Founder D2 (in-app #394): a stopped contract whose review_required verdict comes from issued invoices that still
     * owe. Findable as 金額待確認 in the tuition queue, never in confirmed totals, and never on a parent slip.
     * One predicate for the queue, the queue marker, /alerts/tuition-slip and /invoices/{id}/slip-data.
     *
     * @param array<string, mixed> $verdict courseStatusesByStudentClassIds() row
     */
    public static function isAmountPendingConfirmation(StudentClass $course, array $verdict): bool
    {
        return (int) ($course->getAttribute('Stop') ?? 0) === 1
            && ($verdict['status'] ?? null) === 'review_required'
            && ($verdict['source'] ?? null) === 'invoice'
            && (int) ($verdict['outstanding'] ?? 0) > 0;
    }

    /**
     * #4114 (Founder Q3): which contracts / packages have a receipt still standing (net Payment > 0, the mayOwe ledger) on a
     * non-void invoice of the contract or of any course in its package (a package bill sits on one member). A paid status
     * outside these sets rests on the legacy StudentClass.Paid / CoursePackage.paid flag alone: shown 需核對, never 已收,
     * and still never owed. Same receipt / reversal ledger as lastPaidAtByStudentClassIds (#4123): an invoice with net > 0 is
     * exactly one that still has an unreversed receipt, so a receipt cancelled by a reversal is not evidence. Read-only.
     *
     * Package expansion stays inside the package owner's own courses (StudentClass.StudentID = course_packages.student_id)
     * and, when given, the caller's authorized campuses: a course of another student or campus linked to the same PackageID
     * by bad data is never read, so its ledger cannot clear this caller's 需核對 (#4174 review).
     *
     * @param int[] $studentClassIds
     * @param int[] $packageIds
     * @param int[]|null $campusIds the caller's authorized campuses; null = unrestricted (super admin / teacher scope)
     * @return array{classes: array<int, true>, packages: array<int, true>}
     */
    public function validPaymentEvidence(array $studentClassIds, array $packageIds = [], ?array $campusIds = null): array
    {
        $ids = collect($studentClassIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values()->all();
        $packageOf = $ids === [] ? [] : StudentClass::query()->whereIn('ID', $ids)->where('PackageID', '>', 0)
            ->pluck('PackageID', 'ID')->map(fn ($p) => (int) $p)->all();
        $packages = collect($packageIds)->map(fn ($id) => (int) $id)->merge(array_values($packageOf))->filter(fn (int $id) => $id > 0)->unique()->values()->all();
        $campuses = $campusIds === null ? null : array_values(array_unique(array_map('intval', $campusIds)));
        $members = $packages === [] || $campuses === [] ? [] : DB::table('StudentClass')
            ->join('course_packages', 'course_packages.id', '=', 'StudentClass.PackageID')
            ->whereIn('StudentClass.PackageID', $packages)
            ->whereColumn('StudentClass.StudentID', 'course_packages.student_id')
            ->when($campuses !== null, fn ($q) => $q->join('Student', 'Student.id', '=', 'StudentClass.StudentID')
                ->whereIn('Student.CampusID', $campuses))
            ->pluck('StudentClass.PackageID', 'StudentClass.ID')->mapWithKeys(fn ($p, $id) => [(int) $id => (int) $p])->all();
        $scope = array_values(array_unique(array_merge($ids, array_keys($members))));
        $paid = $scope === [] ? [] : DB::table('Invoice')
            ->join('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
            ->whereIn('Invoice.StudentClassID', $scope)
            ->where(fn ($q) => $q->whereNull('Invoice.Status')->orWhere('Invoice.Status', '!=', 'void'))
            ->groupBy('Invoice.id', 'Invoice.StudentClassID')
            ->havingRaw("SUM(CASE WHEN Payment.Amount < 0 OR Payment.Method = 'void' THEN -ABS(Payment.Amount) ELSE Payment.Amount END) > 0")
            ->pluck('Invoice.StudentClassID')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        $paidPackages = [];
        foreach ($members as $classId => $packageId) {
            if (isset($paid[$classId])) {
                $paidPackages[$packageId] = true;
            }
        }
        $classes = [];
        foreach ($ids as $id) {
            // A seed reads its package's receipts only when it is itself an in-scope member of that package.
            if (isset($paid[$id]) || (isset($members[$id]) && isset($paidPackages[$members[$id]]))) {
                $classes[$id] = true;
            }
        }

        return ['classes' => $classes, 'packages' => $paidPackages];
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
            ->get(['id', 'StudentClassID', 'IssueDate', 'DueDate', 'TotalAmount', 'Status', 'billing_period', 'zero_amount_reason'])
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
        // #3991: staff marked a NT$0 bill 免費／贈送 on this no-fee course, so a period-engine review cannot reopen it.
        // #3991 review: B15 flags review only at the top level, so EVERY period it lists must be a marked term; an unmarked
        // month with lessons (an `unattributed` row) keeps the course in review.
        // #4017: the course's fee evidence is read once (and only when a term is marked), not once per marked term.
        $hasFee = !$invoices->contains(fn ($invoice) => $invoice->isMarkedFreeGift()) || Invoice::courseHasFee($course);
        $freePeriods = $invoices->filter(fn ($invoice) => $invoice->freeGiftHolds($course, $hasFee))->pluck('billing_period')->filter()->all();
        $reviewPeriods = collect($monthly['periods'] ?? [])->pluck('billing_period');
        $markedFree = $reviewPeriods->isNotEmpty() && $reviewPeriods->diff($freePeriods)->isEmpty();
        $zeroFee = !$isPackageMember && (!$monthlyReview || $markedFree) && $charge <= 0 && (float) ($course->getAttribute('Rate') ?? 0) <= 0 && !$hasBillableInvoice;
        // #4005 (Founder 2026-10-29): an unmarked zero-fee course is settled only while nothing is owed: a live priced amendment or a
        // positive bill line (also on another invoice, e.g. a member bill) is fee evidence, so the verdict falls through to review/unbilled.
        if ($zeroFee && !$tutoring && Invoice::courseHasBillEvidence($course, true)) {
            $zeroFee = false;
        }
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
                'applied' => 0, 'overpaid' => 0, 'amount_discrepancy' => false, 'open_invoice_ids' => [], 'due_date' => null];
            $row['invoice_ids'][] = (int) $invoice->getAttribute('id');
            if ($total > min($total, $net)) {
                $row['open_invoice_ids'][] = (int) $invoice->getAttribute('id');
                // Earliest due date among the period's open invoices (what the student panel's due-by-today split reads).
                $due = $invoice->getAttribute('DueDate') ? substr((string) $invoice->getAttribute('DueDate'), 0, 10) : null;
                $row['due_date'] = $due !== null && ($row['due_date'] === null || $due < $row['due_date']) ? $due : $row['due_date'];
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

        // resolve() reads items only for date-mode courses (#3454): one batched query, none for count-only batches.
        // Selection projects candidates too (#3875), so every date-mode candidate gets its items here.
        $needItems = $invoicesByClass
            ->filter(fn ($invoices, $classId) => (string) ($courseMap[$classId]->ScheduleMode ?? '') === 'date')
            ->flatten(1);
        if ($needItems->isNotEmpty()) {
            (new \Illuminate\Database\Eloquent\Collection($needItems->all()))->load('items');
        }

        // One session query for all monthly candidates (sole caller AlertController is never inside another preload).
        $dateIds = $invoicesByClass->keys()->filter(fn ($classId) => (string) ($courseMap[$classId]->ScheduleMode ?? '') === 'date')->all();
        if ($dateIds !== []) {
            $this->invoiceAmounts->preloadSessions($dateIds);
        }
        $resolved = [];
        foreach ($ids as $classId) {
            [$invoice, $projection] = $this->selectRelevantInvoice($invoicesByClass->get($classId, collect()), $courseMap[$classId] ?? null);
            if (!$invoice) {
                $resolved[$classId] = $this->unbilled();
                continue;
            }

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
        if ($dateIds !== []) {
            $this->invoiceAmounts->forgetPreloadedSessions();
        }

        return $resolved;
    }

    /**
     * Batch-fetch Invoice paid aggregates per StudentClass ID.
     *
     * @param  int[]  $studentClassIds
     * @return array<int, array{paid_amount: int, total_amount: int, active_invoice_count: int, outstanding_amount: int}>
     */
    public function invoiceAggregateByStudentClassIds(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }

        $rows = DB::table('Invoice')
            ->whereIn('StudentClassID', $studentClassIds)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
            ->select(
                'StudentClassID',
                DB::raw('COALESCE(SUM(PaidAmount), 0) as paid_amount'),
                DB::raw('COALESCE(SUM(TotalAmount), 0) as total_amount'),
                DB::raw('COUNT(*) as active_invoice_count')
            )
            ->groupBy('StudentClassID')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->StudentClassID] = [
                'paid_amount' => (int) $row->paid_amount,
                'total_amount' => (int) $row->total_amount,
                'active_invoice_count' => (int) $row->active_invoice_count,
                'outstanding_amount' => max(0, (int) $row->total_amount - (int) $row->paid_amount),
            ];
        }

        return $map;
    }

    /**
     * Batch-fetch the latest valid receipt date (Y-m-d) per StudentClass ID (#4123), from the same net Payment ledger the
     * resolver projects (mayOwe / InvoiceAmountReconciliationService): a receipt is a positive, non-void row; a reversal is
     * a negative row or a `Method='void'` row of any sign. Per non-void invoice, reversals are applied in write order; see
     * latestUnreversedReceiptDate for how each one is matched to the receipt it reverses. A date counts only from a receipt
     * that still has a positive amount after that, so an invoice whose net applied is 0 gives no date. Void invoices are
     * ignored.
     *
     * @param  int[]  $studentClassIds
     * @return array<int, string>  only classes that have a dated payment
     */
    public function lastPaidAtByStudentClassIds(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }

        $rows = DB::table('Invoice')
            ->join('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
            ->whereIn('Invoice.StudentClassID', $studentClassIds)
            ->where(fn ($q) => $q->whereNull('Invoice.Status')->orWhere('Invoice.Status', '!=', 'void'))
            // a legacy-backfilled receipt is linked only one way, via payment_reports.payment_id (BackfillLegacyPayments)
            ->leftJoin('payment_reports as reversed_report', 'reversed_report.id', '=', 'Payment.payment_report_id')
            ->select('Invoice.StudentClassID', 'Payment.InvoiceID', 'Payment.id', 'Payment.Amount', 'Payment.Method', 'Payment.PaidAt',
                'Payment.payment_report_id', 'reversed_report.payment_id as report_payment_id')
            ->orderBy('Payment.id')
            ->get();

        $map = [];
        foreach ($rows->groupBy('InvoiceID') as $payments) {
            $classId = (int) $payments->first()->StudentClassID;
            $date = $this->latestUnreversedReceiptDate($payments);
            if ($date !== null && (!isset($map[$classId]) || $date > $map[$classId])) {
                $map[$classId] = $date;
            }
        }

        return $map;
    }

    /**
     * IDs of the given contracts that have any Payment row at all (void invoices and reversal rows included). Only a
     * contract with no Payment history may fall back to the legacy StudentClass.PayDate for 最近繳費 (#4123).
     *
     * @param  int[]  $studentClassIds
     * @return array<int, true>
     */
    public function studentClassIdsWithPaymentHistory(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }

        return DB::table('Invoice')
            ->join('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
            ->whereIn('Invoice.StudentClassID', $studentClassIds)
            ->distinct()
            ->pluck('Invoice.StudentClassID')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * One invoice's ledger, rows in write (id) order. Each reversal, in write order, cancels:
     *  1. the receipt it is explicitly linked to: the receipt with the same payment_report_id, or the receipt its payment
     *     report points to through payment_reports.payment_id (legacy backfill rows carry no payment_report_id);
     *  2. what is left, only receipts written before it (a reversal cannot reverse a later repayment): the single earlier
     *     receipt whose standing amount equals the remainder, otherwise the oldest earlier receipts first, so an ambiguous
     *     reversal fails safe to keeping the newest receipt's date;
     *  3. anything still left (no earlier receipt to reverse) nets against later receipts, oldest first, so an invoice whose
     *     net applied is 0 never gives a date.
     *
     * @param  Collection<int, object>  $payments
     */
    private function latestUnreversedReceiptDate(Collection $payments): ?string
    {
        $isReversal = fn ($p) => (int) ($p->Amount ?? 0) < 0 || (string) ($p->Method ?? '') === 'void';
        $receipts = $payments->filter(fn ($p) => !$isReversal($p) && (int) ($p->Amount ?? 0) > 0)
            ->map(fn ($p) => ['left' => (int) $p->Amount, 'paid_at' => (string) ($p->PaidAt ?? ''), 'id' => (int) $p->id,
                'report' => $p->payment_report_id === null ? null : (int) $p->payment_report_id])
            ->values()->all();
        // oldest first: by receipt date, then write order
        usort($receipts, fn ($a, $b) => [$a['paid_at'], $a['id']] <=> [$b['paid_at'], $b['id']]);
        $orphaned = 0;
        foreach ($payments->filter($isReversal) as $reversal) {
            $amount = abs((int) ($reversal->Amount ?? 0));
            $report = $reversal->payment_report_id === null ? null : (int) $reversal->payment_report_id;
            $reportPayment = ($reversal->report_payment_id ?? null) === null ? null : (int) $reversal->report_payment_id;
            if ($report !== null || $reportPayment !== null) {
                $amount = $this->cancelReceipts($receipts, $amount, fn ($r) => ($report !== null && $r['report'] === $report)
                    || ($reportPayment !== null && $r['id'] === $reportPayment));
            }
            $earlier = fn ($r) => $r['id'] < (int) $reversal->id;
            $exact = array_keys(array_filter($receipts, fn ($r) => $earlier($r) && $r['left'] > 0 && $r['left'] === $amount));
            if ($amount > 0 && count($exact) === 1) {
                $receipts[$exact[0]]['left'] = 0;
                $amount = 0;
            }
            $orphaned += $this->cancelReceipts($receipts, $amount, $earlier);
        }
        $this->cancelReceipts($receipts, $orphaned, fn () => true);

        $latest = null;
        foreach ($receipts as $receipt) {
            if ($receipt['left'] > 0 && $receipt['paid_at'] !== '' && ($latest === null || $receipt['paid_at'] > $latest)) {
                $latest = $receipt['paid_at'];
            }
        }

        return $latest === null ? null : substr($latest, 0, 10);
    }

    /**
     * Cancel up to $amount from the matching receipts in their (oldest-first) order; returns what could not be cancelled.
     *
     * @param  array<int, array{left: int, paid_at: string, id: int, report: int|null}>  $receipts
     */
    private function cancelReceipts(array &$receipts, int $amount, callable $matches): int
    {
        foreach ($receipts as &$receipt) {
            if ($amount <= 0) {
                break;
            }
            if ($receipt['left'] > 0 && $matches($receipt)) {
                $take = min($amount, $receipt['left']);
                $receipt['left'] -= $take;
                $amount -= $take;
            }
        }
        unset($receipt);

        return $amount;
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

    /**
     * The bill the queue shows and collect buttons pay: the first (open status, newest period, then id; monthly: oldest
     * period, any status) whose Payment-row projection still owes (#3875, #3888). None owes → the first in the default order.
     * @param Collection<int, Invoice> $invoices
     * @return array{0: ?Invoice, 1: ?array}
     */
    private function selectRelevantInvoice(Collection $invoices, ?StudentClass $course): array
    {
        foreach ($this->sortRelevant($invoices, (string) ($course->ScheduleMode ?? '') === 'date') as $invoice) {
            if ($this->mayOwe($invoice) && (int) ($projection = $this->invoiceAmounts->resolve($invoice, $course))['outstanding_amount'] > 0) {
                return [$invoice, $projection];
            }
        }
        $fallback = $this->sortRelevant($invoices)->first();

        return $fallback ? [$fallback, $this->invoiceAmounts->resolve($fallback, $course)] : [null, null];
    }

    /**
     * The order the queue walks a course's non-void bills to pick its payable bill (selectRelevantInvoice). A collect
     * request that names no bill walks the same order, so it lands on the bill the list shows (Founder 2026-10-13).
     * @param Collection<int, Invoice> $invoices
     * @return Collection<int, Invoice>
     */
    public function collectionOrder(Collection $invoices, ?StudentClass $course): Collection
    {
        return $this->sortRelevant($invoices, (string) ($course->ScheduleMode ?? '') === 'date')->values();
    }

    /** Cheap superset of "projection still owes" from the loaded payments (see InvoiceAmountReconciliationService). */
    private function mayOwe(Invoice $invoice): bool
    {
        $net = 0;
        foreach ($invoice->getRelationValue('payments') ?? [] as $payment) {
            $amount = (int) ($payment->Amount ?? 0);
            $net += ($amount < 0 || (string) ($payment->Method ?? '') === 'void') ? -abs($amount) : $amount;
        }
        $net = max(0, $net);

        return (int) ($invoice->getAttribute('TotalAmount') ?? 0) > $net
            || ((string) ($invoice->getAttribute('Status') ?? '') === 'unpaid' && $net === 0);
    }

    /** @param Collection<int, Invoice> $invoices */
    private function sortRelevant(Collection $invoices, bool $oldestFirst = false): Collection
    {
        return $invoices->sort(function (Invoice $left, Invoice $right) use ($oldestFirst): int {
            $leftOpen = in_array((string) ($left->getAttribute('Status') ?? ''), ['unpaid', 'partial'], true);
            $rightOpen = in_array((string) ($right->getAttribute('Status') ?? ''), ['unpaid', 'partial'], true);
            // Monthly: period order only; the Payment-row projection decides which still owes (#3888 r4244111096).
            if ($leftOpen !== $rightOpen && !$oldestFirst) {
                return $leftOpen ? -1 : 1;
            }

            $leftPeriod = (string) ($left->getAttribute('billing_period') ?: substr((string) $left->getAttribute('IssueDate'), 0, 7));
            $rightPeriod = (string) ($right->getAttribute('billing_period') ?: substr((string) $right->getAttribute('IssueDate'), 0, 7));
            if ($leftPeriod !== $rightPeriod) {
                return $oldestFirst ? strcmp($leftPeriod, $rightPeriod) : strcmp($rightPeriod, $leftPeriod);
            }

            return ($oldestFirst ? -1 : 1) * ((int) $right->getAttribute('id') <=> (int) $left->getAttribute('id'));
        });
    }
}
