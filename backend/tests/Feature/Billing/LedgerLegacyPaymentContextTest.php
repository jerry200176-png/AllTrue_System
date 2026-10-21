<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\CoursePackage;
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

    /** #4150 review round 2: the real waiver shape (waiveCourse() requires Paid=0 and voids open bills) is flagged too. */
    public function test_a_real_paid_zero_waiver_is_flagged_waived_and_not_paid(): void
    {
        $student = $this->student();
        $course = $this->course($student, ['ScheduleMode' => 'count', 'Charge' => 5000, 'Paid' => 0, 'Stop' => 1]);
        // Same order as waiveCourse(): the open bill is voided, then the contract is closed as waived.
        Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-01', 'Note' => '', 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'void']);
        DB::table('StudentClass')->where('ID', $course->ID)->update(['closed_reason' => 'waived']);

        $row = collect($this->ledger($student))->firstWhere('id', $course->ID);
        $this->assertSame([true, false, null], [$row['waived'], $row['paid'], $row['paid_at']]);
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

    /**
     * in-app #428: the ledger row carries the server-derived trial exemption (StudentClass::isTrialFeeRecordedByDate, the
     * same rule as legacy_paid_flag_only), so the panel does not show 需核對 for a dated paid trial. A trial paid without a
     * date, a regular paid course, a Paid=0 package member, and a member of a trial package that has no paid_at stay unexempted.
     */
    public function test_trial_fee_recorded_by_date_requires_trial_type_and_payment_date(): void
    {
        $student = $this->student();
        $count = ['ScheduleMode' => 'count', 'Charge' => 500, 'SessionCount' => 1, 'RemainingSessions' => 1];
        $datedTrial = $this->course($student, $count + ['ClassType' => 'trial', 'Paid' => 1, 'PayDate' => '2026-10-10']);
        $undatedTrial = $this->course($student, $count + ['ClassType' => 'trial', 'Paid' => 1, 'SubjectID' => 2]);
        $regular = $this->course($student, $count + ['Paid' => 1, 'PayDate' => '2026-10-10', 'SubjectID' => 3]);
        $pkgFields = ['student_id' => $student->id, 'campus_id' => 1, 'billing_mode' => 'count', 'total_sessions' => 1,
            'remaining_sessions' => 1, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'trial',
            'paid' => true, 'stop' => false, 'enabled' => true];
        $datedPkg = CoursePackage::create($pkgFields + ['name' => 'T1', 'paid_at' => '2026-10-10']);
        $undatedPkg = CoursePackage::create($pkgFields + ['name' => 'T2']);
        $datedMember = $this->course($student, $count + ['PackageID' => $datedPkg->id, 'SubjectID' => 4]);
        $undatedMember = $this->course($student, $count + ['ClassType' => 'trial', 'PackageID' => $undatedPkg->id, 'SubjectID' => 5]);

        $courses = collect($this->ledger($student));
        $flag = fn (StudentClass $c) => $courses->firstWhere('id', $c->ID)['trial_fee_recorded_by_date'];

        $this->assertTrue($flag($datedTrial));
        $this->assertFalse($flag($undatedTrial), 'a trial marked paid without a date stays 需核對');
        $this->assertFalse($flag($regular), '#4174 unchanged for a regular course');
        // #4339 review: the ledger serializes `paid` from the course's own Paid, so a Paid=0 member of a paid trial package is
        // not flagged: its card keeps the unpaid state it had before #428 rather than a recorded payment beside 還沒開帳單.
        $member = $courses->firstWhere('id', $datedMember->ID);
        $this->assertSame([false, false, null], [$member['paid'], $member['trial_fee_recorded_by_date'], $member['paid_at']]);
        $this->assertFalse($flag($undatedMember), 'a trial package marked paid without paid_at stays unexempted');
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
