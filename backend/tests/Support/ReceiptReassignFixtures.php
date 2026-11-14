<?php

namespace Tests\Support;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * #5113 (in-app #522): fixtures shared by the receipt-move tests (preview and apply). The using class defines AMOUNT.
 */
trait ReceiptReassignFixtures
{
    /** @return array<string, mixed> receipt confirmed on the wrong student's paid bill; the right student has an open bill */
    private function fixture(): array
    {
        $wrong = $this->student(1);
        $right = $this->student(1);
        $w = $this->bill($wrong, self::AMOUNT, 'paid', self::AMOUNT);
        $r = $this->bill($right, self::AMOUNT);
        $report = PaymentReport::create([
            'StudentID' => $wrong->id, 'StudentClassID' => $w['course']->ID, 'InvoiceID' => $w['invoice']->id, 'reported_by_name' => $wrong->name,
            'payment_date' => '2026-11-10', 'payment_method' => 'transfer', 'reported_amount' => self::AMOUNT, 'status' => 'confirmed',
            'confirmed_by' => 1, 'confirmed_at' => Carbon::now(), 'payment_id' => $w['payment']->id,
            'report_token_hash' => hash('sha256', uniqid('r', true)), 'token_expires_at' => Carbon::now()->addDay(),
        ]);

        return ['wrong' => $wrong, 'right' => $right, 'wrongCourse' => $w['course'], 'rightCourse' => $r['course'],
            'wrongInvoice' => $w['invoice'], 'rightInvoice' => $r['invoice'], 'report' => $report];
    }

    /** @return array{course:StudentClass, invoice:Invoice, payment:?Payment} */
    private function bill(Student $student, int $total, string $status = 'unpaid', int $paid = 0, array $courseOverrides = [], array $invoiceOverrides = []): array
    {
        $course = StudentClass::create($courseOverrides + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'TotalHours' => 20, 'Charge' => $total, 'Paid' => $paid > 0 ? 1 : 0, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 5, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        // Overrides go on the left: array union keeps the left-hand keys.
        $invoice = Invoice::create($invoiceOverrides + ['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-11-01',
            'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $status === 'void' ? 'void' : ($paid >= $total && $paid > 0 ? 'paid' : 'unpaid')]);
        $payment = $paid > 0 ? Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => '2026-11-10', 'Method' => 'transfer']) : null;

        return ['course' => $course, 'invoice' => $invoice, 'payment' => $payment];
    }

    private function payment(Invoice $invoice, int $amount, string $method = 'transfer'): Payment
    {
        return Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $amount, 'PaidAt' => '2026-11-11', 'Method' => $method]);
    }

    private function pendingReport(Student $student, StudentClass $course, Invoice $invoice): PaymentReport
    {
        return PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'InvoiceID' => $invoice->id, 'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(), 'payment_method' => 'cash', 'reported_amount' => 100, 'status' => 'pending',
            'report_token_hash' => hash('sha256', uniqid('p', true)), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
    }

    private function student(int $campusId): Student
    {
        return Student::create(['name' => '測試學生' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
    }

    /** @param array<string, mixed> $f */
    private function preview(string $token, array $f)
    {
        return $this->previewTo($token, $f['report'], $f['rightInvoice']);
    }

    private function previewTo(string $token, PaymentReport $report, Invoice $target)
    {
        return $this->postJson("/api/v1/payment-reports/{$report->id}/reassign", ['target_invoice_id' => $target->id, 'preview' => true, 'reason' => '選錯學生'], $this->h($token));
    }

    /** @return array<string, string> */
    private function h(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    /** A = director (Admin=1), T = teacher, S = super_admin. @param int|list<int> $campus */
    private function token(string $type, int|array $campus): string
    {
        $user = User::create(['LoginName' => 'rra-' . strtolower($type) . '-' . uniqid() . '@test.com', 'Name' => '收據移動測試帳號', 'PSW' => 'secret', 'type' => $type, 'phone' => '0912345678']);
        foreach ((array) $campus as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    /** POST the move itself (no preview) with a state_hash. */
    private function apply(string $token, PaymentReport $report, Invoice $target, string $hash, string $reason = '選錯學生')
    {
        return $this->postJson("/api/v1/payment-reports/{$report->id}/reassign", ['target_invoice_id' => $target->id, 'state_hash' => $hash, 'reason' => $reason], $this->h($token));
    }

    /** @param array{course:StudentClass, invoice:Invoice, payment:?Payment} $bill a confirmed receipt for the bill's own payment */
    private function confirmedReport(array $bill): PaymentReport
    {
        return PaymentReport::create([
            'StudentID' => $bill['invoice']->StudentID, 'StudentClassID' => $bill['course']->ID, 'InvoiceID' => $bill['invoice']->id, 'reported_by_name' => 'x',
            'payment_date' => '2026-11-10', 'payment_method' => 'transfer', 'reported_amount' => (int) $bill['payment']->Amount, 'status' => 'confirmed',
            'confirmed_by' => 1, 'confirmed_at' => Carbon::now(), 'payment_id' => $bill['payment']->id,
            'report_token_hash' => hash('sha256', uniqid('r', true)), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
    }

    /** An unpaid per-session count package (8 × 1000) of $owner in campus 1. */
    private function package(Student $owner): CoursePackage
    {
        return CoursePackage::create(['student_id' => $owner->id, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => 'count', 'total_sessions' => 8, 'remaining_sessions' => 8,
            'used_sessions' => 0, 'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true]);
    }

    /** @return array<string, mixed> every table this feature could ever write to */
    private function snapshot(): array
    {
        return [
            'payments' => DB::table('Payment')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'reports' => DB::table('payment_reports')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'invoices' => DB::table('Invoice')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'courses' => DB::table('StudentClass')->orderBy('ID')->get()->map(fn ($r) => (array) $r)->all(),
            'links' => DB::table('payment_report_reassignments')->count(),
            'audit' => DB::table('security_audit_events')->count(),
        ];
    }
}
