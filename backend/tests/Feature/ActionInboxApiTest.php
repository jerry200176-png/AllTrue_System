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

    public function test_lists_open_leave_case_in_plain_language_without_notification_row(): void
    {
        [, , $session] = $this->seedSession(1, '陳小明', '0912111001');
        $this->postJson("/api/v1/parent/sessions/{$session->id}/leave", [
            'reason' => '身體不舒服',
        ], ['Authorization' => 'Bearer '.$this->parentToken('陳小明', '0912111001')])->assertOk();

        $res = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $this->dirHeaders([1]));
        $res->assertOk()->assertJsonPath('meta.cases_open', 1)->assertJsonCount(1, 'data');
        $item = $res->json('data.0');
        $this->assertSame('case', $item['lane']);
        $this->assertSame('陳小明申請請假', $item['title']);
        $this->assertSame('等待安排補課', $item['status_label']);
        $this->assertSame('安排補課', $item['action']['label']);
        $this->assertSame('director', $item['action']['target']);
        $this->assertSame('exception-workflows', $item['action']['section']);
        $this->assertStringContainsString('身體不舒服', (string) $item['body']);
        $this->assertDatabaseCount('Notifications', 0);
    }

    public function test_duplicate_leave_yields_one_case_and_campus_scope_holds(): void
    {
        [, , $s1] = $this->seedSession(1, '大安', '0912111002');
        [, $c2, $s2] = $this->seedSession(2, '木柵', '0912111003');
        $tok = $this->parentToken('大安', '0912111002');
        $this->postJson("/api/v1/parent/sessions/{$s1->id}/leave", ['reason' => 'a'], ['Authorization' => "Bearer {$tok}"])->assertOk();
        $this->postJson("/api/v1/parent/sessions/{$s1->id}/leave", ['reason' => 'b'], ['Authorization' => "Bearer {$tok}"])->assertOk();
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$s2->id}",
            'campus_id' => 2,
            'student_id' => Student::where('name', '木柵')->value('id'),
            'student_class_id' => $c2->ID,
            'class_session_id' => $s2->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addDay(),
            'payload' => ['reason' => '木柵原因'],
        ]);

        $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $this->dirHeaders([1]))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('exception_workflows', 2);
        $this->getJson('/api/v1/action-inbox?branch_id=2&lane=case', $this->dirHeaders([1]))
            ->assertStatus(403);
    }

    public function test_closed_cases_disappear_and_overdue_sorts_first(): void
    {
        [$stu, $course, $session] = $this->seedSession(1, '結案', '0912111004');
        $wf = app(ExceptionWorkflowService::class)->createOrGet([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $stu->id,
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addDay(),
        ]);
        $h = $this->dirHeaders([1]);
        $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $h)->assertOk()->assertJsonCount(1, 'data');
        $wf->update(['status' => 'confirmed', 'closed_at' => now()]);
        $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $h)->assertOk()->assertJsonCount(0, 'data');

        [$a, , $sa] = $this->seedSession(1, '未到期', '0912111005');
        [$b, , $sb] = $this->seedSession(1, '已逾期', '0912111006');
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$sa->id}", 'campus_id' => 1, 'student_id' => $a->id,
            'class_session_id' => $sa->id, 'type' => 'student_leave', 'status' => 'open',
            'due_at' => now()->addHours(6), 'payload' => ['reason' => 'x'],
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$sb->id}", 'campus_id' => 1, 'student_id' => $b->id,
            'class_session_id' => $sb->id, 'type' => 'student_leave', 'status' => 'open',
            'due_at' => now()->subHours(3), 'payload' => ['reason' => 'y'],
        ]);
        $res = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $h)->assertOk();
        $this->assertSame('已逾期申請請假', $res->json('data.0.title'));
        $this->assertTrue($res->json('data.0.overdue'));
    }

    public function test_count_contract_and_teacher_forbidden_and_candidate_ready_counts(): void
    {
        [$stu, $course, $session] = $this->seedSession(1, '計數', '0912111007');
        Notification::create([
            'CampusID' => 1, 'Type' => 'pending_swipe', 'Severity' => 'medium',
            'Title' => '未識別刷卡', 'Body' => 'RFID', 'SourceType' => 'PendingSwipe',
            'SourceID' => '9', 'SourceKey' => 'pending_swipe:1:9', 'Payload' => [], 'OccurredAt' => now(),
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$session->id}", 'campus_id' => 1,
            'student_id' => $stu->id, 'student_class_id' => $course->ID, 'class_session_id' => $session->id,
            'type' => 'student_leave', 'status' => 'candidate_ready', 'due_at' => now()->addDay(),
            'payload' => ['reason' => '家庭隱私事由'],
        ]);

        $this->getJson('/api/v1/action-inbox/count?branch_id=1', $this->dirHeaders([1]))
            ->assertOk()
            ->assertJsonPath('notifications_unread', 1)
            ->assertJsonPath('cases_open', 1)
            ->assertJsonPath('needs_attention', 2)
            ->assertJsonMissing(['unread_count' => 2]);

        $teacher = $this->staffToken([1], 'teacher-inbox@example.com', 'T');
        $this->getJson('/api/v1/action-inbox?branch_id=1', [
            'Authorization' => "Bearer {$teacher}", 'Accept' => 'application/json',
        ])->assertStatus(403);
    }

    public function test_ops_lane_keeps_existing_notifications(): void
    {
        [$stu, , $session] = $this->seedSession(1, '通知學生', '0912111008');
        Notification::create([
            'CampusID' => 1, 'Type' => 'tuition', 'Severity' => 'high',
            'Title' => '通知學生 數學 未繳費', 'Body' => '未繳費', 'SourceType' => 'StudentClass',
            'SourceID' => '1', 'SourceKey' => 'tuition:1:manual', 'Payload' => ['student_name' => '通知學生'],
            'OccurredAt' => now(),
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$session->id}", 'campus_id' => 1,
            'student_id' => $stu->id, 'class_session_id' => $session->id,
            'type' => 'student_leave', 'status' => 'open', 'due_at' => now()->addDay(),
        ]);
        $all = $this->getJson('/api/v1/action-inbox?branch_id=1', $this->dirHeaders([1]))->assertOk();
        $lanes = collect($all->json('data'))->pluck('lane')->unique()->sort()->values()->all();
        $this->assertSame(['case', 'ops'], $lanes);
        $this->assertDatabaseMissing('Notifications', ['Type' => 'student_leave']);
    }

    private function seedSession(int $campusId, string $name, string $phone): array
    {
        $student = Student::create([
            'name' => $name, 'CampusID' => $campusId, 'ClassID' => 1, 'SchoolName' => '測試學校',
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => $phone,
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-07-01', 'EndDate' => '2026-08-31',
            'TotalHours' => 8, 'Charge' => 8800, 'Paid' => 1, 'Rate' => 1100, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => '2026-07-24',
            'StartTime' => '17:00:00', 'EndTime' => '19:00:00', 'Status' => 'scheduled',
        ]);

        return [$student, $course, $session];
    }

    private function parentToken(string $name, string $phone): string
    {
        return $this->postJson('/api/v1/parent/login', ['Name' => $name, 'Phone' => $phone])
            ->assertOk()->json('token');
    }

    private function dirHeaders(array $campusIds): array
    {
        return [
            'Authorization' => 'Bearer '.$this->staffToken($campusIds, 'director-inbox@example.com', 'A'),
            'Accept' => 'application/json',
        ];
    }

    private function staffToken(array $campusIds, string $login, string $type): string
    {
        $user = User::create([
            'LoginName' => $login, 'Name' => $type === 'T' ? '老師' : '主任',
            'PSW' => 'secret', 'type' => $type, 'phone' => 912345678,
        ]);
        foreach ($campusIds as $id) {
            UserCampus::create(['CampusID' => $id, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
