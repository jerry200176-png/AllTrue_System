<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    public function payments(Request $request)
    {
        return $this->paymentResponse($request, false);
    }

    public function paymentsExport(Request $request)
    {
        return $this->paymentResponse($request, true);
    }

    public function ledger(Request $request)
    {
        $studentClassId = (int) $request->input('student_class_id', 0);
        $reportId = (int) $request->input('report_id', 0);

        if ($studentClassId <= 0 && $reportId <= 0) {
            return response()->json(['message' => 'student_class_id or report_id is required'], 422);
        }

        $anchorReport = null;
        if ($reportId > 0) {
            $anchorReport = PaymentReport::with(['student', 'studentClass.student'])->find($reportId);
            if (!$anchorReport) {
                return response()->json(['message' => 'Payment report not found'], 404);
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
            return response()->json(['message' => 'Student class not found'], 404);
        }

        $student = $anchorClass->student ?: Student::find($anchorClass->StudentID);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
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

        $invoices = Invoice::with(['payments' => function ($query) {
                $query->select(['id', 'InvoiceID', 'Amount', 'PaidAt', 'Method', 'Note', 'payment_report_id'])
                    ->orderBy('PaidAt')
                    ->orderBy('id');
            }])
            ->whereIn('StudentClassID', $classIds)
            ->orderByRaw("COALESCE(billing_period, DATE_FORMAT(IssueDate, '%Y-%m')) DESC")
            ->orderByDesc('id')
            ->get();

        $reports = PaymentReport::with(['confirmedByUser'])
            ->where('StudentID', $student->id)
            ->whereIn('status', ['pending', 'confirmed', 'voided'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        $reportsByPaymentId = $reports->whereNotNull('payment_id')->keyBy('payment_id');
        $reportsById = $reports->keyBy('id');
        $reportsByInvoiceId = $reports->whereNotNull('InvoiceID')->groupBy('InvoiceID');
        $anomalies = [];

        $invoiceRows = $invoices->map(function (Invoice $invoice) use ($reportsByPaymentId, $reportsById, $reportsByInvoiceId, &$anomalies) {
            $payments = $invoice->payments;
            $positivePayments = $payments
                ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) > 0 && (string) ($payment->Method ?? '') !== 'void')
                ->values();
            $voidPayments = $payments
                ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void')
                ->values();
            $netApplied = (int) $payments->sum(fn ($payment) => (int) ($payment->Amount ?? 0));
            $appliedAmount = max(0, $netApplied);
            $voidedAmount = abs((int) $voidPayments->sum(fn ($payment) => (int) ($payment->Amount ?? 0)));
            $totalAmount = (int) ($invoice->TotalAmount ?? 0);
            $outstanding = max(0, $totalAmount - $appliedAmount);
            $invoiceAnomalies = [];

            if ($positivePayments->count() > 1) {
                $invoiceAnomalies[] = $this->ledgerAnomaly(
                    'duplicate_effective_payments',
                    'warning',
                    '同一張帳單有多筆正向有效收款，需確認是否為分批付款或重複入帳。',
                    $invoice
                );
            }

            if ((int) ($invoice->PaidAmount ?? 0) !== $appliedAmount) {
                $invoiceAnomalies[] = $this->ledgerAnomaly(
                    'paid_amount_mismatch',
                    'warning',
                    '帳單 PaidAmount 與付款流水淨額不一致。',
                    $invoice
                );
            }

            if ((string) $invoice->Status === 'paid' && $outstanding > 0) {
                $invoiceAnomalies[] = $this->ledgerAnomaly(
                    'paid_status_with_balance',
                    'critical',
                    '帳單狀態為已繳，但依付款流水仍有未結清金額。',
                    $invoice
                );
            }

            if (in_array((string) $invoice->Status, ['unpaid', 'partial'], true) && $totalAmount > 0 && $outstanding === 0) {
                $invoiceAnomalies[] = $this->ledgerAnomaly(
                    'open_status_without_balance',
                    'warning',
                    '帳單狀態仍為未繳/部分付款，但付款流水已足額。',
                    $invoice
                );
            }

            $paymentRows = $payments->map(function ($payment) use ($reportsByPaymentId, $reportsById, $invoice, &$invoiceAnomalies) {
                $report = $reportsByPaymentId->get((int) $payment->id)
                    ?? ($payment->payment_report_id ? $reportsById->get((int) $payment->payment_report_id) : null);
                $isVoid = (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void';

                if (!$isVoid && !$report) {
                    $invoiceAnomalies[] = $this->ledgerAnomaly(
                        'payment_without_receipt',
                        'warning',
                        '付款流水找不到對應收據/核帳紀錄。',
                        $invoice,
                        null,
                        (int) $payment->id
                    );
                }

                return [
                    'id' => (int) $payment->id,
                    'paid_at' => $payment->PaidAt ? substr((string) $payment->PaidAt, 0, 10) : null,
                    'amount' => (int) $payment->Amount,
                    'method' => (string) ($payment->Method ?? ''),
                    'note' => (string) ($payment->Note ?? ''),
                    'is_void' => $isVoid,
                    'report_id' => $report ? (int) $report->id : null,
                    'receipt_no' => $report ? $this->receiptNo((int) $report->id) : null,
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
                        '已確認收據缺少 Payment 關聯，會造成收據與帳單難以對齊。',
                        $invoice,
                        (int) $reportRow['report_id']
                    );
                }
            }

            $anomalies = array_merge($anomalies, $invoiceAnomalies);

            return [
                'id' => (int) $invoice->id,
                'student_class_id' => (int) $invoice->StudentClassID,
                'billing_period' => $invoice->billing_period,
                'issue_date' => $invoice->IssueDate ? substr((string) $invoice->IssueDate, 0, 10) : null,
                'due_date' => $invoice->DueDate ? substr((string) $invoice->DueDate, 0, 10) : null,
                'total_amount' => $totalAmount,
                'paid_amount' => (int) ($invoice->PaidAmount ?? 0),
                'calculated_applied_amount' => $appliedAmount,
                'voided_amount' => $voidedAmount,
                'outstanding_amount' => $outstanding,
                'status' => (string) ($invoice->Status ?? ''),
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
                    'message' => '已確認收據未套用到本學生帳單。',
                    'invoice_id' => $report->InvoiceID ? (int) $report->InvoiceID : null,
                    'student_class_id' => (int) $report->StudentClassID,
                    'report_id' => (int) $report->id,
                    'payment_id' => $report->payment_id ? (int) $report->payment_id : null,
                ];
            }
            if ($report->status === 'confirmed' && empty($report->payment_id)) {
                $anomalies[] = [
                    'code' => 'confirmed_receipt_without_payment',
                    'severity' => 'warning',
                    'message' => '已確認收據缺少 Payment 關聯。',
                    'invoice_id' => $report->InvoiceID ? (int) $report->InvoiceID : null,
                    'student_class_id' => (int) $report->StudentClassID,
                    'report_id' => (int) $report->id,
                    'payment_id' => null,
                ];
            }
            if ($report->payment_id && !in_array((int) $report->payment_id, $paymentIds, true)) {
                $anomalies[] = [
                    'code' => 'receipt_payment_outside_ledger',
                    'severity' => 'warning',
                    'message' => '收據關聯的 Payment 不在本學生帳單流水內。',
                    'invoice_id' => $report->InvoiceID ? (int) $report->InvoiceID : null,
                    'student_class_id' => (int) $report->StudentClassID,
                    'report_id' => (int) $report->id,
                    'payment_id' => (int) $report->payment_id,
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
            ],
            'summary' => [
                'invoice_total' => $invoiceTotal,
                'applied_total' => $appliedTotal,
                'voided_total' => (int) $invoiceRows->sum('voided_amount'),
                'outstanding_total' => max(0, $invoiceTotal - $appliedTotal),
                'invoice_count' => $invoiceRows->count(),
                'receipt_count' => $reportRows->where('status', 'confirmed')->count(),
                'anomaly_count' => $uniqueAnomalies->count(),
            ],
            'courses' => $classes->map(fn (StudentClass $class) => [
                'id' => (int) $class->ID,
                'subject' => $class->displaySubjectName(),
                'schedule_mode' => (string) ($class->ScheduleMode ?? ''),
                'charge' => (int) ($class->Charge ?? 0),
                'paid' => (int) ($class->Paid ?? 0) === 1,
                'paid_at' => $class->PayDate ? substr((string) $class->PayDate, 0, 10) : null,
                'start_date' => $class->StartDate ? substr((string) $class->StartDate, 0, 10) : null,
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

        $query = PaymentReport::with(['student', 'studentClass.subjectRecord', 'confirmedByUser'])
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
            $query->whereHas('student', fn ($q) => $q->where('name', 'like', "%{$student}%"));
        }

        if ($request->filled('subject')) {
            $subject = trim((string) $request->input('subject'));
            $query->whereHas('studentClass.subjectRecord', fn ($q) => $q->where('Subject_Name', 'like', "%{$subject}%"));
        }

        if ($request->filled('payment_method')) {
            $method = (string) $request->input('payment_method');
            if (!in_array($method, ['cash', 'transfer'], true)) {
                return response()->json(['message' => 'Invalid payment_method'], 422);
            }
            $query->where('payment_method', $method);
        }

        $allRows = (clone $query)
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit($export ? 5000 : 10000)
            ->get();
        $firstSessionMap = $this->firstSessionDateMap($allRows->pluck('StudentClassID')->all());
        $transformed = $allRows->map(fn (PaymentReport $report) => $this->transformPaymentReport($report, $firstSessionMap))->values();
        $summary = $this->summarize($transformed);

        if ($export) {
            return response()->json([
                'data' => $transformed,
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

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
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
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role !== 'super_admin' && !empty($campusIds) && !in_array($campusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
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
        ];
    }

    private function ledgerReportRow(PaymentReport $report): array
    {
        return [
            'report_id' => (int) $report->id,
            'receipt_no' => $this->receiptNo((int) $report->id),
            'student_class_id' => (int) $report->StudentClassID,
            'invoice_id' => $report->InvoiceID ? (int) $report->InvoiceID : null,
            'payment_id' => $report->payment_id ? (int) $report->payment_id : null,
            'payment_date' => $report->payment_date ? $report->payment_date->toDateString() : null,
            'payment_method' => (string) ($report->payment_method ?? ''),
            'amount' => (int) round((float) $report->reported_amount),
            'status' => (string) $report->status,
            'confirmed_at' => $report->confirmed_at?->toIso8601String(),
            'confirmed_by_name' => $report->confirmedByUser?->Name,
            'voided_at' => $report->voided_at?->toIso8601String(),
            'void_reason' => (string) ($report->void_reason ?? ''),
            'is_backfilled' => !empty($report->backfill_note),
        ];
    }

    private function receiptNo(int $reportId): string
    {
        return 'R-' . str_pad((string) $reportId, 6, '0', STR_PAD_LEFT);
    }

    private function firstSessionDateMap(array $studentClassIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentClassIds))));
        if ($ids === []) {
            return [];
        }

        return ClassSession::query()
            ->selectRaw('StudentClassID, MIN(SessionDate) as first_date')
            ->whereIn('StudentClassID', $ids)
            ->where('Status', '!=', 'cancelled')
            ->groupBy('StudentClassID')
            ->pluck('first_date', 'StudentClassID')
            ->map(fn ($date) => $date ? substr((string) $date, 0, 10) : null)
            ->all();
    }

    private function transformPaymentReport(PaymentReport $report, array $firstSessionMap): array
    {
        $method = (string) ($report->payment_method ?? 'cash');
        $amount = (int) round((float) $report->reported_amount);
        $paymentDate = $report->payment_date ? $report->payment_date->toDateString() : null;
        $firstSessionDate = $firstSessionMap[(int) $report->StudentClassID] ?? null;
        $isConfirmed = $report->status === 'confirmed';

        return [
            'report_id' => (int) $report->id,
            'receipt_no' => 'R-' . str_pad((string) $report->id, 6, '0', STR_PAD_LEFT),
            'payment_date' => $paymentDate,
            'student_id' => (int) $report->StudentID,
            'student_name' => $report->student?->name ?? $report->reported_by_name,
            'student_class_id' => (int) $report->StudentClassID,
            'subject' => $report->studentClass?->displaySubjectName() ?? '課程',
            'schedule_mode' => (string) ($report->studentClass?->ScheduleMode ?? ''),
            'first_session_date' => $firstSessionDate,
            'is_prepaid' => $paymentDate !== null && $firstSessionDate !== null && $paymentDate < $firstSessionDate,
            'payment_method' => $method,
            'cash_amount' => $isConfirmed && $method === 'cash' ? $amount : 0,
            'transfer_amount' => $isConfirmed && $method === 'transfer' ? $amount : 0,
            'total_amount' => $isConfirmed ? $amount : 0,
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
