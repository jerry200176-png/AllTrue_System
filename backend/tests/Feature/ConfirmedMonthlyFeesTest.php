<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\MonthlyBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmedMonthlyFeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_unconfirmed_sessions_are_zero_until_attendance_without_changing_invoice(): void
    {
        $student = Student::create(['name' => 'Monthly evidence fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1, 'by1' => 2, 'Period' => 4,
            'StartDate' => '2026-09-11', 'EndDate' => '2026-09-30', 'TotalHours' => 6, 'Charge' => 4500, 'Rate' => 1500, 'rate_unit' => 'session', 'Paid' => 0,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 3, 'SessionDuration' => 120, 'settlement_day' => 31]);
        foreach (['2026-09-16' => 'cancelled', '2026-09-23' => 'leave', '2026-09-30' => 'scheduled'] as $date => $status) {
            $session = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-29', 'DueDate' => '2026-09-30', 'billing_period' => '2026-09', 'TotalAmount' => 4500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->assertSame(0, app(MonthlyBillingService::class)->summarizePeriod($course, '2026-09')['charge']);
        $projection = app(InvoiceAmountReconciliationService::class)->resolve($invoice, $course);
        $this->assertSame(0, $projection['total_amount']);
        $this->assertSame(4500, (int) $invoice->fresh()->TotalAmount);
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->attributes->set('auth_role', 'director'); $request->attributes->set('auth_campus_ids', [1]);
        $slip = app(\App\Http\Controllers\AlertController::class)->tuitionSlipData($request, (int) $course->ID)->getData(true);
        $this->assertSame(0, $slip['charge']); $this->assertSame(0, $slip['payable_amount']);
        $session->update(['Status' => 'attended']);
        $this->assertSame(1500, app(InvoiceAmountReconciliationService::class)->resolve($invoice->fresh(), $course)['total_amount']);
        $invoice->update(['Status' => 'partial', 'PaidAmount' => 100]);
        $this->assertSame(4500, app(InvoiceAmountReconciliationService::class)->resolve($invoice->fresh(), $course)['total_amount']);
        $invoice->update(['Status' => 'paid', 'PaidAmount' => 4500]);
        $this->assertSame(4500, app(InvoiceAmountReconciliationService::class)->resolve($invoice->fresh(), $course)['total_amount']);
    }
}
