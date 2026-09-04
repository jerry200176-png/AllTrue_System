<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\PackageDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualSessionBookingTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private Student $student;
    private StudentClass $course;

    protected function setUp(): void
    {
        parent::setUp();

        $teacher = User::create([
            'LoginName' => 'manual-teacher-' . uniqid() . '@example.com',
            'Name' => 'Manual teacher',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0900000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $director = User::create([
            'LoginName' => 'manual-director-' . uniqid() . '@example.com',
            'Name' => 'Manual director',
            'PSW' => 'secret',
            'type' => 'D',
            'phone' => '0900000001',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);

        $this->student = Student::create([
            'name' => 'Manual student', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $this->course = StudentClass::create([
            'StudentID' => $this->student->id,
            'TeacherID' => $teacher->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'ScheduleMode' => 'count',
            'scheduling_policy' => 'manual_occurrence',
            'SessionCount' => 3,
            'RemainingSessions' => 3,
            'UsedSessions' => 0,
            'SessionDuration' => 60,
            'Rate' => 500,
            'TotalHours' => 3,
            'Charge' => 1500,
            'StartDate' => Carbon::today()->toDateString(),
            'EndDate' => Carbon::today()->addMonths(3)->toDateString(),
            'Stop' => 0,
            'by1' => 1,
            'MDate' => now(),
        ]);
    }

    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ];
    }

    public function test_manual_booking_is_one_at_a_time_idempotent_and_does_not_deduct(): void
    {
        $date = Carbon::today()->addDays(7)->toDateString();
        $payload = ['session_date' => $date, 'start_time' => '16:00'];

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", $payload)
            ->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('available_sessions', 3);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions", $payload)
            ->assertCreated()
            ->assertJsonPath('created', true);

        $this->assertDatabaseCount('ClassSession', 1);
        $this->assertSame(3, (int) $this->course->fresh()->RemainingSessions);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions", $payload)
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertDatabaseCount('ClassSession', 1);
    }

    public function test_future_reservations_consume_booking_capacity_but_not_remaining_balance(): void
    {
        foreach ([7, 14, 21] as $days) {
            ClassSession::create([
                'StudentClassID' => $this->course->ID,
                'SessionDate' => Carbon::today()->addDays($days)->toDateString(),
                'StartTime' => '16:00:00',
                'EndTime' => '17:00:00',
                'Status' => 'scheduled',
            ]);
        }

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", [
                'session_date' => Carbon::today()->addDays(28)->toDateString(),
                'start_time' => '16:00',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'RESERVATION_LIMIT');
        $this->assertSame(3, (int) $this->course->fresh()->RemainingSessions);
    }

    public function test_shared_package_manual_booking_does_not_reserve_pool_for_future_plans(): void
    {
        $package = CoursePackage::create([
            'student_id' => $this->student->id,
            'campus_id' => 1,
            'name' => '共用方案測試',
            'billing_mode' => 'count',
            'total_sessions' => 3,
            'remaining_sessions' => 3,
            'used_sessions' => 0,
            'rate' => 500,
            'rate_unit' => 'session',
            'class_type' => 'one_on_one',
            'paid' => true,
            'stop' => false,
            'enabled' => true,
        ]);

        $member = $this->course->replicate();
        $member->ID = null;
        $member->PackageID = $package->id;
        $member->scheduling_policy = 'manual_occurrence';
        $member->save();
        $this->course->PackageID = $package->id;
        $this->course->save();

        $firstDate = Carbon::today()->addDays(7)->toDateString();
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", [
                'session_date' => $firstDate,
                'start_time' => '16:00',
            ])
            ->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('remaining_sessions', 3)
            ->assertJsonPath('available_sessions', 3);

        foreach ([7, 14, 21] as $days) {
            ClassSession::create([
                'StudentClassID' => $member->ID,
                'SessionDate' => Carbon::today()->addDays($days)->toDateString(),
                'StartTime' => '18:00:00',
                'EndTime' => '19:00:00',
                'Status' => 'scheduled',
            ]);
        }

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", [
                'session_date' => Carbon::today()->addDays(21)->toDateString(),
                'start_time' => '16:00',
            ])
            ->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('remaining_sessions', 3)
            ->assertJsonPath('future_planned_sessions', 3)
            ->assertJsonPath('projected_future_planned_sessions', 4)
            ->assertJsonPath('overage_sessions', 1)
            ->assertJsonPath('renewal_warning', true);

        $this->assertSame(3, (int) $package->fresh()->remaining_sessions);
    }

    public function test_shared_package_actual_attendance_consumes_pool_but_future_plans_do_not(): void
    {
        $package = CoursePackage::create([
            'student_id' => $this->student->id,
            'campus_id' => 1,
            'name' => '共用方案實際扣堂測試',
            'billing_mode' => 'count',
            'total_sessions' => 3,
            'remaining_sessions' => 3,
            'used_sessions' => 0,
            'rate' => 500,
            'rate_unit' => 'session',
            'class_type' => 'one_on_one',
            'paid' => true,
            'stop' => false,
            'enabled' => true,
        ]);
        $this->course->PackageID = $package->id;
        $this->course->save();

        $attended = ClassSession::create([
            'StudentClassID' => $this->course->ID,
            'SessionDate' => Carbon::today()->subDay()->toDateString(),
            'StartTime' => '16:00:00',
            'EndTime' => '17:00:00',
            'Status' => 'attended',
        ]);
        PackageDeductionService::deductForSession(
            $package->id,
            $this->course->ID,
            $attended->id,
            'attended'
        );

        foreach (range(1, 5) as $days) {
            ClassSession::create([
                'StudentClassID' => $this->course->ID,
                'SessionDate' => Carbon::today()->addDays($days)->toDateString(),
                'StartTime' => '16:00:00',
                'EndTime' => '17:00:00',
                'Status' => 'scheduled',
            ]);
        }

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", [
                'session_date' => Carbon::today()->addDays(10)->toDateString(),
                'start_time' => '16:00',
            ])
            ->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('purchased_entitlement', 3)
            ->assertJsonPath('actual_consumed', 1)
            ->assertJsonPath('remaining_sessions', 2)
            ->assertJsonPath('future_planned_sessions', 5)
            ->assertJsonPath('overage_sessions', 4);

        $this->assertSame(2, $package->fresh()->computeRemainingFromLedger());
        $this->assertDatabaseCount('package_session_ledger', 1);
    }

    public function test_excused_future_occurrences_do_not_consume_shared_package_capacity(): void
    {
        $package = CoursePackage::create([
            'student_id' => $this->student->id,
            'campus_id' => 1,
            'name' => 'Manual package with excused occurrences',
            'billing_mode' => 'count',
            'total_sessions' => 3,
            'remaining_sessions' => 3,
            'used_sessions' => 0,
            'rate' => 500,
            'rate_unit' => 'session',
            'class_type' => 'one_on_one',
            'paid' => true,
            'stop' => false,
            'enabled' => true,
        ]);

        $this->course->PackageID = $package->id;
        $this->course->save();

        foreach ([7, 14, 21] as $days) {
            ClassSession::create([
                'StudentClassID' => $this->course->ID,
                'SessionDate' => Carbon::today()->addDays($days)->toDateString(),
                'StartTime' => '16:00:00',
                'EndTime' => '17:00:00',
                'Status' => 'excused',
            ]);
        }

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", [
                'session_date' => Carbon::today()->addDays(28)->toDateString(),
                'start_time' => '16:00',
            ])
            ->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('reserved_sessions', 0)
            ->assertJsonPath('available_sessions', 3);
    }

    public function test_stopped_count_course_with_remaining_sessions_can_book_past_end_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));
        $this->course->Stop = 1;
        $this->course->closed_reason = 'settled';
        $this->course->RemainingSessions = 1;
        $this->course->UsedSessions = 2;
        // StartDate was set in setUp() via the real (unfrozen) Carbon::today(), which
        // drifts forward with wall-clock time. Once real "today" passes the payload's
        // fixed 2026-08-17 session_date, that inherited StartDate makes the booking
        // look like it's before the course even starts. Pin it alongside EndDate so
        // this test stays independent of when it actually runs.
        $this->course->StartDate = '2026-08-01';
        $this->course->EndDate = '2026-08-08';
        $this->course->save();

        $payload = ['session_date' => '2026-08-17', 'start_time' => '16:00'];

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", $payload)
            ->assertOk()
            ->assertJsonPath('can_add', true);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions", $payload)
            ->assertCreated()
            ->assertJsonPath('created', true);
    }

    public function test_stopped_count_course_with_no_remaining_sessions_stays_blocked(): void
    {
        $this->course->Stop = 1;
        $this->course->closed_reason = 'settled';
        $this->course->RemainingSessions = 0;
        $this->course->UsedSessions = 3;
        $this->course->save();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", [
                'session_date' => Carbon::today()->addDays(7)->toDateString(),
                'start_time' => '16:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'COURSE_STOPPED');
    }

    // in-app 排課: a normal auto_recurrence contract had no first-time "排課"
    // action in CourseManagement (only manual_occurrence courses could use this
    // endpoint). This endpoint/service is now shared across both policies so
    // directors can schedule a next session for a regular contract too.
    public function test_auto_recurrence_course_can_use_manual_session_booking(): void
    {
        $this->course->scheduling_policy = 'auto_recurrence';
        $this->course->save();

        $date = Carbon::today()->addDays(7)->toDateString();
        $payload = ['session_date' => $date, 'start_time' => '16:00'];

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", $payload)
            ->assertOk()
            ->assertJsonPath('can_add', true);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions", $payload)
            ->assertCreated()
            ->assertJsonPath('created', true);

        $this->assertDatabaseCount('ClassSession', 1);
        $this->assertSame(3, (int) $this->course->fresh()->RemainingSessions);
    }

    public function test_monthly_course_can_add_an_occurrence_inside_its_date_range_without_session_quota(): void
    {
        $this->course->ScheduleMode = 'date';
        $this->course->scheduling_policy = 'auto_recurrence';
        $this->course->SessionCount = 0;
        $this->course->StartDate = Carbon::today()->toDateString();
        $this->course->EndDate = Carbon::today()->addDays(30)->toDateString();
        $this->course->save();

        $payload = [
            'session_date' => Carbon::today()->addDays(7)->toDateString(),
            'start_time' => '16:00',
        ];

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", $payload)
            ->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('billing_mode', 'monthly')
            ->assertJsonPath('monthly', true)
            ->assertJsonPath('available_sessions', null);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions", $payload)
            ->assertCreated()
            ->assertJsonPath('created', true);

        $this->assertDatabaseCount('ClassSession', 1);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", [
                'session_date' => Carbon::today()->addDays(31)->toDateString(),
                'start_time' => '16:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'AFTER_COURSE_END');
    }

    public function test_monthly_course_requires_an_explicit_end_date(): void
    {
        $this->course->ScheduleMode = 'date';
        $this->course->SessionCount = 0;
        $this->course->EndDate = null;
        $this->course->save();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/student-classes/{$this->course->ID}/manual-sessions/check", [
                'session_date' => Carbon::today()->addDays(7)->toDateString(),
                'start_time' => '16:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MONTHLY_DATE_RANGE_REQUIRED');
    }
}
