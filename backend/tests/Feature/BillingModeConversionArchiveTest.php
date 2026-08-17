<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BillingModeConversionArchiveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #934: converting count ↔ date must archive unpaid/pending artifacts
 * and stamp legacy invoice snapshots, without reversing confirmed money.
 */
class BillingModeConversionArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversion_voids_unpaid_invoice_and_pending_report_only(): void
    {
        $student = $this->createStudent();
        $sc = $this->createCountModeClass($student->id, ['Charge' => 8000, 'Paid' => 1]);

        $unpaid = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => Carbon::today()->toDateString(),
            'TotalAmount' => 8000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'ScheduleModeAtIssue' => 'count',
        ]);

        $paid = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => Carbon::today()->toDateString(),
            'TotalAmount' => 4000,
            'PaidAmount' => 4000,
            'Status' => 'paid',
            'ScheduleModeAtIssue' => 'count',
        ]);
        Payment::create([
            'InvoiceID' => $paid->id,
            'Amount' => 4000,
            'PaidAt' => Carbon::today()->toDateString(),
            'Method' => 'cash',
            'Note' => 'confirmed',
        ]);

        $pending = $this->createReport($student, $sc, [
            'InvoiceID' => $unpaid->id,
            'status' => 'pending',
            'reported_amount' => 8000,
        ]);
        $confirmed = $this->createReport($student, $sc, [
            'InvoiceID' => $paid->id,
            'status' => 'confirmed',
            'reported_amount' => 4000,
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => 1,
        ]);

        $summary = app(BillingModeConversionArchiveService::class)
            ->archiveAfterScheduleModeChange($sc, 'count', 'date', 9);

        $this->assertSame(1, $summary['voided_unpaid_invoices']);
        $this->assertSame(1, $summary['voided_pending_reports']);
        $this->assertSame(1, $summary['skipped_paid_invoices']);
        $this->assertSame(1, $summary['skipped_confirmed_reports']);

        $this->assertSame('void', $unpaid->fresh()->Status);
        $this->assertStringContainsString('course_billing_mode_converted', (string) $unpaid->fresh()->Note);
        $this->assertSame('paid', $paid->fresh()->Status);
        $this->assertSame(4000, (int) $paid->fresh()->PaidAmount);
        $this->assertSame('voided', $pending->fresh()->status);
        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame(1, (int) $sc->fresh()->Paid);
        $this->assertSame(1, Payment::where('InvoiceID', $paid->id)->count());
        $this->assertSame(0, Payment::where('Amount', '<', 0)->count());
    }

    public function test_conversion_stamps_legacy_null_snapshot_so_receipt_can_flag(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $sc = $this->createCountModeClass($student->id, ['Charge' => 5000, 'Paid' => 1]);

        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => Carbon::today()->toDateString(),
            'TotalAmount' => 5000,
            'PaidAmount' => 5000,
            'Status' => 'paid',
            'ScheduleModeAtIssue' => null,
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 5000,
            'PaidAt' => Carbon::today()->toDateString(),
            'Method' => 'transfer',
            'Note' => 'legacy',
        ]);
        $report = $this->createReport($student, $sc, [
            'InvoiceID' => $invoice->id,
            'status' => 'confirmed',
            'reported_amount' => 5000,
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => 1,
        ]);

        $summary = app(BillingModeConversionArchiveService::class)
            ->archiveAfterScheduleModeChange($sc, 'count', 'date', 1);
        $this->assertSame(1, $summary['stamped_invoices']);
        $this->assertSame(0, $summary['voided_unpaid_invoices']);

        $sc->update(['ScheduleMode' => 'date']);
        $invoice->refresh();
        $this->assertSame('count', $invoice->ScheduleModeAtIssue);
        $this->assertTrue($invoice->fresh()->load('studentClass')->billingModeChangedSinceIssue());

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/payment-reports/{$report->id}/receipt");

        $res->assertOk();
        $this->assertTrue($res->json('billing_mode_changed'));
        $this->assertSame(5000, (int) $res->json('amount'));
    }

    public function test_same_mode_is_a_noop(): void
    {
        $student = $this->createStudent();
        $sc = $this->createCountModeClass($student->id);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => Carbon::today()->toDateString(),
            'TotalAmount' => 1000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);

        $summary = app(BillingModeConversionArchiveService::class)
            ->archiveAfterScheduleModeChange($sc, 'count', 'count', 1);

        $this->assertSame(0, $summary['voided_unpaid_invoices']);
        $this->assertSame('unpaid', $invoice->fresh()->Status);
    }

    public function test_course_update_payment_type_runs_archive(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $sc = $this->createCountModeClass($student->id, ['Charge' => 8000, 'Paid' => 0]);

        $unpaid = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => Carbon::today()->toDateString(),
            'TotalAmount' => 8000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'ScheduleModeAtIssue' => null,
        ]);
        $pending = $this->createReport($student, $sc, [
            'InvoiceID' => $unpaid->id,
            'status' => 'pending',
            'reported_amount' => 8000,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$sc->ID}", [
            'payment_type' => 'monthly',
        ]);

        $res->assertOk();
        $this->assertSame('date', (string) $sc->fresh()->ScheduleMode);
        $this->assertSame(0, (int) $sc->fresh()->Paid);
        $this->assertSame('void', $unpaid->fresh()->Status);
        $this->assertSame('count', $unpaid->fresh()->ScheduleModeAtIssue);
        $this->assertSame('voided', $pending->fresh()->status);
        $this->assertSame(1, (int) $res->json('billing_mode_conversion.voided_unpaid_invoices'));
        $this->assertSame(1, (int) $res->json('billing_mode_conversion.voided_pending_reports'));
    }

    public function test_partial_invoice_with_applied_payment_is_not_voided(): void
    {
        $student = $this->createStudent();
        $sc = $this->createCountModeClass($student->id);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => Carbon::today()->toDateString(),
            'TotalAmount' => 8000,
            'PaidAmount' => 4000,
            'Status' => 'partial',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 4000,
            'PaidAt' => Carbon::today()->toDateString(),
            'Method' => 'transfer',
            'Note' => 'partial',
        ]);

        $summary = app(BillingModeConversionArchiveService::class)
            ->archiveAfterScheduleModeChange($sc, 'count', 'date', 1);

        $this->assertSame(0, $summary['voided_unpaid_invoices']);
        $this->assertSame(1, $summary['skipped_paid_invoices']);
        $this->assertSame('partial', $invoice->fresh()->Status);
        $this->assertSame(4000, (int) $invoice->fresh()->PaidAmount);
        $this->assertSame('count', $invoice->fresh()->ScheduleModeAtIssue);
    }

    private function createDirectorToken(): string
    {
        $user = User::create([
            'LoginName' => 'dir-934-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0910000934',
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $raw,
            'expires_at' => Carbon::now()->addDay(),
        ]);

        return $raw;
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '轉換封存測試生' . uniqid(),
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'Phone' => '0912345678',
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createCountModeClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => 8000,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => null,
            'LearnTimeID' => null,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 5,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function createReport(Student $student, StudentClass $studentClass, array $overrides = []): PaymentReport
    {
        return PaymentReport::create(array_merge([
            'StudentID' => $student->id,
            'StudentClassID' => $studentClass->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'transfer',
            'reported_amount' => 8000,
            'status' => 'pending',
            'report_token_hash' => hash('sha256', '934-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ], $overrides));
    }
}
