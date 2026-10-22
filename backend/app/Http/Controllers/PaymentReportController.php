<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\SecurityAuditEvent;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Support\AccountingCourseClarity;
use App\Services\Billing\BillingPeriodInvoiceExists;
use App\Services\Billing\InvoiceCorrectionLink;
use App\Services\Billing\InvoiceIssuer;
use App\Services\Billing\ContractMoneyState;
use App\Services\MonthlyBillingService;
use App\Services\PaymentReportTokenService;
use App\Services\BillingPayableResolver;
use App\Services\InvoiceAmountReconciliationService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentReportController extends Controller
{
    public function __construct(private MonthlyBillingService $monthlyBilling)
    {
    }

    /**
     * POST /api/v1/payment-reports/generate-link
     * Director generates a payment report link for a student class.
     */
    public function generateLink(Request $request, PaymentReportTokenService $tokenService)
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer',
        ]);

        $sc = StudentClass::query()->with(['student', 'subjectRecord'])->whereKey($data['student_class_id'])->first();
        if (!$sc) {
            abort(404);
        }
        if ($blockedTutoringPayment = $this->tutoringPaymentBlocked($sc)) {
            return $blockedTutoringPayment;
        }
        if ($refusal = ContractMoneyState::waivedRefusal($sc, '此合約已確認不收，不能產生繳費連結', 'course_waived')) {
            return $refusal;
        }

        $branchId = $sc->student->CampusID ?? 0;
        $result = $tokenService->generate($sc->ID, $branchId);

        $baseUrl = rtrim(config('app.url', $request->getSchemeAndHttpHost()), '/');
        $link = $baseUrl . '/pay-report?token=' . urlencode($result['token']);

        $subjectName = $sc->subjectRecord->Subject_Name ?? '課程';
        $billingPeriod = $sc->getAttribute('ScheduleMode') === 'date'
            ? $this->monthlyBilling->defaultPeriodFor($sc)
            : null;
        $billing = $billingPeriod
            ? $this->monthlyBilling->summarizePeriod($sc, $billingPeriod)
            : null;
        $charge = $billing['charge'] ?? ($sc->Charge ?? 0);

        $periodStart = null;
        $periodEnd = null;
        if ($sc->getAttribute('ScheduleMode') === 'date') {
            $periodStart = $billing['period_start'] ?? Carbon::today()->startOfMonth()->toDateString();
            $periodEnd = $billing['period_end'] ?? Carbon::today()->endOfMonth()->toDateString();
        } else {
            $periodStart = $sc->StartDate;
            $periodEnd = $sc->EndDate;
        }

        return response()->json([
            'link'              => $link,
            'token'             => $result['token'],
            'token_hash'        => $result['token_hash'],
            'expires_at'        => $result['expires_at']->toIso8601String(),
            'student_name'      => $sc->student->name ?? '',
            'subject'           => $subjectName,
            'session_count'     => $sc->SessionCount,
            'remaining_sessions' => $sc->RemainingSessions,
            'charge'            => $charge,
            'billing_period'    => $billingPeriod,
            'period_sessions'   => $billing['period_sessions'] ?? null,
            'sessions'          => $billingPeriod
                ? $this->monthlyBilling->billableSessionDetailsForPeriod($sc, $billingPeriod)
                : [],
            'period_start'      => $periodStart,
            'period_end'        => $periodEnd,
        ]);
    }

    /**
     * GET /api/v1/pay-report?token=xxx
     * Public endpoint - parent fetches form data via token.
     */
    public function formData(Request $request, PaymentReportTokenService $tokenService)
    {
        $token = $request->query('token', '');
        $payload = $tokenService->verify($token);

        if (!$payload) {
            return response()->json(['message' => '連結已失效或無效，請聯繫老師重新產生'], 403);
        }

        $tokenHash = $tokenService->hash($token);
        $existing = PaymentReport::where('report_token_hash', $tokenHash)
            ->where('status', '!=', 'rejected')
            ->first();
        if ($existing) {
            return response()->json(['message' => '此連結已提交過繳費回報，請勿重複提交'], 409);
        }

        $sc = StudentClass::query()->with(['student', 'subjectRecord'])->whereKey($payload['scid'])->first();
        if (!$sc || !$sc->student) {
            return response()->json(['message' => '課程資料不存在'], 404);
        }
        if ($blockedTutoringPayment = $this->tutoringPaymentBlocked($sc)) {
            return $blockedTutoringPayment;
        }
        if ($refusal = ContractMoneyState::waivedRefusal($sc, '此合約已確認不收，無須繳費回報', 'course_waived')) {
            return $refusal;
        }

        $subjectName = $sc->subjectRecord->Subject_Name ?? '課程';

        $periodStart = null;
        $periodEnd = null;
        $billingPeriod = $sc->ScheduleMode === 'date'
            ? $this->monthlyBilling->defaultPeriodFor($sc)
            : null;
        $billing = $billingPeriod
            ? $this->monthlyBilling->summarizePeriod($sc, $billingPeriod)
            : null;
        if ($sc->ScheduleMode === 'date') {
            $periodStart = $billing['period_start'] ?? Carbon::today()->startOfMonth()->format('Y/m/d');
            $periodEnd = $billing['period_end'] ?? Carbon::today()->endOfMonth()->format('Y/m/d');
        } else {
            $periodStart = $sc->StartDate ? Carbon::parse($sc->StartDate)->format('Y/m/d') : null;
            $periodEnd = $sc->EndDate ? Carbon::parse($sc->EndDate)->format('Y/m/d') : null;
        }

        return response()->json([
            'student_name'       => $sc->student->name,
            'subject'            => $subjectName,
            'session_count'      => $sc->SessionCount,
            'remaining_sessions' => $sc->RemainingSessions,
            'charge'             => $billing['charge'] ?? ($sc->Charge ?? 0),
            'period_sessions'    => $billing['period_sessions'] ?? null,
            'billing_period'     => $billingPeriod,
            'sessions'           => $billingPeriod
                ? $this->monthlyBilling->billableSessionDetailsForPeriod($sc, $billingPeriod)
                : [],
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'schedule_mode'      => $sc->ScheduleMode,
            'class_type'         => $sc->ClassType,
        ]);
    }

    /**
     * POST /api/v1/pay-report
     * Public endpoint - parent submits payment report.
     */
    public function submit(Request $request, PaymentReportTokenService $tokenService)
    {
        $data = $request->validate([
            'token'          => 'required|string',
            'payment_date'   => 'required|date|before_or_equal:today',
            'payment_method' => 'required|in:transfer,cash',
            'reported_amount' => 'required|integer|min:1|max:999999',
            'account_last5'  => 'nullable|string|max:5|regex:/^[0-9]*$/',
        ]);

        if ($data['payment_method'] === 'transfer' && empty($data['account_last5'])) {
            return response()->json(['message' => '匯款繳費請填寫帳號後5碼'], 422);
        }

        $payload = $tokenService->verify($data['token']);
        if (!$payload) {
            return response()->json(['message' => '連結已失效或無效，請聯繫老師重新產生'], 403);
        }

        $tokenHash = $tokenService->hash($data['token']);

        $existing = PaymentReport::where('report_token_hash', $tokenHash)
            ->where('status', '!=', 'rejected')
            ->first();
        if ($existing) {
            return response()->json(['message' => '此連結已提交過繳費回報，請勿重複提交'], 409);
        }

        $sc = StudentClass::with('student')->find($payload['scid']);
        if (!$sc || !$sc->student) {
            return response()->json(['message' => '課程資料不存在'], 404);
        }
        if ($blockedTutoringPayment = $this->tutoringPaymentBlocked($sc)) {
            return $blockedTutoringPayment;
        }

        // 確認不收（waived）為終態：鎖課程後再建立回報，避免與 waive 競爭。
        $refusal = null;
        $report = DB::transaction(function () use ($sc, $data, $tokenHash, $payload, &$refusal) {
            $locked = StudentClass::query()->whereKey($sc->getKey())->lockForUpdate()->first();
            if (!$locked || ContractMoneyState::isWaived($locked)) {
                return null;
            }
            // Same target confirm() will pick; cap against that bill, not the course total (#3864 hotfix P1-a).
            $target = $this->pickOpenInvoice((int) $locked->getKey(), $locked);
            $refusal = $target && $this->invoiceCoveredByPayments($target, $locked)
                ? $this->invoiceAlreadyCoveredResponse($target)
                : $this->amountExceedsInvoiceResponse($target, $locked, (float) $data['reported_amount']);
            if ($refusal) {
                return null;
            }

            return PaymentReport::create([
                'StudentID'        => $sc->StudentID,
                'StudentClassID'   => $sc->ID,
                'InvoiceID'        => null,
                'reported_by_name' => $sc->student->name,
                'payment_date'     => $data['payment_date'],
                'payment_method'   => $data['payment_method'],
                'reported_amount'  => $data['reported_amount'],
                'account_last5'    => $data['account_last5'] ?? null,
                'status'           => 'pending',
                'report_token_hash' => $tokenHash,
                'token_expires_at' => Carbon::createFromTimestamp($payload['exp']),
            ]);
        });
        if ($refusal) {
            return $refusal;
        }
        if (!$report) {
            return response()->json(['message' => '此課程已確認不收，無需繳費'], 422);
        }

        Log::info('PaymentReport submitted', [
            'report_id'       => $report->id,
            'student_class_id' => $sc->ID,
            'amount'          => $data['reported_amount'],
            'method'          => $data['payment_method'],
        ]);

        return response()->json([
            'message' => '繳費回報已送出，分校對到帳後才會開收據',
            'report_id' => $report->id,
        ], 201);
    }

    /**
     * GET /api/v1/payment-reports
     * Director views pending/confirmed/rejected payment reports.
     */
    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        $query = PaymentReport::with([
            'student', 'studentClass.subjectRecord', 'confirmedByUser', 'invoice.payments', 'invoice.items',
        ]);

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->whereHas('student', fn ($q) => $q->where('CampusID', $bid));
        } elseif (!empty($campusIds)) {
            $query->whereHas('student', fn ($q) => $q->whereIn('CampusID', $campusIds));
        }

        if ($request->filled('student_class_id')) {
            $scId = (int) $request->input('student_class_id');
            $sc = \App\Models\StudentClass::with('student')->find($scId);
            if (!$sc || !$sc->student) {
                return response()->json(['message' => 'Not found'], 404);
            }
            $studentCampus = (int) ($sc->student->CampusID ?? 0);
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($studentCampus, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('StudentClassID', $scId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $reports = $query->orderByRaw("FIELD(status, 'pending', 'confirmed', 'rejected')")
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        $invoiceAmounts = app(InvoiceAmountReconciliationService::class);
        $payableResolver = app(BillingPayableResolver::class);
        $reports->getCollection()->transform(function ($r) use ($invoiceAmounts, $payableResolver) {
            $subjectName = $r->studentClass?->subjectRecord?->Subject_Name ?? '課程';
            $estimatedAmount = $r->studentClass?->Charge ?? 0;
            $payable = $r->invoice
                ? (function () use ($r, $invoiceAmounts) {
                    $projection = $invoiceAmounts->resolve($r->invoice, $r->studentClass);
                    return [
                        'payable_amount' => $projection['total_amount'],
                        'payable_outstanding' => $projection['outstanding_amount'],
                        'payable_status' => 'invoiced',
                        'payable_source' => 'invoice',
                        'payable_invoice_id' => (int) $r->invoice->id,
                        'billing_period' => $projection['billing_period'],
                    ];
                })()
                : $payableResolver->unbilled();
            return [
                'id'               => $r->id,
                'student_name'     => $r->student?->name ?? $r->reported_by_name,
                'student_id'       => $r->StudentID,
                'student_class_id' => $r->StudentClassID,
                'subject'          => $subjectName,
                'payment_date'     => $r->payment_date->format('Y-m-d'),
                'payment_method'   => $r->payment_method,
                'reported_amount'  => (float) $r->reported_amount,
                'account_last5'    => $r->account_last5,
                'note'             => (string) ($r->note ?? ''),
                'status'           => $r->status,
                'confirmed_by_name' => $r->confirmedByUser?->Name ?? null,
                'confirmed_at'     => $r->confirmed_at?->toIso8601String(),
                'rejection_note'   => $r->rejection_note,
                'charge'           => $estimatedAmount,
                'estimated_amount' => $estimatedAmount,
                'charge_semantics' => 'estimate_only',
                ...$payable,
                'created_at'       => $r->created_at?->toIso8601String(),
            ];
        });

        return response()->json($reports);
    }

    /**
     * PUT /api/v1/payment-reports/{id}/confirm
     * Director confirms a payment report → creates Payment, updates Invoice.
     * $legacyFlagBackfill (Founder GO 2026-10-21; never from the route) is set only by recordLegacyFlagBackfill() after
     * legacyFlagRefusal() proved `paid` rests on the legacy Paid flag alone: it skips just the "already paid" guards that
     * flag trips; every other guard, the bill cap and the ledger writes stay this one confirm path.
     */
    public function confirm(Request $request, $id, bool $legacyFlagBackfill = false)
    {
        PaymentReport::findOrFail($id);

        $userId = $request->attributes->get('auth_user_id') ?? $request->attributes->get('auth_user')?->id; // bearer auth sets only auth_user
        $confirmationNote = trim((string) $request->input('note', ''));
        // Routing id only — must not run inside the transaction (would start an RR
        // consistent-read snapshot before waiting on the course lock).
        $courseId = $this->resolveReportCourseId((int) $id);

        return DB::transaction(function () use ($id, $courseId, $userId, $confirmationNote, $legacyFlagBackfill) {
            // Global lock order with BillingCorrection::applyCountCorrection and void:
            // StudentClass → payment_reports (never report-first). Serializes confirm retries
            // after a lost response so a second Payment cannot be created.
            [$sc, $report] = $this->lockCourseThenReport((int) $id, $courseId);

            if ($report->status !== 'pending') {
                return response()->json(['message' => '此回報已處理過'], 422);
            }
            // Payment.Amount is whole NT$; a fractional legacy report would be truncated (0.50 → a NT$0 Payment).
            if (fmod((float) $report->reported_amount, 1.0) !== 0.0) {
                return response()->json([
                    'message' => '回報金額有小數，收款只能是整數元；請退回這筆回報後重新登記。沒有登記任何款項。',
                    'code' => 'fractional_amount',
                ], 422);
            }

            $note = $confirmationNote !== ''
                ? $confirmationNote
                : trim((string) ($report->note ?? ''));
            if ($refusal = ContractMoneyState::waivedRefusal($sc, '此合約已確認不收，不能再確認回報')) {
                return $refusal;
            }
            if ($sc && ($blockedTutoringPayment = $this->tutoringPaymentBlocked($sc))) {
                return $blockedTutoringPayment;
            }
            // #4132 Codex P1: recheck package membership on confirm (convert already blocks payment_reports).
            $isCorrectionReport = DB::table('invoice_corrections')
                ->where('payment_report_id', (int) $report->id)
                ->exists();
            if ($isCorrectionReport && $sc && $sc->isPartOfPackage()) {
                return response()->json([
                    'message' => '這筆更正回報建立後，課程已加入共用方案；請先解除方案歸屬或作廢此回報後再處理。沒有登記任何款項。',
                    'code' => 'correction_reissue_joined_package',
                ], 422);
            }
            $package = $sc ? $this->lockPackageForCourse($sc) : null;

            if ($sc && !$legacyFlagBackfill && ($blockReason = $this->courseMoneyBlockReason((int) $sc->getKey(), (int) ($sc->getAttribute('Paid') ?? 0)))) {
                return $this->duplicateCoursePaymentResponse($blockReason);
            }
            if ($sc && !$legacyFlagBackfill && ($over = $this->amountExceedsOutstandingResponse((int) $sc->getKey(), (float) $report->reported_amount))) {
                return $over;
            }

            $invoice = null;
            if (!empty($report->InvoiceID)) {
                $invoice = Invoice::where('id', (int) $report->InvoiceID)
                    ->where('StudentClassID', $report->StudentClassID)
                    ->lockForUpdate()
                    ->first();
                // A stored Status=paid does not decide: the Payment-row projection below does (#3875).
                if ($invoice && (string) $invoice->Status === 'void') {
                    return response()->json([
                        'message' => '作廢帳單不可重新入帳，請使用目前有效帳單或讓系統建立新的對應帳單。',
                        'code' => 'void_invoice_cannot_confirm',
                    ], 422);
                }
            }

            if (! $invoice) {
                $invoice = $this->pickOpenInvoice((int) $report->StudentClassID, $sc);
            }

            if ($invoice && $this->invoiceCoveredByPayments($invoice, $sc instanceof StudentClass ? $sc : null)) {
                return $this->invoiceAlreadyCoveredResponse($invoice);
            }
            if ($over = $this->amountExceedsInvoiceResponse($invoice, $sc, (float) $report->reported_amount)) {
                return $over;
            }

            $hasPaidInvoice = false;
            if (! $invoice) {
                $hasPaidInvoice = Invoice::where('StudentClassID', $report->StudentClassID)
                    ->where('Status', 'paid')
                    ->exists();
            }

            if (! $invoice && $sc && !$legacyFlagBackfill && ((int) ($sc->Paid ?? 0) === 1 || $hasPaidInvoice)) {
                return $this->duplicateCoursePaymentResponse('paid');
            }

            $monthlyProjection = null;
            if ($sc && $sc->ScheduleMode === 'date') {
                $period = $invoice && preg_match('/^\d{4}-\d{2}$/', (string) $invoice->billing_period)
                    ? (string) $invoice->billing_period : $this->monthlyBilling->defaultPeriodFor($sc, $report->payment_date);
                $actual = $this->monthlyBilling->summarizePeriod($sc, $period);
                $monthlyProjection = $invoice ? $this->invoiceProjection($invoice, $sc) : null;
                // A pending forecast must be rechecked before any ledger write.
                // Partial receipts remain allowed; explicit cross-month cycles keep their invoice authority.
                // Paid so far = Payment rows, never the stored PaidAmount (#3864 hotfix P1-b).
                $limit = max(0, (int) $actual['charge'] - (int) ($monthlyProjection['net_applied'] ?? 0));
                if ($actual['source'] === 'billable_sessions' && (!$invoice || ($monthlyProjection['computed_total_amount'] !== null && $monthlyProjection['repriceable']))
                    && (int) $report->reported_amount > $limit) {
                    return response()->json(['message' => '月結已上堂次或應收已變動，請重新核對回報金額。',
                        'code' => 'monthly_amount_stale', 'expected_amount' => $limit, 'billing_period' => $period,
                        'period_sessions' => (int) $actual['period_sessions']], 422);
                }
            }

            if (!$invoice) {
                try {
                $invoice = app(InvoiceIssuer::class)->issue([
                    'StudentID'      => $report->StudentID,
                    'StudentClassID' => $report->StudentClassID,
                    'IssueDate'      => Carbon::today()->toDateString(),
                    'TotalAmount'    => (int) $report->reported_amount,
                    'ScheduleModeAtIssue' => $sc->ScheduleMode ?? null,
                    'billing_period' => $sc?->ScheduleMode === 'date'
                        ? $this->monthlyBilling->defaultPeriodFor($sc, $report->payment_date)
                        : null,
                ], [], true);
                } catch (BillingPeriodInvoiceExists) {
                    // Defense in depth: the lookup above attaches to any live invoice, so this is a race backstop.
                    return response()->json([
                        'message' => '此課程該月份已有有效帳單，請勿重複建立。',
                        'code' => 'billing_period_invoice_exists',
                    ], 409);
                }
            }

            if ($sc && $sc->ScheduleMode === 'date') {
                $billingPeriod = preg_match('/^\d{4}-\d{2}$/', (string) $invoice->billing_period)
                    ? (string) $invoice->billing_period
                    : $this->monthlyBilling->defaultPeriodFor($sc, $report->payment_date);
                $billing = $this->monthlyBilling->summarizePeriod($sc, $billingPeriod);

                if (
                    (string) ($invoice->Status ?? 'unpaid') === 'unpaid'
                    && (int) ($monthlyProjection['net_applied'] ?? 0) === 0
                    && (!$monthlyProjection || $monthlyProjection['computed_total_amount'] !== null)
                    && $billing['source'] === 'billable_sessions'
                ) {
                    $invoice->TotalAmount = (int) $billing['charge'];
                }

                // A fixed (non-repriceable) bill keeps its own evidence: never snapshot today's sessions onto it (#3888 review).
                if (empty($invoice->billing_snapshot) && (!$monthlyProjection || $monthlyProjection['repriceable'])) {
                    $invoice->billing_period = $billingPeriod;
                    $invoice->billing_snapshot = [
                        'version' => 1,
                        'source' => 'monthly_billable_sessions',
                        'billing_period' => $billingPeriod,
                        'period_start' => $billing['period_start'],
                        'period_end' => $billing['period_end'],
                        'period_sessions' => (int) $billing['period_sessions'],
                        'charge' => (int) $billing['charge'],
                        'sessions' => $this->monthlyBilling
                            ->billableSessionDetailsForPeriod($sc, $billingPeriod),
                    ];
                    $invoice->save();
                }
            }

            $payment = Payment::create([
                'InvoiceID'         => $invoice->id,
                'Amount'            => (int) $report->reported_amount,
                'PaidAt'            => $report->payment_date,
                'Method'            => $report->payment_method === 'transfer' ? 'transfer' : 'cash',
                'Note'              => $note ?: "繳費回報核帳確認 (report #{$report->id})",
                'payment_report_id' => $report->id,
            ]);

            // Recompute from Payment rows (now including this one), never stored PaidAmount + new (#3864 hotfix P1-b).
            $after = $this->invoiceProjection($invoice, $sc);
            $newPaid = (int) $after['net_applied'];
            $status = (int) $after['outstanding_amount'] <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
            $invoice->update([
                'PaidAmount'     => $newPaid,
                'Status'         => $status,
                'reconciled_at'  => Carbon::now(),
                'reconciled_by'  => $userId,
            ]);

            // F7 S7: only the resolver (all non-void invoices) may settle the course; a partial leaves Paid=0.
            if ($sc && !$sc->Paid && $this->courseResolvedPaid($sc)) {
                $sc->update([
                    'Paid' => 1,
                    'PayDate' => Carbon::today()->toDateString(),
                    'closed_reason' => $sc->getAttribute('closed_reason') === 'settled_pending'
                        ? 'settled'
                        : $sc->getAttribute('closed_reason'),
                ]);
            }
            if ($package && $status === 'paid') {
                $paidAt = Carbon::make($report->payment_date)?->toDateString()
                    ?? Carbon::today()->toDateString();
                $this->markPackagePaid($package, $paidAt);
            }

            $report->update([
                'status'       => 'confirmed',
                'confirmed_by' => $userId,
                'confirmed_at' => Carbon::now(),
                'payment_id'   => $payment->id,
                'InvoiceID'    => $invoice->id,
            ]);

            Log::info('PaymentReport confirmed', [
                'report_id'  => $report->id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'user_id'    => $userId,
            ]);

            return response()->json([
                'message'    => '已確認入帳',
                'report_id'  => $report->id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
            ]);
        });
    }

    /**
     * POST /api/v1/payment-reports/director-record
     * Admin/director records a claimed payment (LINE / verbal). Does not mark Paid
     * or issue a receipt — accounting confirm does that (#1827 Phase 1).
     */
    public function directorRecord(Request $request)
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer',
            'invoice_id'       => 'nullable|integer',
            'payment_date'     => 'required|date|before_or_equal:today',
            'payment_method'   => 'required|in:transfer,cash',
            // Whole NT$ only: Payment/Invoice amounts are integer columns (#3875 review).
            'amount'           => 'required|integer|min:0|max:999999',
            'account_last5'    => 'nullable|string|max:5|regex:/^[0-9]*$/',
            'note'             => 'nullable|string|max:500',
            'free_gift'        => 'sometimes|boolean',
            // Founder D3/B1 (#4132): this payment settles a new bill that corrects a voided one; both notes cross-reference.
            'replaces_invoice_id' => 'nullable|integer|min:1',
            'correction_reason' => 'required_with:replaces_invoice_id|string|min:3|max:80',
            'idempotency_key'   => 'required_with:replaces_invoice_id|uuid',
        ]);
        $freeGift = (bool) ($data['free_gift'] ?? false);

        $sc = StudentClass::with('student')->findOrFail($data['student_class_id']);
        if (!$sc->student) {
            return response()->json(['message' => '課程資料不存在'], 404);
        }

        $userId = $request->attributes->get('auth_user_id');
        // Founder GO 2026-10-21: 已收過，補登收款 for a legacy-flag-only course (Paid=1, no payment record).
        if ($request->boolean('legacy_flag_backfill')) {
            if ($freeGift || !empty($data['invoice_id']) || !empty($data['replaces_invoice_id'])) {
                return response()->json(['message' => '補登收款不能同時指定帳單、更正或標記免費。沒有登記任何款項。', 'code' => 'legacy_backfill_conflict'], 422);
            }
            $request->validate(['idempotency_key' => 'required|uuid']);
            $actorId = $request->attributes->get('auth_user')?->id;

            return $this->recordLegacyFlagBackfill($request, $data, (int) $sc->getKey(), $actorId === null ? null : (int) $actorId);
        }
        // #4132 Codex P1: feature gate + campus auth before tutoring/zero-fee classification.
        if (!empty($data['replaces_invoice_id'])) {
            if (!config('billing.receipt_correction_reissue_enabled', false)) {
                return response()->json(['message' => '更正帳單收款功能尚未開放', 'code' => 'correction_reissue_disabled'], 404);
            }
            if ($freeGift || !empty($data['invoice_id'])) {
                return response()->json(['message' => '更正帳單不能同時指定其他帳單或標記免費。沒有登記任何款項。', 'code' => 'correction_reissue_conflict'], 422);
            }

            $actorId = $request->attributes->get('auth_user')?->id; // the auth_user_id attribute is not set on this route
            return $sc instanceof StudentClass ? $this->recordCorrectionReissue($request, $data, $sc, $actorId === null ? null : (int) $actorId)
                : response()->json(['message' => '課程資料不存在'], 404);
        }

        if ($freeGift && $request->attributes->get('auth_role') !== 'super_admin' // #3991 review: authorize the campus before classifying
            && !in_array((int) $sc->student->CampusID, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // #3991: an explicit 免費／贈送 mark skips the generic zero-fee guard (it is not a payment); tutoring stays refused.
        if ($blockedTutoringPayment = $this->tutoringPaymentBlocked($sc, $freeGift)) {
            return $blockedTutoringPayment;
        }

        // #1096 (in-app #190): a date-mode (月結) course billed at NT$0 is nonsensical —
        // the reported "金額顯示0應該顯示3000" came from an unset monthly fee. Block a NT$0
        // record for date-mode and point staff to set the fee first. Count-mode is untouched,
        // so legitimately-free (0-fee) tutoring courses still settle at 0.
        if ($freeGift && (int) $data['amount'] !== 0) {
            return response()->json([
                'message' => '標記為「' . Invoice::ZERO_REASON_FREE_GIFT_LABEL . '」時金額必須是 NT$0。沒有登記任何款項。',
                'code' => 'free_gift_requires_zero',
            ], 422);
        }
        if (!$freeGift && $sc->getAttribute('ScheduleMode') === 'date' && (float) ($data['amount'] ?? 0) <= 0) {
            return response()->json([
                'message' => '月結課程的繳費金額不可為 0；若月費顯示為 0，請先於課程管理設定正確月費金額後再登記。'
                    . '若這門課確定免費，請勾選「' . Invoice::ZERO_REASON_FREE_GIFT_LABEL . '」。',
                'code' => 'monthly_fee_unset',
            ], 422);
        }
        if ($freeGift) {
            return $this->recordFreeGiftBill((int) $data['student_class_id'], $data, $userId);
        }

        return DB::transaction(function () use ($data, $sc, $userId) {
            $invoice = null;
            $lockedCourse = StudentClass::query()->whereKey($sc->getKey())->lockForUpdate()->first();
            if (!$lockedCourse) {
                return response()->json(['message' => '找不到此課程'], 404); // deleted concurrently
            }
            if ($refusal = ContractMoneyState::waivedRefusal($lockedCourse, '此合約已確認不收，不能登記收款')) {
                return $refusal;
            }
            // #3892: package membership from the locked row, so a concurrent convertToPackage is never missed.
            $package = $this->lockPackageForCourse($lockedCourse);

            if ($blockReason = $this->courseMoneyBlockReason((int) $sc->getKey(), (int) ($sc->getAttribute('Paid') ?? 0))) {
                return $this->duplicateCoursePaymentResponse($blockReason);
            }
            if ($over = $this->amountExceedsOutstandingResponse((int) $sc->getKey(), (float) $data['amount'])) {
                return $over;
            }

            if (!empty($data['invoice_id'])) {
                $invoice = Invoice::where('id', (int) $data['invoice_id'])
                    ->where('StudentClassID', $sc->ID)
                    ->lockForUpdate()
                    ->first();

                if (!$invoice) {
                    return response()->json(['message' => '指定帳單不存在或不屬於此課程'], 404);
                }
                // A stored Status=paid does not decide: the Payment-row projection below does (#3875).
                if ((string) $invoice->Status === 'void') {
                    return response()->json([
                        'message' => '作廢帳單不可重新登記，請讓系統建立新的對應帳單。',
                        'code' => 'void_invoice_cannot_record',
                    ], 422);
                }
            }

            // 沒指定帳單：用名單上顯示的那張（月結＝最早未繳清的一期；Founder 2026-10-13，取代 FR-006 當月優先）
            if (! $invoice) {
                $invoice = $this->pickOpenInvoice((int) $sc->ID, $lockedCourse);
            }

            if ($invoice && $this->invoiceCoveredByPayments($invoice, $lockedCourse)) {
                return $this->invoiceAlreadyCoveredResponse($invoice);
            }
            if ($over = $this->amountExceedsInvoiceResponse($invoice, $lockedCourse, (float) $data['amount'])) {
                return $over;
            }

            $hasPaidInvoice = false;
            if (! $invoice) {
                $hasPaidInvoice = Invoice::where('StudentClassID', $sc->ID)
                    ->where('Status', 'paid')
                    ->exists();
            }

            if (! $invoice && ((int) ($sc->Paid ?? 0) === 1 || $hasPaidInvoice)) {
                return $this->duplicateCoursePaymentResponse('paid');
            }

            if ($sc->getAttribute('ScheduleMode') === 'date') {
                $billingPeriod = $invoice && preg_match('/^\d{4}-\d{2}$/', (string) $invoice->billing_period)
                    ? (string) $invoice->billing_period
                    : $this->monthlyBilling->defaultPeriodFor($sc, $data['payment_date']);
                $billing = $this->monthlyBilling->summarizePeriod($sc, $billingPeriod);
                // Paid so far = Payment rows, never the stored PaidAmount (#3864 hotfix P1-b). A non-repriceable bill
                // (paid/partial/has payments) keeps its fixed total: owe its projected outstanding (#3888 review).
                $projection = $invoice ? $this->invoiceProjection($invoice, $lockedCourse) : null;
                $expectedAmount = $projection && !$projection['repriceable'] ? (int) $projection['outstanding_amount']
                    : max(0, (int) $billing['charge'] - (int) ($projection['net_applied'] ?? 0));
                if ((int) $data['amount'] !== $expectedAmount) {
                    return response()->json([
                        'message' => sprintf('月結本期應收為 NT$%d，請重新載入繳費通知後再登記。', $expectedAmount),
                        'code' => 'monthly_amount_stale',
                        'expected_amount' => $expectedAmount,
                        'billing_period' => $billingPeriod,
                        'period_sessions' => (int) $billing['period_sessions'],
                    ], 422);
                }
            }

            // #3753: a shared CoursePackage is one obligation, so one pending report covers every member. The package row
            // lock (held above) serializes member records; locking the sibling's report after it would invert confirm()'s
            // report -> package order and deadlock (#3892 review), so package members read without a row lock.
            // Monthly ('date') package members are recorded per member against their own period subtotal, so they keep
            // the per-course pending check (#3892 review); only count-mode packages share one obligation.
            $existingPending = $this->isSharedPackageObligation($package, $lockedCourse)
                ? PaymentReport::query()->whereIn('StudentClassID', StudentClass::query()->where('PackageID', $package->getKey())->pluck('ID')->all())
                    ->where('status', 'pending')->first()
                : PaymentReport::where('StudentClassID', $sc->ID)->where('status', 'pending')->lockForUpdate()->first();
            if ($existingPending) {
                return response()->json([
                    'message' => ((int) $existingPending->StudentClassID === (int) $lockedCourse->getKey() ? '此課程' : '同一共用方案')
                        . '已有「已回報付款，待你核對入帳」的繳費回報，請先到帳務中心確認收款或退回後再登錄。',
                    'code' => 'pending_report_exists',
                    'report_id' => $existingPending->id,
                ], 422);
            }

            $report = $this->createPendingReport($lockedCourse, $data, $invoice?->id);

            Log::info('PaymentReport director-record pending', [
                'report_id'  => $report->id,
                'invoice_id' => $invoice?->id,
                'user_id'    => $userId,
            ]);

            return response()->json([
                'message'    => '已送出，待你核對入帳',
                'report_id'  => $report->id,
                'payment_id' => null,
                'invoice_id' => $invoice?->id,
                'status'     => 'pending',
            ]);
        });
    }

    /**
     * Founder GO 2026-10-21 (§只有舊「已收」標記): the director says the money of a legacy-flag-only course was received.
     * No separate money rule: the same pending report director-record writes, then confirmReport() (the 確認入帳 path:
     * bill pick or issue, Payment, projection, receipt) in this one transaction. Paid stays 1; the course now has payment
     * evidence, so the #4114 rule stops showing 需核對. A replay of the same idempotency key returns the first result.
     *
     * @param array<string, mixed> $data
     */
    private function recordLegacyFlagBackfill(Request $request, array $data, int $courseId, ?int $userId)
    {
        $tokenHash = hash('sha256', "legacy-flag-backfill|{$courseId}|" . $data['idempotency_key']);

        return DB::transaction(function () use ($request, $data, $courseId, $userId, $tokenHash) {
            $course = StudentClass::query()->whereKey($courseId)->lockForUpdate()->first();
            if ($denied = $this->legacyFlagAccessRefusal($request, $course)) {
                return $denied;
            }
            $replay = PaymentReport::query()->where('StudentClassID', $courseId)->where('report_token_hash', $tokenHash)->first();
            if ($replay && (int) $replay->reported_amount === (int) $data['amount'] && $replay->payment_method === $data['payment_method'] && $replay->payment_date?->toDateString() === Carbon::parse($data['payment_date'])->toDateString()) {
                return response()->json(['message' => '這筆補登已經完成', 'report_id' => $replay->id, 'payment_id' => $replay->payment_id,
                    'invoice_id' => $replay->InvoiceID, 'status' => $replay->status, 'replayed' => true]);
            }
            if ($refusal = $this->legacyFlagRefusal($course)) {
                return $refusal;
            }
            if ((int) $data['amount'] <= 0) {
                return response()->json(['message' => '補登金額必須大於 0，請填實際收到的金額。沒有登記任何款項。', 'code' => 'zero_amount_with_balance'], 422);
            }
            $report = $this->createPendingReport($course, $data, null, $tokenHash);
            $confirmed = $this->confirm($request, $report->id, true); // the backfill already holds the course lock
            if (!$confirmed instanceof JsonResponse || $confirmed->getStatusCode() !== 200) {
                throw new HttpResponseException($confirmed); // roll the pending report back with the refusal
            }
            if (!$this->courseResolvedPaid($course)) { // a partial payment leaves money owed: drop the flag, as void() does
                $course->refresh()->update(['Paid' => 0, 'Pay' => 0, 'PayDate' => null]
                    + ($course->getAttribute('closed_reason') === 'settled' ? ['closed_reason' => 'settled_pending'] : []));
            }
            $this->auditLegacyFlag($course, $userId, 'student_class.legacy_paid_flag_backfill', ['reason_code' => 'legacy_paid_flag_backfill']);

            return response()->json(['status' => 'confirmed'] + (array) $confirmed->getData(true));
        });
    }

    /**
     * POST /api/v1/payment-reports/legacy-flag-clear — Founder GO 2026-10-21: 其實沒收，改成未繳.
     * Clears only the legacy flag (Paid=0, Pay=0, PayDate=null) of a legacy-flag-only course; never touches invoices or
     * payments. Repeating it on a course already back to Paid=0 changes nothing (idempotent).
     */
    public function clearLegacyFlag(Request $request)
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer|min:1',
            'reason' => 'required|string|min:2|max:200',
        ]);
        $courseId = (int) $data['student_class_id'];
        $actorId = $request->attributes->get('auth_user')?->id;

        return DB::transaction(function () use ($request, $data, $courseId, $actorId) {
            $course = StudentClass::query()->whereKey($courseId)->lockForUpdate()->first();
            if ($denied = $this->legacyFlagAccessRefusal($request, $course)) {
                return $denied;
            }
            $hasPayment = isset(app(BillingPayableResolver::class)->validPaymentEvidence([$courseId])['classes'][$courseId]);
            if ((int) ($course->Paid ?? 0) !== 1 && !$hasPayment) {
                return response()->json(['message' => '這門課已經是未繳，不需要再改', 'student_class_id' => $courseId, 'replayed' => true]);
            }
            if ($refusal = $this->legacyFlagRefusal($course)) {
                return $refusal;
            }
            $reopen = $course->getAttribute('closed_reason') === 'settled' ? ['closed_reason' => 'settled_pending'] : [];
            $course->update(['Paid' => 0, 'Pay' => 0, 'PayDate' => null] + $reopen);
            // What is owed now: an open bill's balance (the resolver's), else the contract amount (as AlertController).
            $status = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$courseId])[$courseId] ?? [];
            $owed = ($status['source'] ?? null) === 'invoice' ? (int) $status['outstanding'] : (int) ($course->Charge ?? 0);
            $this->auditLegacyFlag($course, $actorId === null ? null : (int) $actorId, 'student_class.legacy_paid_flag_cleared', [
                'reason_code' => 'legacy_paid_flag_cleared',
                'reason_hash' => hash('sha256', trim((string) $data['reason'])),
                'outstanding_amount' => $owed,
            ]);

            return response()->json(['message' => '已改成未繳', 'student_class_id' => $courseId]);
        });
    }

    /** Campus authorization for the legacy-flag actions (404 for a missing course, 403 outside the caller's campuses). */
    private function legacyFlagAccessRefusal(Request $request, ?StudentClass $course)
    {
        $campusId = $course ? (int) DB::table('Student')->where('id', $course->StudentID)->value('CampusID') : 0;
        if (!$course || $campusId <= 0) {
            return response()->json(['message' => '找不到此課程'], 404);
        }
        $allowed = $request->attributes->get('auth_role') === 'super_admin'
            || in_array($campusId, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true);

        return $allowed ? null : response()->json(['message' => 'Forbidden'], 403);
    }

    /**
     * Both legacy-flag actions apply only to a single count-mode course whose `paid` rests on StudentClass.Paid alone:
     * Paid=1, no net payment on any non-void bill (BillingPayableResolver::validPaymentEvidence, the #4114 rule) and no
     * pending or confirmed payment report. Caller holds the course row lock.
     */
    private function legacyFlagRefusal(StudentClass $course)
    {
        $id = (int) $course->getKey();
        $refuse = fn (string $message, string $code) => response()->json(['message' => $message . '沒有變更任何資料。', 'code' => $code], 422);
        if ($blocked = $this->tutoringPaymentBlocked($course)) {
            return $blocked;
        }
        if ($waived = ContractMoneyState::waivedRefusal($course, '此合約已確認不收，不能變更繳費狀態')) {
            return $waived;
        }
        if ((int) ($course->Charge ?? 0) <= 0 && (float) ($course->Rate ?? 0) <= 0) { // 0 元 courses are out of scope
            return $refuse('這門課沒有設定收費金額，不需要補登或改未繳；若要收費請先到課程管理設定費用。', 'legacy_flag_zero_fee');
        }
        if ((string) ($course->ScheduleMode ?? 'count') !== 'count' || $course->isPartOfPackage() || $course->isTrialFeeRecordedByDate()) {
            return $refuse('目前只有單一堂數制正式課程可以在這裡處理舊「已收」標記；月結、共用方案或已記錄繳費日期的試聽請到學生帳務逐筆核對。', 'legacy_flag_count_only');
        }
        if ((int) ($course->Paid ?? 0) !== 1) {
            return $refuse('這門課沒有「已收」標記，請重新整理。', 'legacy_flag_not_set');
        }
        if (isset(app(BillingPayableResolver::class)->validPaymentEvidence([$id])['classes'][$id])) {
            return $refuse('這門課已有收款紀錄，不是只有舊標記；請重新整理後在學生帳務核對。', 'legacy_flag_has_payment');
        }
        $report = PaymentReport::query()->where('StudentClassID', $id)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->first();
        if ($report) {
            return $report->status === 'pending'
                ? $refuse('這門課有待核對的繳費回報，請先確認入帳或退回那筆回報。', 'pending_report_exists')
                : $refuse('這門課已有確認過的收款回報，請在學生帳務核對那筆收款。', 'confirmed_report_exists');
        }

        return null;
    }

    /** @param array<string, int|string> $metadata no free text: a reason is stored only as its hash */
    private function auditLegacyFlag(StudentClass $course, ?int $actorId, string $event, array $metadata): void
    {
        SecurityAuditEvent::append($event, 'success', [
            'campus_id' => DB::table('Student')->where('id', $course->StudentID)->value('CampusID'),
            'actor_type' => 'user', 'actor_id' => $actorId,
            'subject_type' => 'student_class', 'subject_id' => (int) $course->getKey(),
        ], $metadata + ['outcome' => 'success'], true); // required: an audit failure rolls the billing write back
    }

    /** The 已回報、待核對 report director-record writes (also the first step of the legacy-flag backfill). @param array<string, mixed> $data */
    private function createPendingReport(StudentClass $sc, array $data, ?int $invoiceId, ?string $tokenHash = null): PaymentReport
    {
        return PaymentReport::create([
            'StudentID'         => $sc->StudentID,
            'StudentClassID'    => $sc->ID,
            'InvoiceID'         => $invoiceId,
            'reported_by_name'  => (string) $sc->student?->name,
            'payment_date'      => $data['payment_date'],
            'payment_method'    => $data['payment_method'],
            'reported_amount'   => $data['amount'],
            'account_last5'     => $data['account_last5'] ?? null,
            'note'              => trim((string) ($data['note'] ?? '')) ?: null,
            'status'            => 'pending',
            'report_token_hash' => $tokenHash ?? hash('sha256', 'director-' . $sc->ID . '-' . now()->timestamp),
            'token_expires_at'  => Carbon::now()->addDays(30),
        ]);
    }

    /** Receipt-intent reissue; confirmation separately settles the pending report. @param array<string, mixed> $data */
    private function recordCorrectionReissue(Request $request, array $data, StudentClass $sc, ?int $userId)
    {
        $link = app(InvoiceCorrectionLink::class);
        return DB::transaction(function () use ($request, $data, $sc, $userId, $link) {
            // Fresh authorization and ownership, locked student → course → invoices.
            $student = Student::query()->whereKey($sc->StudentID)->lockForUpdate()->first();
            $course = StudentClass::query()->whereKey($sc->getKey())->lockForUpdate()->first();
            $campusAllowed = $student && ($request->attributes->get('auth_role') === 'super_admin'
                || in_array((int) $student->CampusID, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true));
            if (!$student || !$course || (int) $course->StudentID !== (int) $student->getKey() || !$campusAllowed) {
                return response()->json(['message' => '找不到此課程', 'code' => 'replaces_invoice_not_found'], 404);
            }
            // Course-classifying guards only after campus auth.
            if ($blockedTutoringPayment = $this->tutoringPaymentBlocked($course)) {
                return $blockedTutoringPayment;
            }
            if ($refusal = ContractMoneyState::waivedRefusal($course, '此合約已確認不收，不能登記收款')) {
                return $refusal;
            }
            if ((string) ($course->ScheduleMode ?? 'count') !== 'count' || $course->isPartOfPackage()) {
                return response()->json(['message' => '目前只有單一堂數制課程可以登記更正帳單。沒有登記任何款項。', 'code' => 'correction_reissue_count_only'], 422);
            }
            $replaced = Invoice::query()->whereKey((int) $data['replaces_invoice_id'])->lockForUpdate()->first();
            $check = $link->precheck($replaced, $course, true, (string) $data['correction_reason'], (int) $data['amount'], (string) $data['idempotency_key']);
            if ($check['refusal']) {
                return $check['refusal'];
            }
            if ($replay = $check['replay']) {
                $report = PaymentReport::query()->whereKey((int) $replay->payment_report_id)->first();
                if (!$report || (int) $report->InvoiceID !== (int) $replay->replacement_invoice_id
                    || (int) $report->StudentClassID !== (int) $course->getKey()
                    || (int) $report->StudentID !== (int) $student->getKey()
                    || (float) $report->reported_amount !== (float) $data['amount']
                    || $report->payment_date?->toDateString() !== Carbon::parse($data['payment_date'])->toDateString()
                    || $report->payment_method !== $data['payment_method']
                    || (string) $report->account_last5 !== (string) ($data['account_last5'] ?? '')
                    || (string) $report->note !== (string) (trim((string) ($data['note'] ?? '')) ?: null)) {
                    return response()->json(['message' => '更正回報資料與原請求不同，請重新核對帳務。', 'code' => 'correction_replay_incompatible'], 409);
                }
                return response()->json(['message' => '這筆更正已經送出過', 'report_id' => $report->id, 'payment_id' => null, 'correction_id' => (int) $replay->id,
                    'invoice_id' => (int) $replay->replacement_invoice_id, 'status' => $report->status ?? 'pending', 'replayed' => true]);
            }
            if ((int) $data['amount'] <= 0) {
                return response()->json(['message' => '更正帳單的金額必須大於 0。沒有登記任何款項。', 'code' => 'correction_amount_invalid'], 422);
            }
            if ($blockReason = $this->courseMoneyBlockReason((int) $course->getKey(), (int) ($course->getAttribute('Paid') ?? 0))) {
                return $this->duplicateCoursePaymentResponse($blockReason);
            }
            if ($over = $this->amountExceedsOutstandingResponse((int) $course->getKey(), (float) $data['amount'])) {
                return $over;
            }
            if ($this->pickOpenInvoice((int) $course->getKey(), $course)) {
                return response()->json(['message' => '此課程還有未作廢的帳單，請直接登記那張帳單。沒有登記任何款項。', 'code' => 'open_invoice_exists'], 422);
            }
            // Confirmation locks report → course; course lock already serializes new reports.
            if ($pending = PaymentReport::query()->where('StudentClassID', $course->getKey())->where('status', 'pending')->first()) {
                return response()->json(['message' => '此課程已有「已回報付款，待你核對入帳」的繳費回報，請先確認收款或退回後再登錄。',
                    'code' => 'pending_report_exists', 'report_id' => $pending->id], 422);
            }

            $invoice = app(InvoiceIssuer::class)->issue([
                'StudentID' => $course->StudentID, 'StudentClassID' => $course->getKey(), 'IssueDate' => Carbon::today()->toDateString(),
                'TotalAmount' => (int) $data['amount'], 'ScheduleModeAtIssue' => 'count',
            ], [['StudentClassID' => $course->getKey(), 'Description' => '更正帳單', 'Amount' => (int) $data['amount']]]);
            $correctionId = $link->link($replaced, $invoice, $userId, (string) $data['correction_reason'], (string) $data['idempotency_key']);

            /** @var PaymentReport $report */
            $report = PaymentReport::query()->create([
                'StudentID' => $student->getKey(), 'StudentClassID' => $course->getKey(), 'InvoiceID' => $invoice->id,
                'reported_by_name' => $student->name, 'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'], 'reported_amount' => $data['amount'],
                'account_last5' => $data['account_last5'] ?? null, 'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'status' => 'pending', 'report_token_hash' => hash('sha256', 'director-' . $course->getKey() . '-' . now()->timestamp),
                'token_expires_at' => Carbon::now()->addDays(30),
            ]);
            DB::table('invoice_corrections')->where('id', $correctionId)->update(['payment_report_id' => $report->getKey()]);
            Log::info('PaymentReport director-record correction reissue', ['report_id' => $report->getKey(), 'invoice_id' => $invoice->id,
                'replaces_invoice_id' => $replaced->id, 'user_id' => $userId]);

            return response()->json(['message' => '已開更正帳單並送出，待你核對入帳', 'report_id' => $report->getKey(), 'payment_id' => null,
                'invoice_id' => (int) $invoice->id, 'status' => 'pending', 'correction_id' => $correctionId]);
        });
    }

    /** #3991 (Founder 2026-10-13): an audited NT$0 免費／贈送 bill for a course with no fee; a bill, not a payment.
     * @param array<string, mixed> $data */
    private function recordFreeGiftBill(int $studentClassId, array $data, mixed $userId)
    {
        return DB::transaction(function () use ($studentClassId, $data, $userId) {
            $course = StudentClass::query()->whereKey($studentClassId)->lockForUpdate()->first();
            if (!$course) {
                return response()->json(['message' => '找不到此課程'], 404);
            }
            if ($refusal = ContractMoneyState::waivedRefusal($course, '此合約已確認不收，不能登錄帳單')) {
                return $refusal;
            }
            if (Invoice::courseHasFee($course)) {
                return response()->json([
                    'message' => '這門課有設定費用（或費用在套裝上），不能登錄為「' . Invoice::ZERO_REASON_FREE_GIFT_LABEL . '」。沒有登記任何款項。',
                    'code' => 'free_gift_course_has_fee',
                ], 422);
            }
            // #4012: a pending report must be confirmed or rejected first; the NT$0 mark would make the course `free`,
            // which drops it from the collection queue while the zero-fee guard blocks confirming that report.
            if (PaymentReport::query()->where('StudentClassID', $course->ID)->where('status', 'pending')->exists()) {
                return response()->json([
                    'message' => '這門課還有一筆待核對入帳的付款回報，請先確認或退回那筆回報，再標記「' . Invoice::ZERO_REASON_FREE_GIFT_LABEL . '」。沒有登記任何款項。',
                    'code' => 'free_gift_pending_report',
                ], 422);
            }
            $freeNote = trim(Invoice::ZERO_REASON_FREE_GIFT_LABEL . ' ' . trim((string) ($data['note'] ?? '')));
            $named = !empty($data['invoice_id']);
            $period = !$named && $course->getAttribute('ScheduleMode') === 'date'
                ? $this->monthlyBilling->defaultPeriodFor($course, $data['payment_date']) : null;
            $query = Invoice::query();
            if ($named) {
                $query->whereKey((int) $data['invoice_id']);
            }
            if ($period !== null) {
                $query->where('billing_period', $period);
            }
            $matches = $query->where('StudentClassID', $course->ID)
                ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))->orderBy('id')->lockForUpdate()->get();
            if ($matches->count() > 1) { // #3991 review: never pick one of several live bills for the same term
                return response()->json(['message' => '這期有多張有效帳單，請先在學生帳務更正。沒有登記任何款項。', 'code' => 'free_gift_invoice_ambiguous'], 409);
            }
            $invoice = $matches->first();
            if (!$invoice && $named) {
                return response()->json(['message' => '指定帳單不存在、已作廢或不屬於此課程'], 404);
            }

            if ($invoice && (int) $invoice->StudentID !== (int) $course->StudentID) {
                // #3991 review: never relabel a bill that belongs to another student.
                return response()->json([
                    'message' => '這張帳單不屬於這門課的學生，不能改成「' . Invoice::ZERO_REASON_FREE_GIFT_LABEL . '」。請先在學生帳務更正帳單。沒有登記任何款項。',
                    'code' => 'free_gift_invoice_owner_mismatch',
                    'invoice_id' => (int) $invoice->id,
                ], 422);
            }
            if ($invoice && $invoice->billing_period === null && $course->getAttribute('ScheduleMode') === 'date') { // #3991 review: never guess the month
                return response()->json(['message' => '這張月結帳單沒有月份，請先在學生帳務更正帳單月份。沒有登記任何款項。', 'code' => 'free_gift_invoice_unperiodized'], 422);
            }
            if ($invoice) {
                $projection = $this->invoiceProjection($invoice, $course);
                if ((int) $projection['total_amount'] > 0 || (int) $projection['net_applied'] > 0 || (int) $invoice->TotalAmount !== 0
                    || $invoice->payments()->exists() || PaymentReport::query()->where('InvoiceID', $invoice->id)->exists()) {
                    return response()->json([
                        'message' => sprintf('這期已有 NT$%d 的帳單或收款，不能改成「%s」。沒有登記任何款項。', $projection['total_amount'], Invoice::ZERO_REASON_FREE_GIFT_LABEL),
                        'code' => 'free_gift_invoice_has_amount',
                        'invoice_id' => (int) $invoice->id,
                    ], 422);
                }
                $invoice->fill(['zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT, 'Status' => 'paid', 'PaidAmount' => 0,
                    'Note' => mb_substr(trim($invoice->Note . ' ' . $freeNote), 0, 255)])->save();
            } else {
                try {
                    $invoice = app(InvoiceIssuer::class)->issue(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
                        'IssueDate' => $data['payment_date'], 'TotalAmount' => 0, 'Status' => 'paid', 'ScheduleModeAtIssue' => $course->getAttribute('ScheduleMode'),
                        'Note' => mb_substr($freeNote, 0, 255), // Invoice.Note is VARCHAR(255)
                        'billing_period' => $period, 'zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT], [], true);
                } catch (BillingPeriodInvoiceExists) {
                    return response()->json(['message' => '此課程該月份已有有效帳單，請重新整理後再試。', 'code' => 'billing_period_invoice_exists'], 409);
                }
            }

            Log::info('PaymentReport director-record free-gift bill', ['invoice_id' => (int) $invoice->id, 'student_class_id' => (int) $course->ID, 'user_id' => $userId]);

            return response()->json([
                'message' => '已登錄 NT$0「' . Invoice::ZERO_REASON_FREE_GIFT_LABEL . '」帳單（不是收款，不用確認入帳）',
                'report_id' => null, 'payment_id' => null, 'invoice_id' => (int) $invoice->id,
                'status' => Invoice::ZERO_REASON_FREE_GIFT,
                'zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT,
            ]);
        });
    }

    /**
     * POST /api/v1/payment-reports/director-record-batch
     */
    public function directorRecordBatch(Request $request)
    {
        $data = $request->validate([
            'payment_date'   => 'required|date|before_or_equal:today',
            'payment_method' => 'required|in:transfer,cash',
            'note'           => 'nullable|string|max:500',
            'entries'        => 'required|array|min:1|max:40',
            'entries.*.student_class_id' => 'required|integer',
            'entries.*.amount'           => 'required|integer|min:0|max:999999',
            'entries.*.account_last5'    => 'nullable|string|max:5|regex:/^[0-9]*$/',
            'entries.*.invoice_id'       => 'nullable|integer',
        ]);

        $results = [];
        $accepted = 0;
        foreach ($data['entries'] as $index => $entry) {
            $payload = [
                'student_class_id' => $entry['student_class_id'],
                'payment_date'     => $data['payment_date'],
                'payment_method'   => $data['payment_method'],
                'amount'           => $entry['amount'],
            ];
            if (!empty($entry['account_last5'])) {
                $payload['account_last5'] = $entry['account_last5'];
            }
            if (!empty($entry['invoice_id'])) {
                $payload['invoice_id'] = $entry['invoice_id'];
            }
            if (!empty($data['note'])) {
                $payload['note'] = $data['note'];
            }

            $inner = Request::create('/api/v1/payment-reports/director-record', 'POST', $payload);
            $inner->headers->set('Accept', 'application/json');
            foreach (['auth_user_id', 'auth_role', 'auth_campus_ids'] as $key) {
                $inner->attributes->set($key, $request->attributes->get($key));
            }

            $resp = $this->directorRecord($inner);
            $body = json_decode($resp->getContent(), true) ?: [];
            $http = $resp->getStatusCode();
            if ($http < 300) {
                $accepted++;
            }
            $results[] = [
                'index' => $index,
                'student_class_id' => (int) $entry['student_class_id'],
                'http_status' => $http,
                'report_id' => $body['report_id'] ?? null,
                'message' => $body['message'] ?? null,
                'code' => $body['code'] ?? null,
            ];
        }

        return response()->json([
            'message' => "已送出 {$accepted} / " . count($data['entries']) . ' 筆，待你核對入帳',
            'accepted' => $accepted,
            'results' => $results,
        ], $accepted === count($data['entries']) ? 200 : 207);
    }

    /**
     * POST /api/v1/payment-reports/confirm-batch
     */
    public function confirmBatch(Request $request)
    {
        $data = $request->validate([
            'ids'  => 'required|array|min:1|max:40',
            'ids.*' => 'integer',
            'note' => 'nullable|string|max:500',
        ]);

        $results = [];
        $accepted = 0;
        foreach (array_values(array_unique($data['ids'])) as $id) {
            $inner = Request::create('/api/v1/payment-reports/' . $id . '/confirm', 'PUT', [
                'note' => $data['note'] ?? '',
            ]);
            $inner->headers->set('Accept', 'application/json');
            foreach (['auth_user_id', 'auth_role', 'auth_campus_ids'] as $key) {
                $inner->attributes->set($key, $request->attributes->get($key));
            }
            $resp = $this->confirm($inner, $id);
            $body = json_decode($resp->getContent(), true) ?: [];
            $http = $resp->getStatusCode();
            if ($http < 300) {
                $accepted++;
            }
            $results[] = [
                'id' => (int) $id,
                'http_status' => $http,
                'message' => $body['message'] ?? null,
                'code' => $body['code'] ?? null,
            ];
        }

        return response()->json([
            'message' => "已確認收款 {$accepted} / " . count($results) . ' 筆',
            'accepted' => $accepted,
            'results' => $results,
        ], $accepted === count($results) ? 200 : 207);
    }

    private function courseResolvedPaid(StudentClass $sc): bool
    {
        $id = (int) $sc->ID;
        $status = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$id])[$id]['status'] ?? null;

        return $status === 'paid';
    }

    /**
     * Plain routing lookup — call only outside DB::transaction.
     * A non-locking SELECT inside the write txn would establish the REPEATABLE-READ
     * consistent-read snapshot before this connection waits on the course lock, so a
     * peer confirm that commits while we wait would be invisible to later resolver
     * non-locking reads (invoice/payments) even though locking invoice reads see it.
     */
    private function resolveReportCourseId(int $reportId): int
    {
        $courseId = (int) PaymentReport::query()->whereKey($reportId)->value('StudentClassID');
        if ($courseId <= 0) {
            abort(404);
        }

        return $courseId;
    }

    /**
     * Shared course-first confirm/void lock prefix — must stay aligned with
     * BillingCorrection::applyCountCorrection (course, then payment_reports).
     * $courseId must already be resolved outside the transaction; after both locks,
     * re-check report StudentClassID / presence (status checked by callers).
     *
     * @return array{0: ?StudentClass, 1: PaymentReport}
     */
    private function lockCourseThenReport(int $reportId, int $courseId): array
    {
        $sc = StudentClass::query()->whereKey($courseId)->lockForUpdate()->first();
        $report = PaymentReport::query()->whereKey($reportId)->lockForUpdate()->first();
        if (!$report instanceof PaymentReport) {
            abort(404);
        }
        if ((int) $report->getAttribute('StudentClassID') !== $courseId) {
            abort(response()->json([
                'message' => '課程歸屬已變更，請重新整理',
                'code' => 'report_course_changed',
            ], 409));
        }

        return [$sc, $report];
    }

    /**
     * F7 S6 (B9): duplicate-payment guard on the BillingPayableResolver, not "any paid invoice" / raw Charge / SUM(PaidAmount).
     * Blocks `paid` and `free`; partial (the remainder may be recorded), unbilled, review_required and a plain unpaid
     * course may take a payment. Package members follow the resolver (a paid package counts while no non-void invoice exists).
     * Fail closed: no resolver answer (error, unknown course) blocks.
     * Founder Q2 (2026-10-11): the R28 carve-out is gone. Invoices + Payment rows are the truth; a Paid=1 flag beside an
     * unpaid/partial invoice does not block, so the owed amount can be recorded.
     *
     * Why it blocks (null = it allows): paid | free |
     * unknown (no resolver answer). The decision is the guard's; the reason only picks the sentence the director reads.
     */
    private function courseMoneyBlockReason(int $studentClassId, int $coursePaid): ?string
    {
        try {
            $status = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$studentClassId])[$studentClassId]['status'] ?? null;
        } catch (\Throwable) {
            return 'unknown';
        }

        return match (true) {
            $status === null => 'unknown',
            $status === 'paid' => 'paid',
            $status === 'free' => 'free',
            default => null,
        };
    }

    /**
     * F7 S6: a partial course (or an unpaid one with an invoice) cannot take more than the resolver's outstanding,
     * so a second report for the same balance cannot over-collect. Count and date mode alike.
     */
    private function amountExceedsOutstandingResponse(int $studentClassId, float $amount)
    {
        try {
            $row = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$studentClassId])[$studentClassId] ?? null;
        } catch (\Throwable) {
            return $this->duplicateCoursePaymentResponse('unknown');
        }
        if (!$row || !(($row['status'] ?? '') === 'partial' || (($row['status'] ?? '') === 'unpaid' && ($row['source'] ?? '') === 'invoice'))) {
            return null;
        }
        $outstanding = (int) $row['outstanding'];
        if ($amount <= $outstanding) {
            return null;
        }

        return response()->json([
            'message' => sprintf('金額超過此課程未繳餘額 NT$%d，請重新核對後再登記。', $outstanding),
            'code' => 'amount_exceeds_outstanding',
            'expected_amount' => $outstanding,
        ], 422);
    }

    private function lockPackageForCourse(StudentClass $studentClass): ?CoursePackage
    {
        $packageId = (int) ($studentClass->PackageID ?? 0);
        if ($packageId <= 0) {
            return null;
        }

        return CoursePackage::where('id', $packageId)->lockForUpdate()->first();
    }

    /**
     * #3753 / #3892: a CoursePackage is one shared payment obligation only for count-mode members. Monthly ('date')
     * members are billed and settled per member against their own period subtotal.
     *
     * @phpstan-assert-if-true CoursePackage $package
     */
    private function isSharedPackageObligation(?CoursePackage $package, StudentClass $course): bool
    {
        return $package !== null && strtolower((string) ($course->getAttribute('ScheduleMode') ?? 'count')) !== 'date';
    }

    private function markPackagePaid(CoursePackage $package, string $paidAt): void
    {
        $package->paid = true;
        $package->paid_at = $paidAt;
        $package->save();

        StudentClass::where('PackageID', $package->id)->update([
            'Paid' => 1,
            'PayDate' => $paidAt,
        ]);
    }

    /**
     * R28 invariant (Founder Q2 coordination): a payment may only be recorded against a bill that still owes. A bill whose
     * Payment rows already cover its total is refused even when its stored Status is stale ("unpaid"), whatever the Paid flag says.
     */
    private function invoiceCoveredByPayments(Invoice $invoice, ?StudentClass $sc): bool
    {
        $projection = $this->invoiceProjection($invoice, $sc);

        return $this->packagePaidHold($invoice) || ((int) $projection['total_amount'] > 0 && (int) $projection['outstanding_amount'] <= 0);
    }

    /** As Invoice::mayOweSql (#3891): a PAID package member's stored-paid bill stays settled (待核對); an unpaid package holds nothing. */
    private function packagePaidHold(Invoice $invoice): bool
    {
        return (string) $invoice->Status === 'paid' && StudentClass::query()->join('course_packages', 'course_packages.id', '=', 'StudentClass.PackageID')
            ->where('StudentClass.ID', $invoice->StudentClassID)->where('course_packages.paid', true)->exists();
    }

    /**
     * The invoice's money from its Payment rows (re-read with a locking read so a concurrent confirm's committed row
     * counts), never the stored PaidAmount/Status. Callers already hold the course row lock.
     */
    private function invoiceProjection(Invoice $invoice, ?StudentClass $sc): array
    {
        $invoice->setRelation('payments', $invoice->payments()->lockForUpdate()->get());
        $invoice->loadMissing('items');

        return app(InvoiceAmountReconciliationService::class)->resolve($invoice, $sc);
    }

    /**
     * Invoice to collect against when the caller named none: the bill the collection list shows (Founder 2026-10-13),
     * i.e. the resolver's collection order (monthly: oldest period first). Every non-void bill is a candidate and its
     * Payment-row projection decides whether it still owes, whatever its stored Status (#3875: stored paid can still
     * owe). None owes → the first not-stored-paid one, so the caller refuses it as covered; never another bill.
     */
    private function pickOpenInvoice(int $studentClassId, ?StudentClass $sc): ?Invoice
    {
        $live = app(BillingPayableResolver::class)->collectionOrder(Invoice::where('StudentClassID', $studentClassId)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
            ->orderBy('id')->lockForUpdate()->get(), $sc);

        return $live->first(fn (Invoice $i) => !$this->packagePaidHold($i) && (int) $this->invoiceProjection($i, $sc)['outstanding_amount'] > 0)
            ?? $live->first(fn (Invoice $i) => (string) $i->Status !== 'paid');
    }

    /**
     * #3864 hotfix P1-a: the amount is capped by the TARGET invoice's own outstanding (Payment-row projection), not the
     * course-wide total — otherwise a payment owed to another bill lands on this one as an overpayment.
     * #3875: a NT$0 bill takes no positive amount (that would overpay it at once), and NT$0 is not a report against a bill
     * that still owes. NT$0 on a NT$0 bill stays allowed (free courses settle at 0).
     */
    private function amountExceedsInvoiceResponse(?Invoice $invoice, ?StudentClass $sc, float $amount)
    {
        if (!$invoice) {
            // Unbilled charge-bearing contract: NT$0 is not a report either (free courses never reach here, #3888 review).
            return $amount <= 0 && ((int) ($sc->Charge ?? 0) > 0 || (float) ($sc->Rate ?? 0) > 0) ? response()->json([ // #3991: a Rate is a fee too
                'message' => '這門課要收費，金額不可為 0；請填實際收到的金額。沒有登記任何款項。',
                'code' => 'zero_amount_with_balance',
            ], 422) : null;
        }
        $projection = $this->invoiceProjection($invoice, $sc);
        $outstanding = (int) $projection['outstanding_amount'];
        // NT$0 on a stored NT$0 bill of a course that charges is an erroneous bill, not a free settlement (#3888 review).
        $erroneousZero = (int) $projection['stored_total_amount'] <= 0 && ((int) ($sc->Charge ?? 0) > 0 || (float) ($sc->Rate ?? 0) > 0);
        if ($amount <= 0 && ($outstanding > 0 || $erroneousZero)) {
            return response()->json([
                'message' => $erroneousZero ? '這張帳單金額是 NT$0，但這門課要收費；請先在學生帳務更正帳單金額。沒有登記任何款項。'
                    : sprintf('這張帳單還欠 NT$%d，金額不可為 0；請填實際收到的金額。沒有登記任何款項。', $outstanding),
                'code' => 'zero_amount_with_balance',
                'expected_amount' => $outstanding,
                'invoice_id' => (int) $invoice->id,
            ], 422);
        }
        // A monthly bill repriced to NT$0 by held sessions is refused by the monthly_amount_stale guard with its own sentence.
        if ($amount <= $outstanding
            || ((int) $projection['total_amount'] <= 0 && $projection['amount_source'] === 'billable_sessions')) {
            return null;
        }

        return response()->json([
            'message' => (int) $projection['total_amount'] <= 0
                ? '這張帳單金額是 NT$0，不能登記收款；請先在學生帳務更正帳單金額。沒有登記任何款項。'
                : sprintf('這張帳單只欠 NT$%d，請改成 %d 以下，或分到其他帳單。', $outstanding, $outstanding),
            'code' => 'amount_exceeds_outstanding',
            'expected_amount' => $outstanding,
            'invoice_id' => (int) $invoice->id,
        ], 422);
    }

    /** The target bill is settled by its Payment rows: warn and block; the money is never moved to another bill. */
    private function invoiceAlreadyCoveredResponse(Invoice $invoice)
    {
        return response()->json([
            'message' => $this->packagePaidHold($invoice) ? '這張套餐帳單已標記繳清，收款紀錄待核對，不能在這裡登記；請在學生帳務核對套餐收款。沒有登記任何款項。'
                : '這張帳單依收款紀錄已繳清，不能再登記，也不會改記到其他帳單。請重新整理名單；若帳單狀態對不上，請在學生帳務核對收款紀錄，作廢重複或錯誤的帳單。沒有登記任何款項。',
            'code' => 'course_already_paid',
            'invoice_id' => (int) $invoice->id,
        ], 422);
    }

    private function duplicateCoursePaymentResponse(string $reason)
    {
        // Same code and status for every reason; only the sentence differs so the director knows what to do next.
        return response()->json([
            'message' => match ($reason) {
                'free' => '這門課是免費課程（輔導、試上或 0 元），不需要收款。',
                'paid' => '這門課的款項已收齊，不需要再登記；若收錯了，請先作廢原收款再重新登記。',
                // Any other/unknown reason is the safe sentence, never "已收齊".
                default => '暫時無法確認這門課的繳費狀態，沒有登記任何款項。請稍後再試；一直失敗請回報。',
            },
            'code'    => 'course_already_paid',
        ], 422);
    }

    /**
     * PUT /api/v1/payment-reports/{id}/reject
     * Director rejects a payment report with a reason.
     */
    public function reject(Request $request, $id)
    {
        $report = PaymentReport::findOrFail($id);

        if ($report->status !== 'pending') {
            return response()->json(['message' => '此回報已處理過'], 422);
        }

        $data = $request->validate([
            'rejection_note' => 'required|string|max:500',
        ]);

        $userId = $request->attributes->get('auth_user_id');

        $report->update([
            'status'         => 'rejected',
            'confirmed_by'   => $userId,
            'confirmed_at'   => Carbon::now(),
            'rejection_note' => $data['rejection_note'],
        ]);

        Log::info('PaymentReport rejected', [
            'report_id' => $report->id,
            'user_id'   => $userId,
            'reason'    => $data['rejection_note'],
        ]);

        return response()->json(['message' => '已退回此回報', 'report_id' => $report->id]);
    }

    /**
     * PUT /api/v1/payment-reports/{id}/void
     * Director voids a confirmed payment report — reverses Payment, Invoice, StudentClass.
     */
    public function void(Request $request, $id)
    {
        $report = PaymentReport::with('student')->findOrFail($id);

        if ($report->status !== 'confirmed') {
            $msg = $report->status === 'voided'
                ? '此收款已撤銷過'
                : '此回報尚未確認，無法撤銷';
            return response()->json(['message' => $msg], 422);
        }

        $data = $request->validate([
            'void_reason' => 'required|string|max:500',
        ]);

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if (!empty($campusIds) && $report->student) {
            if (!in_array((int) $report->student->CampusID, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $userId = $request->attributes->get('auth_user_id');
        // Routing id only — outside the write transaction so waiting on the course
        // lock cannot inherit a stale REPEATABLE-READ snapshot from a plain SELECT.
        $courseId = $this->resolveReportCourseId((int) $id);

        return DB::transaction(function () use ($id, $courseId, $userId, $data, $role, $campusIds) {
            // Align with BillingCorrection (StudentClass → payment_reports).
            // A delayed request must not reuse its pre-transaction status.
            [$sc, $report] = $this->lockCourseThenReport((int) $id, $courseId);
            if ($report->getAttribute('status') !== 'confirmed') {
                return response()->json(['message' => '此收款已處理過，請重新整理'], 422);
            }
            $campusId = (int) DB::table('Student')->where('id', $report->getAttribute('StudentID'))->value('CampusID');
            if ($campusId <= 0 || ($role !== 'super_admin' && !in_array($campusId, $campusIds, true))) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $originalAmount = (int) abs((float) $report->getAttribute('reported_amount'));
            $reportStudentId = (int) $report->getAttribute('StudentID');

            // Ownership before any write: course must exist and match the report student.
            // Non-null InvoiceID that cannot be loaded must refuse (do not clear Paid on a missing bill).
            // Legitimate null-InvoiceID legacy path is allowed only when course/report ownership already matches.
            if (!$sc || (int) $sc->StudentID !== $reportStudentId) {
                return response()->json(['message' => '原帳單已作廢或歸屬不符，請重新核對', 'code' => 'void_invoice_mismatch'], 422);
            }

            $invoiceId = $report->getAttribute('InvoiceID');
            $invoice = null;
            // Strict non-null: InvoiceID=0 must not take the legacy null path (truthy check would).
            if ($invoiceId !== null) {
                $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
                if (!$invoice
                    || (string) $invoice->Status === 'void'
                    || (int) $invoice->StudentClassID !== (int) $sc->ID
                    || (int) $invoice->StudentID !== $reportStudentId) {
                    return response()->json(['message' => '原帳單已作廢或歸屬不符，請重新核對', 'code' => 'void_invoice_mismatch'], 422);
                }
                $invoice->payments()->lockForUpdate()->get();
            }

            if ($invoice) {
                Payment::create([
                    'InvoiceID'         => $invoice->id,
                    'Amount'            => -$originalAmount,
                    'PaidAt'            => Carbon::today()->toDateString(),
                    'Method'            => 'void',
                    'Note'              => $data['void_reason'],
                    'payment_report_id' => $report->getAttribute('id'),
                ]);

                $newPaid = max(0, (int) $invoice->PaidAmount - $originalAmount);
                $invoiceStatus = $newPaid <= 0 ? 'unpaid' : ($newPaid < (int) $invoice->TotalAmount ? 'partial' : 'paid');
                $invoice->update([
                    'PaidAmount' => $newPaid,
                    'Status'     => $invoiceStatus,
                ]);
            }

            $scPaid = 0;
            // F7 S7: recompute from the resolver; no invoice or no longer paid (partial or unpaid) clears the flag.
            // $sc already required above (missing/mismatched course returns void_invoice_mismatch).
            if ($invoice && $this->courseResolvedPaid($sc)) {
                $scPaid = (int) $sc->Paid;
            } else {
                $reopen = $sc->getAttribute('closed_reason') === 'settled' ? ['closed_reason' => 'settled_pending'] : [];
                $sc->update(['Paid' => 0, 'PayDate' => null] + $reopen);
            }

            $report->update([
                'status'      => 'voided',
                'voided_by'   => $userId,
                'voided_at'   => Carbon::now(),
                'void_reason' => $data['void_reason'],
            ]);

            Log::info('[PaymentVoid]', [
                'report_id'        => $report->getAttribute('id'),
                'payment_id'       => $report->getAttribute('payment_id'),
                'user_id'          => $userId,
                'void_reason'      => $data['void_reason'],
                'student_class_id' => $report->getAttribute('StudentClassID'),
                'amount'           => $originalAmount,
            ]);

            return response()->json([
                'message'            => '已撤銷收款',
                'report_id'          => $report->getAttribute('id'),
                'invoice_status'     => $invoice->Status ?? null,
                'student_class_paid' => $scPaid,
            ]);
        });
    }

    /**
     * GET /api/v1/payment-reports/{id}/receipt
     * Returns receipt data for Canvas rendering.
     */
    public function receipt(Request $request, $id)
    {
        $report = PaymentReport::with(['student', 'studentClass.subjectRecord', 'confirmedByUser', 'invoice', 'payment'])
            ->findOrFail($id);

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        $studentCampusId = (int) ($report->student?->CampusID ?? 0);
        if ($role !== 'super_admin' && ($studentCampusId <= 0 || !in_array($studentCampusId, $campusIds, true))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($report->status !== 'confirmed') {
            return response()->json(['message' => '尚未核帳確認，無法產生收據'], 422);
        }

        $sc = $report->studentClass;
        $subjectName = $sc?->displaySubjectName() ?? ($sc?->subjectRecord?->Subject_Name ?? '課程');

        $periodStart = null;
        $periodEnd = null;
        $attendedDates = [];
        $invoice = $report->getRelationValue('invoice');
        if (! $invoice instanceof Invoice) {
            $invoice = null;
        }
        $invoiceBillingSnapshot = $invoice ? $invoice->getAttribute('billing_snapshot') : null;
        $billingSnapshot = is_array($invoiceBillingSnapshot)
            ? $invoiceBillingSnapshot
            : null;
        $invoiceBillingPeriod = $invoice ? $invoice->getAttribute('billing_period') : null;
        $snapshotSessionRows = is_array($billingSnapshot['sessions'] ?? null)
            ? $billingSnapshot['sessions']
            : [];
        $receiptSubject = (string) ($snapshotSessionRows[0]['subject'] ?? $subjectName);
        // (#554) 收據上課日期：除了已上課（attended）日期外，堂數制再補列「已購但尚未上課」
        // 的預期堂次，補足到已購堂數，讓家長看到的堂數與實付堂數一致。
        // 結構：[{ date: 'Y/m/d', expected: bool }]。attended_dates 保留供既有相容。
        $sessionDates = [];
        if ($sc && $billingSnapshot && $sc->ScheduleMode === 'date') {
            $formatSnapshotDate = static function ($date): ?string {
                if (!$date) {
                    return null;
                }
                try {
                    return Carbon::parse((string) $date)->format('Y/m/d');
                } catch (\Throwable) {
                    return null;
                }
            };
            $periodStart = $formatSnapshotDate($billingSnapshot['period_start'] ?? null);
            $periodEnd = $formatSnapshotDate($billingSnapshot['period_end'] ?? null);
            $sessionDates = collect($snapshotSessionRows)
                ->map(fn (array $session) => [
                    'date' => Carbon::parse((string) ($session['date'] ?? ''))->format('Y/m/d'),
                    'start_time' => $session['start_time'] ?? null,
                    'end_time' => $session['end_time'] ?? null,
                    'subject' => $session['subject'] ?? $receiptSubject,
                    'lesson' => (int) ($session['lesson'] ?? 0),
                    'expected' => false,
                ])
                ->values()
                ->all();
            $attendedDates = array_values(array_map(
                fn (array $session) => $session['date'],
                $sessionDates
            ));
        } elseif ($sc) {
            $reportedDate = $sc->ScheduleMode === 'date' ? Carbon::make($report->payment_date) : null;
            // No snapshot: the bill's service period (#3888), slip precedence: this course's items, a single item, else billing_period.
            [$itemStart, $itemEnd] = $invoice && $reportedDate ? $this->monthlyBilling->serviceRangeForCourse($invoice, (int) $sc->ID) : [null, null];
            $anchor = preg_match('/^\d{4}-\d{2}$/', (string) $invoiceBillingPeriod) ? Carbon::createFromFormat('!Y-m', (string) $invoiceBillingPeriod) : $reportedDate;
            [$from, $to] = $itemStart !== null && $itemEnd !== null ? [Carbon::parse($itemStart), Carbon::parse($itemEnd)]
                : [$anchor?->copy()->startOfMonth(), $anchor?->copy()->endOfMonth()];
            if ($sc->ScheduleMode === 'date') {
                $periodStart = $from?->format('Y/m/d');
                $periodEnd = $to?->format('Y/m/d');
            } else {
                $periodStart = $sc->StartDate ? Carbon::parse($sc->StartDate)->format('Y/m/d') : null;
                $periodEnd = $sc->EndDate ? Carbon::parse($sc->EndDate)->format('Y/m/d') : null;
            }

            $sessionQuery = \App\Models\ClassSession::where('StudentClassID', $sc->ID)
                ->whereIn('Status', ['attended', 'completed', 'late'])
                ->orderBy('SessionDate');

            if ($reportedDate !== null) {
                $sessionQuery->whereDate('SessionDate', '>=', $from->toDateString())->whereDate('SessionDate', '<=', $to->toDateString());
            }

            if ($sc->ScheduleMode === 'date') {
                if ($sc->StartDate) {
                    $sessionQuery->whereDate('SessionDate', '>=', Carbon::parse($sc->StartDate)->toDateString());
                }
                if ($sc->EndDate) {
                    $sessionQuery->whereDate('SessionDate', '<=', Carbon::parse($sc->EndDate)->toDateString());
                }
            }

            $attendedDates = $sessionQuery->pluck('SessionDate')
                ->map(fn ($d) => Carbon::parse((string) $d)->format('Y/m/d'))
                ->values()
                ->all();

            $sessionDates = array_map(fn ($d) => ['date' => $d, 'expected' => false], $attendedDates);

            // 月結制（date）以月份為單位，不補預期堂次；僅堂數制補足到已購堂數。
            if ($sc->ScheduleMode !== 'date') {
                $purchased = (int) ($sc->SessionCount ?? 0);
                // $needed = null 代表未設已購堂數（不限制，列出全部尚未上課的排課）
                $needed = $purchased > 0 ? max(0, $purchased - count($attendedDates)) : null;
                if ($needed === null || $needed > 0) {
                    $upcoming = \App\Models\ClassSession::where('StudentClassID', $sc->ID)
                        ->whereIn('Status', ['scheduled', 'rescheduled'])
                        ->orderBy('SessionDate')
                        ->pluck('SessionDate')
                        ->map(fn ($d) => Carbon::parse((string) $d)->format('Y/m/d'))
                        ->values()
                        ->all();
                    if ($needed !== null) {
                        $upcoming = array_slice($upcoming, 0, $needed);
                    }
                    foreach ($upcoming as $d) {
                        $sessionDates[] = ['date' => $d, 'expected' => true];
                    }
                }

                // A count-course's StartDate/EndDate is legacy contract metadata,
                // not the receipt period. Renewal, leave-extension, and older
                // imports can leave those fields stale (or even inverted) while
                // ClassSession remains the authoritative dated record. Derive
                // the printed period from the same sessions shown on the receipt
                // so an old payment cannot display a newer course period (#232).
                $receiptDates = collect($sessionDates)
                    ->pluck('date')
                    ->filter()
                    ->map(fn ($d) => Carbon::createFromFormat('Y/m/d', (string) $d)->startOfDay())
                    ->sortBy(fn (Carbon $d) => $d->timestamp)
                    ->values();
                if ($receiptDates->isNotEmpty()) {
                    $periodStart = $receiptDates->first()->format('Y/m/d');
                    $periodEnd = $receiptDates->last()->format('Y/m/d');
                }
            }
        }

        $campusName = '';
        if ($report->student) {
            $campus = \App\Models\Campus::find($report->student->CampusID);
            $campusName = $campus->name ?? '';
        }

        $sessionMeta = ['first_live' => null, 'first_any' => null];
        if ($sc) {
            $sessionMetaRow = \App\Models\ClassSession::query()
                ->selectRaw("MIN(CASE WHEN Status <> 'cancelled' THEN SessionDate END) as first_live, MIN(SessionDate) as first_any")
                ->where('StudentClassID', $sc->ID)
                ->first();
            $sessionMeta = [
                'first_live' => $sessionMetaRow?->getAttribute('first_live') ? substr((string) $sessionMetaRow->getAttribute('first_live'), 0, 10) : null,
                'first_any' => $sessionMetaRow?->getAttribute('first_any') ? substr((string) $sessionMetaRow->getAttribute('first_any'), 0, 10) : null,
            ];
        }
        $course = $sc instanceof StudentClass ? $sc : null;
        $life = AccountingCourseClarity::lifecycle($course);
        $first = AccountingCourseClarity::firstSession($sessionMeta, $course);
        $classType = $course !== null ? (string) $course->getAttribute('ClassType') : '';
        $amount = (float) $report->reported_amount;

        return response()->json([
            'receipt_no'       => 'R-' . str_pad((string) $report->id, 6, '0', STR_PAD_LEFT),
            'student_name'     => $report->student?->name ?? $report->reported_by_name,
            'campus_name'      => $campusName,
            'subject'          => $receiptSubject,
            'session_count'    => $sc?->SessionCount,
            'period_sessions'  => $billingSnapshot['period_sessions'] ?? null,
            'billing_period'   => $billingSnapshot['billing_period'] ?? $invoiceBillingPeriod,
            'period_start'     => $periodStart,
            'period_end'       => $periodEnd,
            'attended_dates'   => $attendedDates,
            'session_dates'    => $sessionDates,
            'payment_date'     => $report->payment_date->format('Y/m/d'),
            'payment_method'   => $report->payment_method,
            'note'             => $report instanceof PaymentReport ? $report->displayNote() : '',
            'amount'           => $amount,
            'confirmed_at'     => $report->confirmed_at?->format('Y/m/d'),
            'confirmed_by'     => $report->confirmedByUser?->Name ?? '系統',
            'class_type'       => $classType,
            'class_type_label' => AccountingCourseClarity::classTypeLabel($classType),
            'schedule_mode'    => $sc?->ScheduleMode,
            'course_lifecycle' => $life['code'],
            'course_lifecycle_label' => $life['label'],
            'first_session_date' => $first['date'],
            'first_session_display' => $first['display'],
            'first_session_source' => $first['source'],
            'first_session_note' => $first['note'],
            'is_backfilled'    => !empty($report->backfill_note),
            'backfill_note'    => $report->backfill_note,
            // #934: course's billing mode (count/date) changed since this receipt
            // was issued — the amount/period shown may no longer reflect the
            // current contract. Display-only flag; no ledger figure is touched.
            'billing_mode_changed' => (bool) $invoice?->billingModeChangedSinceIssue(),
        ]);
    }

    private function tutoringPaymentBlocked($course, bool $explicitFreeGift = false)
    {
        if (!$course instanceof StudentClass) {
            return null;
        }

        if (strtolower(trim((string) ($course->ClassType ?? ''))) === 'tutoring') {
            return response()->json([
                'message' => '輔導課無須繳費，不能建立付款回報、收款或付款義務。請先檢查課程帳務資料。',
                'code' => 'tutoring_no_payment_obligation',
            ], 422);
        }
        // #3203: any course the resolver calls `free` (e.g. a 0-total trial) has no payment obligation either.
        // Only 0-charge courses can be free; paid courses skip this and keep the later fail-closed guards.
        if ((int) ($course->Charge ?? 0) > 0 || $explicitFreeGift) {
            return null;
        }
        $id = (int) $course->getKey();
        try {
            $free = (app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$id])[$id]['status'] ?? null) === 'free';
        } catch (\Throwable $e) {
            $free = false; // the duplicate-payment guard below fails closed on its own
        }
        if ($free) {
            return response()->json([
                'message' => '這門課金額為 0，無須繳費，不能建立付款回報、收款或付款義務。',
                'code' => 'zero_fee_no_payment_obligation',
            ], 422);
        }

        return null;
    }
}
