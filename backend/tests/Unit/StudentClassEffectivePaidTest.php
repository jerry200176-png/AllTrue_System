<?php

namespace Tests\Unit;

use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentClassEffectivePaidTest extends TestCase
{
    use RefreshDatabase;

    private function pkg(bool $paid): CoursePackage
    {
        return CoursePackage::create([
            'student_id' => 1, 'campus_id' => 1, 'name' => '方案',
            'total_sessions' => 20, 'remaining_sessions' => 15, 'used_sessions' => 5,
            'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => $paid, 'paid_at' => $paid ? now()->toDateString() : null,
            'stop' => false, 'enabled' => true,
        ]);
    }

    private function sc(?int $pkgId, int $paid = 0, int $subjectId = 1): StudentClass
    {
        return StudentClass::create([
            'StudentID' => 1, 'GradeID' => 1, 'SubjectID' => $subjectId, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10, 'SessionCount' => 10, 'RemainingSessions' => 8, 'UsedSessions' => 2,
            'Charge' => 10000, 'Pay' => $paid ? 10000 : 0, 'Paid' => $paid,
            'Stop' => 0, 'PackageID' => $pkgId, 'ScheduleMode' => 'count', 'MDate' => now(),
        ]);
    }

    public function test_paid_package_member_with_paid_zero_is_effectively_paid(): void
    {
        $sc = $this->sc($this->pkg(true)->id, 0);
        $this->assertTrue($sc->isEffectivelyPaid());
        $this->assertTrue(StudentClass::effectivelyPaid()->where('ID', $sc->ID)->exists());
        $this->assertFalse(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }

    public function test_unpaid_package_member_with_paid_zero_is_effectively_unpaid(): void
    {
        $sc = $this->sc($this->pkg(false)->id, 0);
        $this->assertFalse($sc->isEffectivelyPaid());
        $this->assertFalse(StudentClass::effectivelyPaid()->where('ID', $sc->ID)->exists());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }

    public function test_non_package_course_unchanged(): void
    {
        $unpaid = $this->sc(null, 0, 1);
        $this->assertFalse($unpaid->isEffectivelyPaid());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $unpaid->ID)->exists());

        $paid = $this->sc(null, 1, 2);
        $this->assertTrue($paid->isEffectivelyPaid());
        $this->assertTrue(StudentClass::effectivelyPaid()->where('ID', $paid->ID)->exists());
    }

    public function test_pending_report_does_not_equal_paid(): void
    {
        $sc = $this->sc($this->pkg(false)->id, 0);
        PaymentReport::create([
            'StudentID' => 1, 'StudentClassID' => $sc->ID, 'reported_amount' => 10000,
            'status' => 'pending', 'reported_by_name' => 'Tester',
            'report_token_hash' => hash('sha256', Str::random(32)),
            'token_expires_at' => now()->addDays(7),
            'payment_date' => now()->toDateString(), 'payment_method' => 'transfer',
        ]);
        $this->assertFalse($sc->isEffectivelyPaid());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }

    public function test_partial_payment_does_not_equal_paid(): void
    {
        $sc = $this->sc($this->pkg(false)->id, 0);
        $invoice = Invoice::create([
            'StudentID' => 1, 'StudentClassID' => $sc->ID, 'IssueDate' => now()->toDateString(),
            'TotalAmount' => 10000, 'PaidAmount' => 4000, 'Status' => 'unpaid',
        ]);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 4000, 'PaidAt' => now(), 'Method' => 'transfer']);
        $this->assertFalse($sc->isEffectivelyPaid());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }

    public function test_reject_or_void_does_not_create_false_paid_state(): void
    {
        $sc = $this->sc($this->pkg(false)->id, 0);
        PaymentReport::create([
            'StudentID' => 1, 'StudentClassID' => $sc->ID, 'reported_amount' => 10000,
            'status' => 'rejected', 'reported_by_name' => 'Tester',
            'report_token_hash' => hash('sha256', Str::random(32)),
            'token_expires_at' => now()->addDays(7), 'rejection_note' => 'Duplicate',
            'payment_date' => now()->toDateString(), 'payment_method' => 'transfer',
        ]);
        Invoice::create([
            'StudentID' => 1, 'StudentClassID' => $sc->ID, 'IssueDate' => now()->toDateString(),
            'TotalAmount' => 10000, 'PaidAmount' => 0, 'Status' => 'void',
        ]);
        $this->assertFalse($sc->isEffectivelyPaid());
        $this->assertTrue(StudentClass::effectivelyUnpaid()->where('ID', $sc->ID)->exists());
    }
}
