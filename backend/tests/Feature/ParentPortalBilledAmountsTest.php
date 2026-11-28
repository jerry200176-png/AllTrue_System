<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ParentSession;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #3521 (Founder 2026-11-27): where a parent sees an amount, a course that has bills shows what the bills say
 * (total after discounts, money actually received), not the stored Charge / Pay columns or sessions × unit price.
 */
class ParentPortalBilledAmountsTest extends TestCase
{
    use RefreshDatabase;

    private function student(): Student
    {
        return Student::create(['name' => '帳單金額測試生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => '0911003521']);
    }

    private function bearer(Student $student): array
    {
        $raw = Str::random(32);
        ParentSession::create(['StudentID' => $student->id, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addHours(2)]);

        return ['Authorization' => 'Bearer ' . $raw];
    }

    private function course(Student $student, int $subjectId, array $extra = []): StudentClass
    {
        return StudentClass::create($extra + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => $subjectId, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->startOfMonth()->toDateString(), 'TotalHours' => 4, 'Charge' => 6600, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1650,
            'MDate' => now()->toDateString(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 4, 'RemainingSessions' => 4,
            'UsedSessions' => 0, 'SessionDuration' => 90, 'ClassType' => 'one_on_one', 'CampusID' => 1,
        ]);
    }

    private function bill(StudentClass $course, int $total, int $received, array $extra = []): void
    {
        $invoice = Invoice::create($extra + ['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => now()->toDateString(),
            'TotalAmount' => $total, 'PaidAmount' => $received, 'Status' => $received >= $total ? 'paid' : ($received > 0 ? 'partial' : 'unpaid')]);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => 'tuition', 'Amount' => $total]);
        if ($received > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $received, 'PaidAt' => now()->toDateString(), 'Method' => 'cash']);
        }
    }

    public function test_billing_history_shows_the_bill_total_and_money_received_for_a_billed_course(): void
    {
        $student = $this->student();
        $billed = $this->course($student, 1);          // stored 6600 / 0, billed 6000 after a discount, 2000 received
        $this->bill($billed, 6000, 2000);
        $unbilled = $this->course($student, 2, ['Pay' => 3000]); // never billed: the stored columns stay the source

        $rows = collect($this->getJson('/api/v1/parent/billing-history', $this->bearer($student))->assertOk()->json('records'))
            ->keyBy('student_class_id');

        $this->assertSame([6000, 2000, 'partial'], [$rows[$billed->ID]['charge'], $rows[$billed->ID]['paid'], $rows[$billed->ID]['status']]);
        $this->assertSame([6600, 3000, 'partial'], [$rows[$unbilled->ID]['charge'], $rows[$unbilled->ID]['paid'], $rows[$unbilled->ID]['status']]);
    }

    public function test_a_bill_shared_by_two_courses_does_not_put_its_whole_total_on_the_header_course(): void
    {
        $student = $this->student();
        $header = $this->course($student, 1, ['Charge' => 5000]);
        $member = $this->course($student, 2, ['Charge' => 5000]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $header->ID, 'IssueDate' => now()->toDateString(),
            'TotalAmount' => 10000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $header->ID, 'Description' => 'A', 'Amount' => 5000]);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $member->ID, 'Description' => 'B', 'Amount' => 5000]);

        $rows = collect($this->getJson('/api/v1/parent/billing-history', $this->bearer($student))->assertOk()->json('records'))
            ->keyBy('student_class_id');

        $this->assertSame([5000, 5000], [$rows[$header->ID]['charge'], $rows[$member->ID]['charge']], 'NT$10,000 billed once, not 10,000 + 5,000');
    }
}
