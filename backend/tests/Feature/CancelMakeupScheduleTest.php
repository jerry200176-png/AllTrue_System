<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelMakeupScheduleTest extends TestCase
{
    use RefreshDatabase;

    private string $dirToken;
    private string $teacherToken;
    private int $teacherId;
    private int $studentId;
    private int $courseId;
    private int $campusId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $director = User::create([
            'LoginName' => 'dir-makeup@example.com',
            'Name' => '主任補課測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000001',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $this->campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);
        $this->dirToken = $dirToken;

        $teacher = User::create([
            'LoginName' => 'teacher-makeup@example.com',
            'Name' => '老師補課測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0911000002',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $this->campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $teacherToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $teacherToken, 'expires_at' => now()->addDay()]);
        $this->teacherId = (int) $teacher->id;
        $this->teacherToken = $teacherToken;

        $student = Student::create([
            'name' => '補課學生',
            'CampusID' => $this->campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $this->studentId = (int) $student->id;

        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacher->id,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-01',
            'TotalHours' => 8,
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 3,
            'UsedSessions' => 1,
            'Charge' => 800,
            'Pay' => 3200,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'RoomID' => '1',
            'MDate' => now(),
            'ScheduleMode' => 'count',
        ]);
        $this->courseId = (int) $sc->ID;
    }

    private function makeExtraSchedule(string $date = '2026-06-15', string $status = 'scheduled'): Schedule
    {
        return Schedule::create([
            'student_id'        => $this->studentId,
            'teacher_id'        => $this->teacherId,
            'subject'           => 'Math',
            'day_of_week'       => 0,
            'start_time'        => '23:00',
            'end_time'          => '23:30',
            'branch_id'         => $this->campusId,
            'schedule_date'     => $date,
            'status'            => $status,
            'type'              => 'extra',
            'student_course_id' => $this->courseId,
            'class_type'        => 'one_on_one',
        ]);
    }

    public function test_director_can_cancel_extra_scheduled_makeup(): void
    {
        $schedule = $this->makeExtraSchedule();
        $session = ClassSession::create([
            'StudentClassID' => $this->courseId,
            'SessionDate'    => '2026-06-15',
            'StartTime'      => '23:00',
            'EndTime'        => '23:30',
            'Status'         => 'scheduled',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->dirToken}"])
            ->postJson("/api/v1/schedules/{$schedule->id}/cancel-makeup");

        $response->assertStatus(200)->assertJsonFragment(['id' => $schedule->id]);

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('ClassSession', ['ID' => $session->id, 'Status' => 'cancelled']);
    }

    public function test_remaining_sessions_unchanged_after_cancel(): void
    {
        $schedule = $this->makeExtraSchedule();
        ClassSession::create([
            'StudentClassID' => $this->courseId,
            'SessionDate'    => '2026-06-15',
            'StartTime'      => '23:00',
            'EndTime'        => '23:30',
            'Status'         => 'scheduled',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$this->dirToken}"])
            ->postJson("/api/v1/schedules/{$schedule->id}/cancel-makeup");

        $this->assertDatabaseHas('StudentClass', ['ID' => $this->courseId, 'RemainingSessions' => 3]);
    }

    public function test_teacher_cannot_cancel_makeup(): void
    {
        $schedule = $this->makeExtraSchedule();

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->teacherToken}"])
            ->postJson("/api/v1/schedules/{$schedule->id}/cancel-makeup");

        $response->assertStatus(403);
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'scheduled']);
    }

    public function test_cannot_cancel_non_extra_schedule(): void
    {
        $schedule = Schedule::create([
            'student_id'    => $this->studentId,
            'teacher_id'    => $this->teacherId,
            'day_of_week'   => 1,
            'start_time'    => '23:00',
            'end_time'      => '23:30',
            'branch_id'     => $this->campusId,
            'schedule_date' => '2026-06-15',
            'status'        => 'leave',
            'type'          => 'normal',
            'class_type'    => 'one_on_one',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->dirToken}"])
            ->postJson("/api/v1/schedules/{$schedule->id}/cancel-makeup");

        $response->assertStatus(422);
    }

    public function test_cannot_cancel_already_cancelled_makeup(): void
    {
        $schedule = $this->makeExtraSchedule(status: 'cancelled');

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->dirToken}"])
            ->postJson("/api/v1/schedules/{$schedule->id}/cancel-makeup");

        $response->assertStatus(422);
    }

    public function test_cancel_without_class_session_still_succeeds(): void
    {
        $schedule = $this->makeExtraSchedule();

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->dirToken}"])
            ->postJson("/api/v1/schedules/{$schedule->id}/cancel-makeup");

        $response->assertStatus(200);
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'cancelled']);
    }

    public function test_attended_session_not_overwritten_on_cancel(): void
    {
        $schedule = $this->makeExtraSchedule();
        $session = ClassSession::create([
            'StudentClassID' => $this->courseId,
            'SessionDate'    => '2026-06-15',
            'StartTime'      => '23:00',
            'EndTime'        => '23:30',
            'Status'         => 'attended',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$this->dirToken}"])
            ->postJson("/api/v1/schedules/{$schedule->id}/cancel-makeup");

        $this->assertDatabaseHas('ClassSession', ['ID' => $session->id, 'Status' => 'attended']);
    }
}
