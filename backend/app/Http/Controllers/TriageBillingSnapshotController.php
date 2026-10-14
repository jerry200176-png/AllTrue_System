<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\Invoice;
use App\Models\StudentClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * #4100: read-only billing numbers of ONE contract for a triage token, so an AI can check the
 * G-009 / G-011 money state behind an open bug report without production SSH.
 * IDs and numbers only (no names, notes or free text). The contract must belong to the campus
 * of an OPEN bug report named by bug_report_id; anything else is 404 so IDs are not confirmed.
 * AuthenticateTriageToken audits every call; RestrictAccessTokenScope closes every other route.
 */
class TriageBillingSnapshotController extends Controller
{
    private const OPEN_STATUSES = ['new', 'triaged', 'in_progress'];

    public function show(Request $request, int $id)
    {
        $validator = Validator::make($request->query(), [
            'bug_report_id' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid query', 'errors' => $validator->errors()], 422);
        }

        $bug = BugReport::query()
            ->whereKey((int) $validator->validated()['bug_report_id'])
            ->whereIn('status', self::OPEN_STATUSES)
            ->first();
        $course = StudentClass::query()->whereKey($id)->first();
        $courseCampusId = $course instanceof StudentClass
            ? DB::table('Student')->where('id', (int) $course->StudentID)->value('CampusID')
            : null;
        // Fail closed on a missing Student row or an unset (null/0) campus: never match "no campus" to "no campus".
        if (!$bug instanceof BugReport || $courseCampusId === null || (int) $courseCampusId <= 0
            || (int) $courseCampusId !== (int) $bug->getAttribute('CampusID')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->snapshot($course, (int) $bug->getKey()));
    }

    /** @return array<string, mixed> */
    private function snapshot(StudentClass $course, int $bugReportId): array
    {
        $courseId = (int) $course->getKey();
        $rate = $course->Rate === null ? null : (float) $course->Rate;
        $rateUnit = (string) $course->rate_unit ?: 'session';
        $quantity = $rateUnit === 'hour' ? (int) $course->TotalHours : (int) ($course->SessionCount ?? 0);
        // Same base as StudentClassController::update() (G-009 preservedDelta = Charge - base).
        $base = (int) round((float) ($rate ?? 0) * $quantity);
        $charge = $course->Charge === null ? null : (int) $course->Charge;

        $invoices = Invoice::query()->where('StudentClassID', $courseId)->orderBy('id')
            ->get(['id', 'Status', 'TotalAmount', 'PaidAmount', 'billing_period', 'IssueDate']);
        $payments = $invoices->isEmpty() ? collect() : DB::table('Payment')
            ->whereIn('InvoiceID', $invoices->pluck('id')->all())
            ->orderBy('id')
            ->get(['id', 'InvoiceID', 'Amount']);

        return [
            'student_class_id' => $courseId,
            'bug_report_id' => $bugReportId,
            'rate' => $rate,
            'rate_unit' => $rateUnit,
            'session_count' => $course->SessionCount === null ? null : (int) $course->SessionCount,
            'total_hours' => (int) $course->TotalHours,
            'standard_lesson_minutes' => $course->standard_lesson_minutes === null ? null : (int) $course->standard_lesson_minutes,
            'deduction_basis' => $course->deduction_basis,
            'charge' => $charge,
            'rate_times_count' => $base,
            'charge_minus_base' => $charge === null ? null : $charge - $base,
            'session_charge_rows' => DB::table('ClassSession')
                ->where('StudentClassID', $courseId)->whereNotNull('session_charge')->count(),
            'paid_flag' => (int) $course->Paid,
            'remaining_sessions' => $course->RemainingSessions === null ? null : (int) $course->RemainingSessions,
            'stop' => (int) $course->Stop,
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => (int) $invoice->id,
                'status' => (string) $invoice->Status,
                'total' => (int) $invoice->TotalAmount,
                'paid' => (int) $invoice->PaidAmount,
                'period' => $invoice->billing_period,
                'issue_date' => substr((string) $invoice->IssueDate, 0, 10),
            ])->values()->all(),
            'payments' => $payments->map(fn (object $payment) => [
                'id' => (int) $payment->id,
                'invoice_id' => (int) $payment->InvoiceID,
                'amount' => (int) $payment->Amount,
            ])->values()->all(),
        ];
    }
}
