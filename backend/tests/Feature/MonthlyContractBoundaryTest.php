<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\MonthlyContractBoundaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonthlyContractBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_course_extension_requires_renewal_but_package_and_unchanged_boundary_do_not(): void
    {
        $source = new StudentClass(['ScheduleMode' => 'date', 'Paid' => 1, 'EndDate' => '2026-09-30']);
        $service = app(MonthlyContractBoundaryService::class);
        $this->assertTrue($service->extensionRequiresRenewal($source, ['EndDate' => '2026-10-31']));
        $this->assertFalse($service->extensionRequiresRenewal($source, ['EndDate' => '2026-09-30']));
        $source->PackageID = 1;
        $this->assertFalse($service->extensionRequiresRenewal($source, ['EndDate' => '2026-10-31']));
    }

    public function test_invalid_date_is_a_validation_error(): void
    {
        $source = new StudentClass(['ScheduleMode' => 'date', 'Paid' => 1, 'EndDate' => '2026-09-30']);
        $this->expectException(ValidationException::class);
        app(MonthlyContractBoundaryService::class)->extensionRequiresRenewal($source, ['EndDate' => 'bad-date']);
    }

    public function test_money_is_the_resolver_applied_amount_net_of_voids_and_fails_closed(): void
    {
        $student = Student::create(['name' => 'S6', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => 'T', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $service = app(MonthlyContractBoundaryService::class);
        $make = fn (array $payments) => tap(StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'TotalHours' => 20, 'Stop' => 0, 'StartDate' => '2026-09-01', 'EndDate' => '2026-09-30', 'Charge' => 6000, 'Paid' => 1, 'ScheduleMode' => 'date',
        ]), function ($course) use ($student, $payments) {
            $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-01',
                'TotalAmount' => 6000, 'PaidAmount' => 6000, 'Status' => 'paid', 'billing_period' => '2026-09']);
            foreach ($payments as [$amount, $method]) {
                Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $amount, 'Method' => $method, 'PaidAt' => '2026-09-02']);
            }
        });

        $this->assertTrue($service->extensionRequiresRenewal($make([[6000, 'cash']]), ['EndDate' => '2026-10-31']));
        // Fully voided: Flag=1 / stored PaidAmount no longer count as money.
        $this->assertFalse($service->extensionRequiresRenewal($make([[6000, 'cash'], [-6000, 'void']]), ['EndDate' => '2026-10-31']));

        $this->mock(\App\Services\BillingPayableResolver::class)
            ->shouldReceive('courseStatusesByStudentClassIds')->andThrow(new \RuntimeException('boom'));
        $this->assertTrue(app(MonthlyContractBoundaryService::class)->extensionRequiresRenewal($make([]), ['EndDate' => '2026-10-31']));
    }
}
