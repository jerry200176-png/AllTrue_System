<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassSessionUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_to_attended_succeeds_and_deducts(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession('scheduled');

        $this->patchSession($token, $session->id, ['status' => 'attended'])
            ->assertOk()
            ->assertJsonPath('session.status', 'attended');

        $session->refresh();
        $this->assertSame('attended', $session->Status);

        $this->assertTrue(
            SessionDeductionLedger::where('student_class_id', $courseId)
                ->where('class_session_id', $session->id)
                ->where('event_type', 'deduct')
                ->exists()
        );
    }

    public function test_scheduled_to_leave_succeeds(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession('scheduled');

        $this->patchSession($token, $session->id, ['status' => 'leave'])
            ->assertOk()
            ->assertJsonPath('session.status', 'leave');
    }

    public function test_attended_to_leave_adjusted_succeeds(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession('attended');

        SessionDeductionLedger::create([
            'student_class_id' => $courseId,
            'class_session_id' => $session->id,
            'event_type'       => 'deduct',
            'source'           => 'attendance',
        ]);

        $this->patchSession($token, $session->id, ['status' => 'leave_adjusted', 'reason' => 'test retro'])
            ->assertOk()
            ->assertJsonPath('session.status', 'leave_adjusted');

        $this->assertDatabaseHas('session_deduction_ledger', [
            'student_class_id' => $courseId,
            'class_session_id' => $session->id,
            'event_type'       => 'reverse',
        ]);

        $this->assertSame(0, SessionDeductionLedger::netCount($courseId));
    }

    public function test_invalid_transition_returns_422(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession('scheduled');

        $this->patchSession($token, $session->id, ['status' => 'leave_adjusted'])
            ->assertStatus(422);
    }

    public function test_leave_adjusted_requires_director(): void
    {
        $dirToken = $this->createDirectorToken([1], 'dir-perm-cs@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-perm-cs@example.com');
        $teacherToken = $this->createTeacherToken($teacherId);
        $student = $this->createStudent(1, '權限CS學生');

        $courseId = $this->createCourseWithSessions($dirToken, $student->id, $teacherId);
        $session = ClassSession::where('StudentClassID', $courseId)->first();
        $session->Status = 'attended';
        $session->save();

        $this->patchSession($teacherToken, $session->id, ['status' => 'leave_adjusted'])
            ->assertForbidden();
    }

    public function test_teacher_can_only_mark_attendance(): void
    {
        $dirToken = $this->createDirectorToken([1], 'dir-ta-cs@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-ta-cs@example.com');
        $teacherToken = $this->createTeacherToken($teacherId);
        $student = $this->createStudent(1, '老師限制CS學生');

        $courseId = $this->createCourseWithSessions($dirToken, $student->id, $teacherId);
        $session = ClassSession::where('StudentClassID', $courseId)->first();

        // Teacher can mark attended
        $this->patchSession($teacherToken, $session->id, ['status' => 'attended'])
            ->assertOk();

        // Teacher cannot cancel
        $this->patchSession($teacherToken, $session->id, ['status' => 'cancelled'])
            ->assertForbidden();
    }

    public function test_idempotent_same_status(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession('attended');

        $this->patchSession($token, $session->id, ['status' => 'attended'])
            ->assertOk();
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function setupCourseWithSession(string $initialStatus): array
    {
        $token = $this->createDirectorToken([1], 'dir-cs-' . uniqid() . '@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-cs-' . uniqid() . '@example.com');
        $student = $this->createStudent(1, 'CS學生' . uniqid());

        $courseId = $this->createCourseWithSessions($token, $student->id, $teacherId);
        $session = ClassSession::where('StudentClassID', $courseId)->first();
        $session->Status = $initialStatus;
        $session->save();

        return [$token, $courseId, $session];
    }

    private function createCourseWithSessions(string $token, int $studentId, int $teacherId): int
    {
        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id'        => 1,
            'student_id'       => $studentId,
            'teacher_id'       => $teacherId,
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'total_classes'    => 4,
            'confirmed_dates'  => [],
            'future_dates'     => $futureDates,
            'days_of_week'     => [3],
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type'     => 'session',
            'start_time'       => '16:00',
        ])->assertCreated();

        return (int) DB::table('StudentClass')
            ->where('StudentID', $studentId)
            ->where('TeacherID', $teacherId)
            ->max('ID');
    }

    private function patchSession(string $token, int $sessionId, array $data)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$sessionId}", $data);
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
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

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
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

    private function createTeacherToken(int $teacherId): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $teacherId,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
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
}
