<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\MonthlyPeriodPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyPeriodPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function course(): StudentClass
    {
        $student = Student::create(['name' => 'Period fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 2, 'Period' => 4, 'StartDate' => '2026-08-01', 'EndDate' => '2026-09-30',
            'TotalHours' => 16, 'Charge' => 4000, 'Rate' => 1000, 'Paid' => 1,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'settlement_day' => 20,
        ]);
        foreach (['2026-08-15', '2026-09-02'] as $date) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }
        return $course;
    }

    private function invoice(StudentClass $course, string $period, int $paid): Invoice
    {
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'IssueDate' => $period . '-01', 'billing_period' => $period, 'TotalAmount' => 1000,
            'PaidAmount' => $paid, 'Status' => $paid === 1000 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid')]);
        if ($paid > 0) Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => $period . '-20', 'Method' => 'cash']);
        return $invoice;
    }

    public function test_old_paid_flag_never_pays_a_different_invoice_period(): void
    {
        $course = $this->course();
        $this->invoice($course, '2026-08', 1000);
        $this->invoice($course, '2026-09', 0);
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertSame('paid', $summary['periods'][0]['payment_status']);
        $this->assertSame('unpaid', $summary['periods'][1]['payment_status']);
        $this->assertSame('unpaid', $summary['payment_status']);
        $this->assertSame('2026-09', $summary['billing_period']);
        $this->assertFalse($summary['review_required']);
        $this->assertSame(1, (int) $course->fresh()->Paid);
    }

    public function test_unassigned_paid_flag_on_multi_period_course_requires_review(): void
    {
        $course = $this->course();
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertTrue($summary['review_required']);
        $this->assertSame('review_required', $summary['payment_status']);
        $this->assertSame('unknown', $summary['periods'][1]['payment_status']);
    }

    public function test_partial_and_voided_payments_do_not_become_paid(): void
    {
        $course = $this->course();
        $this->invoice($course, '2026-08', 1000);
        $invoice = $this->invoice($course, '2026-09', 400);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -100, 'PaidAt' => '2026-09-21', 'Method' => 'void']);
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertSame('partial', $summary['payment_status']);
        $this->assertSame(300, $summary['periods'][1]['paid_amount']);
    }

    public function test_missing_next_period_invoice_is_unknown_not_paid(): void
    {
        $course = $this->course();
        $this->invoice($course, '2026-08', 1000);
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertTrue($summary['review_required']);
        $this->assertSame('unknown', $summary['periods'][1]['payment_status']);
    }

    public function test_single_period_legacy_and_packages_keep_their_existing_payment_contract(): void
    {
        $course = $this->course();
        ClassSession::where('StudentClassID', $course->ID)->where('SessionDate', '2026-09-02')->delete();
        $course->update(['EndDate' => '2026-08-31']);
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertSame('paid', $summary['payment_status']);
        $course->setAttribute('PackageID', 1);
        $this->assertSame([], app(MonthlyPeriodPaymentService::class)->batch(collect([$course])));
    }

    public function test_next_period_without_sessions_is_still_not_covered_by_august_payment(): void
    {
        $course = $this->course();
        ClassSession::where('StudentClassID', $course->ID)->where('SessionDate', '2026-09-02')->delete();
        $this->invoice($course, '2026-08', 1000);
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertTrue($summary['review_required']);
        $this->assertSame('review_required', $summary['payment_status']);
    }

    public function test_explicit_non_calendar_service_range_is_not_split_at_month_boundary(): void
    {
        $course = $this->course();
        $course->update(['StartDate' => '2026-08-15', 'EndDate' => '2026-09-14']);
        $invoice = $this->invoice($course, '2026-08', 1000);
        \App\Models\InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID,
            'Description' => 'Settlement cycle', 'Amount' => 1000,
            'PeriodStart' => '2026-08-15', 'PeriodEnd' => '2026-09-14']);
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertFalse($summary['review_required']);
        $this->assertSame('paid', $summary['payment_status']);
        $this->assertCount(1, $summary['periods']);
    }

    public function test_inventory_detects_in_boundary_mixed_periods_without_mutation(): void
    {
        $course = $this->course();
        $this->invoice($course, '2026-08', 1000);
        $before = [$course->fresh()->getAttributes(), ClassSession::count(), Invoice::count(), Payment::count()];
        $this->withoutMockingConsoleOutput();
        \Illuminate\Support\Facades\Artisan::call('monthly:leave-boundary-inventory', ['--json' => true]);
        $report = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $report['aggregate']['anomaly_sessions']);
        $this->assertSame(1, $report['period_payment_totals']['review_required']);
        $this->assertSame((int) $course->ID, $report['bounded_period_payment_review'][0]['course_id']);
        $this->assertSame($before, [$course->fresh()->getAttributes(), ClassSession::count(), Invoice::count(), Payment::count()]);
    }
}
