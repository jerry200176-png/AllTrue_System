<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\BillingPayableResolver;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\MonthlyRenewalPeriodService;
use App\Support\AccountingCourseClarity;
use App\Support\Utf8mb3SearchSanitizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountingController extends Controller
{
    public function __construct(private InvoiceAmountReconciliationService $invoiceAmounts)
    {
    }

    public function payments(Request $request)
    {
        return $this->paymentResponse($request, false);
    }

    public function paymentsExport(Request $request)
    {
        return $this->paymentResponse($request, true);
    }

    /**
     * Plan B step 1: read-only proposal of monthly contracts needing next-period billing.
     * Price/period come from MonthlyRenewalPeriodService, the same code renewMonthly uses.
     * Contracts that already have the proposed renewal are excluded, not listed.
     */
    public function monthlyDrafts(Request $request)
    {
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $monthStart = Carbon::createFromFormat('!Y-m', (string) $request->input('month', now('Asia/Taipei')->format('Y-m')), 'Asia/Taipei');
        $end = $monthStart->copy()->endOfMonth()->toDateString();

        $query = StudentClass::with(['student', 'subjectRecord'])
            ->where('Stop', 0)
            ->where('ScheduleMode', 'date')
            ->where(fn ($q) => $q->whereNull('PackageID')->orWhere('PackageID', 0))
            ->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) NOT IN ('tutoring', 'trial')")
            ->whereNotNull('EndDate')
            ->whereDate('EndDate', '<', $end);
        if (($guard = $this->applyStudentClassCampusGuard($request, $query)) !== null) {
            return $guard;
        }

        $svc = app(MonthlyRenewalPeriodService::class);
        $rows = [];
        $totals = ['ready' => 0, 'blocked' => 0, 'lapsed_no_lessons' => 0];
        foreach ($query->orderBy('ID')->get() as $course) {
            $review = $svc->inspect($course, $end);
            if ($svc->findDuplicate($course, $review['start_date'], $end) !== null) {
                continue;
            }
            $blocker = $review['blockers'][0] ?? null;
            $oldEnd = Carbon::parse($course->getAttribute('EndDate'))->toDateString();
            $status = $blocker ? 'blocked' : ($oldEnd < $monthStart->toDateString() ? 'lapsed_no_lessons' : 'ready');
            $preview = $svc->previewPeriod($course, $review['start_date'], $end);
            $totals[$status]++;
            $rows[] = [
                'student_class_id' => (int) $course->getKey(),
                'student_id' => (int) $course->getAttribute('StudentID'),
                'student_name' => $course->student?->getAttribute('name'),
                'subject' => $course->subjectRecord?->getAttribute('Subject_Name'),
                'teacher_id' => (int) $course->getAttribute('TeacherID'),
                'current_end_date' => $oldEnd,
                'proposed_start_date' => $review['start_date'],
                'proposed_end_date' => $end,
                'period_sessions' => $preview['sessions'],
                'amount' => $preview['charge'],
                'due_date' => $review['due_date'],
                'status' => $status,
                'blocker_code' => $blocker['code'] ?? null,
                'blocker_message' => $blocker['message'] ?? null,
            ];
        }

        return response()->json(['month' => $monthStart->format('Y-m'), 'data' => $rows, 'totals' => $totals]);
    }

    public function settledCourses(Request $request)
    {
        // F7 S3a: stopped contracts are listed by what the resolver says they owe (or that the
        // payment period cannot be attributed), not by a closed_reason allowlist.
        $applyFilters = function ($query) use ($request) {
            $guard = $this->applyStudentClassCampusGuard($request, $query);
            if ($guard !== null) {
                return $guard;
            }
            if ($request->filled('course_id')) {
                $query->where('ID', (int) $request->input('course_id'));
            }
            if ($request->filled('student')) {
                $student = trim((string) $request->input('student'));
                $query->whereHas('student', function ($q) use ($student) {
                    Utf8mb3SearchSanitizer::applyLike($q, 'name', $student);
                });
            }
            if ($request->filled('subject')) {
                $subject = trim((string) $request->input('subject'));
                $query->whereHas('subjectRecord', fn ($q) => $q->where('Subject_Name', 'like', "%{$subject}%"));
            }

            return null;
        };

        $stopped = StudentClass::query()
            ->where('Stop', 1)
            ->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) <> ?", ['tutoring'])
            ->where(fn ($q) => $q->whereNull('closed_reason')->orWhere('closed_reason', '!=', 'waived'));
        if (($guard = $applyFilters($stopped)) !== null) {
            return $guard;
        }
        // Same scope, filters and ordering as the final query, capped at its 500-row limit, so the resolver
        // never sees more than 500 stopped contracts. ponytail: if more than 500 stopped contracts match, owing ones
        // past the cap are not listed until filters narrow; chunk/paginate the resolver if that ever bites.
        $stoppedCourses = $stopped->orderByDesc('PayDate')->orderByDesc('ID')->limit(500)->get();
        $stoppedStatuses = app(BillingPayableResolver::class)
            ->courseStatusesByStudentClassIds($stoppedCourses->pluck('ID')->all(), $stoppedCourses);
        $listedStopped = [];
        foreach ($stoppedCourses as $course) {
            $status = $stoppedStatuses[(int) $course->getAttribute('ID')] ?? null;
            if ($status !== null && $this->stoppedCourseVisibleState($course, $status) !== null) {
                $listedStopped[] = (int) $course->getAttribute('ID');
            }
        }

        $query = StudentClass::with(['student', 'subjectRecord'])
            ->where(function ($q) use ($listedStopped) {
                $q->where('Paid', 1)
                    ->orWhereHas('invoices', fn ($invoice) => $invoice->where('Status', 'paid'))
                    ->orWhere('closed_reason', 'waived') // 歷史 · 確認不收
                    ->orWhereIn('ID', $listedStopped);
            });
        if (($guard = $applyFilters($query)) !== null) {
            return $guard;
        }

        $courses = $query
            ->orderByDesc('PayDate')
            ->orderByDesc('ID')
            ->limit(500)
            ->get();
        $courseIds = $courses->pluck('ID')->map(fn ($id) => (int) $id)->values()->all();
        $invoiceMap = Invoice::with([
                'studentClass',
                'items',
                'payments' => fn ($q) => $q->select(['id', 'InvoiceID', 'Amount', 'PaidAt', 'Method']),
            ])
            ->whereIn('StudentClassID', $courseIds)
            ->notVoided()
            ->get()
            ->groupBy('StudentClassID');
        $reportMap = PaymentReport::query()
            ->whereIn('StudentClassID', $courseIds)
            ->where('status', 'confirmed')
            ->select(['id', 'StudentClassID', 'payment_date'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('StudentClassID');

        $rows = $courses->map(function (StudentClass $course) use ($invoiceMap, $reportMap, $stoppedStatuses) {
            $invoices = $invoiceMap->get((int) $course->ID, collect());
            $reports = $reportMap->get((int) $course->ID, collect());
            [$invoiceTotal, $appliedTotal, $overpaidTotal, $outstandingTotal] = $this->invoiceTotals($invoices);

            $legacyPaid = $invoices->isEmpty() && (int) ($course->Paid ?? 0) === 1;
            $closedReason = (string) ($course->getAttribute('closed_reason') ?? '');
            $status = $stoppedStatuses[(int) $course->getAttribute('ID')] ?? null;
            $state = $status !== null && (int) $course->getAttribute('Stop') === 1 ? $this->stoppedCourseVisibleState($course, $status) : null;
            $pendingReconciliation = $state === 'pending';
            $paymentReview = $state === 'review';
            if ($state === 'pending' || $state === 'review') {
                $invoiceTotal = (int) $status['payable_total'];
                $appliedTotal = (int) $status['applied'];
                $outstandingTotal = (int) $status['outstanding'];
            }
            $waived = $closedReason === 'waived';
            if ($waived) {
                $outstandingTotal = 0; // 確認不收：不再算欠款（帳單與收款歷史保留）
            }
            $latestReport = $reports->first();
            $lastPaidAt = $course->PayDate ? substr((string) $course->PayDate, 0, 10) : null;
            if (!$lastPaidAt && $latestReport?->payment_date) {
                $lastPaidAt = $latestReport->payment_date->toDateString();
            }

            return [
                'student_class_id' => (int) $course->ID,
                'course_ref' => $this->courseRef((int) $course->ID),
                'student_id' => (int) $course->StudentID,
                'student_name' => (string) ($course->student?->name ?? ''),
                'subject' => $course->displaySubjectName(),
                'schedule_mode' => (string) ($course->ScheduleMode ?? ''),
                'charge' => (int) ($course->Charge ?? 0),
                'paid_amount' => $invoices->isEmpty() ? ((int) ($course->Paid ?? 0) === 1 ? (int) ($course->Charge ?? 0) : 0) : $appliedTotal,
                'invoice_total' => $invoiceTotal,
                'outstanding_amount' => $outstandingTotal,
                'waivable_amount' => $this->waivableAmount($course, $invoices),
                'overpaid_amount' => $overpaidTotal,
                'invoice_count' => $invoices->count(),
                'receipt_count' => $reports->count(),
                'last_paid_at' => $lastPaidAt,
                'legacy_paid_without_invoice' => $legacyPaid,
                'has_exception' => $overpaidTotal > 0,
                'pending_reconciliation' => $pendingReconciliation,
                'payment_review_required' => $paymentReview,
                'reconciliation_label' => $pendingReconciliation
                    ? ($closedReason === '' ? '暫停中 · 待對帳' : '結案待對帳')
                    : ($paymentReview ? '付款期間待確認' : ($waived ? '歷史 · 確認不收' : null)),
                'closed_reason' => $course->getAttribute('closed_reason'),
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'course_count' => $rows->count(),
                'legacy_count' => $rows->where('legacy_paid_without_invoice', true)->count(),
                'exception_count' => $rows->where('has_exception', true)->count(),
                'pending_reconciliation_count' => $rows->where('pending_reconciliation', true)->count(),
                'payment_review_count' => $rows->where('payment_review_required', true)->count(),
                'pending_reconciliation_total' => (int) $rows->where('pending_reconciliation', true)->sum('outstanding_amount'),
                'paid_total' => (int) $rows->sum('paid_amount'),
                'overpaid_total' => (int) $rows->sum('overpaid_amount'),
            ],
        ]);
    }

    /**
     * Stopped contract: 'pending' (resolver says it still owes), 'review' (payment period unattributable),
     * 'history' (closed settled_pending/amended but the resolver says paid) or null.
     * waived is history with outstanding 0, never pending.
     *
     * @param array<string, mixed> $status BillingPayableResolver::courseStatusesByStudentClassIds() row
     */
    private function stoppedCourseVisibleState(StudentClass $course, array $status): ?string
    {
        if ((string) ($course->getAttribute('closed_reason') ?? '') === 'waived') {
            return null;
        }
        if ($status['status'] === 'review_required') {
            return 'review';
        }
        // Settled by Payment rows although the invoice/closure state says otherwise: keep it in history.
        if ($status['status'] === 'paid' && in_array((string) $course->getAttribute('closed_reason'), ['settled_pending', 'contract_amended'], true)) {
            return 'history';
        }

        return in_array($status['status'], ['unpaid', 'partial', 'unbilled'], true) && (int) $status['outstanding'] > 0 ? 'pending' : null;
    }

    /** 確認不收會沖銷的金額：所有未繳帳單的應收合計；沒有帳單時用 Charge。 */
    private function waivableAmount(StudentClass $course, $invoices): int
    {
        if ($invoices->isEmpty()) {
            return (int) ($course->Charge ?? 0);
        }

        return (int) $invoices->filter(fn ($i) => (string) ($i->Status ?? '') !== 'paid')
            ->sum(fn ($i) => (int) $this->invoiceAmounts->resolve($i, $i->getRelationValue('studentClass'))['total_amount']);
    }

    /** @return array{0:int,1:int,2:int,3:int} invoice total, applied, overpaid, outstanding */
    private function invoiceTotals($invoices): array
    {
        $invoiceTotal = $appliedTotal = $overpaidTotal = $outstandingTotal = 0;
        foreach ($invoices as $invoice) {
            $projection = $this->invoiceAmounts->resolve($invoice, $invoice->getRelationValue('studentClass'));
            $invoiceTotal += $projection['total_amount'];
            $appliedTotal += $projection['applied_amount'];
            $overpaidTotal += $projection['overpaid_amount'];
            $outstandingTotal += $projection['outstanding_amount'];
        }

        return [$invoiceTotal, $appliedTotal, $overpaidTotal, $outstandingTotal];
    }

    /**
     * 確認不收（director）：結案待對帳且仍有欠款的合約，明確沖銷並留稽核紀錄。
     * 只改 closed_reason 與未結帳單狀態；Paid / Charge / 收款一律不動。
     */
    public function waiveCourse(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:2', 'max:200']]);
        $reason = trim($data['reason']);
        if (mb_strlen($reason) < 2) {
            return response()->json(['message' => '請填寫確認不收的原因（至少 2 字）'], 422);
        }

        return DB::transaction(function () use ($request, $id, $reason) {
            $course = StudentClass::with('student')->whereKey($id)->lockForUpdate()->first();
            if (!$course) {
                return response()->json(['message' => '找不到此課程合約'], 404);
            }
            if ($denied = $this->authorizeCampusForStudent($request, (int) ($course->student->CampusID ?? 0))) {
                return $denied;
            }

            if ($course->isPartOfPackage()) {
                return response()->json(['message' => '套裝課程請到套裝處理'], 422);
            }

            $closedReason = (string) ($course->closed_reason ?? '');
            $unpaid = (int) ($course->Paid ?? 0) !== 1;
            $pending = $closedReason === 'settled_pending' || ($closedReason === 'contract_amended' && $unpaid);
            if ((int) $course->Stop !== 1 || !$pending || !$unpaid) {
                return response()->json(['message' => '只有「結案待對帳」且尚未繳清的合約才能確認不收'], 422);
            }

            $invoices = Invoice::with(['studentClass', 'items', 'payments'])
                ->where('StudentClassID', $id)
                ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))->lockForUpdate()->get();
            // 已有收款的帳單不在此處理，避免作廢帳單蓋住已收的錢。
            if ($invoices->contains(fn ($i) => $i->payments->isNotEmpty() || (int) ($i->PaidAmount ?? 0) !== 0)) {
                return response()->json(['message' => '此合約已有收款紀錄，請先到帳務更正後再處理'], 422);
            }
            // A waiver may only touch invoices that belong to this course alone. Any non-void invoice involving this
            // course (as anchor or line) that also involves another course, or has no anchor, needs accounting first.
            $involved = DB::table('Invoice')->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
                ->where(fn ($q) => $q->where('StudentClassID', $id)
                    ->orWhereIn('id', DB::table('InvoiceItem')->where('StudentClassID', $id)->select('InvoiceID')))
                ->get(['id', 'StudentClassID']);
            $shared = $involved->contains(fn ($inv) => (int) ($inv->StudentClassID ?? 0) !== $id)
                || ($involved->isNotEmpty() && DB::table('InvoiceItem')->whereIn('InvoiceID', $involved->pluck('id')->all())
                    ->whereNotNull('StudentClassID')->where('StudentClassID', '!=', $id)->exists());
            if ($shared) {
                return response()->json(['message' => '此合約在合併帳單中，請先到帳務處理該帳單'], 422);
            }
            if (PaymentReport::query()->where('StudentClassID', $id)->where('status', 'confirmed')->exists()) {
                return response()->json(['message' => '此合約已有確認過的繳費回報，請先到帳務更正後再處理'], 422);
            }
            if (PaymentReport::query()->where('StudentClassID', $id)->where('status', 'pending')->exists()) {
                return response()->json(['message' => '有待確認的繳費回報，請先確認或退回'], 422);
            }
            $toVoid = $invoices->filter(fn ($i) => (string) ($i->Status ?? '') !== 'paid');
            $outstanding = $this->waivableAmount($course, $invoices);
            // The director must confirm the exact amount being written off (the dialog sends what it showed).
            if (!$request->filled('expected_amount') || (int) $request->input('expected_amount') !== $outstanding) {
                return response()->json(['message' => '金額已變動，請重新整理', 'waivable_amount' => $outstanding], 409);
            }
            if ($outstanding <= 0) {
                return response()->json(['message' => '此合約已沒有欠款，不需要確認不收'], 422);
            }

            $openIds = $toVoid->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
            $actorId = (int) $request->attributes->get('auth_user_id');
            $course->setAttribute('closed_reason', 'waived');
            // 保留原結案快照（contract_amended 還原用）於 previous 欄位
            $course->setAttribute('settlement_snapshot', json_encode([
                'kind' => 'waived',
                'before' => ['closed_reason' => $closedReason, 'Paid' => $course->Paid, 'Charge' => $course->Charge, 'invoice_ids' => $openIds],
                'after' => ['closed_reason' => 'waived', 'invoice_status' => 'void'],
                'previous_snapshot' => $course->getOriginal('settlement_snapshot'),
                'outstanding_amount' => $outstanding,
                'reason' => $reason,
                'actor_user_id' => $actorId ?: null,
                'at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $course->save();
            if ($openIds !== []) {
                Invoice::query()->whereIn('id', $openIds)->update(['Status' => 'void']);
            }
            $correlationId = (string) Str::uuid();
            SecurityAuditEvent::append('accounting.course_waived', 'success', [
                'correlation_id' => $correlationId,
                'actor_type' => 'user', 'actor_id' => $actorId ?: null,
                'subject_type' => 'student_class', 'subject_id' => $id,
                'campus_id' => (int) ($course->student->CampusID ?? 0) ?: null,
            ], [
                'outstanding_amount' => $outstanding,
                'old_type' => $closedReason, 'new_type' => 'waived',
                'reason_hash' => hash('sha256', $reason),
            ]);
            // append() swallows write failures; a waiver without its audit row must roll back.
            if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
                throw new \RuntimeException('確認不收稽核紀錄寫入失敗，已取消操作');
            }

            return response()->json(['message' => '已確認不收', 'student_class_id' => $id, 'outstanding_amount' => $outstanding]);
        });
    }

    public function ledger(Request $request)
    {
        $studentClassId = (int) $request->input('student_class_id', 0);
        $reportId = (int) $request->input('report_id', 0);

        if ($studentClassId <= 0 && $reportId <= 0) {
            return response()->json(['message' => '請指定課程或收據'], 422);
        }

        $anchorReport = null;
        if ($reportId > 0) {
            $anchorReport = PaymentReport::with(['student', 'studentClass.student'])->find($reportId);
            if (!$anchorReport) {
                return response()->json(['message' => '找不到收據'], 404);
            }
            $studentClassId = $studentClassId > 0 ? $studentClassId : (int) $anchorReport->StudentClassID;
        }

        $anchorClass = $studentClassId > 0
            ? StudentClass::with(['student', 'subjectRecord'])->where('ID', $studentClassId)->first()
            : null;
        if (!$anchorClass && $anchorReport?->studentClass) {
            $anchorClass = $anchorReport->studentClass;
        }
        if (!$anchorClass) {
            return response()->json(['message' => '找不到課程'], 404);
        }

        $student = $anchorClass->student ?: Student::find($anchorClass->StudentID);
        if (!$student) {
            return response()->json(['message' => '找不到學生'], 404);
        }

        $guard = $this->authorizeCampusForStudent($request, (int) $student->CampusID);
        if ($guard !== null) {
            return $guard;
        }

        $classes = StudentClass::with(['student', 'subjectRecord'])
            ->where('StudentID', $student->id)
            ->orderByDesc('StartDate')
            ->orderByDesc('ID')
            ->get();
        $classIds = $classes->pluck('ID')->map(fn ($id) => (int) $id)->values()->all();
        $firstSessionDates = ClassSession::query()
            ->whereIn('StudentClassID', $classIds)
            ->select('StudentClassID')
            ->selectRaw('MIN(SessionDate) AS first_session_date')
            ->groupBy('StudentClassID')
            ->pluck('first_session_date', 'StudentClassID');

        $invoices = Invoice::with(['studentClass', 'items', 'payments' => function ($query) {
                $query->select(['id', 'InvoiceID', 'Amount', 'PaidAt', 'Method', 'Note', 'payment_report_id'])
                    ->orderBy('PaidAt')
                    ->orderBy('id');
            }])
            ->whereIn('StudentClassID', $classIds)
            ->orderByRaw("COALESCE(billing_period, DATE_FORMAT(IssueDate, '%Y-%m')) DESC")
            ->orderByDesc('id')
            ->get();

        $reports = PaymentReport::with(['confirmedByUser', 'payment'])
            ->where('StudentID', $student->id)
            ->whereIn('status', ['pending', 'confirmed', 'voided', 'rejected'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        $reportsByPaymentId = $reports->whereNotNull('payment_id')->keyBy('payment_id');
        $reportsById = $reports->keyBy('id');
        $reportsByInvoiceId = $reports->whereNotNull('InvoiceID')->groupBy('InvoiceID');
        $anomalies = [];

        $invoiceRows = $invoices->map(function (Invoice $invoice) use ($reportsByPaymentId, $reportsById, $reportsByInvoiceId, $firstSessionDates, &$anomalies) {
            $invoiceStudentClassId = (int) $invoice->getAttribute('StudentClassID');
            $invoiceStudentClass = $invoice->getRelationValue('studentClass');
            $payments = $invoice->payments;
            $projection = $this->invoiceAmounts->resolve($invoice, $invoiceStudentClass);
            $totalAmount = $projection['total_amount'];
            $netApplied = $projection['net_applied'];
            $voidedAmount = $projection['voided_amount'];
            $appliedAmount = $projection['applied_amount'];
            $overpaidAmount = $projection['overpaid_amount'];
            $status = (string) ($invoice->Status ?? '');
            // 作廢帳單保留總額供稽核，但不算欠款。
            $outstanding = $status === 'void' ? 0 : $projection['outstanding_amount'];
            $paidAmount = (int) ($invoice->PaidAmount ?? 0);
            $invoiceAnomalies = [];

            if ($overpaidAmount > 0) {
                $invoiceAnomalies[] = $this->ledgerAnomaly(
                    'overpayment_pending_review',
                    'critical',
                    '同一張帳單的收款超過應收金額，多出的部分請撤銷或轉到其他帳單。',
                    $invoice
                );
            }

            if ($paidAmount !== $appliedAmount) {
                $invoiceAnomalies[] = $this->ledgerAnomaly(
                    'paid_amount_mismatch',
                    'warning',
                    '帳單已收金額與收款紀錄合計不一致。',
                    $invoice
                );
            }

            if ($status === 'paid' && $outstanding > 0) {
                $invoiceAnomalies[] = $this->ledgerAnomaly(
                    'paid_status_with_balance',
                    'critical',
                    '帳單顯示已繳，但依收款紀錄仍有未結清金額。',
                    $invoice
                );
            }

            if (in_array($status, ['unpaid', 'partial'], true) && $totalAmount > 0 && $outstanding === 0) {
                $invoiceAnomalies[] = $this->ledgerAnomaly(
                    'open_status_without_balance',
                    'warning',
                    '帳單仍顯示未繳或部分付款，但收款紀錄已繳足。',
                    $invoice
                );
            }

            $remainingInvoiceAmount = $totalAmount;
            $paymentRows = $payments->map(function ($payment) use ($reportsByPaymentId, $reportsById, $invoice, &$invoiceAnomalies, &$remainingInvoiceAmount) {
                $report = $reportsByPaymentId->get((int) $payment->id)
                    ?? ($payment->payment_report_id ? $reportsById->get((int) $payment->payment_report_id) : null);
                $isVoid = (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void';
                $amount = (int) ($payment->Amount ?? 0);
                $appliedToInvoice = 0;
                $unappliedAmount = 0;
                $applicationStatus = $isVoid ? 'voided' : 'applied';

                if (!$isVoid && $amount > 0) {
                    $appliedToInvoice = min($amount, max(0, $remainingInvoiceAmount));
                    $remainingInvoiceAmount -= $appliedToInvoice;
                    $unappliedAmount = max(0, $amount - $appliedToInvoice);
                    if ($appliedToInvoice === 0) {
                        $applicationStatus = 'overpayment_pending_review';
                    } elseif ($unappliedAmount > 0) {
                        $applicationStatus = 'partially_applied';
                    }
                }

                // 轉課 transfer_in is a ledger carry-over, not a receipted cash payment.
                if (!$isVoid && !$report && (string) ($payment->Method ?? '') !== 'transfer_in') {
                    $invoiceAnomalies[] = $this->ledgerAnomaly(
                        'payment_without_receipt',
                        'warning',
                        '收款紀錄找不到對應收據。',
                        $invoice,
                        null,
                        (int) $payment->id
                    );
                }

                return [
                    'id' => (int) $payment->id,
                    'payment_no' => $this->paymentNo((int) $payment->id, $payment->PaidAt ? substr((string) $payment->PaidAt, 0, 7) : null),
                    'paid_at' => $payment->PaidAt ? substr((string) $payment->PaidAt, 0, 10) : null,
                    'amount' => $amount,
                    'applied_amount' => $appliedToInvoice,
                    'unapplied_amount' => $unappliedAmount,
                    'application_status' => $applicationStatus,
                    'method' => (string) ($payment->Method ?? ''),
                    'note' => (string) ($payment->Note ?? ''),
                    'is_void' => $isVoid,
                    'report_id' => $report ? (int) $report->id : null,
                    'receipt_no' => $report ? $this->receiptNo((int) $report->id, $report->payment_date ? $report->payment_date->toDateString() : null) : null,
                    'report_status' => $report ? (string) $report->status : null,
                ];
            })->values();

            $invoiceReports = ($reportsByInvoiceId->get((int) $invoice->id, collect()))
                ->map(fn (PaymentReport $report) => $this->ledgerReportRow($report))
                ->values();

            foreach ($invoiceReports as $reportRow) {
                if ($reportRow['status'] === 'confirmed' && empty($reportRow['payment_id'])) {
                    $invoiceAnomalies[] = $this->ledgerAnomaly(
                        'receipt_without_payment',
                        'warning',
                        '已確認收據缺少收款紀錄，會造成收據與帳單難以對齊。',
                        $invoice,
                        (int) $reportRow['report_id']
                    );
                }
            }

            $anomalies = array_merge($anomalies, $invoiceAnomalies);

            return [
                'id' => (int) $invoice->id,
                'invoice_no' => $this->invoiceNo($invoice),
                'student_class_id' => (int) $invoice->StudentClassID,
                'course_ref' => $this->courseRef((int) $invoice->StudentClassID),
                'subject' => $invoiceStudentClass?->displaySubjectName(),
                'first_session_date' => $firstSessionDates->get($invoiceStudentClassId),
                'billing_period' => $invoice->billing_period,
                // Service range from the items: a 9/28–10/27 cycle is billing_period 2026-09,
                // so the panel shows the dates instead of "9月" (in-app #378).
                'period_start' => ($itemStart = $invoice->items->pluck('PeriodStart')->filter()->min()) ? substr((string) $itemStart, 0, 10) : null,
                'period_end' => ($itemEnd = $invoice->items->pluck('PeriodEnd')->filter()->max()) ? substr((string) $itemEnd, 0, 10) : null,
                'issue_date' => $invoice->IssueDate ? substr((string) $invoice->IssueDate, 0, 10) : null,
                'due_date' => $invoice->DueDate ? substr((string) $invoice->DueDate, 0, 10) : null,
                'total_amount' => $totalAmount,
                'stored_total_amount' => $projection['stored_total_amount'],
                'computed_total_amount' => $projection['computed_total_amount'],
                'amount_source' => $projection['amount_source'],
                'amount_discrepancy' => $projection['amount_discrepancy'],
                'period_sessions' => $projection['period_sessions'],
                'paid_amount' => $paidAmount,
                'calculated_applied_amount' => $appliedAmount,
                'voided_amount' => $voidedAmount,
                'overpaid_amount' => $overpaidAmount,
                'outstanding_amount' => $outstanding,
                'status' => $status,
                'can_direct_void' => !in_array($status, ['paid', 'partial', 'void'], true) && $paidAmount <= 0 && $netApplied <= 0,
                'can_exception_void' => $status !== 'void' && ($netApplied > 0 || $paidAmount > 0),
                'payments' => $paymentRows->all(),
                'reports' => $invoiceReports->all(),
                'anomalies' => $invoiceAnomalies,
            ];
        })->values();

        $invoiceIds = $invoices->pluck('id')->map(fn ($id) => (int) $id)->all();
        $paymentIds = $invoices->flatMap(fn ($invoice) => $invoice->payments)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $reportRows = $reports->map(function (PaymentReport $report) use ($invoiceIds, $paymentIds, &$anomalies) {
            $row = $this->ledgerReportRow($report);
            if ($report->status === 'confirmed' && !in_array((int) $report->InvoiceID, $invoiceIds, true)) {
                $anomalies[] = [
                    'code' => 'receipt_without_invoice',
                    'severity' => 'warning',
                    'message' => '已確認收據尚未對到本學生帳單。',
                    'invoice_id' => $report->InvoiceID ? (int) $report->InvoiceID : null,
                    'student_class_id' => (int) $report->StudentClassID,
                    'report_id' => (int) $report->id,
                    'payment_id' => $report->payment_id ? (int) $report->payment_id : null,
                    'action' => $this->ledgerAnomalyAction('receipt_without_invoice', (int) $report->id, $report->payment_id ? (int) $report->payment_id : null),
                ];
            }
            if ($report->status === 'confirmed' && empty($report->payment_id)) {
                $anomalies[] = [
                    'code' => 'confirmed_receipt_without_payment',
                    'severity' => 'warning',
                    'message' => '已確認收據缺少收款紀錄。',
                    'invoice_id' => $report->InvoiceID ? (int) $report->InvoiceID : null,
                    'student_class_id' => (int) $report->StudentClassID,
                    'report_id' => (int) $report->id,
                    'payment_id' => null,
                    'action' => $this->ledgerAnomalyAction('confirmed_receipt_without_payment', (int) $report->id, null),
                ];
            }
            if ($report->payment_id && !in_array((int) $report->payment_id, $paymentIds, true)) {
                $anomalies[] = [
                    'code' => 'receipt_payment_outside_ledger',
                    'severity' => 'warning',
                    'message' => '收據對應的收款不在本學生帳單紀錄內。',
                    'invoice_id' => $report->InvoiceID ? (int) $report->InvoiceID : null,
                    'student_class_id' => (int) $report->StudentClassID,
                    'report_id' => (int) $report->id,
                    'payment_id' => (int) $report->payment_id,
                    'action' => $this->ledgerAnomalyAction('receipt_payment_outside_ledger', (int) $report->id, (int) $report->payment_id),
                ];
            }
            return $row;
        })->values();

        $uniqueAnomalies = collect($anomalies)->unique(fn ($a) => implode(':', [
            $a['code'] ?? '',
            $a['invoice_id'] ?? '',
            $a['student_class_id'] ?? '',
            $a['report_id'] ?? '',
            $a['payment_id'] ?? '',
        ]))->values();

        $appliedTotal = (int) $invoiceRows->sum('calculated_applied_amount');
        $invoiceTotal = (int) $invoiceRows->sum('total_amount');
        $openInvoiceTotal = (int) $invoiceRows->where('status', '!=', 'void')->sum('total_amount');

        return response()->json([
            'student' => [
                'id' => (int) $student->id,
                'name' => (string) $student->name,
                'campus_id' => (int) $student->CampusID,
            ],
            'scope' => [
                'student_class_id' => (int) $anchorClass->ID,
                'report_id' => $anchorReport ? (int) $anchorReport->id : null,
                'class_count' => count($classIds),
                // Tutoring has no payment obligation (PaymentReportController::tutoringPaymentBlocked).
                'no_payment_obligation' => strtolower(trim((string) $anchorClass->getAttribute('ClassType'))) === 'tutoring',
            ],
            'summary' => [
                'invoice_total' => $invoiceTotal,
                'applied_total' => $appliedTotal,
                'voided_total' => (int) $invoiceRows->sum('voided_amount'),
                'overpaid_total' => (int) $invoiceRows->sum('overpaid_amount'),
                // Sum per-row balances: a void row owes 0 and its retained applications must not offset open rows.
                'outstanding_total' => (int) $invoiceRows->sum('outstanding_amount'),
                'invoice_count' => $invoiceRows->count(),
                'receipt_count' => $reportRows->where('status', 'confirmed')->count(),
                'anomaly_count' => $uniqueAnomalies->count(),
            ],
            'courses' => $classes->map(fn (StudentClass $class) => [
                'id' => (int) $class->ID,
                'course_ref' => $this->courseRef((int) $class->ID),
                'subject' => $class->displaySubjectName(),
                'schedule_mode' => (string) ($class->ScheduleMode ?? ''),
                'charge' => (int) ($class->Charge ?? 0),
                'paid' => (int) ($class->Paid ?? 0) === 1,
                'paid_at' => $class->PayDate ? substr((string) $class->PayDate, 0, 10) : null,
                'start_date' => $class->StartDate ? substr((string) $class->StartDate, 0, 10) : null,
                'first_session_date' => $firstSessionDates->get((int) $class->getAttribute('ID')),
                'stop' => (int) ($class->Stop ?? 0) === 1,
            ])->values()->all(),
            'invoices' => $invoiceRows->all(),
            'receipts' => $reportRows->all(),
            'anomalies' => $uniqueAnomalies->all(),
        ]);
    }

    private function paymentResponse(Request $request, bool $export)
    {
        [$start, $end] = $this->resolveDateRange($request);
        $status = (string) $request->input('status', 'confirmed');

        $query = PaymentReport::with(['student', 'studentClass.subjectRecord', 'confirmedByUser', 'payment'])
            ->whereDate('payment_date', '>=', $start)
            ->whereDate('payment_date', '<=', $end);

        $guard = $this->applyCampusGuard($request, $query);
        if ($guard !== null) {
            return $guard;
        }

        if ($status === 'all') {
            $query->whereIn('status', ['confirmed', 'voided']);
        } elseif ($status === 'voided') {
            $query->where('status', 'voided');
        } else {
            $query->where('status', 'confirmed');
        }

        if ($request->filled('student')) {
            $student = trim((string) $request->input('student'));
            $query->whereHas('student', function ($q) use ($student) {
                Utf8mb3SearchSanitizer::applyLike($q, 'name', $student);
            });
        }

        if ($request->filled('subject')) {
            $subject = trim((string) $request->input('subject'));
            $query->whereHas('studentClass.subjectRecord', fn ($q) => $q->where('Subject_Name', 'like', "%{$subject}%"));
        }

        if ($request->filled('payment_method')) {
            $method = (string) $request->input('payment_method');
            if (!in_array($method, ['cash', 'transfer'], true)) {
                return response()->json(['message' => '繳費方式無效'], 422);
            }
            $query->where('payment_method', $method);
        }

        $allRows = (clone $query)
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit($export ? 5000 : 10000)
            ->get();
        $firstSessionMap = $this->firstSessionMetaMap($allRows->pluck('StudentClassID')->all());
        $transformed = $allRows->map(fn (PaymentReport $report) => $this->transformPaymentReport($report, $firstSessionMap))->values();
        $summary = $this->summarize($transformed);

        if ($export) {
            return response()->json([
                // 匯出檔不含後5碼（既有隱私規則）；畫面列表才顯示。
                'data' => $transformed->map(fn (array $row) => \Illuminate\Support\Arr::except($row, ['account_last5']))->values(),
                'summary' => $summary,
                'generated_at' => Carbon::now()->toIso8601String(),
                'filters_label' => [
                    'start' => $start,
                    'end' => $end,
                    'status' => $status,
                ],
            ]);
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(200, max(1, (int) $request->input('per_page', 50)));
        $pageRows = $transformed->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $pageRows,
            'summary' => $summary,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $transformed->count(),
                'last_page' => (int) max(1, ceil($transformed->count() / $perPage)),
                'filters' => [
                    'start' => $start,
                    'end' => $end,
                    'status' => $status,
                ],
            ],
        ]);
    }

    private function resolveDateRange(Request $request): array
    {
        $today = Carbon::today();
        $start = $request->filled('start')
            ? Carbon::parse((string) $request->input('start'))->toDateString()
            : $today->copy()->startOfMonth()->toDateString();
        $end = $request->filled('end')
            ? Carbon::parse((string) $request->input('end'))->toDateString()
            : $today->copy()->endOfMonth()->toDateString();

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    private function applyCampusGuard(Request $request, $query)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        // No campus assigned = no access (never "unrestricted"); only super_admin is cross-campus.
        if ($role !== 'super_admin' && $campusIds === []) {
            return response()->json(['message' => '沒有權限執行此操作'], 403);
        }

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !in_array($branchId, $campusIds, true)) {
                return response()->json(['message' => '沒有權限執行此操作'], 403);
            }
            $query->whereHas('student', fn ($q) => $q->where('CampusID', $branchId));
            return null;
        }

        if (!empty($campusIds)) {
            $query->whereHas('student', fn ($q) => $q->whereIn('CampusID', $campusIds));
        }

        return null;
    }

    private function applyStudentClassCampusGuard(Request $request, $query)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        // No campus assigned = no access (never "unrestricted"); only super_admin is cross-campus.
        if ($role !== 'super_admin' && $campusIds === []) {
            return response()->json(['message' => '沒有權限執行此操作'], 403);
        }

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !in_array($branchId, $campusIds, true)) {
                return response()->json(['message' => '沒有權限執行此操作'], 403);
            }
            $query->whereHas('student', fn ($q) => $q->where('CampusID', $branchId));
            return null;
        }

        if (!empty($campusIds)) {
            $query->whereHas('student', fn ($q) => $q->whereIn('CampusID', $campusIds));
        }

        return null;
    }

    private function authorizeCampusForStudent(Request $request, int $campusId)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        if ($request->filled('branch_id') && (int) $request->input('branch_id') !== $campusId) {
            return response()->json(['message' => '沒有權限執行此操作'], 403);
        }

        if ($role !== 'super_admin' && !in_array($campusId, $campusIds, true)) {
            return response()->json(['message' => '沒有權限執行此操作'], 403);
        }

        return null;
    }

    private function ledgerAnomaly(string $code, string $severity, string $message, Invoice $invoice, ?int $reportId = null, ?int $paymentId = null): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'invoice_id' => (int) $invoice->id,
            'student_class_id' => (int) $invoice->StudentClassID,
            'report_id' => $reportId,
            'payment_id' => $paymentId,
            'action' => $this->ledgerAnomalyAction($code, $reportId, $paymentId),
        ];
    }

    private function ledgerAnomalyAction(string $code, ?int $reportId, ?int $paymentId): array
    {
        if ($reportId && in_array($code, ['receipt_without_invoice', 'receipt_without_payment', 'confirmed_receipt_without_payment'], true)) {
            return ['type' => 'void_report', 'label' => '撤銷收據', 'report_id' => $reportId];
        }
        if ($reportId) {
            return ['type' => 'view_receipt', 'label' => '查看收據', 'report_id' => $reportId];
        }
        if ($paymentId) {
            return ['type' => 'manual_review', 'label' => '請聯絡總部核對收款資料'];
        }
        return ['type' => 'review', 'label' => '檢視對帳明細'];
    }

    private function ledgerReportRow(PaymentReport $report): array
    {
        $receiptNo = $this->receiptNo((int) $report->id, $report->payment_date ? $report->payment_date->toDateString() : null);

        return [
            'report_id' => (int) $report->id,
            // 退回的回報從未成為收據，不給收據編號。
            'receipt_no' => (string) $report->getAttribute('status') === 'rejected' ? '' : $receiptNo,
            'student_class_id' => (int) $report->StudentClassID,
            'course_ref' => $this->courseRef((int) $report->StudentClassID),
            'invoice_id' => $report->InvoiceID ? (int) $report->InvoiceID : null,
            'payment_id' => $report->payment_id ? (int) $report->payment_id : null,
            'payment_date' => $report->payment_date ? $report->payment_date->toDateString() : null,
            'payment_method' => (string) ($report->payment_method ?? ''),
            'account_last5' => (string) ($report->account_last5 ?? ''),
            'note' => $report->displayNote(),
            'amount' => (int) round((float) $report->reported_amount),
            'status' => (string) $report->status,
            'confirmed_at' => $report->confirmed_at?->toIso8601String(),
            'confirmed_by_name' => $report->confirmedByUser?->Name,
            'voided_at' => $report->voided_at?->toIso8601String(),
            'void_reason' => (string) ($report->void_reason ?? ''),
            'is_backfilled' => !empty($report->backfill_note),
        ];
    }

    private function receiptNo(int $reportId, ?string $paymentDate = null): string
    {
        $period = preg_replace('/[^0-9]/', '', (string) substr((string) $paymentDate, 0, 7));
        return 'RCPT-' . ($period ?: 'LEGACY') . '-' . str_pad((string) $reportId, 6, '0', STR_PAD_LEFT);
    }

    private function invoiceNo(Invoice $invoice): string
    {
        $period = preg_replace('/[^0-9]/', '', (string) ($invoice->billing_period ?: substr((string) $invoice->IssueDate, 0, 7)));
        return 'INV-' . ($period ?: 'LEGACY') . '-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
    }

    private function paymentNo(int $paymentId, ?string $paidPeriod): string
    {
        $period = preg_replace('/[^0-9]/', '', (string) $paidPeriod);
        return 'PAY-' . ($period ?: 'LEGACY') . '-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
    }

    private function courseRef(int $studentClassId): string
    {
        return 'COURSE-' . str_pad((string) $studentClassId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<int, array{first_live:?string,first_any:?string}>
     */
    private function firstSessionMetaMap(array $studentClassIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentClassIds))));
        if ($ids === []) {
            return [];
        }

        $rows = ClassSession::query()
            ->selectRaw("StudentClassID as sid,
                MIN(CASE WHEN Status <> 'cancelled' THEN SessionDate END) as first_live,
                MIN(SessionDate) as first_any")
            ->whereIn('StudentClassID', $ids)
            ->groupBy('StudentClassID')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $id = (int) $row->sid;
            $map[$id] = [
                'first_live' => $row->first_live ? substr((string) $row->first_live, 0, 10) : null,
                'first_any' => $row->first_any ? substr((string) $row->first_any, 0, 10) : null,
            ];
        }

        return $map;
    }

    private function transformPaymentReport(PaymentReport $report, array $firstSessionMap): array
    {
        $method = (string) ($report->payment_method ?? 'cash');
        $amount = (int) round((float) $report->reported_amount);
        $paymentDate = $report->payment_date ? $report->payment_date->toDateString() : null;
        $sc = $report->getRelationValue('studentClass');
        $sc = $sc instanceof StudentClass ? $sc : null;
        $meta = $firstSessionMap[(int) $report->StudentClassID] ?? ['first_live' => null, 'first_any' => null];
        $first = AccountingCourseClarity::firstSession($meta, $sc);
        $firstSessionDate = $first['date'];
        $isConfirmed = $report->status === 'confirmed';
        $classType = $sc !== null ? (string) $sc->getAttribute('ClassType') : '';
        $life = AccountingCourseClarity::lifecycle($sc);
        $sessionCount = $sc?->getAttribute('SessionCount');
        $remaining = $sc?->getAttribute('RemainingSessions');

        return [
            'report_id' => (int) $report->id,
            'receipt_no' => $this->receiptNo((int) $report->id, $paymentDate),
            'payment_date' => $paymentDate,
            'student_id' => (int) $report->StudentID,
            'student_name' => $report->student?->name ?? $report->reported_by_name,
            'student_class_id' => (int) $report->StudentClassID,
            'subject' => $sc?->displaySubjectName() ?? '課程',
            'class_type' => $classType,
            'class_type_label' => AccountingCourseClarity::classTypeLabel($classType),
            'session_count' => $sessionCount !== null ? (int) $sessionCount : null,
            'remaining_sessions' => $remaining !== null ? (int) $remaining : null,
            'schedule_mode' => (string) ($report->studentClass?->ScheduleMode ?? ''),
            'course_lifecycle' => $life['code'],
            'course_lifecycle_label' => $life['label'],
            'first_session_date' => $firstSessionDate,
            'first_session_display' => $first['display'],
            'first_session_source' => $first['source'],
            'first_session_note' => $first['note'],
            'contract_start_date' => AccountingCourseClarity::contractStartDate($sc),
            'is_prepaid' => $paymentDate !== null && $firstSessionDate !== null && $paymentDate < $firstSessionDate,
            'payment_method' => $method,
            'account_last5' => (string) ($report->account_last5 ?? ''),
            // 備註以收款紀錄為準（確認時可能覆寫；作廢後仍沿用），否則用回報備註。
            'note' => $report->displayNote(),
            'cash_amount' => $isConfirmed && $method === 'cash' ? $amount : 0,
            'transfer_amount' => $isConfirmed && $method === 'transfer' ? $amount : 0,
            'total_amount' => $isConfirmed ? $amount : 0,
            'zero_reason' => AccountingCourseClarity::zeroReason($amount, $classType),
            'status' => (string) $report->status,
            'confirmed_at' => $report->confirmed_at?->toIso8601String(),
            'confirmed_by_name' => $report->confirmedByUser?->Name,
            'is_backfilled' => !empty($report->backfill_note),
        ];
    }

    private function summarize($rows): array
    {
        $confirmed = $rows->where('status', 'confirmed');
        $confirmedByCourse = $confirmed->groupBy('student_class_id');
        $duplicateGroups = $confirmedByCourse->filter(fn ($group) => $group->count() > 1);

        return [
            'total_count' => $confirmed->count(),
            'unique_paid_course_count' => $confirmedByCourse->count(),
            'duplicate_payment_course_count' => $duplicateGroups->count(),
            'duplicate_payment_extra_count' => $duplicateGroups->sum(fn ($group) => max(0, $group->count() - 1)),
            'cash_total' => (int) $rows->sum('cash_amount'),
            'transfer_total' => (int) $rows->sum('transfer_amount'),
            'grand_total' => (int) $rows->sum('total_amount'),
            'prepaid_count' => $confirmed->where('is_prepaid', true)->count(),
        ];
    }
}
