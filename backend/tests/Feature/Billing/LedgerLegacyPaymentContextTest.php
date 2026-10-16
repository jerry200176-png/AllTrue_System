<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4150: the ledger course row carries the server's own waiver (ContractMoneyState::isWaived) and current-term
 * 免費／贈送 (Invoice::freeGiftHolds) verdicts, so the panel never guesses either from legacy fields. Display only.
 */
class LedgerLegacyPaymentContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_waived_contract_keeps_its_legacy_fields_and_is_flagged_waived(): void
    {
        $student = $this->student();
        $waived = $this->course($student, ['ScheduleMode' => 'count', 'Charge' => 5000, 'Paid' => 1, 'PayDate' => '2026-08-01', 'Stop' => 1]);
        DB::table('StudentClass')->where('ID', $waived->ID)->update(['closed_reason' => 'waived']);
        $plain = $this->course($student, ['ScheduleMode' => 'count', 'Charge' => 5000, 'Paid' => 1, 'PayDate' => '2026-08-01']);

        $courses = collect($this->ledger($student));
        $row = $courses->firstWhere('id', $waived->ID);
        $this->assertTrue($row['waived']);
        $this->assertSame('2026-08-01', $row['paid_at']); // the waiver keeps the legacy fields; only the context is added
        $this->assertFalse($courses->firstWhere('id', $plain->ID)['waived']);
    }

    public function test_free_gift_settled_follows_the_current_term_rule(): void
    {
        $student = $this->student();
        $current = $this->course($student);
        $this->giftBill($current, '2026-10');
        $stale = $this->course($student);
        $this->giftBill($stale, '2026-09');
        // A stale gift next to a positive current obligation and a legacy PayDate: the mark settles nothing.
        $owed = $this->course($student, ['PayDate' => '2026-08-01']);
        $this->giftBill($owed, '2026-09');
        Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $owed->ID, 'IssueDate' => '2026-10-01', 'Note' => '', 'TotalAmount' => 3000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-10']);

        $courses = collect($this->ledger($student));
        $this->assertSame([true, true], [$courses->firstWhere('id', $current->ID)['free_gift_settled'], $courses->firstWhere('id', $current->ID)['paid']]);
        $this->assertSame([false, false], [$courses->firstWhere('id', $stale->ID)['free_gift_settled'], $courses->firstWhere('id', $stale->ID)['paid']]);
        $this->assertFalse($courses->firstWhere('id', $owed->ID)['free_gift_settled']);
    }

    /** @return array<int, array<string, mixed>> */
    private function ledger(Student $student): array
    {
        $user = User::create(['LoginName' => 'director_' . uniqid() . '@test.com', 'Name' => 'TestDirector', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $this->withToken($raw)->getJson('/api/v1/accounting/ledger?student_id=' . $student->id)->assertOk()->json('courses');
    }

    private function giftBill(StudentClass $course, string $period): void
    {
        Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => $period . '-01', 'Note' => '', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'paid', 'billing_period' => $period, 'zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]);
    }

    private function student(): Student
    {
        return Student::create(['name' => 'Student ' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0900000000', 'MDT' => now(), 'Notify_Token' => '']);
    }

    /** @param array<string, mixed> $over */
    private function course(Student $student, array $over = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'EndDate' => '2026-12-31', 'TotalHours' => 20, 'Charge' => 0, 'Paid' => 0,
            'Rate' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 0,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $over));
    }
}
