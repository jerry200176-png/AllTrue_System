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
        ]);

        $forced->assertCreated();
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
