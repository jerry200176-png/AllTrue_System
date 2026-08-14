<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Regression for the togglePause() cross-campus/cross-teacher IDOR (same bug
// class as #1504/#1509 confirmPayment()): togglePause() previously mutated
// StudentClass.Stop/closed_reason/EndDate and cancelled future ClassSession
// rows before any object-level authorization check, so any director/teacher
// account could pause or resume another campus's (or another teacher's)
// course by guessing the StudentClassID.
class StudentClassTogglePauseAuthzTest extends TestCase
{
    use RefreshDatabase;

    public function test_pause_forbidden_for_director_outside_course_campus(): void
    {
        $token = $this->createDirectorToken([2]);
        $sc = $this->createStudentClassOnCampus(1);
        $futureSession = $this->createClassSession((int) $sc->ID, '2099-01-05', 'scheduled');

        $res = $this->postJson(
            "/api/v1/student-classes/{$sc->ID}/pause",
            ['action' => 'pause'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(403);

        $sc->refresh();
        $this->assertSame(0, (int) $sc->Stop);
        $this->assertNull($sc->closed_reason);
        $this->assertSame(
            'scheduled',
            DB::table('ClassSession')->where('id', $futureSession)->value('Status')
        );
    }

    public function test_resume_forbidden_for_director_outside_course_campus(): void
    {
        $token = $this->createDirectorToken([2]);
        $sc = $this->createStudentClassOnCampus(1, [
            'Stop' => 1,
            'closed_reason' => 'settled',
            'EndDate' => '2026-01-01',
        ]);

        $res = $this->postJson(
            "/api/v1/student-classes/{$sc->ID}/pause",
            ['action' => 'resume'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(403);

        $sc->refresh();
        $this->assertSame(1, (int) $sc->Stop);
        $this->assertSame('settled', $sc->closed_reason);
        $this->assertStringStartsWith('2026-01-01', (string) $sc->EndDate);
    }

    public function test_pause_forbidden_for_teacher_not_owning_course(): void
    {
        $token = $this->createTeacherToken([1]);
        // TeacherID deliberately does not match the authenticated teacher's real id.
        $sc = $this->createStudentClassOnCampus(1, ['TeacherID' => 999999]);
        $futureSession = $this->createClassSession((int) $sc->ID, '2099-01-05', 'scheduled');

        $res = $this->postJson(
            "/api/v1/student-classes/{$sc->ID}/pause",
            ['action' => 'pause'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(403);

        $sc->refresh();
        $this->assertSame(0, (int) $sc->Stop);
        $this->assertSame(
            'scheduled',
            DB::table('ClassSession')->where('id', $futureSession)->value('Status')
        );
    }

    public function test_resume_forbidden_for_teacher_not_owning_course(): void
    {
        $token = $this->createTeacherToken([1]);
        $sc = $this->createStudentClassOnCampus(1, [
            'TeacherID' => 999999,
            'Stop' => 1,
            'closed_reason' => 'settled',
        ]);

        $res = $this->postJson(
            "/api/v1/student-classes/{$sc->ID}/pause",
            ['action' => 'resume'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(403);

        $sc->refresh();
        $this->assertSame(1, (int) $sc->Stop);
        $this->assertSame('settled', $sc->closed_reason);
    }

    public function test_pause_succeeds_for_director_in_same_campus(): void
    {
        $token = $this->createDirectorToken([1]);
        $sc = $this->createStudentClassOnCampus(1);
        $futureSession = $this->createClassSession((int) $sc->ID, '2099-01-05', 'scheduled');

        $res = $this->postJson(
            "/api/v1/student-classes/{$sc->ID}/pause",
            ['action' => 'pause'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();

        $sc->refresh();
        $this->assertSame(1, (int) $sc->Stop);
        $this->assertSame(
            'cancelled',
            DB::table('ClassSession')->where('id', $futureSession)->value('Status')
        );
    }

    public function test_resume_succeeds_for_director_in_same_campus(): void
    {
        $token = $this->createDirectorToken([1]);
        $sc = $this->createStudentClassOnCampus(1, ['Stop' => 1, 'closed_reason' => 'settled']);

        $res = $this->postJson(
            "/api/v1/student-classes/{$sc->ID}/pause",
            ['action' => 'resume'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();

        $sc->refresh();
        $this->assertSame(0, (int) $sc->Stop);
        $this->assertNull($sc->closed_reason);
    }

    public function test_pause_succeeds_for_owning_teacher(): void
    {
        $token = $this->createTeacherToken([1]);
        $sc = $this->createStudentClassOnCampus(1, ['TeacherID' => $this->lastTeacherUserId]);
        $futureSession = $this->createClassSession((int) $sc->ID, '2099-01-05', 'scheduled');

        $res = $this->postJson(
            "/api/v1/student-classes/{$sc->ID}/pause",
            ['action' => 'pause'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();

        $sc->refresh();
        $this->assertSame(1, (int) $sc->Stop);
        $this->assertSame(
            'cancelled',
            DB::table('ClassSession')->where('id', $futureSession)->value('Status')
        );
    }

    public function test_resume_succeeds_for_owning_teacher(): void
    {
        $token = $this->createTeacherToken([1]);
        $sc = $this->createStudentClassOnCampus(1, [
            'TeacherID' => $this->lastTeacherUserId,
            'Stop' => 1,
            'closed_reason' => 'settled',
        ]);

        $res = $this->postJson(
            "/api/v1/student-classes/{$sc->ID}/pause",
            ['action' => 'resume'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();

        $sc->refresh();
        $this->assertSame(0, (int) $sc->Stop);
        $this->assertNull($sc->closed_reason);
    }

    // ── helpers ──

    private int $lastTeacherUserId = 0;

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-pauseauthz-' . uniqid() . '@test.com',
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

    private function createTeacherToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'teach-pauseauthz-' . uniqid() . '@test.com',
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0912345679',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        }
        $this->lastTeacherUserId = (int) $user->id;
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createStudentClassOnCampus(int $campusId, array $overrides = []): StudentClass
    {
        $student = Student::create([
            'name' => '暫停授權測試生-' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id,
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

    private function createClassSession(int $courseId, string $date, string $status): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => $status,
        ]);
    }
}
