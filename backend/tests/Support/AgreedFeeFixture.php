<?php

namespace Tests\Support;

use App\Models\{AuthToken, Invoice, InvoiceItem, Payment, PaymentReport, Student, StudentClass, User, UserCampus};
use Illuminate\Support\Str;

trait AgreedFeeFixture
{
    private function fixture(): array
    {
        $user = User::create(['LoginName' => 'fee-'.Str::uuid(), 'Name' => 'Fixture', 'PSW' => 'test', 'type' => 'A', 'phone' => '']);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        $token = (string) Str::uuid();
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => 'Synthetic fee fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-10-01', 'EndDate' => '2026-12-31', 'TotalHours' => 16,
            'Charge' => 8000, 'Rate' => 1000, 'Paid' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 8, 'RemainingSessions' => 5, 'UsedSessions' => 3, 'SessionDuration' => 120, 'ClassType' => 'trial']);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01',
            'DueDate' => '2026-10-20', 'TotalAmount' => 7600, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => 'Original retained note', 'ScheduleModeAtIssue' => 'count']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => 'Original retained line', 'Amount' => 7600]);
        // Keep synthetic API fixtures inside their own test-only scope.
        // Production config defaults remain empty env-derived arrays (fail closed).
        $courseIds = array_map('intval', (array) config('billing.agreed_fee_correction_course_ids', []));
        $courseIds[] = (int) $course->ID;
        config(['billing.agreed_fee_correction_campus_ids' => [1], 'billing.agreed_fee_correction_course_ids' => array_values(array_unique($courseIds))]);
        return compact('user', 'token', 'student', 'course', 'invoice');
    }

    private function feePayload(Invoice $invoice): array
    {
        return ['scope' => 'billed', 'original_invoice_id' => $invoice->id, 'new_charge' => 500, 'reason' => 'Synthetic agreed fee correction', 'idempotency_key' => (string) Str::uuid()];
    }

    private function reportFor(array $f, string $status = 'pending'): PaymentReport
    {
        return PaymentReport::create(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID, 'InvoiceID' => $f['invoice']->id,
            'reported_by_name' => 'Fixture', 'payment_date' => '2026-10-01', 'payment_method' => 'cash', 'reported_amount' => 7600,
            'status' => $status, 'report_token_hash' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->addDay()]);
    }

    private function receiptFor(array $f): PaymentReport
    {
        $report = $this->reportFor($f, 'confirmed');
        $payment = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => 7600, 'PaidAt' => '2026-10-01', 'Method' => 'cash', 'payment_report_id' => $report->id]);
        $report->update(['payment_id' => $payment->id]);
        $f['invoice']->update(['PaidAmount' => 7600, 'Status' => 'paid']);
        return $report;
    }
}
