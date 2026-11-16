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
        // Merely paused: a committed close is refused instead (#4188, StudentClassPauseCloseGuardTest).
        $sc = $this->createStudentClassOnCampus(1, ['Stop' => 1]);

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

    /** Contract terms and money are the director's: a teacher is refused at the route, even for an own course in the same campus. */
    public function test_contract_write_routes_refuse_a_teacher_and_change_nothing(): void
    {
        $token = $this->createTeacherToken([1]);
        $sc = $this->createStudentClassOnCampus(1, ['TeacherID' => $this->lastTeacherUserId, 'Charge' => 8800, 'Rate' => 1100]);
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->putJson("/api/v1/student-classes/{$sc->ID}", ['StudentID' => $sc->StudentID, 'Charge' => 1, 'Paid' => 1, 'ScheduleMode' => 'count'], $auth)->assertForbidden();
        $this->putJson("/api/v1/student-classes/{$sc->ID}", ['rate_per_30min' => 1], $auth)->assertForbidden();
        $this->postJson('/api/v1/student-classes', ['StudentID' => $sc->StudentID], $auth)->assertForbidden();
        $this->postJson('/api/v1/student-classes/sync', [], $auth)->assertForbidden();
        foreach (['renewal-confirm', 'purchase-batch', 'renew-monthly', 'convert-trial', 'transfer-sessions', 'convert-to-package'] as $action) {
            $this->postJson("/api/v1/student-classes/{$sc->ID}/{$action}", [], $auth)->assertForbidden();
        }
        $this->deleteJson("/api/v1/student-classes/{$sc->ID}", [], $auth)->assertForbidden();
        // Shared packages carry the same terms (rate, paid mark) for every member contract; marking a contract paid is the director's.
        $this->postJson('/api/v1/course-packages/create-multi-subject', [], $auth)->assertForbidden();
        $this->putJson('/api/v1/course-packages/1', ['rate' => 1, 'paid' => 1], $auth)->assertForbidden();
        $this->postJson("/api/v1/student-classes/{$sc->ID}/confirm-payment", ['paid_at' => '2026-11-17'], $auth)->assertForbidden();
        $this->postJson("/api/v1/student-classes/{$sc->ID}/renewal-preview", [], $auth)->assertForbidden();
        $this->postJson('/api/v1/course-contract-groups', [], $auth)->assertForbidden();

        $sc->refresh();
        $this->assertSame([8800, 1100, 0], [(int) $sc->Charge, (int) $sc->Rate, (int) $sc->Paid]);
        // Reads stay open to the teacher of the course.
        $this->getJson("/api/v1/student-classes/{$sc->ID}", $auth)->assertOk();
    }

    public function test_contract_edit_still_works_for_a_director_in_the_campus(): void
    {
        $token = $this->createDirectorToken([1]);
        $sc = $this->createStudentClassOnCampus(1, ['Charge' => 8800, 'Rate' => 1100, 'rate_unit' => 'session']);

        $this->putJson("/api/v1/student-classes/{$sc->ID}", ['rate_per_30min' => 1200, 'sessions_purchased' => 8], ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->assertSame(9600, (int) $sc->fresh()->Charge);
    }

    /** Counters, closure and package links have their own audited flows; a raw contract edit cannot set them. */
    public function test_raw_contract_edit_cannot_set_counters_closure_or_package_links(): void
    {
        $token = $this->createDirectorToken([1]);
        $sc = $this->createStudentClassOnCampus(1, ['Charge' => 8800, 'Rate' => 1100, 'rate_unit' => 'session']);
        $before = $sc->fresh()->getAttributes();

        $this->putJson("/api/v1/student-classes/{$sc->ID}", ['StudentID' => $sc->StudentID, 'UsedSessions' => 0, 'closed_reason' => 'waived'],
            ['Authorization' => "Bearer {$token}"])->assertStatus(422)->assertJsonPath('code', 'protected_contract_fields')
            ->assertJsonPath('fields', ['UsedSessions', 'closed_reason']);
        $this->assertSame($before, $sc->fresh()->getAttributes());

        $this->putJson("/api/v1/student-classes/{$sc->ID}", ['StudentID' => $sc->StudentID, 'Memo' => 'raw edit still works'],
            ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertSame('raw edit still works', $sc->fresh()->Memo);
    }

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
