<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\BugReportContractLink;
use App\Models\Invoice;
use App\Models\StudentClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** #4100 (option B): IDs + numbers (no names, notes, free text) of ONE contract for a triage token. Needs ALL of: switch on
 * (default off), a live super-admin link of exactly this (report, contract), an open report, the same campus; else the same 404. */
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

        // Anchored or itemized here. Not wholly this student's (BillingNoteController::invoiceStudent() rule): left out, count only. Also covers another of their contracts: no money.
        $invoices = Invoice::query()->where('StudentClassID', $courseId)
            ->orWhereIn('id', DB::table('InvoiceItem')->where('StudentClassID', $courseId)->select('InvoiceID'))
            ->orderBy('id')->get(['id', 'StudentID', 'StudentClassID', 'Status', 'TotalAmount', 'PaidAmount', 'billing_period', 'IssueDate']);
        $items = DB::table('InvoiceItem')->whereIn('InvoiceID', $invoices->pluck('id')->all())->whereNotNull('StudentClassID')->get(['InvoiceID', 'StudentClassID']);
        $refs = fn (Invoice $i) => $items->where('InvoiceID', $i->id)->pluck('StudentClassID')->push($i->StudentClassID)->map(fn ($c) => (int) $c)->filter()->unique();
        $owners = StudentClass::query()->whereIn('ID', $invoices->flatMap(fn (Invoice $i) => $refs($i))->unique()->all())->pluck('StudentID', 'ID');
        [$own, $foreign] = $invoices->partition(fn (Invoice $i) => (int) $i->StudentID === (int) $course->StudentID && $refs($i)->every(fn (int $c) => (int) ($owners[$c] ?? 0) === (int) $course->StudentID));
        $shared = $own->filter(fn (Invoice $i) => $refs($i)->contains(fn (int $c) => $c !== $courseId))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $payments = DB::table('Payment')->whereIn('InvoiceID', $own->pluck('id')->diff($shared)->all())->orderBy('id')->get(['id', 'InvoiceID', 'Amount', 'Method']);

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
            'invoices' => $own->map(fn (Invoice $invoice) => [
                'id' => (int) $invoice->id, 'status' => (string) $invoice->Status, 'shared' => in_array((int) $invoice->id, $shared, true),
                'total' => in_array((int) $invoice->id, $shared, true) ? null : (int) $invoice->TotalAmount,
                'paid' => in_array((int) $invoice->id, $shared, true) ? null : (int) $invoice->PaidAmount,
                'period' => $invoice->billing_period, 'issue_date' => substr((string) $invoice->IssueDate, 0, 10),
            ])->values()->all(),
            'excluded_foreign_invoices' => $foreign->count(),
            // Signed like Invoice::netPaidSql(): a reversal (negative, or Method 'void') is negative; the raw method is not exposed.
            'payments' => $payments->map(fn (object $p) => ['id' => (int) $p->id, 'invoice_id' => (int) $p->InvoiceID,
                'amount' => (int) $p->Amount < 0 || $p->Method === 'void' ? -abs((int) $p->Amount) : (int) $p->Amount])->values()->all(),
        ];
    }
}
