<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Services\PaymentReportTokenService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentReportController extends Controller
{
    /**
     * POST /api/v1/payment-reports/generate-link
     * Director generates a payment report link for a student class.
     */
    public function generateLink(Request $request, PaymentReportTokenService $tokenService)
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer',
        ]);

        $sc = StudentClass::with('student', 'subjectRecord')->findOrFail($data['student_class_id']);

        $branchId = $sc->student->CampusID ?? 0;
        $result = $tokenService->generate($sc->ID, $branchId);

        $baseUrl = rtrim(config('app.url', $request->getSchemeAndHttpHost()), '/');
        $link = $baseUrl . '/pay-report?token=' . urlencode($result['token']);

        $subjectName = $sc->subjectRecord->Subject_Name ?? '課程';
        $charge = $sc->Charge ?? 0;

        $periodStart = null;
        $periodEnd = null;
        if ($sc->ScheduleMode === 'date') {
            $periodStart = Carbon::today()->startOfMonth()->toDateString();
            $periodEnd = Carbon::today()->endOfMonth()->toDateString();
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

        $sc = StudentClass::with('student', 'subjectRecord')->find($payload['scid']);
        if (!$sc || !$sc->student) {
            return response()->json(['message' => '課程資料不存在'], 404);
        }

        $subjectName = $sc->subjectRecord->Subject_Name ?? '課程';

        $periodStart = null;
        $periodEnd = null;
        if ($sc->ScheduleMode === 'date') {
            $periodStart = Carbon::today()->startOfMonth()->format('Y/m/d');
            $periodEnd = Carbon::today()->endOfMonth()->format('Y/m/d');
        } else {
            $periodStart = $sc->StartDate ? Carbon::parse($sc->StartDate)->format('Y/m/d') : null;
            $periodEnd = $sc->EndDate ? Carbon::parse($sc->EndDate)->format('Y/m/d') : null;
        }

        return response()->json([
            'student_name'       => $sc->student->name,
            'subject'            => $subjectName,
            'session_count'      => $sc->SessionCount,
            'remaining_sessions' => $sc->RemainingSessions,
            'charge'             => $sc->Charge ?? 0,
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
            'reported_amount' => 'required|numeric|min:1|max:999999',
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

        $report = PaymentReport::create([
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

        Log::info('PaymentReport submitted', [
            'report_id'       => $report->id,
            'student_class_id' => $sc->ID,
            'amount'          => $data['reported_amount'],
            'method'          => $data['payment_method'],
        ]);

        return response()->json([
            'message' => '繳費回報已送出，請等待會計確認',
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

        $query = PaymentReport::with(['student', 'studentClass.subjectRecord', 'confirmedByUser']);

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->whereHas('student', fn ($q) => $q->where('CampusID', $bid));
        } elseif (!empty($campusIds)) {
            $query->whereHas('student', fn ($q) => $q->whereIn('CampusID', $campusIds));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $reports = $query->orderByRaw("FIELD(status, 'pending', 'confirmed', 'rejected')")
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        $reports->getCollection()->transform(function ($r) {
            $subjectName = $r->studentClass?->subjectRecord?->Subject_Name ?? '課程';
            return [
                'id'               => $r->id,
                'student_name'     => $r->student?->name ?? $r->reported_by_name,
                'student_id'       => $r->StudentID,
                'student_class_id' => $r->StudentClassID,
                'subject'          => $subjectName,
                'payment_date'     => $r->payment_date?->format('Y-m-d'),
                'payment_method'   => $r->payment_method,
                'reported_amount'  => (float) $r->reported_amount,
                'account_last5'    => $r->account_last5,
                'status'           => $r->status,
                'confirmed_by_name' => $r->confirmedByUser?->Name ?? null,
                'confirmed_at'     => $r->confirmed_at?->toIso8601String(),
                'rejection_note'   => $r->rejection_note,
                'charge'           => $r->studentClass?->Charge ?? 0,
                'created_at'       => $r->created_at?->toIso8601String(),
            ];
        });

        return response()->json($reports);
    }

    /**
     * PUT /api/v1/payment-reports/{id}/confirm
     * Director confirms a payment report → creates Payment, updates Invoice.
     */
    public function confirm(Request $request, $id)
    {
        $report = PaymentReport::findOrFail($id);

        if ($report->status !== 'pending') {
            return response()->json(['message' => '此回報已處理過'], 422);
        }

        $userId = $request->attributes->get('auth_user_id');
        $note = $request->input('note', '');

        return DB::transaction(function () use ($report, $userId, $note) {
            $sc = StudentClass::find($report->StudentClassID);

            $invoice = Invoice::where('StudentClassID', $report->StudentClassID)
                ->where('Status', '!=', 'paid')
                ->first();

            if (!$invoice) {
                $invoice = Invoice::create([
                    'StudentID'      => $report->StudentID,
                    'StudentClassID' => $report->StudentClassID,
                    'IssueDate'      => Carbon::today()->toDateString(),
                    'DueDate'        => null,
                    'TotalAmount'    => (int) $report->reported_amount,
                    'PaidAmount'     => 0,
                    'Status'         => 'unpaid',
                    'Note'           => '',
                ]);
            }

            $payment = Payment::create([
                'InvoiceID'         => $invoice->id,
                'Amount'            => (int) $report->reported_amount,
                'PaidAt'            => $report->payment_date,
                'Method'            => $report->payment_method === 'transfer' ? 'transfer' : 'cash',
                'Note'              => $note ?: "繳費回報核帳確認 (report #{$report->id})",
                'payment_report_id' => $report->id,
            ]);

            $newPaid = $invoice->PaidAmount + (int) $report->reported_amount;
            $status = $newPaid >= $invoice->TotalAmount ? 'paid' : 'partial';
            $invoice->update([
                'PaidAmount'     => $newPaid,
                'Status'         => $status,
                'reconciled_at'  => Carbon::now(),
                'reconciled_by'  => $userId,
            ]);

            if ($sc && !$sc->Paid) {
                $sc->update(['Paid' => 1, 'PayDate' => Carbon::today()->toDateString()]);
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
     * Director directly records a confirmed payment (no parent form needed).
     */
    public function directorRecord(Request $request)
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer',
            'payment_date'     => 'required|date|before_or_equal:today',
            'payment_method'   => 'required|in:transfer,cash',
            'amount'           => 'required|numeric|min:1|max:999999',
            'account_last5'    => 'nullable|string|max:5|regex:/^[0-9]*$/',
            'note'             => 'nullable|string|max:500',
        ]);

        $sc = StudentClass::with('student')->findOrFail($data['student_class_id']);
        if (!$sc->student) {
            return response()->json(['message' => '課程資料不存在'], 404);
        }

        $userId = $request->attributes->get('auth_user_id');

        return DB::transaction(function () use ($data, $sc, $userId) {
            $invoice = Invoice::where('StudentClassID', $sc->ID)
                ->where('Status', '!=', 'paid')
                ->first();

            if (!$invoice) {
                $invoice = Invoice::create([
                    'StudentID'      => $sc->StudentID,
                    'StudentClassID' => $sc->ID,
                    'IssueDate'      => Carbon::today()->toDateString(),
                    'TotalAmount'    => (int) $data['amount'],
                    'PaidAmount'     => 0,
                    'Status'         => 'unpaid',
                    'Note'           => '',
                ]);
            }

            $payment = Payment::create([
                'InvoiceID' => $invoice->id,
                'Amount'    => (int) $data['amount'],
                'PaidAt'    => $data['payment_date'],
                'Method'    => $data['payment_method'],
                'Note'      => $data['note'] ?? '主任核帳登記',
            ]);

            $newPaid = $invoice->PaidAmount + (int) $data['amount'];
            $status = $newPaid >= $invoice->TotalAmount ? 'paid' : 'partial';
            $invoice->update([
                'PaidAmount'     => $newPaid,
                'Status'         => $status,
                'reconciled_at'  => Carbon::now(),
                'reconciled_by'  => $userId,
            ]);

            if ($sc && !$sc->Paid) {
                $sc->update(['Paid' => 1, 'PayDate' => Carbon::today()->toDateString()]);
            }

            $report = PaymentReport::create([
                'StudentID'         => $sc->StudentID,
                'StudentClassID'    => $sc->ID,
                'InvoiceID'         => $invoice->id,
                'reported_by_name'  => $sc->student->name,
                'payment_date'      => $data['payment_date'],
                'payment_method'    => $data['payment_method'],
                'reported_amount'   => $data['amount'],
                'account_last5'     => $data['account_last5'] ?? null,
                'status'            => 'confirmed',
                'confirmed_by'      => $userId,
                'confirmed_at'      => Carbon::now(),
                'payment_id'        => $payment->id,
                'report_token_hash' => hash('sha256', 'director-' . $sc->ID . '-' . now()->timestamp),
                'token_expires_at'  => Carbon::now(),
            ]);

            Log::info('PaymentReport director-record', [
                'report_id'  => $report->id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'user_id'    => $userId,
            ]);

            return response()->json([
                'message'    => '已核帳登記',
                'report_id'  => $report->id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
            ]);
        });
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

        return DB::transaction(function () use ($report, $userId, $data) {
            $originalAmount = (int) abs($report->reported_amount);

            $invoice = $report->InvoiceID ? Invoice::find($report->InvoiceID) : null;

            if ($invoice) {
                Payment::create([
                    'InvoiceID'         => $invoice->id,
                    'Amount'            => -$originalAmount,
                    'PaidAt'            => Carbon::today()->toDateString(),
                    'Method'            => 'void',
                    'Note'              => $data['void_reason'],
                    'payment_report_id' => $report->id,
                ]);

                $newPaid = max(0, (int) $invoice->PaidAmount - $originalAmount);
                $invoiceStatus = $newPaid <= 0 ? 'unpaid' : ($newPaid < (int) $invoice->TotalAmount ? 'partial' : 'paid');
                $invoice->update([
                    'PaidAmount' => $newPaid,
                    'Status'     => $invoiceStatus,
                ]);
            }

            $sc = StudentClass::find($report->StudentClassID);
            $scPaid = 0;
            if ($sc) {
                $invoiceStatus = $invoice->Status ?? 'unpaid';
                if ($invoiceStatus === 'unpaid') {
                    $sc->update(['Paid' => 0, 'PayDate' => null]);
                    $scPaid = 0;
                } else {
                    $scPaid = (int) $sc->Paid;
                }
            }

            $report->update([
                'status'      => 'voided',
                'voided_by'   => $userId,
                'voided_at'   => Carbon::now(),
                'void_reason' => $data['void_reason'],
            ]);

            Log::info('[PaymentVoid]', [
                'report_id'        => $report->id,
                'payment_id'       => $report->payment_id,
                'user_id'          => $userId,
                'void_reason'      => $data['void_reason'],
                'student_class_id' => $report->StudentClassID,
                'amount'           => $originalAmount,
            ]);

            return response()->json([
                'message'            => '已撤銷收款',
                'report_id'          => $report->id,
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
        $report = PaymentReport::with(['student', 'studentClass.subjectRecord', 'confirmedByUser'])
            ->findOrFail($id);

        if ($report->status !== 'confirmed') {
            return response()->json(['message' => '尚未核帳確認，無法產生收據'], 422);
        }

        $sc = $report->studentClass;
        $subjectName = $sc?->subjectRecord?->Subject_Name ?? '課程';

        $periodStart = null;
        $periodEnd = null;
        $attendedDates = [];
        if ($sc) {
            if ($sc->ScheduleMode === 'date') {
                $periodStart = $report->payment_date ? Carbon::parse($report->payment_date)->startOfMonth()->format('Y/m/d') : null;
                $periodEnd = $report->payment_date ? Carbon::parse($report->payment_date)->endOfMonth()->format('Y/m/d') : null;
            } else {
                $periodStart = $sc->StartDate ? Carbon::parse($sc->StartDate)->format('Y/m/d') : null;
                $periodEnd = $sc->EndDate ? Carbon::parse($sc->EndDate)->format('Y/m/d') : null;
            }

            $sessionQuery = \App\Models\ClassSession::where('StudentClassID', $sc->ID)
                ->whereIn('Status', ['attended', 'completed', 'late'])
                ->orderBy('SessionDate');

            if ($sc->ScheduleMode === 'date' && $report->payment_date) {
                $monthStart = Carbon::parse($report->payment_date)->startOfMonth()->toDateString();
                $monthEnd   = Carbon::parse($report->payment_date)->endOfMonth()->toDateString();
                $sessionQuery->whereDate('SessionDate', '>=', $monthStart)
                             ->whereDate('SessionDate', '<=', $monthEnd);
            }

            $attendedDates = $sessionQuery->pluck('SessionDate')
                ->map(fn ($d) => Carbon::parse((string) $d)->format('Y/m/d'))
                ->values()
                ->all();
        }

        $campusName = '';
        if ($report->student) {
            $campus = \App\Models\Campus::find($report->student->CampusID);
            $campusName = $campus->name ?? '';
        }

        return response()->json([
            'receipt_no'       => 'R-' . str_pad($report->id, 6, '0', STR_PAD_LEFT),
            'student_name'     => $report->student?->name ?? $report->reported_by_name,
            'campus_name'      => $campusName,
            'subject'          => $subjectName,
            'session_count'    => $sc?->SessionCount,
            'period_start'     => $periodStart,
            'period_end'       => $periodEnd,
            'attended_dates'   => $attendedDates,
            'payment_date'     => $report->payment_date?->format('Y/m/d'),
            'payment_method'   => $report->payment_method,
            'amount'           => (float) $report->reported_amount,
            'confirmed_at'     => $report->confirmed_at?->format('Y/m/d'),
            'confirmed_by'     => $report->confirmedByUser?->Name ?? '系統',
            'class_type'       => $sc?->ClassType,
            'schedule_mode'    => $sc?->ScheduleMode,
        ]);
    }
}
