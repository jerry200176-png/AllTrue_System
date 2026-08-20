<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CourseContractGroup;
use App\Models\CourseContractGroupMember;
use App\Models\LearningRecord;
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

    public function test_reassigns_session_keeps_learning_record_content_but_syncs_student_class_id_and_recalculates_both_contracts(): void
    {
        $token = $this->superAdminToken();
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $new = $this->createCourse($student->id, subjectId: 1);
        $this->linkGroup($student->id, 1, [$old->ID, $new->ID]);

        $sessionId = $this->createClassSession((int) $old->ID, '2026-08-02');
        $lrContentBefore = '轉移測試評量內容';
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $old->ID,
            'ClassSessionID' => $sessionId,
            'TeacherID' => 1,
            'Content' => $lrContentBefore,
            'Status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lrBefore = DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->first();

        $res = $this->postJson(
            "/api/v1/class-sessions/{$sessionId}/reassign-contract",
            ['new_student_class_id' => $new->ID, 'reason' => '合約續約，堂次改派'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $this->assertSame(
            (int) $new->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );

        // LearningRecord *content* must be byte-for-byte unchanged (Content,
        // Status, TeacherID, etc.) — the teacher's work is never touched.
        // But StudentClassID is a denormalized mirror of
        // ClassSession.StudentClassID (relied on by billing/approval queries
        // in StudentClassController), so it MUST follow the session to the
        // new contract — same invariant the existing transfer-sessions
        // endpoint enforces. Only that one column may differ from $lrBefore.
        $lrAfter = DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->first();
        $beforeMinusStudentClassId = (array) $lrBefore;
        $afterMinusStudentClassId = (array) $lrAfter;
        unset($beforeMinusStudentClassId['StudentClassID'], $afterMinusStudentClassId['StudentClassID']);
        $this->assertEquals($beforeMinusStudentClassId, $afterMinusStudentClassId);
        $this->assertSame($lrContentBefore, $lrAfter->Content);
        $this->assertSame((int) $new->ID, (int) $lrAfter->StudentClassID);

        // Both contracts' 已用/剩餘堂數 recalculated via the same
        // SessionDeductionService the rest of the app uses.
        $old->refresh();
        $new->refresh();
        $this->assertSame(0, (int) $old->UsedSessions);
        $this->assertSame(8, (int) $old->RemainingSessions);
        $this->assertSame(1, (int) $new->UsedSessions);
        $this->assertSame(7, (int) $new->RemainingSessions);

        $this->assertDatabaseHas('class_session_reassignments', [
            'class_session_id' => $sessionId,
            'old_student_class_id' => $old->ID,
            'new_student_class_id' => $new->ID,
            'reason' => '合約續約，堂次改派',
        ]);
    }

    public function test_rejects_cross_student_reassignment(): void
    {
        $token = $this->superAdminToken();
        $studentA = $this->createStudent(1);
        $studentB = $this->createStudent(1);
        $old = $this->createCourse($studentA->id, subjectId: 1);
        $new = $this->createCourse($studentB->id, subjectId: 1);
        $this->linkGroup($studentA->id, 1, [$old->ID]); // group irrelevant here; fails student check first
        $sessionId = $this->createClassSession((int) $old->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/class-sessions/{$sessionId}/reassign-contract",
            ['new_student_class_id' => $new->ID, 'reason' => '測試'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $this->assertSame(
            (int) $old->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
        $this->assertDatabaseMissing('class_session_reassignments', ['class_session_id' => $sessionId]);
    }

    public function test_rejects_cross_subject_reassignment(): void
    {
        $token = $this->superAdminToken();
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $new = $this->createCourse($student->id, subjectId: 2);
        $this->linkGroup($student->id, 1, [$old->ID, $new->ID]);
        $sessionId = $this->createClassSession((int) $old->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/class-sessions/{$sessionId}/reassign-contract",
            ['new_student_class_id' => $new->ID, 'reason' => '測試'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $this->assertSame(
            (int) $old->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
    }

    public function test_rejects_when_contracts_not_linked_via_course_contract_group(): void
    {
        $token = $this->superAdminToken();
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $new = $this->createCourse($student->id, subjectId: 1);
        // No course_contract_group linkage created.
        $sessionId = $this->createClassSession((int) $old->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/class-sessions/{$sessionId}/reassign-contract",
            ['new_student_class_id' => $new->ID, 'reason' => '測試'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $this->assertSame(
            (int) $old->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
    }

    public function test_rejects_when_caller_is_not_super_admin(): void
    {
        $token = $this->directorToken([1]);
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $new = $this->createCourse($student->id, subjectId: 1);
        $this->linkGroup($student->id, 1, [$old->ID, $new->ID]);
        $sessionId = $this->createClassSession((int) $old->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/class-sessions/{$sessionId}/reassign-contract",
            ['new_student_class_id' => $new->ID, 'reason' => '測試'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(403);
        $this->assertSame(
            (int) $old->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
    }

    public function test_targets_endpoint_lists_only_group_linked_same_student_subject_contracts(): void
    {
        $token = $this->superAdminToken();
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $new = $this->createCourse($student->id, subjectId: 1);
        $unrelated = $this->createCourse($student->id, subjectId: 1); // not linked to $old's group
        $this->linkGroup($student->id, 1, [$old->ID, $new->ID]);
        $sessionId = $this->createClassSession((int) $old->ID, '2026-08-02');

        $res = $this->getJson(
            "/api/v1/class-sessions/{$sessionId}/reassign-contract-targets",
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([(int) $new->ID], $ids);
        $this->assertNotContains((int) $unrelated->ID, $ids);
        $this->assertNotContains((int) $old->ID, $ids);
        $this->assertSame(8, $res->json('data.0.remaining_sessions'));
    }

    public function test_targets_endpoint_empty_when_no_group_link(): void
    {
        $token = $this->superAdminToken();
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $sessionId = $this->createClassSession((int) $old->ID, '2026-08-02');

        $res = $this->getJson(
            "/api/v1/class-sessions/{$sessionId}/reassign-contract-targets",
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }

    public function test_targets_endpoint_rejects_when_caller_is_not_super_admin(): void
    {
        $token = $this->directorToken([1]);
        $student = $this->createStudent(1);
        $old = $this->createCourse($student->id, subjectId: 1);
        $sessionId = $this->createClassSession((int) $old->ID, '2026-08-02');

        $res = $this->getJson(
            "/api/v1/class-sessions/{$sessionId}/reassign-contract-targets",
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(403);
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

    private function directorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-reassign-' . uniqid() . '@test.com',
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

    private function createClassSession(int $courseId, string $date): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'attended',
        ]);
    }

    /**
     * @param  array<int>  $studentClassIds
     */
    private function linkGroup(int $studentId, int $campusId, array $studentClassIds): CourseContractGroup
    {
        $group = CourseContractGroup::create([
            'student_id' => $studentId,
            'campus_id' => $campusId,
        ]);
        $seq = 0;
        foreach ($studentClassIds as $scId) {
            CourseContractGroupMember::create([
                'group_id' => $group->id,
                'student_class_id' => $scId,
                'relation_type' => $seq === 0 ? 'original' : 'renewal',
                'sequence' => $seq++,
            ]);
        }
        return $group;
    }
}
