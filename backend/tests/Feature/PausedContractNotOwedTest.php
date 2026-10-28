<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In-app #440, Founder 2026-10-29 (option A): a plain-paused contract (Stop=1, no close reason) that never started - no
 * payment, no bill, no session other than a cancelled one - is not listed as owed while paused and is listed again on resume.
 * Closed contracts and everything that already started keep the F7 S3a rule (docs/DIRECTOR_PAYMENT_ALERT_RULES.md).
 */
class PausedContractNotOwedTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['LoginName' => 'pp-' . uniqid() . '@example.com', 'Name' => '帳務', 'PSW' => 'secret', 'type' => 'D', 'phone' => '0900000000']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addHour()]);
        $this->headers = ['Authorization' => "Bearer {$token}"];
    }

    /** The production shape of #440: 8 sessions at 1,300, paused with every future session cancelled. */
    private function paused(array $o = []): StudentClass
    {
        $student = Student::create(['name' => '暫停測試生', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => 'T', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $c = StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-10-15', 'EndDate' => '2026-12-10', 'TotalHours' => 16, 'Charge' => 10400, 'Rate' => 1300, 'Paid' => 0, 'MDate' => now(),
            'Stop' => 1, 'closed_reason' => null, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $o));
        foreach (['2026-10-17', '2026-10-24'] as $date) {
            $this->addSession($c, $date, 'cancelled');
        }

        return $c;
    }

    private function addSession(StudentClass $c, string $date, string $status): ClassSession
    {
        return ClassSession::create(['StudentClassID' => $c->ID, 'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => $status]);
    }

    private function listedInTuition(StudentClass $c): ?array
    {
        return collect($this->getJson('/api/v1/alerts/tuition?branch_id=1', $this->headers)->assertOk()->json())
            ->first(fn ($r) => (int) ($r['id'] ?? 0) === (int) $c->ID);
    }

    private function settledRow(StudentClass $c): ?array
    {
        return collect($this->getJson("/api/v1/accounting/settled-courses?branch_id=1&course_id={$c->ID}", $this->headers)->assertOk()->json('data'))->first();
    }

    public function test_a_paused_contract_that_never_started_is_owed_nowhere(): void
    {
        $c = $this->paused();

        $this->assertNull($this->listedInTuition($c));
        $this->assertNull($this->settledRow($c));
        $this->assertSame(0, $this->getJson('/api/v1/accounting/settled-courses?branch_id=1', $this->headers)->json('summary.pending_reconciliation_count'));
        $this->assertTrue($c->isUnstartedPause());
    }

    public function test_it_is_listed_again_on_resume(): void
    {
        $c = $this->paused();
        $this->assertNull($this->listedInTuition($c));

        $c->update(['Stop' => 0]);
        $this->addSession($c, '2026-11-07', 'scheduled'); // resuming restores the cancelled lessons
        $row = $this->listedInTuition($c);
        $this->assertNotNull($row);
        $this->assertSame(10400, $row['outstanding']);
        $this->assertFalse($c->fresh()->isUnstartedPause());
    }

    /** @dataProvider startedPauses */
    public function test_a_pause_that_already_started_keeps_the_owed_rule(string $case): void
    {
        $c = $this->paused();
        match ($case) {
            'held session' => $this->addSession($c, '2026-10-17', 'attended'),
            'scheduled session left on the pause' => $this->addSession($c, '2026-11-07', 'scheduled'),
            'leave session' => $this->addSession($c, '2026-10-31', 'leave'),
            'open bill' => Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2026-10-15', 'DueDate' => '2026-10-25',
                'TotalAmount' => 10400, 'PaidAmount' => 0, 'Status' => 'unpaid']),
            'pending payment report' => PaymentReport::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'reported_by_name' => 'S',
                'payment_date' => '2026-10-16', 'payment_method' => 'cash', 'reported_amount' => 3000, 'status' => 'pending',
                'report_token_hash' => hash('sha256', uniqid('t', true)), 'token_expires_at' => Carbon::now()->addDay()]),
            'closed contract' => $c->update(['closed_reason' => 'completed']),
        };

        $this->assertNotNull($this->listedInTuition($c), $case);
        $this->assertNotNull($this->settledRow($c), $case);
        $this->assertFalse($c->fresh()->isUnstartedPause(), $case);
    }

    public static function startedPauses(): array
    {
        return array_map(fn ($c) => [$c], ['held session', 'scheduled session left on the pause', 'leave session', 'open bill', 'pending payment report', 'closed contract']);
    }

    public function test_a_voided_bill_does_not_count_as_a_bill(): void
    {
        $c = $this->paused();
        Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2026-10-15', 'DueDate' => '2026-10-25',
            'TotalAmount' => 10400, 'PaidAmount' => 0, 'Status' => 'void']);

        $this->assertTrue($c->isUnstartedPause());
        $this->assertNull($this->listedInTuition($c));
    }

    public function test_a_paid_pause_is_not_an_unstarted_pause(): void
    {
        $c = $this->paused(['Paid' => 1, 'PayDate' => '2026-10-15']);

        $this->assertFalse($c->isUnstartedPause());
        $this->assertNotNull($this->settledRow($c)); // listed in 已結清 as before
    }

    public function test_shared_package_members_keep_the_rule_and_the_batch_answers_per_course(): void
    {
        $plain = $this->paused();
        $started = $this->paused();
        $this->addSession($started, '2026-10-17', 'completed');
        $member = $this->paused();
        $package = CoursePackage::create(['student_id' => $member->StudentID, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => 'count', 'total_sessions' => 16,
            'remaining_sessions' => 16, 'used_sessions' => 0, 'rate' => 550, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true]);
        $member->update(['PackageID' => $package->id]);
        $resumed = $this->paused(['Stop' => 0]);

        $ids = StudentClass::unstartedPauseIds([$plain->ID, $started->ID, $member->ID, $resumed->ID]);

        $this->assertSame([(int) $plain->ID], array_keys($ids));
    }
}
