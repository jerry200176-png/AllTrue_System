<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TD-062 P4-b (#740): GET /student-classes?start=&end= should return only courses
 * relevant to the calendar window, while still including stopped courses that
 * have ClassSession history inside the window.
 */
class StudentClassIndexCalendarWindowFilterTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(): string
    {
        $user = User::create([
            'LoginName' => 'cal-window-test@example.com',
            'Name' => 'Cal Window Tester',
            'PSW' => bcrypt('x'),
            'type' => 'A',
            'status' => 'active',
        ]);
        UserCampus::firstOrCreate(
            ['UserID' => $user->id, 'CampusID' => 1],
            ['Admin' => 1, 'Approved' => 1]
        );
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => '視窗測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function makeCourse(Student $student, array $extra = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $student->id,
            'TeacherID' => 10,
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-01-01',
            'EndDate' => '2026-12-31',
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'ScheduleMode' => 'count',
            'ClassType' => 'one_on_one',
            'Rate' => 1000,
            'rate_unit' => 'session',
            'Charge' => 8000,
            'UsedSessions' => 0,
            'Stop' => 0,
            'MDate' => now(),
            'RoomID' => '',
            'week' => 1,
            'time' => '18:00:00',
        ], $extra));
    }

    public function test_without_window_returns_all_branch_courses(): void
    {
        $token = $this->makeToken();
        $student = $this->makeStudent();
        $inRange = $this->makeCourse($student, ['StartDate' => '2026-05-01', 'EndDate' => '2026-06-30']);
        $outOfRange = $this->makeCourse($student, ['StartDate' => '2024-01-01', 'EndDate' => '2024-12-31', 'Stop' => 1]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $inRange->ID, $ids);
        $this->assertContains((int) $outOfRange->ID, $ids);
    }

    public function test_window_excludes_contract_ended_before_range(): void
    {
        $token = $this->makeToken();
        $student = $this->makeStudent();
        $active = $this->makeCourse($student, ['StartDate' => '2026-05-01', 'EndDate' => '2026-06-30']);
        $ended = $this->makeCourse($student, ['StartDate' => '2024-01-01', 'EndDate' => '2024-12-31', 'Stop' => 1]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&start=2026-05-01&end=2026-05-31&per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $active->ID, $ids);
        $this->assertNotContains((int) $ended->ID, $ids);
    }

    public function test_window_includes_stopped_course_with_session_in_range(): void
    {
        $token = $this->makeToken();
        $student = $this->makeStudent();
        $stopped = $this->makeCourse($student, [
            'StartDate' => '2024-01-01',
            'EndDate' => '2024-12-31',
            'Stop' => 1,
        ]);

        ClassSession::create([
            'StudentClassID' => $stopped->ID,
            'SessionDate' => '2026-05-15',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'completed',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&start=2026-05-01&end=2026-05-31&per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $stopped->ID, $ids);
    }

    public function test_window_includes_course_with_schedule_exception_in_range(): void
    {
        $token = $this->makeToken();
        $student = $this->makeStudent();
        $course = $this->makeCourse($student, [
            'StartDate' => '2024-01-01',
            'EndDate' => '2024-12-31',
            'Stop' => 1,
        ]);

        DB::table('schedules')->insert([
            'student_id' => $student->id,
            'teacher_id' => 10,
            'student_course_id' => $course->ID,
            'branch_id' => 1,
            'subject' => 'Math',
            'day_of_week' => 4,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'leave',
            'type' => 'normal',
            'deduction' => 0,
            'schedule_date' => '2026-05-20',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&start=2026-05-01&end=2026-05-31&per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $course->ID, $ids);
    }
}
