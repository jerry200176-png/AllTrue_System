<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\DunningService;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F7 S5: dunning + tuition:send-reminders skip courses the resolver says are paid/free (flag billing.paid_status_outbound_notifications). */
class OutboundRemindersResolverTest extends TestCase
{
    use RefreshDatabase;

    private int $campusId;

    private function course(int $charge, float $rate = 1500): StudentClass
    {
        $this->campusId = (int) CampusFactory::new()->create(['name' => '分校', 'messaging_channel_token' => 'token'])->id;
        $student = Student::create(['name' => 'Remind', 'CampusID' => $this->campusId, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-08-01', 'TotalHours' => 20, 'Charge' => $charge, 'Paid' => 0, 'Rate' => $rate, 'MDate' => now()->subDays(30),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 5,
            'UsedSessions' => 0, 'ClassType' => 'one_on_one', 'LearnTimeID' => null,
        ]);
    }

    private function invoice(StudentClass $course, int $total, int $paid): void
    {
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-01', 'DueDate' => '2026-08-15',
            'TotalAmount' => $total, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-08']);
        if ($paid > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => '2026-08-05', 'Method' => 'cash']);
        }
    }

    private function dunned(): int
    {
        return count(app(DunningService::class)->evaluateAll($this->campusId, false));
    }

    private function reminded(): bool
    {
        $out = new \Symfony\Component\Console\Output\BufferedOutput();
        \Illuminate\Support\Facades\Artisan::call('tuition:send-reminders', ['--dry-run' => true, '--overdue-days' => 7], $out);

        return str_contains($out->fetch(), 'Found 1 overdue');
    }

    public function test_fully_paid_invoice_with_stale_paid_flag_is_not_reminded(): void
    {
        $this->invoice($this->course(10000), 10000, 10000);
        $this->assertSame(0, $this->dunned());
        $this->assertFalse($this->reminded());

        config(['billing.paid_status_outbound_notifications' => false]);
        $this->assertSame(1, $this->dunned());
        $this->assertTrue($this->reminded());
    }

    public function test_zero_fee_course_is_not_reminded(): void
    {
        $this->course(0, 0);
        $this->assertSame(0, $this->dunned());
        $this->assertFalse($this->reminded());

        config(['billing.paid_status_outbound_notifications' => false]);
        $this->assertTrue($this->reminded());
    }

    /** #4005 (Founder 2026-10-29): a zero-fee course with a positive bill line on another invoice (a member bill) is not free, so it stays in the reminders. */
    public function test_zero_fee_course_with_a_positive_member_bill_line_is_not_settled(): void
    {
        $course = $this->course(0, 0);
        $bill = Invoice::create(['StudentID' => $course->StudentID, 'IssueDate' => '2026-08-01', 'DueDate' => '2026-08-15', 'Note' => '',
            'TotalAmount' => 1500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        \App\Models\InvoiceItem::create(['InvoiceID' => $bill->id, 'StudentClassID' => $course->ID, 'Description' => 'member', 'Amount' => 1500]);

        $this->assertNotSame('free', app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([$course->ID], [$course])[$course->ID]['status']);
        $this->assertSame(1, $this->dunned());
        $this->assertTrue($this->reminded());
    }

    /** #4005 review: a linked member bill its payments fully cover does not bring a zero-fee course back into the reminders. */
    public function test_zero_fee_course_with_a_fully_paid_member_bill_line_stays_settled(): void
    {
        $course = $this->course(0, 0);
        $bill = Invoice::create(['StudentID' => $course->StudentID, 'IssueDate' => '2026-08-01', 'DueDate' => '2026-08-15', 'Note' => '',
            'TotalAmount' => 1500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        \App\Models\InvoiceItem::create(['InvoiceID' => $bill->id, 'StudentClassID' => $course->ID, 'Description' => 'member', 'Amount' => 1500]);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 1500, 'PaidAt' => '2026-08-05', 'Method' => 'cash']);

        $this->assertSame(0, $this->dunned());
        $this->assertFalse($this->reminded());
    }

    /** #3985 review: the rollback switch keeps the legacy (stored Status) window. */
    public function test_monthly_window_keeps_the_legacy_query_when_the_outbound_switch_is_off(): void
    {
        config(['billing.paid_status_outbound_notifications' => false]);
        \Carbon\Carbon::setTestNow('2026-10-10 09:00:00');
        $course = $this->course(3000, 750);
        $course->update(['ScheduleMode' => 'date', 'settlement_day' => 28, 'StartDate' => '2026-10-01', 'EndDate' => '2026-10-31']);
        Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01', 'DueDate' => '2026-10-12',
            'TotalAmount' => 3000, 'PaidAmount' => 0, 'Status' => 'paid', 'billing_period' => '2026-10']);
        $kept = \App\Services\NotificationSyncService::withinTuitionReminderWindow(new \Illuminate\Database\Eloquent\Collection([$course->fresh()]));
        \Carbon\Carbon::setTestNow();
        $this->assertCount(0, $kept);
    }

    /** #3985 (Founder 2026-10-29): the monthly reminder window follows the verdict's open period, not the stored invoice Status. */
    public function test_monthly_window_follows_the_verdict_open_period_not_the_stored_status(): void
    {
        \Carbon\Carbon::setTestNow('2026-10-10 09:00:00');
        $course = $this->course(3000, 750);
        $course->update(['ScheduleMode' => 'date', 'settlement_day' => 28, 'StartDate' => '2026-10-01', 'EndDate' => '2026-10-31']);
        // stored Status says paid, but no Payment row backs it: the verdict still owes, and it is due in 2 days
        Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01', 'DueDate' => '2026-10-12',
            'TotalAmount' => 3000, 'PaidAmount' => 0, 'Status' => 'paid', 'billing_period' => '2026-10']);

        $kept = \App\Services\NotificationSyncService::withinTuitionReminderWindow(new \Illuminate\Database\Eloquent\Collection([$course->fresh()]));
        \Carbon\Carbon::setTestNow();
        $this->assertCount(1, $kept);
    }

    /** #3991 review: a no-fee monthly course whose bill staff marked 免費／贈送 is settled even when the period engine wants review. */
    public function test_marked_free_gift_monthly_course_is_not_reminded_despite_period_review(): void
    {
        $course = $this->course(0, 0);
        $course->update(['ScheduleMode' => 'date', 'SessionCount' => 0, 'RemainingSessions' => 0, 'EndDate' => '2026-10-31']); // bills stop in Sep: B15 review is active
        foreach (['2026-08', '2026-09'] as $period) {
            Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => $period . '-01', 'DueDate' => $period . '-15',
                'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'paid', 'billing_period' => $period, 'zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]);
        }
        \App\Models\ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-09-10', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'attended']);
        $status = fn () => app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([$course->ID])[$course->ID]['status'];
        $this->assertSame('free', $status());
        $this->assertSame(0, $this->dunned());
        $this->assertFalse($this->reminded());
        $amendment = \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $course->ID, 'effective_from' => '2026-09-01', 'rate' => 800, 'source_reference' => 't', 'reason' => 't']);
        $this->assertNotSame('free', $status()); // #3991 review: a priced amendment after the mark ends its effect for both senders
        $amendment->delete();
        Invoice::query()->where('StudentClassID', $course->ID)->update(['zero_amount_reason' => null]); // unmarked: the same shape is under review
        $this->assertSame('review_required', $status());
        Invoice::query()->where('StudentClassID', $course->ID)->update(['zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]); // marked Aug never settles an unbilled Sep with lessons
        Invoice::query()->where('billing_period', '2026-09')->where('StudentClassID', $course->ID)->delete();
        $this->assertSame('review_required', $status());
    }

    public function test_partial_invoice_is_still_reminded(): void
    {
        $this->invoice($this->course(10000), 10000, 4000);
        $this->assertSame(1, $this->dunned());
        $this->assertTrue($this->reminded());
    }

    public function test_review_required_course_keeps_the_legacy_decision(): void
    {
        $this->course(0, 1500); // Charge 0 + positive Rate, no invoice: resolver cannot state a balance
        $this->assertSame(1, $this->dunned());
        $this->assertTrue($this->reminded());
    }

    public function test_resolver_failure_falls_back_to_legacy(): void
    {
        $this->invoice($this->course(10000), 10000, 10000);
        $this->app->bind(\App\Services\BillingPayableResolver::class, fn () => new class(app(\App\Services\InvoiceAmountReconciliationService::class), app(\App\Services\MonthlyPeriodPaymentService::class)) extends \App\Services\BillingPayableResolver {
            public function courseStatusesByStudentClassIds(array $studentClassIds, iterable $courses = []): array
            {
                throw new \RuntimeException('boom');
            }
        });
        $this->assertSame(1, $this->dunned());
        $this->assertTrue($this->reminded());
    }
}
