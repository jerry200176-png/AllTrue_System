<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\ExceptionWorkflow;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ExceptionWorkflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExceptionWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-01 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_parent_leave_request_creates_idempotent_workflow_and_marks_session_requested(): void
    {
        [$student, $course, $session] = $this->makeStudentCourseSession(1, '請假學生', '0912000001');
        $token = $this->parentLogin('請假學生', '0912000001');

        $first = $this->postJson("/api/v1/parent/sessions/{$session->id}/leave", [
            'reason' => '感冒請假',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $first->assertOk()
            ->assertJsonPath('workflow.type', 'student_leave')
            ->assertJsonPath('workflow.status', 'open')
            ->assertJsonPath('session.status', 'leave_requested');

        $workflowId = (int) $first->json('workflow.id');
        $this->assertDatabaseHas('exception_workflows', [
            'id' => $workflowId,
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'source_type' => 'parent_portal',
            'source_id' => (string) $session->id,
        ]);
        $this->assertDatabaseHas('ClassSession', [
            'id' => $session->id,
            'Status' => 'leave_requested',
        ]);

        $second = $this->postJson("/api/v1/parent/sessions/{$session->id}/leave", [
            'reason' => '重送',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $second->assertOk();
        $this->assertSame($workflowId, (int) $second->json('workflow.id'));
        $this->assertDatabaseCount('exception_workflows', 1);
    }

    public function test_parent_leave_request_rejects_cross_student_session(): void
    {
        $this->makeStudentCourseSession(1, '本人學生', '0912000002');
        [, , $otherSession] = $this->makeStudentCourseSession(1, '別人學生', '0912000003');
        $token = $this->parentLogin('本人學生', '0912000002');

        $res = $this->postJson("/api/v1/parent/sessions/{$otherSession->id}/leave", [
            'reason' => '嘗試越權',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $res->assertStatus(403);
        $this->assertDatabaseCount('exception_workflows', 0);
    }

    public function test_director_workflow_inbox_is_scoped_by_campus(): void
    {
        [$studentA, , $sessionA] = $this->makeStudentCourseSession(1, '大安學生', '0912000004');
        [$studentB, , $sessionB] = $this->makeStudentCourseSession(2, '木柵學生', '0912000005');
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$sessionA->id}",
            'campus_id' => 1,
            'student_id' => $studentA->id,
            'class_session_id' => $sessionA->id,
            'type' => 'student_leave',
            'status' => 'open',
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$sessionB->id}",
            'campus_id' => 2,
            'student_id' => $studentB->id,
            'class_session_id' => $sessionB->id,
            'type' => 'student_leave',
            'status' => 'open',
        ]);
        $token = $this->createDirectorToken([1]);

        $res = $this->getJson('/api/v1/exception-workflows?branch_id=1', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        $res->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($studentA->id, (int) $res->json('data.0.student.id'));

        $this->getJson('/api/v1/exception-workflows?branch_id=2', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertStatus(403);
    }

    public function test_director_can_generate_makeup_candidates_for_leave_workflow(): void
    {
        [$student, $course, $session] = $this->makeStudentCourseSession(1, '候選學生', '0912000006');
        $workflow = app(ExceptionWorkflowService::class)->createOrGet([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
        ]);
        [$otherStudent, $otherCourse] = $this->makeStudentCourseSession(1, '佔用學生', '0912000007');
        ClassSession::create([
            'StudentClassID' => $otherCourse->ID,
            'SessionDate' => '2026-05-07',
            'StartTime' => '09:00',
            'EndTime' => '11:00',
            'Status' => 'scheduled',
        ]);
        $token = $this->createDirectorToken([1]);

        $res = $this->postJson("/api/v1/exception-workflows/{$workflow->id}/generate-candidates", [
            'start_date' => '2026-05-07',
            'end_date' => '2026-05-07',
            'limit' => 3,
        ], [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.workflow_id', $workflow->id)
            ->assertJsonPath('data.generated_count', 3)
            ->assertJsonPath('data.candidates.0.candidate_date', '2026-05-07');

        $starts = collect($res->json('data.candidates'))->pluck('start_time')->all();
        $this->assertNotContains('09:00:00', $starts);
        $this->assertDatabaseCount('exception_workflow_candidates', 3);
        $this->assertDatabaseHas('exception_workflows', [
            'id' => $workflow->id,
            'status' => 'candidate_ready',
        ]);
    }

    public function test_generate_candidates_rejects_cross_campus_director(): void
    {
        [$student, $course, $session] = $this->makeStudentCourseSession(2, '跨校候選學生', '0912000008');
        $workflow = app(ExceptionWorkflowService::class)->createOrGet([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 2,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
        ]);
        $token = $this->createDirectorToken([1]);

        $this->postJson("/api/v1/exception-workflows/{$workflow->id}/generate-candidates", [
            'start_date' => '2026-05-07',
            'end_date' => '2026-05-07',
        ], [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertStatus(403);

        $this->assertDatabaseCount('exception_workflow_candidates', 0);
    }

    public function test_director_can_confirm_candidate_to_create_makeup_schedule_and_class_session(): void
    {
        [$student, $course, $session] = $this->makeStudentCourseSession(1, '確認補課學生', '0912000009');
        $workflow = app(ExceptionWorkflowService::class)->createOrGet([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
        ]);
        $token = $this->createDirectorToken([1]);
        $generated = $this->postJson("/api/v1/exception-workflows/{$workflow->id}/generate-candidates", [
            'start_date' => '2026-05-07',
            'end_date' => '2026-05-07',
            'limit' => 1,
        ], [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertOk();
        $candidateId = (int) $generated->json('data.candidates.0.id');

        $confirmed = $this->postJson("/api/v1/exception-workflows/{$workflow->id}/confirm-candidate", [
            'candidate_id' => $candidateId,
        ], [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        $confirmed->assertOk()
            ->assertJsonPath('data.workflow.status', 'confirmed')
            ->assertJsonPath('data.schedule.status', 'scheduled')
            ->assertJsonPath('data.class_session.status', 'scheduled');

        $this->assertDatabaseHas('schedules', [
            'student_id' => $student->id,
            'teacher_id' => 1,
            'branch_id' => 1,
            'student_course_id' => $course->ID,
            'schedule_date' => '2026-05-07',
            'status' => 'scheduled',
            'type' => 'extra',
        ]);
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-05-07',
            'Status' => 'scheduled',
            'IsContractException' => 1,
        ]);
        $this->assertDatabaseHas('exception_workflows', [
            'id' => $workflow->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_confirm_candidate_is_idempotent_and_rejects_cross_campus(): void
    {
        [$student, $course, $session] = $this->makeStudentCourseSession(2, '確認跨校學生', '0912000010');
        $workflow = app(ExceptionWorkflowService::class)->createOrGet([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 2,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
        ]);
        $allowedToken = $this->createDirectorToken([2], 'director-workflow-allowed@example.com');
        $candidateId = (int) $this->postJson("/api/v1/exception-workflows/{$workflow->id}/generate-candidates", [
            'start_date' => '2026-05-07',
            'end_date' => '2026-05-07',
            'limit' => 1,
        ], [
            'Authorization' => "Bearer {$allowedToken}",
            'Accept' => 'application/json',
        ])->assertOk()->json('data.candidates.0.id');
        $blockedToken = $this->createDirectorToken([1], 'director-workflow-blocked@example.com');

        $this->postJson("/api/v1/exception-workflows/{$workflow->id}/confirm-candidate", [
            'candidate_id' => $candidateId,
        ], [
            'Authorization' => "Bearer {$blockedToken}",
            'Accept' => 'application/json',
        ])->assertStatus(403);

        $first = $this->postJson("/api/v1/exception-workflows/{$workflow->id}/confirm-candidate", [
            'candidate_id' => $candidateId,
        ], [
            'Authorization' => "Bearer {$allowedToken}",
            'Accept' => 'application/json',
        ])->assertOk();
        $second = $this->postJson("/api/v1/exception-workflows/{$workflow->id}/confirm-candidate", [
            'candidate_id' => $candidateId,
        ], [
            'Authorization' => "Bearer {$allowedToken}",
            'Accept' => 'application/json',
        ])->assertOk();

        $this->assertSame((int) $first->json('data.schedule.id'), (int) $second->json('data.schedule.id'));
        $this->assertDatabaseCount('schedules', 1);
        $this->assertSame(2, ClassSession::where('StudentClassID', $course->ID)->count());
    }

    private function makeStudentCourseSession(int $campusId, string $name, string $phone): array
    {
        $student = Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'SchoolName' => '測試學校',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => $phone,
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-01',
            'EndDate' => '2026-06-30',
            'TotalHours' => 8,
            'Charge' => 8800,
            'Paid' => 1,
            'Rate' => 1100,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-05-06',
            'StartTime' => '18:30',
            'EndTime' => '20:30',
            'Status' => 'scheduled',
        ]);

        return [$student, $course, $session];
    }

    private function parentLogin(string $name, string $phone): string
    {
        $res = $this->postJson('/api/v1/parent/login', [
            'Name' => $name,
            'Phone' => $phone,
        ]);
        $res->assertOk();
        return $res->json('token');
    }

    private function createDirectorToken(array $campusIds, string $loginName = 'director-workflow@example.com'): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
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
}
