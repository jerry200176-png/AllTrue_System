<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\ContractMoneyVerdict;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 月對帳表 (Founder 2026-11-17): one campus, one month, one row per student per course. CI only. */
final class MonthlyRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_lists_the_months_lessons_and_money_for_each_course_of_the_directors_campus(): void
    {
        $student = $this->student(1);
        $math = $this->course($student);
        foreach ([['2026-10-27', 'attended'], ['2026-11-03', 'attended'], ['2026-11-10', 'leave'], ['2026-11-17', 'cancelled'], ['2026-11-24', 'scheduled']] as [$date, $status]) {
            ClassSession::create(['StudentClassID' => $math->ID, 'SessionDate' => $date, 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => $status]);
        }
        $bill = $this->bill($math, ['TotalAmount' => 6000, 'PaidAmount' => 1500, 'Status' => 'partial', 'IssueDate' => '2026-11-01']);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 1500, 'PaidAt' => '2026-11-05 10:00:00', 'Method' => 'cash']);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 500, 'PaidAt' => '2026-11-06 10:00:00', 'Method' => 'transfer_in']);
        $this->report($math);
        // No lesson this month but a bill still unpaid: listed. A settled course without lessons: not listed.
        $owing = $this->course($student, ['SubjectID' => 2]);
        $this->bill($owing, ['TotalAmount' => 3000, 'Status' => 'unpaid', 'IssueDate' => '2026-08-01']);
        $settled = $this->course($student, ['SubjectID' => 3]);
        $paidBill = $this->bill($settled, ['TotalAmount' => 3000, 'PaidAmount' => 3000, 'Status' => 'paid', 'IssueDate' => '2026-08-01']);
        Payment::create(['InvoiceID' => $paidBill->id, 'Amount' => 3000, 'PaidAt' => '2026-08-05 10:00:00', 'Method' => 'cash']);
        // Marked paid with no Payment row behind it: still owes, so listed.
        $markedOnly = $this->course($student, ['SubjectID' => 4]);
        $this->bill($markedOnly, ['TotalAmount' => 3000, 'PaidAmount' => 3000, 'Status' => 'paid', 'IssueDate' => '2026-08-01']);
        $elsewhere = $this->course($this->student(2));
        ClassSession::create(['StudentClassID' => $elsewhere->ID, 'SessionDate' => '2026-11-04', 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'attended']);

        $res = $this->withToken($this->director(1))->getJson('/api/v1/accounting/monthly-roster?year=2026&month=11')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $courses = collect($res->json('data.0.courses'))->keyBy('course_id');
        $this->assertSame([$math->ID, $owing->ID, $markedOnly->ID], $courses->keys()->sort()->values()->all());
        $row = $courses[$math->ID];
        $this->assertSame([['2026-11-03', 'held'], ['2026-11-10', 'off'], ['2026-11-24', 'upcoming']],
            array_map(fn ($l) => [$l['date'], $l['kind']], $row['lessons']));
        $this->assertSame(1, $row['held']);
        $this->assertSame([['date' => '2026-11-05', 'amount' => 1500, 'method' => 'cash']], $row['paid_this_month']);
        $this->assertSame(1, $row['pending_reports']);
        // Same verdict as the student ledger, so the two screens never disagree.
        $verdict = app(ContractMoneyVerdict::class)->forCourse($math->fresh());
        $this->assertSame([$verdict->status, $verdict->outstanding], [$row['money']['status'], $row['money']['outstanding']]);
        $this->assertGreaterThan(0, $courses[$owing->ID]['money']['outstanding']);
        $this->assertGreaterThan(0, $courses[$markedOnly->ID]['money']['outstanding']);
        $this->assertSame($courses->sum('money.outstanding'), $res->json('summary.outstanding'));
        $this->assertSame([1, 3, 1500, 1], [$res->json('summary.students'), $res->json('summary.courses'), $res->json('summary.paid_this_month'), $res->json('summary.pending_reports')]);
    }

    public function test_reads_lessons_prices_and_money_the_way_the_other_screens_do(): void
    {
        $student = $this->student(1);
        // A trial lesson the student came to, and one signed in at the door but still stored as scheduled: both held.
        $trial = $this->course($student, ['ClassType' => 'trial']);
        ClassSession::create(['StudentClassID' => $trial->ID, 'SessionDate' => '2026-11-05', 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'trial']);
        $signed = ClassSession::create(['StudentClassID' => $trial->ID, 'SessionDate' => '2026-11-12', 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled']);
        DB::table('StudentSingIn')->insert(['StudentClassID' => $trial->ID, 'ClassSessionID' => $signed->id, 'StudentID' => $student->id, 'TeacherID' => 99, 'Status' => 'present', 'SignInDT' => '2026-11-12 10:00:00']);
        // An October bill collected in November: listed for its money; a reversal stored as a positive 'void' counts negative.
        $old = $this->course($student, ['SubjectID' => 2]);
        $oldBill = $this->bill($old, ['TotalAmount' => 3000, 'PaidAmount' => 3000, 'Status' => 'paid', 'IssueDate' => '2026-10-01']);
        foreach ([[3000, 'cash', '2026-11-08'], [1000, 'cash', '2026-11-09'], [1000, 'void', '2026-11-10']] as [$amount, $method, $day]) {
            Payment::create(['InvoiceID' => $oldBill->id, 'Amount' => $amount, 'PaidAt' => $day . ' 10:00:00', 'Method' => $method]);
        }
        // Price changed mid-month: no single 單價. Changed before the month: the new price, as on the payment slip.
        $repriced = $this->course($student, ['SubjectID' => 3]);
        $amended = $this->course($student, ['SubjectID' => 4]);
        // One student cannot have two lessons at the same time (SlotOccupiedException), so each course gets its own day.
        foreach ([[$repriced, '2026-11-15', '2026-11-20'], [$amended, '2026-10-15', '2026-11-27']] as [$course, $from, $day]) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $day, 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled']);
            DB::table('student_class_pricing_amendments')->insert(['student_class_id' => $course->ID, 'effective_from' => $from, 'rate' => 1800,
                'rate_unit' => 'session', 'source_reference' => 'roster-test', 'reason' => 'test', 'created_at' => now()]);
        }
        // A settled bill with no period, issued in November and paid in October: November's bill, so listed.
        $legacy = $this->course($student, ['SubjectID' => 6]);
        $legacyBill = $this->bill($legacy, ['TotalAmount' => 2000, 'PaidAmount' => 2000, 'Status' => 'paid', 'IssueDate' => '2026-11-03', 'billing_period' => null]);
        Payment::create(['InvoiceID' => $legacyBill->id, 'Amount' => 2000, 'PaidAt' => '2026-10-30 10:00:00', 'Method' => 'cash']);
        // A stopped course: its leftover scheduled row is hidden (as on the attendance screen), its held lesson stays.
        $stopped = $this->course($student, ['SubjectID' => 7, 'Stop' => 1]);
        $stale = $this->course($student, ['SubjectID' => 8, 'Stop' => 1]);
        ClassSession::create(['StudentClassID' => $stopped->ID, 'SessionDate' => '2026-11-04', 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'attended']);
        foreach ([$stopped, $stale] as $course) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-11-25', 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled']);
        }
        // A Monday course whose lessons are not written yet: the calendar's 預排.
        $planned = $this->course($student, ['SubjectID' => 5, 'StartDate' => '2026-11-02', 'week' => 1, 'time' => '10:00:00']);

        $res = $this->withToken($this->director(1))->getJson('/api/v1/accounting/monthly-roster?year=2026&month=11')->assertOk();

        $courses = collect($res->json('data.0.courses'))->keyBy('course_id');
        $this->assertSame([['2026-11-05', 'held', 'trial'], ['2026-11-12', 'held', 'attended']],
            array_map(fn ($l) => [$l['date'], $l['kind'], $l['status']], $courses[$trial->ID]['lessons']));
        $this->assertSame(2, $courses[$trial->ID]['held']);
        $this->assertSame([3000, 1000, -1000], array_column($courses[$old->ID]['paid_this_month'], 'amount'));
        $this->assertSame(3000, $res->json('summary.paid_this_month'));
        $this->assertTrue($courses->has($legacy->ID));
        $this->assertSame([['2026-11-04', 'held']], array_map(fn ($l) => [$l['date'], $l['kind']], $courses[$stopped->ID]['lessons']));
        $this->assertFalse($courses->has($stale->ID));
        $this->assertNull($courses[$repriced->ID]['rate']);
        $this->assertSame(1800, $courses[$amended->ID]['rate']);
        $this->assertContains(['2026-11-23', 'upcoming', 'projected'],
            array_map(fn ($l) => [$l['date'], $l['kind'], $l['status']], $courses[$planned->ID]['lessons']));
    }

    public function test_a_receipt_moved_to_another_course_counts_once_on_the_new_course(): void
    {
        $student = $this->student(1);
        [$from, $to] = [$this->course($student), $this->course($student, ['SubjectID' => 2])];
        $fromBill = $this->bill($from, ['TotalAmount' => 8000, 'Status' => 'void', 'billing_period' => '2026-10']);
        $toBill = $this->bill($to, ['TotalAmount' => 8000, 'PaidAmount' => 8000, 'Status' => 'paid', 'billing_period' => '2026-10']);
        $original = Payment::create(['InvoiceID' => $fromBill->id, 'Amount' => 8000, 'PaidAt' => '2026-10-20 10:00:00', 'Method' => 'cash']);
        $receipt = fn (StudentClass $c, Invoice $bill, string $status, ?int $paymentId, string $hash) => DB::table('payment_reports')->insertGetId([
            'StudentID' => $student->id, 'StudentClassID' => $c->ID, 'InvoiceID' => $bill->id, 'reported_by_name' => 'parent',
            'payment_date' => '2026-10-20', 'payment_method' => 'cash', 'reported_amount' => 8000, 'status' => $status, 'payment_id' => $paymentId,
            'report_token_hash' => str_repeat($hash, 64), 'token_expires_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $old = $receipt($from, $fromBill, 'voided', $original->id, 'e');
        $new = $receipt($to, $toBill, 'confirmed', null, 'f');
        // What ReceiptReassignService::write() leaves: a reversal dated the day of the move, a replacement at the original date.
        Payment::create(['InvoiceID' => $fromBill->id, 'Amount' => -8000, 'PaidAt' => '2026-11-10', 'Method' => 'void', 'Note' => '[receipt_reassign:1] 更正移轉', 'payment_report_id' => $old]);
        Payment::create(['InvoiceID' => $toBill->id, 'Amount' => 8000, 'PaidAt' => '2026-10-20', 'Method' => 'cash', 'Note' => '[receipt_reassign:1] 更正移轉自收據', 'payment_report_id' => $new]);
        DB::table('payment_report_reassignments')->insert(['from_report_id' => $old, 'to_report_id' => $new, 'from_invoice_id' => $fromBill->id,
            'to_invoice_id' => $toBill->id, 'from_student_id' => $student->id, 'to_student_id' => $student->id, 'campus_id' => 1, 'amount' => 8000,
            'reason' => '選錯課', 'idempotency_key' => 'roster-move', 'actor_user_id' => 1, 'created_at' => now()]);
        $token = $this->director(1);

        $this->assertSame(0, $this->withToken($token)->getJson('/api/v1/accounting/monthly-roster?year=2026&month=11')->assertOk()->json('summary.paid_this_month'));
        $oct = $this->withToken($token)->getJson('/api/v1/accounting/monthly-roster?year=2026&month=10')->assertOk();
        $this->assertSame(8000, $oct->json('summary.paid_this_month'));
        $courses = collect($oct->json('data.0.courses'))->keyBy('course_id');
        $this->assertSame([8000], array_column($courses[$to->ID]['paid_this_month'], 'amount'));
        $this->assertFalse($courses->has($from->ID));
    }

    public function test_an_older_bill_is_listed_only_while_it_really_owes(): void
    {
        $student = $this->student(1);
        [$settled, $owing] = [$this->course($student), $this->course($student, ['SubjectID' => 2])];
        // Stored Status still says unpaid, but the payment rows settle it (Billing/PaidStatusParityTest stored_zero_pay_full).
        $stale = $this->bill($settled, ['TotalAmount' => 3000, 'Status' => 'unpaid', 'billing_period' => '2026-09', 'IssueDate' => '2026-09-01']);
        Payment::create(['InvoiceID' => $stale->id, 'Amount' => 3000, 'PaidAt' => '2026-09-05 10:00:00', 'Method' => 'cash']);
        $this->bill($owing, ['TotalAmount' => 3000, 'Status' => 'unpaid', 'billing_period' => '2026-09', 'IssueDate' => '2026-09-01']);

        $res = $this->withToken($this->director(1))->getJson('/api/v1/accounting/monthly-roster?year=2026&month=11')->assertOk();

        $courses = collect($res->json('data.0.courses'))->keyBy('course_id');
        $this->assertFalse($courses->has($settled->ID));
        $this->assertGreaterThan(0, $courses[$owing->ID]['money']['outstanding']);
    }

    public function test_a_paid_mark_with_no_bill_or_payment_says_check_it_except_a_dated_trial_fee(): void
    {
        $legacy = $this->course($this->student(1), ['Paid' => 1]);
        $trial = $this->course($this->student(1), ['Paid' => 1, 'ClassType' => 'trial', 'PayDate' => '2026-11-02']);
        foreach ([[$legacy, '2026-11-04'], [$trial, '2026-11-06']] as [$course, $day]) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $day, 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'attended']);
        }

        $res = $this->withToken($this->director(1))->getJson('/api/v1/accounting/monthly-roster?year=2026&month=11')->assertOk();

        $money = collect($res->json('data'))->flatMap(fn ($s) => $s['courses'])->keyBy('course_id')->map(fn ($c) => $c['money']);
        $this->assertSame(['paid', true], [$money[$legacy->ID]['status'], $money[$legacy->ID]['legacy_unverified']]);
        $this->assertSame(['paid', false], [$money[$trial->ID]['status'], $money[$trial->ID]['legacy_unverified']]);
    }

    public function test_a_waived_contract_owes_nothing_here(): void
    {
        $waived = $this->course($this->student(1));
        ClassSession::create(['StudentClassID' => $waived->ID, 'SessionDate' => '2026-11-04', 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'attended']);
        DB::table('StudentClass')->where('ID', $waived->ID)->update(['closed_reason' => 'waived']); // after the lesson: a waived course locks its sessions

        $res = $this->withToken($this->director(1))->getJson('/api/v1/accounting/monthly-roster?year=2026&month=11')->assertOk();

        $this->assertSame(['waived', 0], [$res->json('data.0.courses.0.money.status'), $res->json('data.0.courses.0.money.outstanding')]);
        $this->assertSame(0, $res->json('summary.outstanding'));
    }

    public function test_one_campus_at_a_time_and_directors_only(): void
    {
        $elsewhere = $this->course($this->student(2));
        ClassSession::create(['StudentClassID' => $elsewhere->ID, 'SessionDate' => '2026-11-04', 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'attended']);
        $url = '/api/v1/accounting/monthly-roster?year=2026&month=11';

        $this->getJson($url)->assertUnauthorized();
        $this->withToken($this->user('T'))->getJson($url)->assertForbidden();
        $this->flushHeaders()->withToken($this->director(1))->getJson($url . '&branch_id=2')->assertForbidden();
        $this->flushHeaders()->withToken($this->user('S'))->getJson($url)->assertStatus(422);
        $this->flushHeaders()->withToken($this->user('S'))->getJson($url . '&branch_id=2')->assertOk()->assertJsonPath('data.0.courses.0.course_id', $elsewhere->ID);
        $this->flushHeaders()->withToken($this->director(1))->getJson('/api/v1/accounting/monthly-roster?year=2026&month=13')->assertStatus(422);
    }

    public function test_tuition_counts_only_the_lessons_a_bill_charges(): void
    {
        // 到班 + 遲到 count; 值班, 請假, 試聽有到, 輔導有到 and lessons still to come do not (Jerry 2026-11-18).
        $course = $this->course($this->student(1));
        foreach ([['03', 'attended'], ['04', 'late'], ['05', 'duty'], ['06', 'leave'], ['07', 'trial'], ['08', 'tutoring_attend'], ['24', 'scheduled']] as [$day, $status]) {
            $this->lesson($course, "2026-11-$day", $status);
        }
        // A 輔導 course owes nothing (#325), even for a lesson stored as attended.
        $tutoring = $this->course($this->student(1), ['ClassType' => 'tutoring']);
        $this->lesson($tutoring, '2026-11-10', 'attended');

        $res = $this->withToken($this->director(1))->getJson('/api/v1/accounting/monthly-roster?year=2026&month=11')->assertOk();

        $rows = collect($res->json('data'))->flatMap(fn ($s) => $s['courses'])->keyBy('course_id');
        $this->assertSame([2, 3000], [$rows[$course->ID]['tuition_lessons'], $rows[$course->ID]['tuition']]);
        $this->assertSame([0, 0], [$rows[$tutoring->ID]['tuition_lessons'], $rows[$tutoring->ID]['tuition']]);
        $this->assertSame([3000, 0], [$res->json('summary.tuition_total'), $res->json('summary.tuition_unpriced')]);
    }

    /**
     * Until the old 當月學收 report is removed: on lessons stored as attended or late (no sign-in, no price change), the
     * roster's 學收合計 equals the report's total — course rate, a course priced by its total, a monthly contract's
     * dates (#2451) and a monthly package's rate.
     */
    public function test_tuition_total_equals_the_old_report_on_the_same_lessons(): void
    {
        $byRate = $this->course($this->student(1));
        $this->lesson($byRate, '2026-11-03', 'attended');
        $this->lesson($byRate, '2026-11-10', 'late');
        $byTotal = $this->course($this->student(1), ['Rate' => 0, 'Charge' => 6000, 'SessionCount' => 4]);
        $this->lesson($byTotal, '2026-11-04', 'attended');
        $monthly = $this->course($this->student(1), ['ScheduleMode' => 'date', 'StartDate' => '2026-11-10', 'EndDate' => '2026-11-30', 'SessionCount' => 0]);
        $this->lesson($monthly, '2026-11-02', 'attended'); // before the contract: not this contract's lesson
        $this->lesson($monthly, '2026-11-16', 'attended');
        $member = $this->student(1);
        $package = CoursePackage::create(['student_id' => $member->id, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => CoursePackage::BILLING_MODE_MONTHLY,
            'rate' => 700, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
            'total_sessions' => 0, 'remaining_sessions' => 0, 'used_sessions' => 0]);
        $inPackage = $this->course($member, ['PackageID' => $package->id]);
        $this->lesson($inPackage, '2026-11-05', 'attended');
        $this->lesson($inPackage, '2026-11-12', 'attended');
        $token = $this->director(1);

        $res = $this->withToken($token)->getJson('/api/v1/accounting/monthly-roster?year=2026&month=11')->assertOk();
        $old = $this->withToken($token)->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=11')->assertOk();

        $rows = collect($res->json('data'))->flatMap(fn ($s) => $s['courses'])->keyBy('course_id');
        $this->assertSame([3000, 1500, 1500, 1400], [$rows[$byRate->ID]['tuition'], $rows[$byTotal->ID]['tuition'], $rows[$monthly->ID]['tuition'], $rows[$inPackage->ID]['tuition']]);
        $this->assertSame(7400, $res->json('summary.tuition_total'));
        $this->assertEquals($old->json('summary.total_tuition'), $res->json('summary.tuition_total'));
    }

    public function test_tuition_prices_an_hourly_package_by_length_and_a_course_priced_by_its_total_without_rounding_each_lesson(): void
    {
        // A monthly package priced by the hour, as the monthly bill prices it: one 2-hour lesson at 700/hour = 1,400.
        $member = $this->student(1);
        $package = CoursePackage::create(['student_id' => $member->id, 'campus_id' => 1, 'name' => 'H', 'billing_mode' => CoursePackage::BILLING_MODE_MONTHLY,
            'rate' => 700, 'rate_unit' => 'hour', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
            'total_sessions' => 0, 'remaining_sessions' => 0, 'used_sessions' => 0]);
        $hourly = $this->course($member, ['PackageID' => $package->id]);
        $this->lesson($hourly, '2026-11-05', 'attended'); // 10:00-12:00
        // 10,000 for 12 lessons, 3 held: 2,500 (rounding 833.33 per lesson first would give 2,499).
        $byTotal = $this->course($this->student(1), ['Rate' => 0, 'Charge' => 10000, 'SessionCount' => 12]);
        foreach (['03', '10', '17'] as $day) {
            $this->lesson($byTotal, "2026-11-$day", 'attended');
        }

        $res = $this->withToken($this->director(1))->getJson('/api/v1/accounting/monthly-roster?year=2026&month=11')->assertOk();

        $rows = collect($res->json('data'))->flatMap(fn ($s) => $s['courses'])->keyBy('course_id');
        $this->assertSame([1, 1400], [$rows[$hourly->ID]['tuition_lessons'], $rows[$hourly->ID]['tuition']]);
        $this->assertSame([3, 2500], [$rows[$byTotal->ID]['tuition_lessons'], $rows[$byTotal->ID]['tuition']]);
        $this->assertSame(3900, $res->json('summary.tuition_total'));
    }

    private function lesson(StudentClass $course, string $date, string $status): void
    {
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => $status]);
    }

    private function student(int $campusId): Student
    {
        return Student::create(['name' => '對帳學生', 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function course(Student $student, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-10-01', 'EndDate' => '2026-12-31', 'TotalHours' => 8, 'Charge' => 6000, 'Paid' => 0, 'Rate' => 1500,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 4, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $overrides));
    }

    private function bill(StudentClass $course, array $overrides): Invoice
    {
        return Invoice::create(array_merge(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'DueDate' => '2026-11-30', 'IssueDate' => '2026-10-01', 'PaidAmount' => 0], $overrides));
    }

    private function report(StudentClass $course): void
    {
        DB::table('payment_reports')->insert(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'reported_by_name' => 'parent',
            'payment_date' => '2026-11-15', 'payment_method' => 'transfer', 'reported_amount' => 4500, 'status' => 'pending',
            'report_token_hash' => str_repeat('c', 64), 'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function director(int $campusId): string
    {
        return $this->user('A', $campusId);
    }

    private function user(string $type, ?int $campusId = null): string
    {
        $user = User::create(['LoginName' => 'roster-' . uniqid() . '@test.com', 'Name' => 'R', 'PSW' => 'secret', 'type' => $type, 'phone' => 912000005]);
        if ($campusId !== null) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }
}
