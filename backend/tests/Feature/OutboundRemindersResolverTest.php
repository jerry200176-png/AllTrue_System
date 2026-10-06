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
