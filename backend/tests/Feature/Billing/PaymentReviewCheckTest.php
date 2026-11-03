<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BillingPayableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReviewCheckTest extends TestCase
{
    use RefreshDatabase;

    private function token(int $campusId = 1): string
    {
        $user = User::create(['LoginName' => 'review-check-' . uniqid() . '@example.com', 'Name' => 'Director fixture', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0900000000', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function course(bool $september = false): StudentClass
    {
        $student = Student::create(['name' => 'Fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1, 'by1' => 2, 'Period' => 4,
            'StartDate' => '2026-08-01', 'EndDate' => $september ? '2026-09-30' : '2026-08-31', 'TotalHours' => 16, 'Charge' => 4000, 'Rate' => 1000,
            'Paid' => 1, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'settlement_day' => 20]);
        foreach ($september ? ['2026-08-15', '2026-09-02'] : ['2026-08-15'] as $date) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }

        return $course;
    }

    private function invoice(StudentClass $course, string $period, int $total, int $paid): Invoice
    {
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => $period . '-01',
            'billing_period' => $period, 'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid')]);
        if ($paid > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => $period . '-20', 'Method' => 'cash']);
        }

        return $invoice;
    }

    private function courseState(StudentClass $course): array
    {
        return app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$course->ID], [$course->fresh()])[$course->ID];
    }

    private function check(string $token, StudentClass $course)
    {
        return $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/payment-review/check");
    }

    /** A paid month whose bill (1500) differs from the lessons (1000): reviewable, nothing owed. */
    private function discrepantPaid(): StudentClass
    {
        $course = $this->course();
        $this->invoice($course, '2026-08', 1500, 1500);

        return $course;
    }

    public function test_check_settles_review_and_audits_then_new_invoice_reopens_it(): void
    {
        $course = $this->discrepantPaid();
        $course->update(['closed_reason' => 'settled_pending']);
        $this->assertSame('review_required', $this->courseState($course)['status']);

        $this->check($this->token(), $course)->assertOk()->assertJson(['status' => 'paid', 'review_checked' => true]);
        $this->assertSame('settled', $course->fresh()->closed_reason);
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('security_audit_events')->where('event_type', 'payment_review_checked')->exists());
        $this->assertSame('paid', $this->courseState($course)['status']);

        // Swapping one lesson for another keeps the count but changes the picture, so review returns.
        ClassSession::where('StudentClassID', $course->ID)->update(['SessionDate' => '2026-08-22']);
        $this->assertSame('review_required', $this->courseState($course)['status']);
        ClassSession::where('StudentClassID', $course->ID)->update(['SessionDate' => '2026-08-15']);
        $this->assertSame('paid', $this->courseState($course)['status']);

        $this->invoice($course, '2026-08', 1000, 1000);
        $after = $this->courseState($course);
        $this->assertSame('review_required', $after['status']);
        $this->assertFalse($after['review_checked']);
    }

    public function test_owed_course_is_refused(): void
    {
        $course = $this->course();
        $this->invoice($course, '2026-08', 1500, 0);
        $this->check($this->token(), $course)->assertStatus(409)->assertJson(['code' => 'payment_review_check_owed']);
        $this->assertNull($course->fresh()->review_checked_signature);
    }

    public function test_month_with_lessons_and_no_bill_is_refused(): void
    {
        $course = $this->course(true);
        $this->invoice($course, '2026-08', 1000, 1000);
        $this->assertSame('review_required', $this->courseState($course)['status']);
        $this->check($this->token(), $course)->assertStatus(409)->assertJson(['code' => 'payment_review_check_unbilled']);
    }

    public function test_course_not_in_review_is_refused(): void
    {
        $course = $this->course(true);
        $this->invoice($course, '2026-08', 1000, 1000);
        $this->invoice($course, '2026-09', 1000, 1000);
        $this->check($this->token(), $course)->assertStatus(422)->assertJson(['code' => 'payment_review_not_pending']);
    }

    public function test_other_campus_director_is_refused(): void
    {
        $course = $this->discrepantPaid();
        $this->check($this->token(2), $course)->assertForbidden();
        $this->assertNull($course->fresh()->review_checked_signature);
    }
}
