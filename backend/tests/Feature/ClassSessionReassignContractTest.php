<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CourseContractGroup;
use App\Models\CourseContractGroupMember;
use App\Models\ClassSession;
use App\Models\SessionDeductionLedger;
use App\Models\StudentSignIn;
use App\Services\SessionDeductionService;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Contract renewal pain point (student 洪睿淵 case). Prior art: #1382
// RFC_COURSE_CONTINUITY.md links renewed contracts for a unified view but
// deliberately never moves session/evaluation history — this endpoint is the
// follow-up that lets an admin move an already-taught+evaluated ClassSession
// onto the renewed contract without the teacher refilling the evaluation.
class ClassSessionReassignContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_attended_session_moves_with_signin_ledger_lr_and_counters_on_both(): void
    {
        $token = $this->directorToken([1]);
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $new = $this->createCourse($student->id, subjectId: 1);
        $sessionId = $this->seedAttended($old, '2026-08-02');
        $this->seedAttended($old, '2026-08-09'); // stays on $old
        SessionDeductionService::recomputeCounters((int) $old->ID);
        $res = $this->move($token, $sessionId, $new->ID);
        $res->assertOk()->assertJsonPath('warnings', []);
        $this->assertSame((int) $new->ID, (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID'));
        foreach (['StudentSingIn', 'LearningRecord'] as $t) {
            $this->assertSame((int) $new->ID, (int) DB::table($t)->where('ClassSessionID', $sessionId)->value('StudentClassID'));
        }
        $this->assertSame((int) $new->ID, (int) DB::table('session_deduction_ledger')->where('class_session_id', $sessionId)->value('student_class_id'));
        $this->assertSame([1, 7, 1, 7], [(int) $old->fresh()->UsedSessions, (int) $old->fresh()->RemainingSessions, (int) $new->fresh()->UsedSessions, (int) $new->fresh()->RemainingSessions]);
        $this->assertDatabaseHas('class_session_reassignments', [
            'class_session_id' => $sessionId, 'old_student_class_id' => $old->ID,
            'new_student_class_id' => $new->ID, 'reason' => '改到正確合約',
        ]);
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'class_session.contract_reassigned']);
    }

    public function test_move_into_closed_contract_works_and_warns_when_remaining_left(): void
    {
        $token = $this->directorToken([1]);
        $student = $this->createStudent(1);
        $closed = $this->createCourse($student->id, subjectId: 1);
        DB::table('StudentClass')->where('ID', $closed->ID)->update(['Stop' => 1, 'closed_reason' => 'contract_amended', 'SessionCount' => 3, 'UsedSessions' => 3, 'RemainingSessions' => 0]);
        $cur = $this->createCourse($student->id, subjectId: 1);
        $sessionId = $this->seedAttended($cur, '2026-08-02');
        ClassSession::resetSettlementLockCache();
        $targets = $this->getJson("/api/v1/class-sessions/{$sessionId}/reassign-contract-targets", ['Authorization' => "Bearer {$token}"]);
        $targets->assertOk()->assertJsonPath('data.0.id', (int) $closed->ID)->assertJsonPath('data.0.closed', true);

        $res = $this->move($token, $sessionId, $closed->ID);

        $res->assertOk();
        $this->assertSame((int) $closed->ID, (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID'));
        $c = $closed->fresh();
        $this->assertSame([1, 1, 2, 'contract_amended'], [(int) $c->Stop, (int) $c->UsedSessions, (int) $c->RemainingSessions, $c->closed_reason]);
        $this->assertNotEmpty($res->json('warnings'));
    }

    public function test_slot_conflict_on_target_is_rejected(): void
    {
        $token = $this->directorToken([1]);
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $new = $this->createCourse($student->id, subjectId: 1);
        $sessionId = $this->seedAttended($old, '2026-08-02');
        $this->createClassSession((int) $new->ID, '2026-08-02', 'scheduled');

        $this->move($token, $sessionId, $new->ID)->assertStatus(422);
        $this->assertSame((int) $old->ID, (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID'));
        $this->assertDatabaseMissing('class_session_reassignments', ['class_session_id' => $sessionId]);
    }

    public function test_reason_required_and_teacher_and_other_campus_director_rejected(): void
    {
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $new = $this->createCourse($student->id, subjectId: 1);
        $sessionId = $this->createClassSession((int) $old->ID, '2026-08-02');
        $url = "/api/v1/class-sessions/{$sessionId}/reassign-contract";
        $targetsUrl = "/api/v1/class-sessions/{$sessionId}/reassign-contract-targets";

        $this->postJson($url, ['new_student_class_id' => $new->ID], ['Authorization' => 'Bearer ' . $this->directorToken([1])])->assertStatus(422);
        $teacher = $this->directorToken([1], 'T');
        $this->postJson($url, ['new_student_class_id' => $new->ID, 'reason' => 'x'], ['Authorization' => "Bearer {$teacher}"])->assertStatus(403);
        $this->getJson($targetsUrl, ['Authorization' => "Bearer {$teacher}"])->assertStatus(403);
        $other = $this->directorToken([2]);
        $this->postJson($url, ['new_student_class_id' => $new->ID, 'reason' => 'x'], ['Authorization' => "Bearer {$other}"])->assertStatus(403);
        $this->getJson($targetsUrl, ['Authorization' => "Bearer {$other}"])->assertStatus(403);
        $director = $this->directorToken([1]);
        foreach ([$this->createCourse($this->createStudent(1)->id, 1), $this->createCourse($student->id, 2)] as $bad) {
            $this->move($director, $sessionId, $bad->ID)->assertStatus(422); // other student / other subject
        }
    }

    public function test_moving_back_restores_everything(): void
    {
        $token = $this->superAdminToken();
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $new = $this->createCourse($student->id, subjectId: 1);
        $sessionId = $this->seedAttended($old, '2026-08-02');
        SessionDeductionService::recomputeCounters((int) $old->ID);

        $this->move($token, $sessionId, $new->ID, ['dry_run' => true])->assertOk()
            ->assertJsonPath('before.old.used_sessions', 1)->assertJsonPath('after.old.used_sessions', 0)
            ->assertJsonPath('after.new.used_sessions', 1);
        $this->move($token, $sessionId, $new->ID)->assertOk();
        $this->move($token, $sessionId, $old->ID, ['reason' => '改回'])->assertOk();

        $this->assertSame((int) $old->ID, (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID'));
        $this->assertSame((int) $old->ID, (int) DB::table('StudentSingIn')->where('ClassSessionID', $sessionId)->value('StudentClassID'));
        $this->assertSame([1, 7, 0, 8], [(int) $old->fresh()->UsedSessions, (int) $old->fresh()->RemainingSessions, (int) $new->fresh()->UsedSessions, (int) $new->fresh()->RemainingSessions]);
        $this->assertSame(2, DB::table('class_session_reassignments')->where('class_session_id', $sessionId)->count());
    }

    // helpers added for the move tests
    private function move(string $token, int $sessionId, int $toId, array $extra = [])
    {
        return $this->postJson(
            "/api/v1/class-sessions/{$sessionId}/reassign-contract",
            $extra + ['new_student_class_id' => $toId, 'reason' => '改到正確合約'],
            ['Authorization' => "Bearer {$token}"]
        );
    }

    private function seedAttended(StudentClass $course, string $date): int
    {
        $id = $this->createClassSession((int) $course->ID, $date);
        StudentSignIn::create([
            'StudentClassID' => $course->ID, 'StudentID' => $course->StudentID, 'TeacherID' => 1, 'GradeID' => 1,
            'SubjectID' => 1, 'Hours' => 2, 'SignInDT' => now()->subHours(2), 'SignOutDT' => now(), 'MDT' => now(),
            'ClassSessionID' => $id, 'Status' => 'present', 'CampusID' => 1, 'SessionDeducted' => true,
        ]);
        SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $id, 'event_type' => 'deduct', 'source' => 'attendance']);
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $id, 'TeacherID' => 1,
            'Content' => '評量', 'Status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    // ── helpers ──

    private function superAdminToken(): string
    {
        $user = User::create([
            'LoginName' => 'super-reassign-' . uniqid() . '@test.com',
            'Name' => '超級管理員測試',
            'PSW' => 'secret',
            'type' => 'S',
            'phone' => '0912000333',
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function directorToken(array $campusIds, string $type = 'A'): string
    {
        $user = User::create([
            'LoginName' => 'dir-reassign-' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => $type,
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
            'name' => '改派測試生-' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createCourse(int $studentId, int $subjectId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => $subjectId,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 8800,
            'Paid' => 1,
            'Rate' => 1100,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);
    }

    private function createClassSession(int $courseId, string $date, string $status = 'attended'): int
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
