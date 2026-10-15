<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\BugReportContractLink;
use App\Models\Invoice;
use App\Models\StudentClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * #4100 (Founder option B): read-only billing IDs + numbers (no names, notes or free text) of ONE contract for a
 * triage token, to check G-009 / G-011 money state behind an open report. Needs ALL of: the switch on (default
 * off), a live super-admin link of exactly this (report, contract), an open report, and the same campus. Anything
 * else is the same 404; re-checked on every request; AuthenticateTriageToken audits every call.
 */
class TriageBillingSnapshotController extends Controller
{
    public function show(Request $request, int $id)
    {
        if (!(bool) config('triage_tokens.billing_snapshot_enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $bugId = filter_var($request->query('bug_report_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($bugId === false) {
            return response()->json(['message' => 'Invalid query: bug_report_id must be a positive integer'], 422);
        }

        $linked = BugReportContractLink::live()
            ->where('bug_report_id', $bugId)->where('student_class_id', $id)->exists();
        $bug = $linked ? BugReport::query()->whereKey($bugId)
            ->whereIn('status', BugReportContractLink::OPEN_REPORT_STATUSES)->first() : null;
        $campusId = $bug instanceof BugReport ? BugReportContractLink::contractCampusId($id) : null;
        $course = $bug instanceof BugReport && $campusId !== null && $campusId === (int) $bug->getAttribute('CampusID')
            ? StudentClass::query()->whereKey($id)->first() : null;
        if (!$course instanceof StudentClass) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->snapshot($course, $bugId));
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
            ->whereIn('InvoiceID', $invoices->pluck('id')->all())->orderBy('id')->get(['id', 'InvoiceID', 'Amount']);

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
                'id' => (int) $invoice->id, 'status' => (string) $invoice->Status, 'total' => (int) $invoice->TotalAmount,
                'paid' => (int) $invoice->PaidAmount, 'period' => $invoice->billing_period, 'issue_date' => substr((string) $invoice->IssueDate, 0, 10),
            ])->values()->all(),
            'payments' => $payments->map(fn (object $payment) => ['id' => (int) $payment->id, 'invoice_id' => (int) $payment->InvoiceID, 'amount' => (int) $payment->Amount])->values()->all(),
        ];
    }
}
