<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** ADR-005 RestoreContractTeacher + temporary legacy shim. */
class RestoreContractTeacherTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_rejects_teacher_identity_and_restores_from_contract(): void
    {
        [$tok, $reg, $sub, $session, $lr] = $this->seedScenario();
        $this->auth($tok)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $sub,
        ])->assertOk();

        $this->auth($tok)->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [
            'substitute_teacher_id' => 99999,
        ])->assertStatus(422)->assertJsonFragment(['code' => 'teacher_identity_not_accepted']);

        $this->auth($tok)->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [
            'reason' => '回復正班老師',
        ])->assertOk()->assertJsonFragment([
            'restored_teacher_id' => $reg,
            'substitute_cleared' => true,
            'code' => 'restore_contract_teacher',
        ])->assertJsonMissing(['deprecated_entrypoint' => true]);

        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'scheduled',
            'teacher_id' => $sub,
        ]);
        $lr->refresh();
        $this->assertSame($reg, (int) $lr->TeacherID);
        if (Schema::hasTable('learning_record_teacher_changes')) {
            $this->assertDatabaseHas('learning_record_teacher_changes', [
                'learning_record_id' => $lr->id,
                'old_teacher_id' => $sub,
                'new_teacher_id' => $reg,
            ]);
        }
    }

    public function test_legacy_shim_uses_db_contract_teacher_and_shares_semantics(): void
    {
        [$tok, $old, $sub, $session, $lr, $campusId] = $this->seedScenario(true);
        $this->auth($tok)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $sub,
        ])->assertOk();

        $new = User::create([
            'LoginName' => 'coco-shim@example.com', 'Name' => 'Coco', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000888', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $new->id, 'Admin' => 0, 'Approved' => 1]);
        StudentClass::where('ID', $session->StudentClassID)->update(['TeacherID' => $new->id]);

        $legacy = $this->auth($tok)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => (int) $new->id,
            'reason' => 'legacy restore',
        ])->assertOk()->assertJsonFragment([
            'restored_teacher_id' => (int) $new->id,
            'code' => 'restore_contract_teacher',
            'deprecated_entrypoint' => true,
            'replacement_command' => 'restore_contract_teacher',
        ])->json();

        $lr->refresh();
        $this->assertSame((int) $new->id, (int) $lr->TeacherID);
        $this->assertNotSame($old, (int) $lr->TeacherID);

        // Re-substitute then named restore — same mutation envelope (sans deprecated).
        $this->auth($tok)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $sub,
        ])->assertOk();
        $named = $this->auth($tok)->postJson(
            "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
            ['reason' => 'named']
        )->assertOk()->json();
        $this->assertSame($named['restored_teacher_id'], $legacy['restored_teacher_id']);
        $this->assertSame($named['code'], $legacy['code']);
        $this->assertArrayNotHasKey('deprecated_entrypoint', $named);
    }

    public function test_forbidden_and_assign_substitute_unaffected(): void
    {
        [$tok, $reg, $sub, $session] = $this->seedScenario();
        $this->auth($tok)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $sub, 'reason' => '請假',
        ])->assertOk()->assertJsonFragment(['substitute_teacher_id' => $sub]);

        $this->auth($this->tokenFor($reg))
            ->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [])
            ->assertStatus(403);

        $this->auth($tok)->postJson('/api/v1/class-sessions/999999/restore-contract-teacher', [])
            ->assertStatus(404)->assertJsonFragment(['code' => 'session_not_found']);
    }

    private function auth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }

    private function tokenFor(int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $userId, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function seedScenario(bool $withCampus = false): array
    {
        $campusId = 1;
        $director = User::create([
            'LoginName' => 'dir-rct-' . bin2hex(random_bytes(3)) . '@example.com',
            'Name' => '主任', 'PSW' => 'x', 'type' => 'A',
            'phone' => '0900' . random_int(100000, 999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = $this->tokenFor($director->id);
        $regular = User::create([
            'LoginName' => 'reg-rct-' . bin2hex(random_bytes(3)) . '@example.com',
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
        $out = [$dirToken, (int) $regular->id, (int) $sub->id, $session, $lr];
        if ($withCampus) {
            $out[] = $campusId;
        }
        return $out;
    }
}
