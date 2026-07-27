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

/** ADR-005 RestoreContractTeacher named command boundary. */
class RestoreContractTeacherTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_happy_path_and_rejects_teacher_identity(): void
    {
        [$dirToken, $regularId, $subId, $session, $lr] = $this->seedScenario();

        $this->auth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        $this->auth($dirToken)->postJson(
            "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
            ['substitute_teacher_id' => 99999]
        )->assertStatus(422)->assertJsonFragment(['code' => 'teacher_identity_not_accepted']);

        $this->auth($dirToken)->postJson(
            "/api/v1/class-sessions/{$session->id}/restore-contract-teacher",
            ['reason' => '回復正班老師']
        )->assertOk()->assertJsonFragment([
            'restored_teacher_id' => $regularId,
            'substitute_cleared' => true,
            'code' => 'restore_contract_teacher',
        ]);

        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'scheduled',
            'teacher_id' => $subId,
        ]);
        $lr->refresh();
        $this->assertSame($regularId, (int) $lr->TeacherID);

        if (Schema::hasTable('learning_record_teacher_changes')) {
            $this->assertDatabaseHas('learning_record_teacher_changes', [
                'learning_record_id' => $lr->id,
                'old_teacher_id' => $subId,
                'new_teacher_id' => $regularId,
            ]);
        }
    }

    public function test_not_found_missing_contract_forbidden_and_legacy_unreachable(): void
    {
        [$dirToken, $regularId, $subId, $session] = $this->seedScenario();
        $this->auth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        $this->auth($dirToken)->postJson('/api/v1/class-sessions/999999/restore-contract-teacher', [])
            ->assertStatus(404)->assertJsonFragment(['code' => 'session_not_found']);

        StudentClass::where('ID', $session->StudentClassID)->update(['TeacherID' => 0]);
        $this->auth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [])
            ->assertStatus(422)->assertJsonFragment(['code' => 'contract_teacher_missing']);
        StudentClass::where('ID', $session->StudentClassID)->update(['TeacherID' => $regularId]);

        $teacherToken = $this->tokenFor($regularId);
        $this->auth($teacherToken)->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [])
            ->assertStatus(403);

        $other = User::create([
            'LoginName' => 'dir-x@example.com', 'Name' => '他校', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0911222333', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 99, 'UserID' => $other->id, 'Admin' => 1, 'Approved' => 1]);
        $otherTok = $this->tokenFor($other->id);
        $this->auth($otherTok)->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [])
            ->assertStatus(403);

        $this->auth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $regularId,
        ])->assertStatus(422)->assertJsonFragment(['code' => 'use_restore_contract_teacher']);

        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'scheduled',
            'teacher_id' => $subId,
        ]);
    }

    public function test_uses_current_contract_teacher_after_contract_change(): void
    {
        [$dirToken, $oldId, $subId, $session, $lr, $campusId] = $this->seedScenario(true);
        $this->auth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        $new = User::create([
            'LoginName' => 'coco-rct@example.com', 'Name' => 'Coco', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000999', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $new->id, 'Admin' => 0, 'Approved' => 1]);
        StudentClass::where('ID', $session->StudentClassID)->update(['TeacherID' => $new->id]);

        \App\Models\Schedule::where('student_course_id', $session->StudentClassID)
            ->whereDate('schedule_date', '2026-04-19')
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->update(['teacher_id' => $oldId]);

        $this->auth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/restore-contract-teacher", [])
            ->assertOk()->assertJsonFragment(['restored_teacher_id' => (int) $new->id, 'substitute_cleared' => true]);
        $lr->refresh();
        $this->assertSame((int) $new->id, (int) $lr->TeacherID);
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
