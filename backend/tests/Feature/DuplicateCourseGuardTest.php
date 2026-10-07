<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateCourseGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_subject_different_class_type_is_allowed(): void
    {
        $token = $this->createDirectorToken();
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $futureDate = now()->addDays(7)->toDateString();

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [$futureDate],
            'days_of_week' => [(int) now()->addDays(7)->dayOfWeekIso],
            'start_time' => '14:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);
        $first->assertCreated();

        $futureDate2 = now()->addDays(8)->toDateString();
        $second = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'tutoring',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [$futureDate2],
            'days_of_week' => [(int) now()->addDays(8)->dayOfWeekIso],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 300,
            'payment_type' => 'session',
        ]);

        $second->assertCreated();
    }

    public function test_same_subject_same_class_type_returns_409(): void
    {
        $token = $this->createDirectorToken();
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $futureDate = now()->addDays(7)->toDateString();

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [$futureDate],
            'days_of_week' => [(int) now()->addDays(7)->dayOfWeekIso],
            'start_time' => '14:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);
        $first->assertCreated();

        $futureDate2 = now()->addDays(14)->toDateString();
        $second = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [$futureDate2],
            'days_of_week' => [(int) now()->addDays(14)->dayOfWeekIso],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $second->assertStatus(409);
        $second->assertJsonPath('code', 'duplicate_active_course');
        $this->assertNotEmpty($second->json('conflicts'));
        $this->assertSame('one_on_one', $second->json('conflicts.0.class_type'));
    }

    public function test_same_subject_same_class_type_with_force_returns_201(): void
    {
        $token = $this->createDirectorToken();
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $futureDate = now()->addDays(7)->toDateString();

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [$futureDate],
            'days_of_week' => [(int) now()->addDays(7)->dayOfWeekIso],
            'start_time' => '14:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);
        $first->assertCreated();

        $futureDate2 = now()->addDays(14)->toDateString();
        $forced = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [$futureDate2],
            'days_of_week' => [(int) now()->addDays(14)->dayOfWeekIso],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'force' => true,
            'force_reason' => 'independent_parallel',
            'force_note' => '測試：同科獨立合約',
        ]);

        $forced->assertCreated();
    }

    /**
     * In-app #382: a calendar quick-add with 逐堂手動排課 creates the course with no lesson. A second try must tell
     * the UI it is that manual course with nothing upcoming, so it offers 新增下一堂 instead of a second course.
     */
    public function test_duplicate_of_manual_course_without_upcoming_lessons_says_so(): void
    {
        $token = $this->createDirectorToken();
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();
        $payload = [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'tutoring',
            'confirmed_dates' => [],
            'future_dates' => [],
            'days_of_week' => [],
            'day_time_slots' => [],
            'start_time' => '16:00',
            'duration_minutes' => 60,
            'rate_unit' => 'session',
            'payment_type' => 'session',
            'scheduling_policy' => 'manual_occurrence',
            'total_classes' => 1,
            'course_start_date' => now()->toDateString(),
        ];
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $first = $this->withHeaders($headers)->postJson('/api/v1/class-sessions/batch', $payload);
        $first->assertCreated();
        $courseId = (int) $first->json('student_class_id');
        $this->assertSame(0, \App\Models\ClassSession::where('StudentClassID', $courseId)->count());

        $this->withHeaders($headers)->postJson('/api/v1/class-sessions/batch', $payload)
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.existing_course_id', $courseId)
            ->assertJsonPath('conflicts.0.scheduling_policy', 'manual_occurrence')
            ->assertJsonPath('conflicts.0.future_session_count', 0);

        // 學生管理's pre-check reads active-courses: same facts, so it offers 新增下一堂 too.
        $this->withHeaders($headers)->getJson("/api/v1/students/{$student->id}/active-courses")
            ->assertOk()
            ->assertJsonPath('courses.0.scheduling_policy', 'manual_occurrence')
            ->assertJsonPath('courses.0.future_session_count', 0);
    }

    private function createDirectorToken(): string
    {
        $user = User::create([
            'LoginName' => 'dup-guard-director@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function createTeacher(): int
    {
        $teacher = User::create([
            'LoginName' => 'dup-guard-teacher@test.com',
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922111111',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);
        return (int) $teacher->id;
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '重複課程測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }
}
