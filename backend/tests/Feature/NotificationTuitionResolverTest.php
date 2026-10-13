<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\NotificationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F7 S5: the tuition notification builder decides "owes" from BillingPayableResolver (flag billing.paid_status_outbound_notifications). */
class NotificationTuitionResolverTest extends TestCase
{
    use RefreshDatabase;

    private function course(int $charge, float $rate = 1500): StudentClass
    {
        $student = Student::create(['name' => 'Notify', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-08-01', 'TotalHours' => 20, 'Charge' => $charge, 'Paid' => 0, 'Rate' => $rate, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 5,
            'UsedSessions' => 0, 'ClassType' => 'one_on_one', 'LearnTimeID' => null,
        ]);
    }

    private function invoice(StudentClass $course, int $total, int $paid): void
    {
        // Not yet due: an overdue invoice has its own notice, which replaces the per-course one (#3877).
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-01', 'DueDate' => now()->addDays(14)->toDateString(),
            'TotalAmount' => $total, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-08']);
        if ($paid > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => '2026-08-05', 'Method' => 'cash']);
        }
    }

    private function tuition(): \Illuminate\Support\Collection
    {
        NotificationSyncService::sync([]);

        return Notification::where('Type', 'tuition')->where('SourceType', 'StudentClass')->whereNull('ResolvedAt')->get();
    }

    public function test_zero_fee_course_gets_no_notification_but_legacy_flag_off_still_does(): void
    {
        $this->course(0, 0);
        $this->assertCount(0, $this->tuition());

        config(['billing.paid_status_outbound_notifications' => false]);
        $this->assertCount(1, $this->tuition());
    }

    public function test_fully_paid_invoice_with_stale_paid_flag_gets_no_notification(): void
    {
        $this->invoice($this->course(10000), 10000, 10000);
        $this->assertCount(0, $this->tuition());

        config(['billing.paid_status_outbound_notifications' => false]);
        $rows = $this->tuition();
        $this->assertCount(1, $rows);
        $this->assertSame(10000, $rows[0]->Payload['outstanding']);
    }

    public function test_partial_invoice_notifies_with_remaining_amount(): void
    {
        $this->invoice($this->course(10000), 10000, 4000);
        $rows = $this->tuition();
        $this->assertCount(1, $rows);
        $this->assertSame(6000, $rows[0]->Payload['outstanding']);

        config(['billing.paid_status_outbound_notifications' => false]);
        $this->assertSame(10000, $this->tuition()[0]->Payload['outstanding']);
    }


    public function test_overdue_invoice_projection_respects_the_outbound_rollback_switch(): void
    {
        $course = $this->course(10000);
        $course->update(['Paid' => 1]);
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'IssueDate' => now()->subDays(20)->toDateString(), 'DueDate' => now()->subDays(9)->toDateString(),
            'TotalAmount' => 10000, 'PaidAmount' => 2500, 'Status' => 'partial', 'billing_period' => now()->format('Y-m')]);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 10000, 'PaidAt' => now(), 'Method' => 'cash']);
        config(['billing.paid_status_outbound_notifications' => true]);
        NotificationSyncService::sync([1]);
        $this->assertSame(0, Notification::where('SourceType', 'Invoice')->whereNull('ResolvedAt')->count());

        config(['billing.paid_status_outbound_notifications' => false]);
        NotificationSyncService::sync([1]);
        $notice = Notification::where('SourceType', 'Invoice')->whereNull('ResolvedAt')->firstOrFail();
        $this->assertSame(10000, (int) $notice->Payload['total_amount']);
        $this->assertSame(2500, (int) $notice->Payload['paid_amount']);
        $this->assertSame('partial', $notice->Payload['status']);

        config(['billing.paid_status_outbound_notifications' => true]);
        NotificationSyncService::sync([1]);
        $this->assertNotNull($notice->fresh()->ResolvedAt);
    }


    public function test_overdue_projection_batches_dependencies_and_clears_session_preload(): void
    {
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $course = $this->course(10000);
            $ids[] = (int) $course->ID;
            Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
                'IssueDate' => now()->subDays(20)->toDateString(), 'DueDate' => now()->subDays(9)->toDateString(),
                'TotalAmount' => 10000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => now()->format('Y-m')]);
        }
        config(['billing.paid_status_outbound_notifications' => true]);
        $this->mock(\App\Services\InvoiceAmountReconciliationService::class, function ($m) use ($ids) {
            $m->shouldReceive('preloadSessions')->once()->with($ids);
            $m->shouldReceive('forgetPreloadedSessions')->once();
            $m->shouldReceive('resolve')->twice()->andReturnUsing(function ($invoice) {
                foreach (['studentClass', 'payments', 'items'] as $relation) {
                    $this->assertTrue($invoice->relationLoaded($relation), $relation . ' must be batched before projection');
                }
                return ['total_amount' => 10000, 'applied_amount' => 0, 'outstanding_amount' => 10000];
            });
        });
        $builder = new \ReflectionMethod(NotificationSyncService::class, 'buildInvoiceOverdueNotifications');
        $builder->setAccessible(true);
        $this->assertCount(2, $builder->invoke(null, [1]));
    }

    public function test_review_required_course_keeps_the_legacy_decision(): void
    {
        // Charge 0 with a positive Rate and no invoice: resolver cannot state a balance.
        $this->course(0, 1500);
        $rows = $this->tuition();
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]->Payload['outstanding']);
    }
}
