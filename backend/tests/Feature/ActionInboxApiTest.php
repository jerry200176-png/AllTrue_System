<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\ExceptionWorkflow;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ExceptionWorkflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Action Inbox (B-lite + D): read-model aggregation of Notifications + open leave workflows.
 * Workflow remains the single source of truth for leave/makeup — no duplicated Notification rows.
 */
class ActionInboxApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-22 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_action_inbox_lists_open_leave_case_with_plain_language_fields(): void
    {
        [$student, $course, $session] = $this->makeStudentCourseSession(1, '陳小明', '0912111001');
        $token = $this->parentLogin('陳小明', '0912111001');
        $this->postJson("/api/v1/parent/sessions/{$session->id}/leave", [
            'reason' => '身體不舒服',
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $directorToken = $this->createDirectorToken([1]);
        $res = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', [
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ]);

        $res->assertOk()
            ->assertJsonPath('meta.cases_open', 1)
            ->assertJsonCount(1, 'data');

        $item = $res->json('data.0');
        $this->assertSame('case', $item['lane']);
        $this->assertSame('student_leave', $item['kind']);
        $this->assertStringStartsWith('workflow:', $item['id']);
        $this->assertSame('陳小明申請請假', $item['title']);
        $this->assertStringContainsString('17:00', $item['summary']);
        $this->assertStringContainsString('身體不舒服', (string) ($item['body'] ?? ''));
        $this->assertSame('等待安排補課', $item['status_label']);
        $this->assertSame('安排補課', $item['action']['label']);
        $this->assertSame('director', $item['action']['target']);
        $this->assertSame('exception-workflows', $item['action']['section']);
        $this->assertGreaterThan(0, (int) $item['action']['workflow_id']);
        $this->assertArrayNotHasKey('student_leave', $item);
        $this->assertStringNotContainsString('leave_requested', json_encode($item, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('exception workflow', strtolower(json_encode($item)));
    }

    public function test_duplicate_parent_leave_yields_single_inbox_case(): void
    {
        [, , $session] = $this->makeStudentCourseSession(1, '重複請假', '0912111002');
        $parentToken = $this->parentLogin('重複請假', '0912111002');

        $this->postJson("/api/v1/parent/sessions/{$session->id}/leave", [
            'reason' => '第一次',
        ], ['Authorization' => "Bearer {$parentToken}"])->assertOk();

        $this->postJson("/api/v1/parent/sessions/{$session->id}/leave", [
            'reason' => '重送',
        ], ['Authorization' => "Bearer {$parentToken}"])->assertOk();

        $directorToken = $this->createDirectorToken([1]);
        $res = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', [
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ]);

        $res->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.cases_open', 1);
        $this->assertDatabaseCount('exception_workflows', 1);
        $this->assertDatabaseCount('Notifications', 0);
    }

    public function test_action_inbox_is_campus_scoped_and_forbids_cross_campus_branch(): void
    {
        [$studentA, , $sessionA] = $this->makeStudentCourseSession(1, '大安學生', '0912111003');
        [$studentB, , $sessionB] = $this->makeStudentCourseSession(2, '木柵學生', '0912111004');
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$sessionA->id}",
            'campus_id' => 1,
            'student_id' => $studentA->id,
            'class_session_id' => $sessionA->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addDay(),
            'payload' => ['reason' => '大安原因'],
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$sessionB->id}",
            'campus_id' => 2,
            'student_id' => $studentB->id,
            'class_session_id' => $sessionB->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addDay(),
            'payload' => ['reason' => '木柵原因'],
        ]);

        $token = $this->createDirectorToken([1]);
        $ok = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
        $ok->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringContainsString('大安', $ok->json('data.0.title'));
        $this->assertStringNotContainsString('木柵原因', json_encode($ok->json(), JSON_UNESCAPED_UNICODE));

        $this->getJson('/api/v1/action-inbox?branch_id=2&lane=case', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertStatus(403);
    }

    public function test_confirmed_or_waived_case_disappears_from_inbox(): void
    {
        [$student, $course, $session] = $this->makeStudentCourseSession(1, '結案學生', '0912111005');
        $workflow = app(ExceptionWorkflowService::class)->createOrGet([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addDay(),
            'payload' => ['reason' => '先請假'],
        ]);
        $token = $this->createDirectorToken([1]);

        $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonCount(1, 'data');

        $workflow->status = 'confirmed';
        $workflow->closed_at = now();
        $workflow->save();

        $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.cases_open', 0);

        $workflow->status = 'waived';
        $workflow->save();
        $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_overdue_cases_sort_before_not_yet_due(): void
    {
        [$studentA, , $sessionA] = $this->makeStudentCourseSession(1, '未到期', '0912111006');
        [$studentB, , $sessionB] = $this->makeStudentCourseSession(1, '已逾期', '0912111007');

        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$sessionA->id}",
            'campus_id' => 1,
            'student_id' => $studentA->id,
            'class_session_id' => $sessionA->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addHours(6),
            'payload' => ['reason' => '未到期原因'],
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$sessionB->id}",
            'campus_id' => 1,
            'student_id' => $studentB->id,
            'class_session_id' => $sessionB->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->subHours(3),
            'payload' => ['reason' => '逾期原因'],
        ]);

        $token = $this->createDirectorToken([1]);
        $res = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        $res->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('已逾期申請請假', $res->json('data.0.title'));
        $this->assertTrue((bool) $res->json('data.0.overdue'));
        $this->assertGreaterThanOrEqual(3, (int) $res->json('data.0.overdue_hours'));
        $this->assertFalse((bool) $res->json('data.1.overdue'));
    }

    public function test_ops_lane_still_exposes_existing_notifications_without_creating_leave_notification(): void
    {
        [$student, , $session] = $this->makeStudentCourseSession(1, '通知學生', '0912111008');
        Notification::create([
            'CampusID' => 1,
            'Type' => 'tuition',
            'Severity' => 'high',
            'Title' => '通知學生 數學 未繳費',
            'Body' => '剩餘 1 堂，狀態：未繳費',
            'SourceType' => 'StudentClass',
            'SourceID' => '1',
            'SourceKey' => 'tuition:1:manual-test',
            'Payload' => ['student_id' => $student->id, 'student_name' => '通知學生'],
            'OccurredAt' => now(),
            'ResolvedAt' => null,
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $student->id,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addDay(),
            'payload' => ['reason' => '請假'],
        ]);

        $token = $this->createDirectorToken([1]);
        $all = $this->getJson('/api/v1/action-inbox?branch_id=1', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertOk();

        $lanes = collect($all->json('data'))->pluck('lane')->unique()->sort()->values()->all();
        $this->assertSame(['case', 'ops'], $lanes);

        $ops = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=ops', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertOk();
        $this->assertTrue(collect($ops->json('data'))->every(fn ($row) => $row['lane'] === 'ops'));
        $this->assertDatabaseMissing('Notifications', [
            'Type' => 'student_leave',
        ]);
    }

    public function test_count_separates_unread_notifications_and_open_cases(): void
    {
        [$student, , $session] = $this->makeStudentCourseSession(1, '計數學生', '0912111009');
        Notification::create([
            'CampusID' => 1,
            'Type' => 'pending_swipe',
            'Severity' => 'medium',
            'Title' => '未識別刷卡',
            'Body' => 'RFID X',
            'SourceType' => 'PendingSwipe',
            'SourceID' => '9',
            'SourceKey' => 'pending_swipe:1:9',
            'Payload' => [],
            'OccurredAt' => now(),
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $student->id,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addDay(),
        ]);

        $token = $this->createDirectorToken([1]);
        $res = $this->getJson('/api/v1/action-inbox/count?branch_id=1', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        $res->assertOk()
            ->assertJsonPath('notifications_unread', 1)
            ->assertJsonPath('cases_open', 1)
            ->assertJsonPath('needs_attention', 2);

        // Must not pretend cases are "unread notifications"
        $this->assertArrayNotHasKey('unread_count', $res->json());
    }

    public function test_teacher_cannot_read_leave_reason_via_action_inbox(): void
    {
        [$student, , $session] = $this->makeStudentCourseSession(1, '敏感學生', '0912111010');
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $student->id,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addDay(),
            'payload' => ['reason' => '家庭隱私事由'],
        ]);

        $teacherToken = $this->createTeacherToken([1]);
        $this->getJson('/api/v1/action-inbox?branch_id=1', [
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->assertStatus(403);

        $this->getJson('/api/v1/action-inbox/count?branch_id=1', [
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->assertStatus(403);
    }

    public function test_candidate_ready_still_counts_as_open_case(): void
    {
        [$student, $course, $session] = $this->makeStudentCourseSession(1, '候選就緒', '0912111011');
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'candidate_ready',
            'due_at' => now()->addDay(),
            'payload' => ['reason' => '已產生候選'],
        ]);

        $token = $this->createDirectorToken([1]);
        $this->getJson('/api/v1/action-inbox/count?branch_id=1', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('cases_open', 1);
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
            'StartDate' => '2026-07-01',
            'EndDate' => '2026-08-31',
            'TotalHours' => 8,
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
        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-07-24',
            'StartTime' => '17:00:00',
            'EndTime' => '19:00:00',
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

    private function createDirectorToken(array $campusIds, string $loginName = 'director-inbox@example.com'): string
    {
        return $this->createStaffToken($campusIds, $loginName, 'A');
    }

    private function createTeacherToken(array $campusIds, string $loginName = 'teacher-inbox@example.com'): string
    {
        return $this->createStaffToken($campusIds, $loginName, 'T');
    }

    private function createStaffToken(array $campusIds, string $loginName, string $type): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => $type === 'T' ? '老師測試' : '主任測試',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => 912345678,
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => $type === 'A' ? 1 : 0,
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
