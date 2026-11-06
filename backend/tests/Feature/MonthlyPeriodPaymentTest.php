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
        // A monthly ('date') package member is billed per member (#3892 D4, in-app #478), so it gets the same period attribution.
        $this->assertSame('paid', app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID]['payment_status']);
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

    public function test_unpaid_non_calendar_cycle_keeps_its_explicit_invoice_amount(): void
    {
        $course = $this->course();
        $course->update(['StartDate' => '2026-08-15', 'EndDate' => '2026-09-14']);
        $invoice = $this->invoice($course, '2026-08', 0);
        $invoice->update(['TotalAmount' => 6000]);
        \App\Models\InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID,
            'Description' => 'Reviewed service cycle', 'Amount' => 6000, 'PeriodStart' => '2026-08-15', 'PeriodEnd' => '2026-09-14']);
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertFalse($summary['review_required']);
        $this->assertSame('unpaid', $summary['payment_status']);
        $this->assertSame(6000, $summary['periods'][0]['charge']);
        $this->assertSame(6000, (int) $invoice->fresh()->TotalAmount);
    }
    public function test_review_exposes_missing_invoices_and_outside_contract_lessons_without_rewriting_cash(): void
    {
        $course = $this->course();
        $course->update(['StartDate' => '2026-07-27', 'EndDate' => '2026-09-10', 'Rate' => 1500, 'Charge' => 7500]);
        ClassSession::where('StudentClassID', $course->ID)->delete();
        foreach (['2026-08-15', '2026-08-20', '2026-08-25', '2026-08-27', '2026-09-02', '2026-09-10', '2026-09-16', '2026-09-23'] as $date) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-08-06', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'leave']);
        $invoice = $this->invoice($course, '2026-07', 1000);
        $invoice->update(['TotalAmount' => 7500, 'PaidAmount' => 7500]);
        $invoice->payments()->update(['Amount' => 7500]);
        $before = [$course->fresh()->getAttributes(), $invoice->fresh()->getAttributes(), Payment::first()->getAttributes(), ClassSession::count()];
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertSame(7500, $summary['registered_paid_amount']);
        $this->assertSame([6000, 6000], array_column($summary['session_review'], 'estimated_charge'));
        $this->assertSame([4, 4], array_column($summary['session_review'], 'uncovered_sessions'));
        $this->assertSame([0, 2], array_column($summary['session_review'], 'outside_contract_sessions'));
        $this->assertTrue($summary['review_required']);
        $this->assertSame('unknown', $summary['periods'][2]['payment_status']);
        $this->assertFalse($course->relationLoaded('pricingAmendments'));
        $this->assertSame($before, [$course->fresh()->getAttributes(), $invoice->fresh()->getAttributes(), Payment::first()->getAttributes(), ClassSession::count()]);
    }

    public function test_review_prices_each_lesson_at_its_effective_rate_and_retains_hourly_snapshot(): void
    {
        $course = $this->course();
        $course->update(['Rate' => 600, 'rate_unit' => 'hour']);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-08-10', 'StartTime' => '18:00', 'EndTime' => '19:30', 'Status' => 'completed', 'session_charge' => 777]);
        foreach ([null, now()] as $voided) {
            \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $course->ID,
                'effective_from' => $voided ? '2026-08-14' : '2026-08-15', 'rate' => $voided ? 9999 : 1200, 'rate_unit' => 'session',
                'source_reference' => 'review-fixture', 'reason' => 'test', 'created_at' => now(), 'voided_at' => $voided]);
        }
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertSame(1977, $summary['session_review'][0]['estimated_charge']);
        $this->assertSame(1200, $summary['session_review'][1]['estimated_charge']);
        $this->assertSame(600, (int) $course->fresh()->Rate);
    }

    public function test_missing_rate_is_unknown_instead_of_using_contract_charge_as_an_estimate(): void
    {
        $course = $this->course();
        $course->update(['Rate' => 0]);
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertNull($summary['session_review'][0]['estimated_charge']);
        $this->assertNull($summary['session_review'][0]['sessions'][0]['estimated_charge']);
    }

    public function test_paid_invoice_amount_discrepancy_is_reviewable_without_repricing_it(): void
    {
        $course = $this->course();
        ClassSession::where('StudentClassID', $course->ID)->where('SessionDate', '2026-09-02')->delete();
        $course->update(['EndDate' => '2026-08-31']);
        $invoice = $this->invoice($course, '2026-08', 1000);
        $invoice->update(['TotalAmount' => 1500, 'PaidAmount' => 1500]);
        $invoice->payments()->update(['Amount' => 1500]);
        $summary = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]))[$course->ID];
        $this->assertTrue($summary['review_required']);
        $this->assertTrue($summary['periods'][0]['amount_discrepancy']);
        $this->assertSame(1000, $summary['session_review'][0]['estimated_charge']);
        $this->assertSame(1500, $summary['registered_paid_amount']);
        $this->assertSame(1500, (int) $invoice->fresh()->TotalAmount);
    }


    private function prepaidOctoberCourse(): array
    {
        $course = $this->course();
        ClassSession::where('StudentClassID', $course->ID)->delete();
        $course->update(['StartDate' => '2026-10-01', 'EndDate' => '2026-10-31']);
        foreach (['2026-10-15', '2026-10-22'] as $date) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '23:00', 'EndTime' => '23:59', 'Status' => 'scheduled']);
        }
        return [$course->fresh(), $this->invoice($course, '2026-10', 1000)];
    }

    public function test_prepaid_current_month_with_no_lessons_held_is_not_displayed_as_a_discrepancy(): void // in-app #510
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-10 12:00:00');
        [$course] = $this->prepaidOctoberCourse();
        $svc = app(MonthlyPeriodPaymentService::class);
        $summary = $svc->batch(collect([$course]))[$course->ID];
        $shown = $svc->forDisplay($summary);
        \Illuminate\Support\Carbon::setTestNow();
        $this->assertTrue($summary['periods'][0]['amount_discrepancy'], 'guards still see main behaviour');
        $this->assertTrue($summary['review_required']);
        $this->assertFalse($shown['periods'][0]['amount_discrepancy']);
        $this->assertFalse($shown['review_required']);
        $this->assertNotSame('review_required', $shown['payment_status']);
    }

    public function test_unpaid_running_month_bill_displays_the_stored_total_not_the_zero_lesson_total(): void // in-app #510
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-10 12:00:00');
        [$course, $invoice] = $this->prepaidOctoberCourse();
        $invoice->payments()->delete();
        $invoice->update(['TotalAmount' => 7200, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $svc = app(MonthlyPeriodPaymentService::class);
        $shown = $svc->forDisplay($svc->batch(collect([$course->fresh()]))[$course->ID]);
        \Illuminate\Support\Carbon::setTestNow();
        $this->assertFalse($shown['review_required']);
        $this->assertSame(7200, $shown['periods'][0]['charge']);
        $this->assertSame(7200, $shown['periods'][0]['outstanding_amount']);
        $this->assertSame('unpaid', $shown['payment_status']);
    }

    public function test_same_prepaid_month_is_displayed_as_a_discrepancy_after_the_period_ends(): void // in-app #510
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 12:00:00');
        [$course] = $this->prepaidOctoberCourse();
        $svc = app(MonthlyPeriodPaymentService::class);
        $shown = $svc->forDisplay($svc->batch(collect([$course]))[$course->ID]);
        \Illuminate\Support\Carbon::setTestNow();
        $this->assertTrue($shown['periods'][0]['amount_discrepancy']);
        $this->assertTrue($shown['review_required']);
    }

    public function test_review_reasons_explain_flag(): void
    {
        $svc = app(MonthlyPeriodPaymentService::class);
        $course = $this->course();
        $this->assertContains('unbilled_month', $svc->batch(collect([$course]))[$course->ID]['review_reasons']);

        $clean = $this->course();
        $this->invoice($clean, '2026-08', 1000);
        $this->invoice($clean, '2026-09', 1000);
        $this->assertSame([], $svc->batch(collect([$clean]))[$clean->ID]['review_reasons']);

        $mid = $this->course();
        $mid->update(['StartDate' => '2026-09-04', 'EndDate' => '2026-09-30']);
        $inv = $this->invoice($mid, '2026-09', 1000);
        $inv->items()->create(['StudentClassID' => $mid->ID, 'Description' => 'm', 'Amount' => 1000, 'PeriodStart' => '2026-09-01', 'PeriodEnd' => '2026-09-30']);
        $this->assertNotContains('item_period_outside_contract', $svc->batch(collect([$mid->fresh()]))[$mid->ID]['review_reasons']); // #4799: whole first month is normal

        $early = $this->course();
        $early->update(['StartDate' => '2026-09-04', 'EndDate' => '2026-09-30']);
        $inv = $this->invoice($early, '2026-09', 1000);
        $inv->items()->create(['StudentClassID' => $early->ID, 'Description' => 'm', 'Amount' => 1000, 'PeriodStart' => '2026-08-25', 'PeriodEnd' => '2026-09-30']);
        $this->assertContains('item_period_outside_contract', $svc->batch(collect([$early->fresh()]))[$early->ID]['review_reasons']); // earlier month still flagged
    }

    public function test_fully_paid_shared_bill_is_not_a_review_but_an_owing_one_is(): void
    {
        // #4803 (Founder 2026-11-03): one bill for two courses, fully paid, is normal; the same bill still owing is reviewed.
        $svc = app(MonthlyPeriodPaymentService::class);
        $other = $this->course();
        foreach ([1500 => false, 1000 => true] as $paid => $flagged) {
            $course = $this->course();
            $this->invoice($course, '2026-09', 1000);
            $shared = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-01',
                'billing_period' => '2026-08', 'TotalAmount' => 1500, 'PaidAmount' => $paid, 'Status' => $paid === 1500 ? 'paid' : 'partial']);
            Payment::create(['InvoiceID' => $shared->id, 'Amount' => $paid, 'PaidAt' => '2026-08-20', 'Method' => 'cash']);
            $shared->items()->create(['StudentClassID' => $course->ID, 'Description' => 'a', 'Amount' => 1000, 'PeriodStart' => '2026-08-01', 'PeriodEnd' => '2026-08-31']);
            $shared->items()->create(['StudentClassID' => $other->ID, 'Description' => 'b', 'Amount' => 500, 'PeriodStart' => '2026-08-01', 'PeriodEnd' => '2026-08-31']);
            $summary = $svc->batch(collect([$course->fresh()]))[$course->ID];
            $this->assertSame($flagged, in_array('shared_or_multi_line_bill', $summary['review_reasons'], true), "paid {$paid}");
        }
        // A legacy PaidAmount with no Payment rows is not payment evidence for the resolver, so it stays reviewed.
        $legacy = $this->course();
        $this->invoice($legacy, '2026-09', 1000);
        $bill = Invoice::create(['StudentID' => $legacy->StudentID, 'StudentClassID' => $legacy->ID, 'IssueDate' => '2026-08-01',
            'billing_period' => '2026-08', 'TotalAmount' => 1500, 'PaidAmount' => 1500, 'Status' => 'paid']);
        $bill->items()->create(['StudentClassID' => $legacy->ID, 'Description' => 'a', 'Amount' => 1000, 'PeriodStart' => '2026-08-01', 'PeriodEnd' => '2026-08-31']);
        $bill->items()->create(['StudentClassID' => $other->ID, 'Description' => 'b', 'Amount' => 500, 'PeriodStart' => '2026-08-01', 'PeriodEnd' => '2026-08-31']);
        $this->assertContains('shared_or_multi_line_bill', $svc->batch(collect([$legacy->fresh()]))[$legacy->ID]['review_reasons']);
        $this->assertSame('review_required', app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([$legacy->ID])[$legacy->ID]['status']);
        // A paid bill whose own lines skip a month (Aug + Oct, September taught and unbilled) stays reviewed.
        $gap = $this->course();
        $gap->update(['EndDate' => '2026-10-31']);
        ClassSession::create(['StudentClassID' => $gap->ID, 'SessionDate' => '2026-10-07', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        $bill = Invoice::create(['StudentID' => $gap->StudentID, 'StudentClassID' => $gap->ID, 'IssueDate' => '2026-08-01',
            'billing_period' => '2026-08', 'TotalAmount' => 2000, 'PaidAmount' => 2000, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 2000, 'PaidAt' => '2026-08-20', 'Method' => 'cash']);
        $bill->items()->create(['StudentClassID' => $gap->ID, 'Description' => 'a', 'Amount' => 1000, 'PeriodStart' => '2026-08-01', 'PeriodEnd' => '2026-08-31']);
        $bill->items()->create(['StudentClassID' => $gap->ID, 'Description' => 'c', 'Amount' => 1000, 'PeriodStart' => '2026-10-01', 'PeriodEnd' => '2026-10-31']);
        $this->assertContains('shared_or_multi_line_bill', $svc->batch(collect([$gap->fresh()]))[$gap->ID]['review_reasons']);
        $this->assertSame('review_required', app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([$gap->ID])[$gap->ID]['status']);
    }
}
