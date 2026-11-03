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
        $audit = \Illuminate\Support\Facades\DB::table('security_audit_events')->where('event_type', 'payment_review_checked')->first();
        $this->assertNotNull($audit);
        $meta = json_decode((string) $audit->metadata, true);
        $this->assertSame('amount_discrepancy', $meta['reason_code'] ?? null);
        $this->assertSame(64, strlen((string) ($meta['reason_hash'] ?? '')));
        // Course management reads the same checked verdict, not the raw period summary.
        $list = $this->withToken($this->token())->getJson('/api/v1/student-classes?branch_id=1&student_id=' . $course->StudentID)->assertOk()->json();
        $row = collect($list['data'] ?? $list)->firstWhere('ID', $course->ID);
        $this->assertNotNull($row);
        $this->assertFalse($row['monthly_payment']['review_required']);
        $this->assertSame('paid', $row['payment_status']);
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

    /** #4832: a stopped course whose invoice flag is stale (Payment rows cover it) stays in 已結清 after 已核對. */
    public function test_checked_course_with_a_stale_invoice_status_stays_in_settled_history(): void
    {
        $course = $this->course();
        $course->update(['Stop' => 1, 'Paid' => 0, 'closed_reason' => 'settled_pending']);
        $this->invoice($course, '2026-08', 1500, 1500)->update(['Status' => 'partial']);
        $token = $this->token();
        $this->check($token, $course)->assertOk();
        $this->assertSame('settled', $course->fresh()->closed_reason);
        $rows = $this->withToken($token)->getJson('/api/v1/accounting/settled-courses?branch_id=1')->assertOk()->json('data');
        $this->assertContains($course->ID, collect($rows)->pluck('student_class_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_zero_value_bills_cannot_be_checked(): void
    {
        $course = $this->course();
        $course->update(['closed_reason' => 'settled_pending']);
        $this->invoice($course, '2026-08', 0, 0);
        $this->assertSame('review_required', $this->courseState($course)['status']);
        $this->check($this->token(), $course)->assertStatus(409)->assertJson(['code' => 'payment_review_check_not_settleable']);
        $this->assertNull($course->fresh()->review_checked_signature);
        $this->assertSame('settled_pending', $course->fresh()->closed_reason);
    }

    public function test_unbilled_lesson_inside_a_billed_month_is_refused(): void
    {
        // A paid August bill that covers only 1-15; the 22nd is taught and on no bill.
        $course = $this->course();
        $invoice = $this->invoice($course, '2026-08', 1500, 1500);
        $invoice->items()->create(['StudentClassID' => $course->ID, 'Description' => 'a', 'Amount' => 1500, 'PeriodStart' => '2026-08-01', 'PeriodEnd' => '2026-08-15']);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-08-22', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        $this->assertSame('review_required', $this->courseState($course->fresh())['status']);
        $this->check($this->token(), $course)->assertStatus(409)->assertJson(['code' => 'payment_review_check_unbilled']);
        $this->assertNull($course->fresh()->review_checked_signature);
    }

    public function test_reversing_an_excess_payment_reopens_a_checked_review(): void
    {
        $course = $this->course();
        $invoice = $this->invoice($course, '2026-08', 1500, 1500);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 300, 'PaidAt' => '2026-08-21', 'Method' => 'cash']);
        $this->check($this->token(), $course)->assertOk();
        $this->assertSame('paid', $this->courseState($course)['status']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -300, 'PaidAt' => '2026-08-22', 'Method' => 'cash']);
        $this->assertSame('review_required', $this->courseState($course)['status']);
    }

    public function test_check_rolls_back_when_the_audit_row_cannot_be_written(): void
    {
        $course = $this->discrepantPaid();
        $token = $this->token();
        $fail = true;
        // Same synthetic fault as the password-change fail-closed test: the audit insert runs, then throws inside append().
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into `security_audit_events`')) {
                $fail = false;
                throw new \RuntimeException('synthetic audit failure');
            }
        });
        $this->check($token, $course)->assertStatus(500);
        $this->assertNull($course->fresh()->review_checked_signature);
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
