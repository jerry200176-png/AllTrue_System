<?php

namespace Tests\Unit;

use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassEffectivePaidTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_package_member_with_paid_zero_is_effectively_paid(): void
    {
        $pkg = CoursePackage::create([
            'student_id' => 1,
            'campus_id' => 1,
            'name' => '雙科方案',
            'total_sessions' => 20,
            'remaining_sessions' => 15,
            'used_sessions' => 5,
            'rate' => 1000,
            'rate_unit' => 'session',
            'class_type' => 'one_on_one',
            'paid' => true,
            'paid_at' => now()->toDateString(),
            'stop' => false,
            'enabled' => true,
        ]);

        $sc = StudentClass::create([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 8,
            'UsedSessions' => 2,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        $this->assertTrue($sc->isEffectivelyPaid());
        $this->assertTrue(StudentClass::effectivelyPaid()->where('ID', $sc->ID)->exists());
        $this->assertFalse(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }

    public function test_unpaid_package_member_with_paid_zero_is_effectively_unpaid(): void
    {
        $pkg = CoursePackage::create([
            'student_id' => 1,
            'campus_id' => 1,
            'name' => '雙科方案未繳',
            'total_sessions' => 20,
            'remaining_sessions' => 20,
            'used_sessions' => 0,
            'rate' => 1000,
            'rate_unit' => 'session',
            'class_type' => 'one_on_one',
            'paid' => false,
            'paid_at' => null,
            'stop' => false,
            'enabled' => true,
        ]);

        $sc = StudentClass::create([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        $this->assertFalse($sc->isEffectivelyPaid());
        $this->assertFalse(StudentClass::effectivelyPaid()->where('ID', $sc->ID)->exists());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }

    public function test_non_package_course_unchanged(): void
    {
        // Unpaid non-package course
        $unpaidSc = StudentClass::create([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => null,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        $this->assertFalse($unpaidSc->isEffectivelyPaid());
        $this->assertFalse(StudentClass::effectivelyPaid()->where('ID', $unpaidSc->ID)->exists());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $unpaidSc->ID)->exists());

        // Paid non-package course
        $paidSc = StudentClass::create([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 2,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 10000,
            'Paid' => 1,
            'Stop' => 0,
            'PackageID' => null,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        $this->assertTrue($paidSc->isEffectivelyPaid());
        $this->assertTrue(StudentClass::effectivelyPaid()->where('ID', $paidSc->ID)->exists());
        $this->assertFalse(StudentClass::effectivelyUnpaid()->where('ID', $paidSc->ID)->exists());
    }

    public function test_pending_report_does_not_equal_paid(): void
    {
        $pkg = CoursePackage::create([
            'student_id' => 1,
            'campus_id' => 1,
            'name' => '待審方案',
            'total_sessions' => 10,
            'remaining_sessions' => 10,
            'used_sessions' => 0,
            'rate' => 1000,
            'paid' => false,
            'stop' => false,
            'enabled' => true,
        ]);

        $sc = StudentClass::create([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(5)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        PaymentReport::create([
            'StudentID' => 1,
            'StudentClassID' => $sc->ID,
            'reported_amount' => 10000,
            'status' => 'pending',
            'reported_by_name' => 'Test Reporter',
            'report_token_hash' => hash('sha256', \Illuminate\Support\Str::random(32)),
            'token_expires_at' => now()->addDays(7),
            'payment_date' => now()->toDateString(),
            'payment_method' => 'transfer',
        ]);

        $this->assertFalse($sc->isEffectivelyPaid());
        $this->assertFalse(StudentClass::effectivelyPaid()->where('ID', $sc->ID)->exists());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }

    public function test_partial_payment_does_not_equal_paid(): void
    {
        $pkg = CoursePackage::create([
            'student_id' => 1,
            'campus_id' => 1,
            'name' => '部分付款方案',
            'total_sessions' => 10,
            'remaining_sessions' => 10,
            'used_sessions' => 0,
            'rate' => 1000,
            'paid' => false,
            'stop' => false,
            'enabled' => true,
        ]);

        $sc = StudentClass::create([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(5)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        $invoice = Invoice::create([
            'StudentID' => 1,
            'StudentClassID' => $sc->ID,
            'IssueDate' => now()->toDateString(),
            'TotalAmount' => 10000,
            'PaidAmount' => 4000,
            'Status' => 'unpaid',
        ]);

        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 4000,
            'PaidAt' => now(),
            'Method' => 'transfer',
        ]);

        $this->assertFalse($sc->isEffectivelyPaid());
        $this->assertFalse(StudentClass::effectivelyPaid()->where('ID', $sc->ID)->exists());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }

    public function test_reject_or_void_does_not_create_false_paid_state(): void
    {
        $pkg = CoursePackage::create([
            'student_id' => 1,
            'campus_id' => 1,
            'name' => '退回方案',
            'total_sessions' => 10,
            'remaining_sessions' => 10,
            'used_sessions' => 0,
            'rate' => 1000,
            'paid' => false,
            'stop' => false,
            'enabled' => true,
        ]);

        $sc = StudentClass::create([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(5)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        PaymentReport::create([
            'StudentID' => 1,
            'StudentClassID' => $sc->ID,
            'reported_amount' => 10000,
            'status' => 'rejected',
            'reported_by_name' => 'Test Reporter',
            'report_token_hash' => hash('sha256', \Illuminate\Support\Str::random(32)),
            'token_expires_at' => now()->addDays(7),
            'rejection_note' => 'Duplicate payment report rejected',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'transfer',
        ]);

        $invoice = Invoice::create([
            'StudentID' => 1,
            'StudentClassID' => $sc->ID,
            'IssueDate' => now()->toDateString(),
            'TotalAmount' => 10000,
            'PaidAmount' => 0,
            'Status' => 'void',
        ]);

        $this->assertFalse($sc->isEffectivelyPaid());
        $this->assertFalse(StudentClass::effectivelyPaid()->where('ID', $sc->ID)->exists());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }
}
