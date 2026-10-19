<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceArAgingTest extends TestCase
{
    use RefreshDatabase;

    private function course(Student $student, array $over = []): StudentClass
    {
        return StudentClass::create($over + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => 1, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(100)->toDateString(),
            'TotalHours' => 10, 'SessionCount' => 5, 'SessionDuration' => 120,
            'RemainingSessions' => 5, 'UsedSessions' => 0,
            'Charge' => 5000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 100, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
    }

    private function invoice(StudentClass $course, int $total, int $paid, int $daysAgo): Invoice
    {
        $invoice = Invoice::create([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'IssueDate' => now()->subDays($daysAgo)->toDateString(),
            'TotalAmount' => $total, 'PaidAmount' => $paid,
            'Status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);
        if ($paid > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => now(), 'Method' => 'cash']);
        }

        return $invoice;
    }

    private function student(Campus $campus, string $name): Student
    {
        return Student::create(['name' => $name, 'CampusID' => $campus->id, 'ClassID' => 0, 'SchoolName' => 'Test School']);
    }

    public function test_buckets_come_from_invoices_and_payments_aged_by_oldest_open_invoice(): void
    {
        [$token, $campus] = $this->seedDirector();
        $this->invoice($this->course($this->student($campus, 'Partial')), 5000, 2000, 45);

        $r = $this->getJson('/api/v1/finance/ar-aging?branch_id=' . $campus->id, $this->bearer($token))->assertOk();
        $this->assertEquals(3000, $r->json('totals.grand_total'));
        $this->assertEquals(3000, $r->json('students.0.thirty'));
        $this->assertEquals(now()->subDays(45)->toDateString(), $r->json('students.0.oldest_unpaid_date'));
    }

    public function test_stopped_contract_debt_is_still_receivable(): void
    {
        // The 宥翰 bug: a closed contract with an unpaid invoice must not vanish from AR.
        [$token, $campus] = $this->seedDirector();
        $this->invoice($this->course($this->student($campus, 'Closed'), ['Stop' => 1, 'closed_reason' => 'settled_pending']), 4950, 0, 95);

        $r = $this->getJson('/api/v1/finance/ar-aging?branch_id=' . $campus->id, $this->bearer($token))->assertOk();
        $this->assertEquals(4950, $r->json('students.0.ninety_plus'));
    }

    public function test_excludes_paid_waived_tutoring_and_unbilled(): void
    {
        [$token, $campus] = $this->seedDirector();
        $s = $this->student($campus, 'Mixed');
        $this->invoice($this->course($s), 3000, 3000, 40);
        $waived = $this->course($s, ['Stop' => 1]);
        $this->invoice($waived, 3000, 0, 40);
        DB::table('StudentClass')->where('ID', $waived->ID)->update(['closed_reason' => 'waived']);
        $this->invoice($this->course($s, ['ClassType' => '  TUTORING  ']), 3000, 0, 40);
        $this->course($s, ['Charge' => 9000, 'Pay' => 0]); // legacy Charge>Pay with no invoice is not AR
        $this->invoice($this->course($s), 1200, 0, 5);

        $r = $this->getJson('/api/v1/finance/ar-aging?branch_id=' . $campus->id, $this->bearer($token))->assertOk();
        $this->assertEquals(1200, $r->json('totals.grand_total'));
        $this->assertEquals(1200, $r->json('students.0.current'));
    }

    public function test_course_list_exposes_canonical_verdict_without_changing_ar_or_ledger(): void
    {
        [$token, $campus] = $this->seedDirector();
        $student = $this->student($campus, 'Verdict controls');
        $stopped = $this->course($student, ['Stop' => 1]);
        $this->invoice($stopped, 3000, 0, 40);
        $partial = $this->course($student);
        $this->invoice($partial, 2000, 800, 10);
        $unbilled = $this->course($student, ['Charge' => 9999]);
        $waived = $this->course($student, ['Stop' => 1]);
        $this->invoice($waived, 500, 0, 20);
        DB::table('StudentClass')->where('ID', $waived->ID)->update(['closed_reason' => 'waived']);
        $tutoring = $this->course($student, ['ClassType' => ' TUTORING ']);
        $this->invoice($tutoring, 500, 0, 20);
        $voided = $this->course($student);
        $invoice = $this->invoice($voided, 700, 0, 20);
        $invoice->update(['Status' => 'void']);
        $other = $this->course($this->student(CampusFactory::new()->create(), 'Other campus'));
        $this->invoice($other, 9000, 0, 20);
        $before = [Invoice::count(), Payment::count(), DB::table('Payment')->sum('Amount')];

        $listed = collect($this->getJson('/api/v1/student-classes?campus_id=' . $campus->id . '&per_page=1000', $this->bearer($token))
            ->assertOk()->json('data'))->keyBy('ID');
        $this->assertCount(6, $listed);
        $this->assertFalse($listed->has($other->ID));
        $this->assertSame(['status' => 'unpaid', 'source' => 'invoice', 'outstanding' => 3000], $listed[$stopped->ID]['billing_verdict']);
        $this->assertSame(['status' => 'partial', 'source' => 'invoice', 'outstanding' => 1200], $listed[$partial->ID]['billing_verdict']);
        foreach ([$unbilled, $voided] as $course) {
            $this->assertSame('unbilled', $listed[$course->ID]['billing_verdict']['status']);
            $this->assertSame('none', $listed[$course->ID]['billing_verdict']['source']);
        }
        $this->assertSame('free', $listed[$tutoring->ID]['billing_verdict']['status']);
        $this->assertSame('waived', $listed[$waived->ID]['closed_reason']);
        $this->getJson('/api/v1/finance/ar-aging?branch_id=' . $campus->id, $this->bearer($token))
            ->assertOk()->assertJsonPath('totals.grand_total', 4200);
        $this->assertSame($before, [Invoice::count(), Payment::count(), DB::table('Payment')->sum('Amount')]);
    }

    private function seedDirector(): array
    {
        $campus = CampusFactory::new()->create();
        $user = User::create([
            'LoginName' => 'dir_ar_' . uniqid() . '@example.com',
            'Name' => 'Director',
            'PSW' => password_hash('s', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900000000',
        ]);
        \App\Models\UserCampus::create([
            'UserID' => $user->id,
            'CampusID' => $campus->id,
            'Approved' => 1,
        ]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return [$tok, $campus];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
