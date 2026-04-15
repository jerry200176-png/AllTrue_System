<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddSessionConflictTest extends TestCase
{
    use RefreshDatabase;

    // --- add-session: locked by attendance ---
    public function test_add_session_returns_structured_409_when_attendance_exists(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('attendance');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
            'duration_minutes' => 120,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSION_LOCKED')
            ->assertJsonPath('conflict_type', 'locked_existing')
            ->assertJsonPath('has_attendance', true)
            ->assertJsonStructure(['message', 'suggested_actions', 'conflict_session_id']);
    }

    // --- add-session: locked by approved learning record ---
    public function test_add_session_returns_structured_409_when_approved_lr_exists(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('learning_record');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
            'duration_minutes' => 120,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSION_LOCKED')
            ->assertJsonPath('has_approved_learning_record', true);
    }

    // --- add-session: existing but unlocked → overwrite OK ---
    public function test_add_session_overwrites_unlocked_existing_session(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-25',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
            'duration_minutes' => 120,
            'auto_approve' => false,
        ]);

        $res->assertStatus(201);
    }

    // --- add-session: full capacity, no movable session ---
    public function test_add_session_returns_full_capacity_when_all_slots_used(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, [
            'SessionCount' => 2,
            'RemainingSessions' => 0,
            'UsedSessions' => 2,
        ]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-20',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-22',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'attended',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-04-01',
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'auto_approve' => false,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSIONS_FULL')
            ->assertJsonPath('conflict_type', 'full_capacity');
    }

    // --- add-session: movable session exists → success with moved_from_date ---
    public function test_add_session_moves_last_future_session_and_returns_moved_from(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 2]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-20',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-12-30',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-04-05',
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'auto_approve' => false,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('moved_from_date', '2026-12-30');
    }

    // --- check endpoint: locked ---
    public function test_check_endpoint_returns_can_add_false_for_locked(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('attendance');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
        ]);

        $res->assertOk()
            ->assertJsonPath('can_add', false)
            ->assertJsonPath('conflict_type', 'locked_existing')
            ->assertJsonPath('error_code', 'SESSION_LOCKED')
            ->assertJsonStructure(['suggested_actions', 'message']);
    }

    // --- check endpoint: no conflict ---
    public function test_check_endpoint_returns_can_add_true_when_no_conflict(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
            'session_date' => '2026-05-01',
            'start_time' => '14:00',
        ]);

        $res->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('conflict_type', 'none');
    }

    // --- check endpoint: full capacity ---
    public function test_check_endpoint_returns_full_capacity(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, [
            'SessionCount' => 1,
            'RemainingSessions' => 0,
            'UsedSessions' => 1,
        ]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-20',
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'attended',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
            'session_date' => '2026-05-10',
            'start_time' => '10:00',
        ]);

        $res->assertOk()
            ->assertJsonPath('can_add', false)
            ->assertJsonPath('conflict_type', 'full_capacity')
            ->assertJsonPath('error_code', 'SESSIONS_FULL');
    }

    // --- API contract: 409 still has message field (backward compat) ---
    public function test_409_response_always_contains_message_field(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('attendance');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
            'duration_minutes' => 120,
        ]);

        $res->assertStatus(409);
        $json = $res->json();
        $this->assertArrayHasKey('message', $json, '409 response must include message for backward compat');
        $this->assertNotEmpty($json['message']);
    }

    // --- race condition: check passes but state changes before submit ---
    public function test_race_condition_check_passes_then_submit_gets_409(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-06-10',
            'StartTime' => '14:00:00',
            'EndTime' => '16:00:00',
            'Status' => 'scheduled',
        ]);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $checkRes = $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
                'session_date' => '2026-06-10', 'start_time' => '14:00',
            ]);
        $checkRes->assertOk()->assertJsonPath('can_add', true);

        StudentSignIn::create([
            'StudentClassID' => $sc->ID,
            'StudentID' => $student->id,
            'ClassSessionID' => $cs->id,
            'SignInDT' => now(), 'MDT' => now(),
            'Status' => 'present', 'CampusID' => 1,
        ]);

        $submitRes = $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
                'session_date' => '2026-06-10', 'start_time' => '14:00',
                'duration_minutes' => 120, 'auto_approve' => false,
            ]);

        $submitRes->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSION_LOCKED')
            ->assertJsonStructure(['suggested_actions']);
    }

    // --- check and add-session error_code values must match ---
    public function test_check_and_add_session_return_same_error_code_for_locked(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('attendance');
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $params = ['session_date' => '2026-03-25', 'start_time' => '19:00'];

        $checkJson = $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", $params)
            ->json();
        $submitJson = $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session", array_merge($params, ['duration_minutes' => 120]))
            ->json();

        $this->assertSame($checkJson['error_code'], $submitJson['error_code']);
        $this->assertSame($checkJson['conflict_type'], $submitJson['conflict_type']);
    }

    // ── helpers ──

    private function seedCourseWithLockedSession(string $lockType): array
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-25',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'attended',
        ]);

        if ($lockType === 'attendance') {
            StudentSignIn::create([
                'StudentClassID' => $sc->ID,
                'StudentID' => $student->id,
                'ClassSessionID' => $cs->id,
                'SignInDT' => now(),
                'MDT' => now(),
                'Status' => 'present',
                'CampusID' => 1,
            ]);
        } elseif ($lockType === 'learning_record') {
            LearningRecord::create([
                'StudentClassID' => $sc->ID,
                'ClassSessionID' => $cs->id,
                'TeacherID' => 99,
                'Content' => 'Test',
                'Subject' => 'Math',
                'SessionDate' => '2026-03-25',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'approved',
                'ApprovedAt' => now(),
            ]);
        }

        return [$token, $sc];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-addsess-' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createStudent(int $campusId): Student
    {
        return Student::create([
            'name' => '加課測試生-' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}
