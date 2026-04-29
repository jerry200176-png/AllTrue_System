<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\ExceptionWorkflow;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExceptionWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

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

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-workflow@example.com',
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
