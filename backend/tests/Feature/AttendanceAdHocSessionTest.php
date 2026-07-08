<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/v1/attendance without ClassSessionID — ad-hoc session creation path.
 *
 * Covers:
 *  1. Director can create ClassSession + StudentSignIn in one call
 *  2. Teacher can create ClassSession + StudentSignIn for own course
 *  3. Teacher is rejected (403) when StudentClassID belongs to another teacher
 *  4. EndTime in the future without mark_mode:arrival returns 422
 *  5. Teacher bypasses future-end check with mark_mode:arrival
 */
class AttendanceAdHocSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_create_adhoc_session_and_sign_in(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher(1);
        $student = $this->createStudent(1, '臨時點名A');
        $courseId = $this->bootstrapCourse($student->id, $teacherId, 10);

        $yesterday = now()->subDay()->toDateString();
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'SessionDate' => $yesterday,
            'StartTime' => '14:00',
            'EndTime' => '16:00',
            'Status' => 'present',
            'mark_mode' => 'arrival',
        ]);

        $res->assertStatus(201);
        $body = $res->json();
        $this->assertNotEmpty($body['sign_in_id'] ?? $body['id'] ?? null, 'Response should contain sign-in ID');

        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $yesterday,
            'StartTime' => '14:00:00',
            'EndTime' => '16:00:00',
        ]);
        $this->assertDatabaseHas('StudentSingIn', [
            'StudentClassID' => $courseId,
            'StudentID' => $student->id,
        ]);
    }

    public function test_teacher_can_create_adhoc_session_for_own_course(): void
    {
        $teacherId = $this->createTeacher(1);
        $token = $this->createTeacherToken($teacherId);
        $student = $this->createStudent(1, '補建學生B');
        $courseId = $this->bootstrapCourse($student->id, $teacherId, 8);

        $yesterday = now()->subDay()->toDateString();
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'SessionDate' => $yesterday,
            'StartTime' => '09:00',
            'EndTime' => '11:00',
            'Status' => 'present',
            'mark_mode' => 'arrival',
        ]);

        $res->assertStatus(201);

        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $yesterday,
        ]);
    }

    public function test_teacher_rejected_for_another_teachers_course(): void
    {
        $ownerTeacherId = $this->createTeacher(1);
        $otherTeacherId = $this->createTeacher(1);
        $otherToken = $this->createTeacherToken($otherTeacherId);

        $student = $this->createStudent(1, '403學生');
        $courseId = $this->bootstrapCourse($student->id, $ownerTeacherId, 6);

        $yesterday = now()->subDay()->toDateString();
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$otherToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'SessionDate' => $yesterday,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'present',
            'mark_mode' => 'arrival',
        ]);

        $res->assertStatus(403);
    }

    public function test_future_end_time_without_arrival_mode_rejected(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher(1);
        $student = $this->createStudent(1, '未來課學生');
        $courseId = $this->bootstrapCourse($student->id, $teacherId, 4);

        $tomorrow = now()->addDay()->toDateString();
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'SessionDate' => $tomorrow,
            'StartTime' => '14:00',
            'EndTime' => '16:00',
            'Status' => 'present',
        ]);

        $res->assertStatus(422);
    }

    public function test_arrival_mode_bypasses_future_end_check(): void
    {
        $teacherId = $this->createTeacher(1);
        $token = $this->createTeacherToken($teacherId);
        $student = $this->createStudent(1, '到班即核學生');
        $courseId = $this->bootstrapCourse($student->id, $teacherId, 4);

        $today = now()->toDateString();
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'SessionDate' => $today,
            'StartTime' => '00:00',
            'EndTime' => '23:59',
            'Status' => 'present',
            'mark_mode' => 'arrival',
        ]);

        $res->assertStatus(201);
    }

    // ── Helpers ──

    private function bootstrapCourse(int $studentId, int $teacherId, int $remaining): int
    {
        $course = StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-01-01',
            'TotalHours' => 40,
            'Memo' => 'adhoc-test',
            'Paid' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => $remaining,
            'RemainingSessions' => $remaining,
            'UsedSessions' => 0,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
            'MDate' => now(),
            'Rate' => 500,
        ]);

        return (int) $course->ID;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createDirectorToken(array $campusIds): string
    {
        static $i = 0;
        $i++;
        $user = User::create([
            'LoginName' => "director-adhoc-{$i}@example.com",
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $raw = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $raw,
            'expires_at' => now()->addDay(),
        ]);

        return $raw;
    }

    private function createTeacher(int $campusId): int
    {
        static $j = 0;
        $j++;
        $teacher = User::create([
            'LoginName' => "teacher-adhoc-{$j}@example.com",
            'Name' => '老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }

    private function createTeacherToken(int $teacherUserId): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $teacherUserId,
            'token' => $raw,
            'expires_at' => now()->addDay(),
        ]);

        return $raw;
    }
}
