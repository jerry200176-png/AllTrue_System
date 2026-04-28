<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\PaymentReport;
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
