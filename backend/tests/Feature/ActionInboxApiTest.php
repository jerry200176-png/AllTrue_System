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

    public function test_lists_open_leave_case_as_display_dto_without_notification_row(): void
    {
        [, , $session] = $this->seedSession(1, '陳小明', '0912111001');
        $this->postJson("/api/v1/parent/sessions/{$session->id}/leave", [
            'reason' => '身體不舒服',
        ], ['Authorization' => 'Bearer '.$this->parentToken('陳小明', '0912111001')])->assertOk();

        $res = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $this->dirHeaders([1]));
        $res->assertOk()
            ->assertJsonPath('summary.cases_unresolved', 1)
            ->assertJsonPath('cases.total', 1)
            ->assertJsonCount(1, 'cases.data');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        $item = $res->json('cases.data.0');
        $this->assertSame('case', $item['lane']);
        $this->assertSame('陳小明申請請假', $item['title']);
        $this->assertSame('open', $item['status_code']);
        $this->assertSame('等待安排補課', $item['status_label']);
        $this->assertSame('安排補課', $item['action']['label']);
        $this->assertSame('director', $item['action']['target']);
        $this->assertSame('exception-workflows', $item['action']['section']);
        $this->assertSame('陳小明', $item['student_name']);
        $this->assertArrayNotHasKey('payload', $item);
        $this->assertArrayNotHasKey('body', $item);
        $this->assertSame('身體不舒服', $item['reason_preview']);
        $this->assertDatabaseCount('Notifications', 0);
    }

    public function test_candidate_ready_uses_distinct_status_and_cta(): void
    {
        [$stu, $course, $session] = $this->seedSession(1, '方案待確認', '0912111099');
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$session->id}",
            'campus_id' => 1,
            'student_id' => $stu->id,
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'candidate_ready',
            'due_at' => now()->addDay(),
            'payload' => ['reason' => '家事'],
        ]);

        $item = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $this->dirHeaders([1]))
            ->assertOk()
            ->json('cases.data.0');
        $this->assertSame('candidate_ready', $item['status_code']);
        $this->assertSame('補課方案待確認', $item['status_label']);
        $this->assertSame('檢視並確認', $item['action']['label']);
    }

    public function test_director_with_zero_campuses_gets_403(): void
    {
        // Fail-closed: RequireCampus middleware and/or ActionInbox scope resolver.
        $this->getJson('/api/v1/action-inbox', $this->dirHeaders([], 'director-zero@example.com'))
            ->assertStatus(403);
        $this->getJson('/api/v1/action-inbox/count', $this->dirHeaders([], 'director-zero-count@example.com'))
            ->assertStatus(403);
    }

    public function test_director_without_branch_id_only_sees_authorized_campuses(): void
    {
        [, , $s1] = $this->seedSession(1, '校區一', '0912111101');
        [, , $s2] = $this->seedSession(2, '校區二', '0912111102');
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$s1->id}", 'campus_id' => 1,
            'student_id' => Student::where('name', '校區一')->value('id'),
            'class_session_id' => $s1->id, 'type' => 'student_leave', 'status' => 'open',
            'due_at' => now()->addDay(),
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$s2->id}", 'campus_id' => 2,
            'student_id' => Student::where('name', '校區二')->value('id'),
            'class_session_id' => $s2->id, 'type' => 'student_leave', 'status' => 'open',
            'due_at' => now()->addDay(),
        ]);

        $res = $this->getJson('/api/v1/action-inbox?lane=case', $this->dirHeaders([1], 'director-scope-a@example.com'))
            ->assertOk();
        $this->assertSame(1, $res->json('cases.total'));
        $this->assertSame(1, $res->json('summary.cases_unresolved'));
        $this->assertSame(1, (int) $res->json('cases.data.0.campus_id'));
    }

    public function test_director_requesting_unauthorized_branch_gets_403(): void
    {
        $this->getJson('/api/v1/action-inbox?branch_id=2&lane=case', $this->dirHeaders([1], 'director-unauth@example.com'))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'unauthorized_campus');
        $this->getJson('/api/v1/action-inbox/count?branch_id=2', $this->dirHeaders([1], 'director-unauth-c@example.com'))
            ->assertStatus(403);
    }

    public function test_super_admin_without_branch_id_sees_all_campuses(): void
    {
        [, , $s1] = $this->seedSession(1, '超管一', '0912111103');
        [, , $s2] = $this->seedSession(2, '超管二', '0912111104');
        foreach ([[$s1, 1, '超管一'], [$s2, 2, '超管二']] as [$session, $campus, $name]) {
            ExceptionWorkflow::create([
                'source_key' => "parent_leave:class_session:{$session->id}",
                'campus_id' => $campus,
                'student_id' => Student::where('name', $name)->value('id'),
                'class_session_id' => $session->id,
                'type' => 'student_leave',
                'status' => 'open',
                'due_at' => now()->addDay(),
            ]);
        }

        $res = $this->getJson('/api/v1/action-inbox?lane=case', $this->superHeaders())
            ->assertOk();
        $this->assertSame(2, $res->json('cases.total'));
        $this->assertSame(2, $res->json('summary.cases_unresolved'));
        $this->assertSame('all', $res->json('meta.scope_mode'));
    }

    public function test_teacher_forbidden(): void
    {
        $teacher = $this->staffToken([1], 'teacher-inbox@example.com', 'T');
        $this->getJson('/api/v1/action-inbox?branch_id=1', [
            'Authorization' => "Bearer {$teacher}", 'Accept' => 'application/json',
        ])->assertStatus(403);
        $this->getJson('/api/v1/action-inbox/count?branch_id=1', [
            'Authorization' => "Bearer {$teacher}", 'Accept' => 'application/json',
        ])->assertStatus(403);
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

        $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $this->dirHeaders([1], 'director-inbox-a@example.com'))
            ->assertOk()->assertJsonPath('cases.total', 1);
        $this->assertDatabaseCount('exception_workflows', 2);
        $this->getJson('/api/v1/action-inbox?branch_id=2&lane=case', $this->dirHeaders([1], 'director-inbox-b@example.com'))
            ->assertStatus(403);
    }

    public function test_closed_cases_disappear_from_unresolved_lane_and_overdue_sorts_first(): void
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
        $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $h)
            ->assertOk()->assertJsonPath('cases.total', 1);
        $wf->update(['status' => 'confirmed', 'closed_at' => now()]);
        $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $h)
            ->assertOk()->assertJsonPath('cases.total', 0);

        // Deep link still returns closed case result.
        $this->getJson('/api/v1/action-inbox/cases/'.$wf->id.'?branch_id=1', $h)
            ->assertOk()
            ->assertJsonPath('data.status_code', 'confirmed')
            ->assertJsonPath('data.status_label', '已安排補課')
            ->assertJsonPath('data.action.label', '查看結果');

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
        $this->assertSame('已逾期申請請假', $res->json('cases.data.0.title'));
        $this->assertTrue($res->json('cases.data.0.overdue'));
        $this->assertSame('overdue', $res->json('cases.data.0.priority'));
    }

    public function test_count_contract_overdue_due_soon_urgent_and_no_store(): void
    {
        [$stu, $course, $session] = $this->seedSession(1, '計數', '0912111007');
        Notification::create([
            'CampusID' => 1, 'Type' => 'pending_swipe', 'Severity' => 'medium',
            'Title' => '未識別刷卡', 'Body' => 'RFID', 'SourceType' => 'PendingSwipe',
            'SourceID' => '9', 'SourceKey' => 'pending_swipe:1:9', 'Payload' => [], 'OccurredAt' => now(),
        ]);
        Notification::create([
            'CampusID' => 1, 'Type' => 'tuition', 'Severity' => 'high',
            'Title' => '急件通知', 'Body' => 'x', 'SourceType' => 'StudentClass',
            'SourceID' => '1', 'SourceKey' => 'tuition:1:urgent', 'Payload' => [], 'OccurredAt' => now(),
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$session->id}", 'campus_id' => 1,
            'student_id' => $stu->id, 'student_class_id' => $course->ID, 'class_session_id' => $session->id,
            'type' => 'student_leave', 'status' => 'candidate_ready', 'due_at' => now()->subHour(),
            'payload' => ['reason' => '家庭隱私事由'],
        ]);
        [$soonStu, , $soonSession] = $this->seedSession(1, '即將到期', '0912111110');
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$soonSession->id}", 'campus_id' => 1,
            'student_id' => $soonStu->id, 'class_session_id' => $soonSession->id,
            'type' => 'student_leave', 'status' => 'open', 'due_at' => now()->addHours(12),
        ]);

        $count = $this->getJson('/api/v1/action-inbox/count?branch_id=1', $this->dirHeaders([1]))
            ->assertOk();
        $this->assertStringContainsString('no-store', (string) $count->headers->get('Cache-Control'));

        $count->assertJsonPath('notifications_unread', 2)
            ->assertJsonPath('cases_unresolved', 2)
            ->assertJsonPath('cases_overdue', 1)
            ->assertJsonPath('cases_due_soon', 1)
            ->assertJsonPath('urgent_total', 2) // 1 high unread + 1 overdue
            ->assertJsonPath('badge_total', 4)
            // deprecated aliases still present
            ->assertJsonPath('cases_open', 2)
            ->assertJsonPath('needs_attention', 4)
            ->assertJsonMissing(['unread_count' => 4]);

        // Ordinary open (non-overdue, non-high) alone must not inflate urgent_total.
        $plain = $this->getJson('/api/v1/action-inbox/count?branch_id=1', $this->dirHeaders([1], 'director-plain-urgent@example.com'));
        $this->assertGreaterThan(0, $plain->json('cases_unresolved'));
        // urgent is overdue+high only — cases_due_soon and medium unread do not force red.
        $this->assertSame(2, $plain->json('urgent_total'));
    }

    public function test_pagination_reaches_case_51_and_count_matches_total(): void
    {
        $student = Student::create([
            'name' => '分頁學生', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => '測試學校',
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => '0912111200',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-07-01', 'EndDate' => '2026-08-31',
            'TotalHours' => 8, 'Charge' => 8800, 'Paid' => 1, 'Rate' => 1100, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);

        for ($i = 1; $i <= 51; $i++) {
            $session = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-08-'.str_pad((string) min(28, $i), 2, '0', STR_PAD_LEFT),
                'StartTime' => '17:00:00', 'EndTime' => '19:00:00', 'Status' => 'leave_requested',
            ]);
            ExceptionWorkflow::create([
                'source_key' => "parent_leave:class_session:{$session->id}",
                'campus_id' => 1,
                'student_id' => $student->id,
                'student_class_id' => $course->ID,
                'class_session_id' => $session->id,
                'type' => 'student_leave',
                'status' => 'open',
                'due_at' => now()->addDays($i),
                'payload' => ['reason' => 'r'.$i],
            ]);
        }

        $h = $this->dirHeaders([1], 'director-page@example.com');
        $count = $this->getJson('/api/v1/action-inbox/count?branch_id=1', $h)->assertOk();
        $this->assertSame(51, $count->json('cases_unresolved'));
        $this->assertSame(51, $count->json('badge_total')); // no unread notifs

        $page1 = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case&page=1&per_page=20', $h)->assertOk();
        $this->assertSame(51, $page1->json('cases.total'));
        $this->assertSame(51, $page1->json('summary.cases_unresolved'));
        $this->assertSame(1, $page1->json('cases.current_page'));
        $this->assertSame(3, $page1->json('cases.last_page'));
        $this->assertTrue($page1->json('cases.has_more'));
        $this->assertCount(20, $page1->json('cases.data'));

        $page3 = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case&page=3&per_page=20', $h)->assertOk();
        $this->assertSame(3, $page3->json('cases.current_page'));
        $this->assertCount(11, $page3->json('cases.data'));
        $this->assertFalse($page3->json('cases.has_more'));

        $targetId = (int) ExceptionWorkflow::query()->where('campus_id', 1)->orderByDesc('id')->value('id');
        $this->getJson("/api/v1/action-inbox/cases/{$targetId}?branch_id=1", $h)
            ->assertOk()
            ->assertJsonPath('data.workflow_id', $targetId)
            ->assertJsonMissing(['payload' => ['reason' => 'r51']]);
    }

    public function test_deep_link_unauthorized_campus_returns_404_not_leak(): void
    {
        [, , $s2] = $this->seedSession(2, '他校', '0912111300');
        $wf = ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$s2->id}",
            'campus_id' => 2,
            'student_id' => Student::where('name', '他校')->value('id'),
            'class_session_id' => $s2->id,
            'type' => 'student_leave',
            'status' => 'open',
            'due_at' => now()->addDay(),
            'payload' => ['reason' => '秘密事由'],
        ]);

        $this->getJson('/api/v1/action-inbox/cases/'.$wf->id.'?branch_id=1', $this->dirHeaders([1], 'director-deeplink@example.com'))
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'case_not_found');
    }

    public function test_ops_lane_keeps_existing_notifications_without_raw_payload(): void
    {
        [$stu, , $session] = $this->seedSession(1, '通知學生', '0912111008');
        Notification::create([
            'CampusID' => 1, 'Type' => 'tuition', 'Severity' => 'high',
            'Title' => '通知學生 數學 未繳費', 'Body' => '未繳費', 'SourceType' => 'StudentClass',
            'SourceID' => '1', 'SourceKey' => 'tuition:1:manual', 'Payload' => ['student_name' => '通知學生', 'secret' => 'nope'],
            'OccurredAt' => now(),
        ]);
        ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$session->id}", 'campus_id' => 1,
            'student_id' => $stu->id, 'class_session_id' => $session->id,
            'type' => 'student_leave', 'status' => 'open', 'due_at' => now()->addDay(),
        ]);

        $all = $this->getJson('/api/v1/action-inbox?branch_id=1', $this->dirHeaders([1]))->assertOk();
        $this->assertSame(1, $all->json('ops.total'));
        $this->assertSame(1, $all->json('cases.total'));
        $opsItem = $all->json('ops.data.0');
        $this->assertSame('ops', $opsItem['lane']);
        $this->assertArrayNotHasKey('payload', $opsItem);
        $this->assertSame('通知學生', $opsItem['context']['student_name'] ?? null);
        $this->assertArrayNotHasKey('secret', $opsItem['context'] ?? []);
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

    private function dirHeaders(array $campusIds, string $login = 'director-inbox@example.com'): array
    {
        return [
            'Authorization' => 'Bearer '.$this->staffToken($campusIds, $login, 'A'),
            'Accept' => 'application/json',
        ];
    }

    private function superHeaders(string $login = 'super-inbox@example.com'): array
    {
        return [
            'Authorization' => 'Bearer '.$this->staffToken([], $login, 'S'),
            'Accept' => 'application/json',
        ];
    }

    private function staffToken(array $campusIds, string $login, string $type): string
    {
        $user = User::create([
            'LoginName' => $login, 'Name' => $type === 'T' ? '老師' : ($type === 'S' ? '超管' : '主任'),
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
