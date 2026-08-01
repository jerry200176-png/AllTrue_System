<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
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
}
