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
    public function test_dto_case_lane_no_raw_payload_and_no_leave_notification(): void
    {
        [, , $session] = $this->seedSession(1, '陳小明', '0912111001');
        $this->postJson("/api/v1/parent/sessions/{$session->id}/leave", [
            'reason' => '身體不舒服',
        ], ['Authorization' => 'Bearer '.$this->parentToken('陳小明', '0912111001')])->assertOk();
        $res = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $this->dirHeaders([1]));
        $res->assertOk()->assertJsonPath('summary.cases_unresolved', 1)->assertJsonPath('cases.total', 1);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $item = $res->json('cases.data.0');
        $this->assertSame(['case', 'open', '等待安排補課', '安排補課'], [
            $item['lane'], $item['status_code'], $item['status_label'], $item['action']['label'],
        ]);
        $this->assertArrayNotHasKey('payload', $item);
        $this->assertSame('身體不舒服', $item['reason_preview']);
        $this->assertDatabaseCount('Notifications', 0);
    }
    public function test_campus_scope_fail_closed_and_super_admin_all(): void
    {
        [, , $s1] = $this->seedSession(1, '校區一', '0912111101');
        [, , $s2] = $this->seedSession(2, '校區二', '0912111102');
        foreach ([[$s1, 1, '校區一'], [$s2, 2, '校區二']] as [$s, $c, $n]) {
            ExceptionWorkflow::create([
                'source_key' => "parent_leave:class_session:{$s->id}", 'campus_id' => $c,
                'student_id' => Student::where('name', $n)->value('id'), 'class_session_id' => $s->id,
                'type' => 'student_leave', 'status' => 'open', 'due_at' => now()->addDay(),
            ]);
        }
        $this->getJson('/api/v1/action-inbox', $this->dirHeaders([], 'dir-zero@ex.com'))->assertStatus(403);
        $this->getJson('/api/v1/action-inbox/count', $this->dirHeaders([], 'dir-zero-c@ex.com'))->assertStatus(403);
        $scoped = $this->getJson('/api/v1/action-inbox?lane=case', $this->dirHeaders([1], 'dir-a@ex.com'))->assertOk();
        $this->assertSame(1, $scoped->json('cases.total'));
        $this->assertSame(1, (int) $scoped->json('cases.data.0.campus_id'));
        $this->getJson('/api/v1/action-inbox?branch_id=2', $this->dirHeaders([1], 'dir-b@ex.com'))
            ->assertStatus(403)->assertJsonPath('error_code', 'unauthorized_campus');
        $this->getJson('/api/v1/action-inbox/count?branch_id=2', $this->dirHeaders([1], 'dir-c@ex.com'))
            ->assertStatus(403);
        $all = $this->getJson('/api/v1/action-inbox?lane=case', $this->superHeaders())->assertOk();
        $this->assertSame(2, $all->json('cases.total'));
        $this->assertSame('all', $all->json('meta.scope_mode'));
        $teacher = $this->staffToken([1], 'teacher-inbox@ex.com', 'T');
        $this->getJson('/api/v1/action-inbox?branch_id=1', [
            'Authorization' => "Bearer {$teacher}", 'Accept' => 'application/json',
        ])->assertStatus(403);
    }
    public function test_counts_pagination_deep_link_and_status_mapping(): void
    {
        [$stu, $course, $session] = $this->seedSession(1, '計數', '0912111007');
        Notification::create([
            'CampusID' => 1, 'Type' => 'pending_swipe', 'Severity' => 'medium', 'Title' => '刷卡',
            'Body' => 'x', 'SourceType' => 'PendingSwipe', 'SourceID' => '9',
            'SourceKey' => 'pending_swipe:1:9', 'Payload' => ['secret' => 'nope'], 'OccurredAt' => now(),
        ]);
        Notification::create([
            'CampusID' => 1, 'Type' => 'tuition', 'Severity' => 'high', 'Title' => '急件',
            'Body' => 'x', 'SourceType' => 'StudentClass', 'SourceID' => '1',
            'SourceKey' => 'tuition:1:urgent', 'Payload' => [], 'OccurredAt' => now(),
        ]);
        $ready = ExceptionWorkflow::create([
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
        $h = $this->dirHeaders([1], 'dir-count@ex.com');
        $count = $this->getJson('/api/v1/action-inbox/count?branch_id=1', $h)->assertOk();
        $this->assertStringContainsString('no-store', (string) $count->headers->get('Cache-Control'));
        $count->assertJsonPath('notifications_unread', 2)
            ->assertJsonPath('cases_unresolved', 2)
            ->assertJsonPath('cases_overdue', 1)
            ->assertJsonPath('cases_due_soon', 1)
            ->assertJsonPath('urgent_total', 2)
            ->assertJsonPath('badge_total', 4)
            ->assertJsonPath('cases_open', 2)
            ->assertJsonPath('needs_attention', 4);
        $readyItem = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case&case_filter=candidate_ready', $h)
            ->assertOk()->json('cases.data.0');
        $this->assertSame(['candidate_ready', '補課方案待確認', '檢視並確認'], [
            $readyItem['status_code'], $readyItem['status_label'], $readyItem['action']['label'],
        ]);
        $this->assertTrue($readyItem['overdue']);
        $list = $this->getJson('/api/v1/action-inbox?branch_id=1', $h)->assertOk();
        $this->assertSame('overdue', $list->json('cases.data.0.priority'));
        $this->assertArrayNotHasKey('payload', $list->json('ops.data.0'));
        $this->assertArrayNotHasKey('secret', $list->json('ops.data.0.context') ?? []);
        [$cStu, $cCourse, $cSession] = $this->seedSession(1, '結案生', '0912111004');
        $closed = app(ExceptionWorkflowService::class)->createOrGet([
            'source_key' => "parent_leave:class_session:{$cSession->id}", 'campus_id' => 1,
            'student_id' => $cStu->id, 'student_class_id' => $cCourse->ID,
            'class_session_id' => $cSession->id, 'type' => 'student_leave', 'status' => 'open',
            'due_at' => now()->addDay(),
        ]);
        $closed->update(['status' => 'confirmed', 'closed_at' => now()]);
        $this->assertSame(2, $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case', $h)->json('summary.cases_unresolved'));
        $this->getJson('/api/v1/action-inbox/cases/'.$closed->id.'?branch_id=1', $h)
            ->assertOk()->assertJsonPath('data.status_code', 'confirmed')
            ->assertJsonPath('data.action.label', '查看結果');
        [, , $other] = $this->seedSession(2, '他校', '0912111300');
        $foreign = ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$other->id}", 'campus_id' => 2,
            'student_id' => Student::where('name', '他校')->value('id'), 'class_session_id' => $other->id,
            'type' => 'student_leave', 'status' => 'open', 'due_at' => now()->addDay(),
            'payload' => ['reason' => '秘密'],
        ]);
        $this->getJson('/api/v1/action-inbox/cases/'.$foreign->id.'?branch_id=1', $h)
            ->assertStatus(404)->assertJsonPath('error_code', 'case_not_found');
        $student = Student::create([
            'name' => '分頁', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => '測',
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => '0912111200',
        ]);
        $course2 = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-07-01', 'EndDate' => '2026-08-31',
            'TotalHours' => 8, 'Charge' => 8800, 'Paid' => 1, 'Rate' => 1100, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        for ($i = 1; $i <= 51; $i++) {
            $sess = ClassSession::create([
                'StudentClassID' => $course2->ID,
                'SessionDate' => '2026-09-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'StartTime' => '17:00:00', 'EndTime' => '19:00:00', 'Status' => 'leave_requested',
            ]);
            ExceptionWorkflow::create([
                'source_key' => "parent_leave:class_session:{$sess->id}", 'campus_id' => 1,
                'student_id' => $student->id, 'student_class_id' => $course2->ID,
                'class_session_id' => $sess->id, 'type' => 'student_leave', 'status' => 'open',
                'due_at' => now()->addDays($i), 'payload' => ['reason' => 'r'.$i],
            ]);
        }
        $pageH = $this->dirHeaders([1], 'dir-page@ex.com');
        $c = $this->getJson('/api/v1/action-inbox/count?branch_id=1', $pageH)->assertOk();
        $unresolved = (int) $c->json('cases_unresolved');
        $this->assertGreaterThanOrEqual(51, $unresolved);
        $p1 = $this->getJson('/api/v1/action-inbox?branch_id=1&lane=case&page=1&per_page=20', $pageH)->assertOk();
        $this->assertSame($unresolved, $p1->json('cases.total'));
        $this->assertSame($unresolved, $p1->json('summary.cases_unresolved'));
        $this->assertTrue($p1->json('cases.has_more'));
        $lastPage = (int) $p1->json('cases.last_page');
        $last = $this->getJson("/api/v1/action-inbox?branch_id=1&lane=case&page={$lastPage}&per_page=20", $pageH)->assertOk();
        $this->assertFalse($last->json('cases.has_more'));
        $this->assertGreaterThan(0, count($last->json('cases.data')));
        $targetId = (int) ExceptionWorkflow::query()->where('campus_id', 1)->where('status', 'open')->orderByDesc('id')->value('id');
        $this->getJson("/api/v1/action-inbox/cases/{$targetId}?branch_id=1", $pageH)
            ->assertOk()->assertJsonPath('data.workflow_id', $targetId);
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
        return ['Authorization' => 'Bearer '.$this->staffToken($campusIds, $login, 'A'), 'Accept' => 'application/json'];
    }
    private function superHeaders(string $login = 'super-inbox@example.com'): array
    {
        return ['Authorization' => 'Bearer '.$this->staffToken([], $login, 'S'), 'Accept' => 'application/json'];
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
