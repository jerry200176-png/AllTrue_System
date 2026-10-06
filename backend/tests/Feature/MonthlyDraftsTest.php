<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyDraftsTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'Asia/Taipei'));
        $user = User::create(['LoginName' => 'drafts@example.com', 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function course(array $o = [], int $campus = 1): StudentClass
    {
        $student = Student::create(['name' => '草稿學生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-08-01', 'EndDate' => '2026-09-10', 'TotalHours' => 20, 'Charge' => 0, 'Paid' => 0,
            'Rate' => 1500, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 0,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
            'settlement_day' => 15, 'monthly_sessions' => 4, 'week' => 3, 'time' => '18:00:00',
        ], $o));
    }

    private function drafts(string $query = '?month=2026-09')
    {
        return $this->withHeaders($this->headers)->getJson('/api/v1/accounting/monthly-drafts' . $query);
    }

    public function test_ready_row_amount_matches_what_renew_monthly_charges(): void
    {
        $course = $this->course();
        $res = $this->drafts()->assertOk()->assertJsonPath('totals.ready', 1);
        $row = $res->json('data.0');
        $this->assertSame((int) $course->ID, $row['student_class_id']);
        $this->assertSame('ready', $row['status']);
        $this->assertSame('2026-09-11', $row['proposed_start_date']);
        $this->assertSame('2026-09-30', $row['proposed_end_date']);
        $this->assertGreaterThan(0, $row['amount']);

        $new = $this->withHeaders($this->headers)
            ->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", ['end_date' => $row['proposed_end_date']])
            ->assertCreated()->json('new_course.id');
        $invoice = Invoice::where('StudentClassID', $new)->firstOrFail();
        $this->assertSame($row['amount'], (int) $invoice->TotalAmount);
        $this->assertSame($row['due_date'], substr((string) $invoice->DueDate, 0, 10));
        $this->assertSame($row['period_sessions'], (int) StudentClass::findOrFail($new)->SessionCount);

        // Renewal now exists -> no longer proposed.
        $this->drafts()->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_attended_session_after_end_date_is_blocked(): void
    {
        $course = $this->course();
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-09-17', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        $this->drafts()->assertOk()->assertJsonPath('totals.blocked', 1)
            ->assertJsonPath('data.0.status', 'blocked')
            ->assertJsonPath('data.0.blocker_code', 'monthly_completed_sessions_outside_contract');
    }

    public function test_lapsed_contract_without_lessons_is_flagged(): void
    {
        $this->course(['EndDate' => '2026-07-31']);
        $this->drafts()->assertOk()->assertJsonPath('totals.lapsed_no_lessons', 1)->assertJsonPath('data.0.proposed_start_date', '2026-08-01');
    }

    public function test_excluded_contracts_and_other_campus_and_month_param(): void
    {
        $this->course(['PackageID' => 5]);
        $this->course(['ClassType' => 'tutoring']);
        $this->course(['ClassType' => 'trial']);
        $this->course(['Stop' => 1]);
        $this->course(['ScheduleMode' => 'count']);
        $this->course([], 2);
        $this->course(['EndDate' => '2026-09-30']); // not before end of month 2026-09
        $this->drafts()->assertOk()->assertJsonCount(0, 'data');

        // Same contract is a candidate for October.
        $this->drafts('?month=2026-10')->assertOk()->assertJsonPath('month', '2026-10')->assertJsonPath('data.0.proposed_end_date', '2026-10-31');
        $this->drafts('?month=bad')->assertStatus(422);
        // Default month is the current one (2026-09).
        $this->drafts('')->assertOk()->assertJsonPath('month', '2026-09');
    }
}
