<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\LearningRecordTeacherChange;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADR-005 RestoreContractTeacher named command boundary.
 */
class RestoreContractTeacherTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_after_substitute_returns_contract_teacher(): void
    {
        [$dirToken, $regularId, $subId, $session, $lr] = $this->seedScenario();

        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'reason' => '正班老師請假',
        ])->assertOk();

        $res = $this->withAuth($dirToken)->postJson(
            "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
            ['reason' => '回復正班老師']
        );

        $res->assertOk()
            ->assertJsonFragment([
                'restored_teacher_id' => $regularId,
                'substitute_cleared' => true,
                'code' => 'restore_contract_teacher',
                'operation_type' => 'restore_contract_teacher',
            ]);

        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'scheduled',
            'teacher_id' => $subId,
        ]);
        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'rescheduled',
        ]);

        $lr->refresh();
        $this->assertSame($regularId, (int) $lr->TeacherID);
    }

    public function test_request_rejects_teacher_id_fields(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenario();
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        foreach (['teacher_id', 'substitute_teacher_id', 'contract_teacher_id', 'original_teacher_id'] as $key) {
            $res = $this->withAuth($dirToken)->postJson(
                "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
                [$key => 99999, 'reason' => 'x']
            );
            $res->assertStatus(422)
                ->assertJsonFragment(['code' => 'teacher_identity_not_accepted']);
        }
    }

    public function test_forged_teacher_identity_cannot_change_restore_target(): void
    {
        [$dirToken, $regularId, $subId, $session, $lr] = $this->seedScenario();
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        // Contaminated payload must be rejected — never applied as restore target.
        $this->withAuth($dirToken)->postJson(
            "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
            ['substitute_teacher_id' => 99999]
        )->assertStatus(422);

        // Clean restore still targets StudentClass.TeacherID.
        $this->withAuth($dirToken)->postJson(
            "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
            []
        )->assertOk()->assertJsonFragment(['restored_teacher_id' => $regularId]);

        $lr->refresh();
        $this->assertSame($regularId, (int) $lr->TeacherID);
    }

    public function test_session_not_found(): void
    {
        [$dirToken] = $this->seedScenario();
        $this->withAuth($dirToken)
            ->postJson('/api/v1/class-sessions/999999/restore-contract-teacher', [])
            ->assertStatus(404)
            ->assertJsonFragment(['code' => 'session_not_found']);
    }

    public function test_contract_teacher_missing(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenario();
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        StudentClass::where('ID', $session->StudentClassID)->update(['TeacherID' => 0]);

        $this->withAuth($dirToken)
            ->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [])
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'contract_teacher_missing']);
    }

    public function test_teacher_role_forbidden(): void
    {
        [$dirToken, $regularId, $subId, $session] = $this->seedScenario();
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        $teacherToken = $this->createTeacherToken($regularId);
        $this->withAuth($teacherToken)
            ->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [])
            ->assertStatus(403);
    }

    public function test_cross_campus_forbidden(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenario();
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        $otherDir = User::create([
            'LoginName' => 'dir-other-campus@example.com', 'Name' => '他校主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0911222333', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 99, 'UserID' => $otherDir->id, 'Admin' => 1, 'Approved' => 1]);
        $otherToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $otherDir->id, 'token' => $otherToken, 'expires_at' => now()->addDay()]);

        $this->withAuth($otherToken)
            ->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [])
            ->assertStatus(403);
    }

    public function test_legacy_substitute_with_contract_teacher_id_is_unreachable(): void
    {
        [$dirToken, $regularId, $subId, $session] = $this->seedScenario();
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $regularId,
            'reason' => 'legacy restore attempt',
        ])->assertStatus(422)
            ->assertJsonFragment(['code' => 'use_restore_contract_teacher']);

        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'scheduled',
            'teacher_id' => $subId,
        ]);
    }

    public function test_audit_event_on_learning_record_teacher_change(): void
    {
        if (!Schema::hasTable('learning_record_teacher_changes')) {
            $this->markTestSkipped('learning_record_teacher_changes missing');
        }

        [$dirToken, $regularId, $subId, $session, $lr] = $this->seedScenario();
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        $this->withAuth($dirToken)->postJson(
            "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
            ['reason' => 'audit restore']
        )->assertOk();

        $this->assertDatabaseHas('learning_record_teacher_changes', [
            'learning_record_id' => $lr->id,
            'old_teacher_id' => $subId,
            'new_teacher_id' => $regularId,
            'reason' => 'audit restore',
        ]);
    }

    public function test_uses_updated_contract_teacher_not_stale_substitute_pair_teacher(): void
    {
        [$dirToken, $oldTeacherId, $subId, $session, $lr, $campusId] = $this->seedScenario(true);

        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        $newContract = User::create([
            'LoginName' => 'new-contract-rct@example.com', 'Name' => 'Coco', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000999', 'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campusId, 'UserID' => $newContract->id, 'Admin' => 0, 'Approved' => 1,
        ]);
        StudentClass::where('ID', $session->StudentClassID)->update(['TeacherID' => $newContract->id]);

        Schedule::where('student_course_id', $session->StudentClassID)
            ->whereDate('schedule_date', '2026-04-19')
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->update(['teacher_id' => $oldTeacherId]);

        $this->withAuth($dirToken)->postJson(
            "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
            []
        )->assertOk()->assertJsonFragment([
            'restored_teacher_id' => (int) $newContract->id,
            'substitute_cleared' => true,
        ]);

        $lr->refresh();
        $this->assertSame((int) $newContract->id, (int) $lr->TeacherID);
    }

    public function test_transaction_keeps_schedules_and_lr_consistent_on_success(): void
    {
        [$dirToken, $regularId, $subId, $session, $lr] = $this->seedScenario();
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        $beforeSched = Schedule::where('student_course_id', $session->StudentClassID)
            ->whereDate('schedule_date', '2026-04-19')
            ->count();
        $this->assertGreaterThan(0, $beforeSched);

        $this->withAuth($dirToken)->postJson(
            "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
            []
        )->assertOk();

        $this->assertSame(0, Schedule::where('student_course_id', $session->StudentClassID)
            ->whereDate('schedule_date', '2026-04-19')
            ->whereIn('status', ['scheduled', 'rescheduled'])
            ->whereNotNull('original_schedule_id')
            ->count());
        $lr->refresh();
        $this->assertSame($regularId, (int) $lr->TeacherID);
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }

    private function createDirectorToken(int $campusId): string
    {
        $director = User::create([
            'LoginName' => 'dir-rct-' . bin2hex(random_bytes(3)) . '@example.com',
            'Name' => '主任', 'PSW' => 'x', 'type' => 'A', 'phone' => '0900' . random_int(100000, 999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function createTeacherToken(int $teacherId): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacherId, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    /**
     * @return array{0:string,1:int,2:int,3:ClassSession,4:LearningRecord,5?:int}
     */
    private function seedScenario(bool $withCampus = false): array
    {
        $campusId = 1;
        $dirToken = $this->createDirectorToken($campusId);

        $regular = User::create([
            'LoginName' => 'regular-rct-' . bin2hex(random_bytes(3)) . '@example.com',
            'Name' => '正班老師', 'PSW' => 'x', 'type' => 'T',
            'phone' => '0900' . random_int(100000, 999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub-rct-' . bin2hex(random_bytes(3)) . '@example.com',
            'Name' => '代課老師', 'PSW' => 'x', 'type' => 'T',
            'phone' => '0900' . random_int(100000, 999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '恢復正班測試生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-19',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        $lr = LearningRecord::create([
            'StudentClassID' => $sc->ID, 'ClassSessionID' => $session->id,
            'TeacherID' => $regular->id, 'Status' => 'pending', 'Content' => '',
            'SessionDate' => '2026-04-19', 'StartTime' => '13:00', 'EndTime' => '15:00',
        ]);

        if ($withCampus) {
            return [$dirToken, (int) $regular->id, (int) $sub->id, $session, $lr, $campusId];
        }

        return [$dirToken, (int) $regular->id, (int) $sub->id, $session, $lr];
    }
}
