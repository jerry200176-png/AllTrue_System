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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/student-classes/{id}/invoices — payment detail fields used by the
 * student management "latest payment info" panel (account_last5, note, report_id,
 * receipt_no, status). account_last5 must follow the same PII-minimization
 * precedent as PaymentReportController::index(): director/super_admin only.
 */
class StudentClassInvoicesPaymentDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoices_includes_payment_details_and_account_last5_for_director(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $course = $this->createCountModeClass($student->id);
        [$invoice, $payment, $report] = $this->createConfirmedPayment($student, $course, [
            'account_last5' => '45688',
            'payment_method' => 'transfer',
        ], [
            'Method' => 'transfer',
            'Note' => '匯款備註測試',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/invoices");

        $res->assertOk();
        $paymentRow = $res->json('invoices.0.payments.0');

        $this->assertSame('45688', $paymentRow['account_last5']);
        $this->assertSame('匯款備註測試', $paymentRow['note']);
        $this->assertSame('transfer', $paymentRow['method']);
        $this->assertSame('confirmed', $paymentRow['status']);
        $this->assertSame((int) $report->id, $paymentRow['report_id']);
        $this->assertNotNull($paymentRow['receipt_no']);
        $this->assertTrue($paymentRow['can_view_receipt']);
    }

    public function test_invoices_hides_account_last5_and_receipt_cta_for_teacher_but_keeps_other_fields(): void
    {
        $teacher = $this->createTeacherUser();
        $token = $this->createTokenFor($teacher, [1]);
        $student = $this->createStudent(1);
        $course = $this->createCountModeClass($student->id, ['TeacherID' => $teacher->id]);
        $this->createConfirmedPayment($student, $course, [
            'account_last5' => '45688',
            'payment_method' => 'transfer',
        ], [
            'Method' => 'transfer',
            'Note' => '匯款備註測試',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/invoices");

        $res->assertOk();
        $paymentRow = $res->json('invoices.0.payments.0');

        $this->assertNull($paymentRow['account_last5']);
        $this->assertSame('匯款備註測試', $paymentRow['note']);
        $this->assertSame('confirmed', $paymentRow['status']);
        // can_view_receipt must reflect GET /payment-reports/{id}/receipt's actual
        // role:director-only route gate — a teacher would 403 there, so the field
        // must be false even though the report itself is confirmed.
        $this->assertFalse($paymentRow['can_view_receipt']);
    }

    public function test_invoices_hides_receipt_cta_for_director_when_report_is_not_confirmed(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $course = $this->createCountModeClass($student->id);
        [, , $report] = $this->createConfirmedPayment($student, $course);
        $report->update([
            'status' => 'voided',
            'voided_by' => 1,
            'voided_at' => Carbon::now(),
            'void_reason' => '測試作廢',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/invoices");

        $res->assertOk();
        $paymentRow = $res->json('invoices.0.payments.0');

        $this->assertSame('voided', $paymentRow['status']);
        $this->assertFalse($paymentRow['can_view_receipt'], 'voided report must not offer a receipt CTA');
    }

    public function test_invoices_forbidden_for_teacher_not_owning_course(): void
    {
        $teacher = $this->createTeacherUser();
        $token = $this->createTokenFor($teacher, [1]);
        $student = $this->createStudent(1);
        $course = $this->createCountModeClass($student->id, ['TeacherID' => 999999]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/invoices");

        $res->assertStatus(403);
    }

    public function test_invoices_works_for_count_mode_course_not_only_monthly(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $course = $this->createCountModeClass($student->id);
        $this->createConfirmedPayment($student, $course);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/invoices");

        $res->assertOk();
        $this->assertNotEmpty($res->json('invoices'));
        $this->assertNotEmpty($res->json('invoices.0.payments'));
    }

    // ── helpers ──

    /** @return array{0: Invoice, 1: Payment, 2: PaymentReport} */
    private function createConfirmedPayment(
        Student $student,
        StudentClass $course,
        array $reportOverrides = [],
        array $paymentOverrides = []
    ): array {
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-10',
            'TotalAmount' => 5000,
            'PaidAmount' => 5000,
            'Status' => 'paid',
            'Note' => '',
        ]);

        $payment = Payment::create(array_merge([
            'InvoiceID' => $invoice->id,
            'Amount' => 5000,
            'PaidAt' => '2026-04-10',
            'Method' => 'cash',
            'Note' => '主任核帳登記',
        ], $paymentOverrides));

        $report = PaymentReport::create(array_merge([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'InvoiceID' => $invoice->id,
            'reported_by_name' => $student->name,
            'payment_date' => '2026-04-10',
            'payment_method' => 'cash',
            'reported_amount' => 5000,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::parse('2026-04-10 12:00:00'),
            'confirmed_by' => 1,
            'payment_id' => $payment->id,
            'report_token_hash' => hash('sha256', 'invoices-detail-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ], $reportOverrides));

        $payment->update(['payment_report_id' => $report->id]);

        return [$invoice, $payment, $report];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-invdetail-' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        return $this->createTokenFor($user, $campusIds, true);
    }

    private function createTeacherUser(): User
    {
        return User::create([
            'LoginName' => 'teach-invdetail-' . uniqid() . '@test.com',
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0912345679',
        ]);
    }

    private function createTokenFor(User $user, array $campusIds, bool $asAdmin = false): string
    {
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => $asAdmin ? 1 : 0, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createStudent(int $campusId): Student
    {
        return Student::create([
            'name' => '繳費資訊測試生-' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
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
            'TotalHours' => 20,
            'Charge' => 5000,
            'Paid' => 1,
            'PayDate' => '2026-04-10',
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}
