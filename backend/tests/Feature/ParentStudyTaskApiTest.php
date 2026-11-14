<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Guardian;
use App\Models\StudentGuardian;
use App\Services\ParentBinding\GuardianSyncService;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentStudyTask;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 家長端讀書任務 (#5269 step 4): read only, one child per parent session, fixed field set. Fake data; "today" is Fri 2026-11-13. */
class ParentStudyTaskApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/parent/study-tasks';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeStudent(int $campus): Student
    {
        return Student::create(['name' => '虛構學生', 'CampusID' => $campus, 'ClassID' => 3, 'SchoolName' => '甲測試國中',
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function makeTask(Student $student, array $o = []): StudentStudyTask
    {
        return StudentStudyTask::create($o + ['campus_id' => $student->CampusID, 'student_id' => $student->id,
            'title' => '第五單元習題', 'kind' => 'homework', 'due_date' => '2026-11-18']);
    }

    /** One parent login (a guardian) for a student: the raw bearer token. */
    private function guardianToken(Student $student, ?Carbon $expires = null): string
    {
        $raw = Str::random(32);
        ParentSession::create(['StudentID' => $student->id, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => $expires ?? now()->addHours(2)]);

        return $raw;
    }

    private function asParent(string $token)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);
    }

    public function test_parent_sees_only_own_childs_tasks_of_the_requested_week(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        $other = $this->makeStudent($campus);
        $this->makeTask($child, ['title' => '週一預習', 'kind' => 'preview', 'due_date' => '2026-11-09', 'minutes' => 20]);
        $this->makeTask($child, ['title' => '週日複習', 'kind' => 'review', 'due_date' => '2026-11-15']);
        $this->makeTask($child, ['title' => '下週作業', 'due_date' => '2026-11-16']);
        $this->makeTask($child, ['title' => '上週作業', 'due_date' => '2026-11-08']);
        $this->makeTask($other, ['title' => '別人的任務', 'due_date' => '2026-11-10']);

        $res = $this->asParent($this->guardianToken($child))->getJson(self::URL . '?week_start=2026-11-11')->assertOk();

        $res->assertJsonPath('week_start', '2026-11-09')->assertJsonPath('week_end', '2026-11-15');
        $this->assertSame(['週一預習', '週日複習'], array_column($res->json('data'), 'title'));
        $this->assertSame(20, $res->json('data.0.minutes'));
    }

    public function test_default_week_is_this_week_in_taipei(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        $this->makeTask($child, ['title' => '本週', 'due_date' => '2026-11-14']);
        $this->makeTask($child, ['title' => '下週', 'due_date' => '2026-11-16']);

        $res = $this->asParent($this->guardianToken($child))->getJson(self::URL)->assertOk();
        $res->assertJsonPath('week_start', '2026-11-09');
        $this->assertSame(['本週'], array_column($res->json('data'), 'title'));
    }

    public function test_exposed_fields_are_exactly_the_decided_set_and_hide_staff_only_data(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        $teacher = User::create(['LoginName' => 'ts' . random_int(1, 99999) . '@example.com', 'Name' => 'Staff', 'PSW' => 'secret',
            'type' => 'T', 'phone' => (string) random_int(900000000, 999999999), 'MustChangePassword' => false]);
        $this->makeTask($child, ['title' => '訂正考卷', 'kind' => 'correction', 'minutes' => 15, 'note' => '內部提醒：家長不要看',
            'created_by' => $teacher->id, 'done_by' => $teacher->id, 'done_at' => '2026-11-12 18:30:00', 'due_date' => '2026-11-12']);
        $this->makeTask($child, ['title' => '未完成', 'due_date' => '2026-11-13']);

        $data = $this->asParent($this->guardianToken($child))->getJson(self::URL . '?week_start=2026-11-09')->assertOk()->json('data');

        $this->assertCount(2, $data); // never let the key assertions below run over an empty list
        foreach ($data as $row) {
            $this->assertSame(['done_at', 'due_date', 'id', 'kind', 'minutes', 'title'], collect(array_keys($row))->sort()->values()->all());
        }
        $this->assertSame('2026-11-12', $data[0]['done_at']);
        $this->assertNull($data[1]['done_at']);
        $this->assertNull($data[1]['minutes']);
        $this->assertStringNotContainsString('內部提醒', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function test_every_guardian_of_the_child_can_read_and_each_login_is_scoped_to_that_child(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        $sibling = $this->makeStudent($campus);
        $this->makeTask($child, ['title' => '孩子的']);
        $this->makeTask($sibling, ['title' => '手足的']);

        foreach ([$this->guardianToken($child), $this->guardianToken($child)] as $token) {
            $res = $this->asParent($token)->getJson(self::URL . '?week_start=2026-11-16')->assertOk();
            $this->assertSame(['孩子的'], array_column($res->json('data'), 'title'));
        }
    }

    public function test_a_student_id_in_the_request_cannot_reach_another_child(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        $stranger = $this->makeStudent($campus);
        $this->makeTask($child, ['title' => '自己的']);
        $this->makeTask($stranger, ['title' => '別家的']);

        // The child's own task proves the list is not empty for another reason; the stranger's task must not appear.
        $res = $this->asParent($this->guardianToken($child))
            ->getJson(self::URL . "?week_start=2026-11-16&student_id={$stranger->id}")->assertOk();
        $this->assertSame(['自己的'], array_column($res->json('data'), 'title'));
    }

    public function test_parent_of_another_campus_sees_only_that_campuss_child(): void
    {
        $campusA = (int) CampusFactory::new()->create()->id;
        $campusB = (int) CampusFactory::new()->create()->id;
        $a = $this->makeStudent($campusA);
        $b = $this->makeStudent($campusB);
        $this->makeTask($a, ['title' => 'A 校任務']);
        $this->makeTask($b, ['title' => 'B 校任務']);

        $res = $this->asParent($this->guardianToken($b))->getJson(self::URL . '?week_start=2026-11-16')->assertOk();
        $this->assertSame(['B 校任務'], array_column($res->json('data'), 'title'));
    }

    public function test_a_task_left_on_the_old_campus_after_a_transfer_is_not_shown(): void
    {
        $campusA = (int) CampusFactory::new()->create()->id;
        $campusB = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campusB);
        $this->makeTask($child, ['title' => '舊校留下的', 'campus_id' => $campusA]);
        $this->makeTask($child, ['title' => '現校的']);

        $res = $this->asParent($this->guardianToken($child))->getJson(self::URL . '?week_start=2026-11-16')->assertOk();
        $this->assertSame(['現校的'], array_column($res->json('data'), 'title'));
    }

    public function test_no_token_unknown_token_expired_session_and_staff_token_are_refused(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        $this->makeTask($child);

        $this->getJson(self::URL, ['Accept' => 'application/json'])->assertStatus(401);
        $this->asParent('not-a-session')->getJson(self::URL)->assertStatus(401);
        $this->asParent($this->guardianToken($child, now()->subMinute()))->getJson(self::URL)->assertStatus(401);

        $director = User::create(['LoginName' => 'dr' . random_int(1, 99999) . '@example.com', 'Name' => 'Staff', 'PSW' => 'secret',
            'type' => 'A', 'phone' => (string) random_int(900000000, 999999999), 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $staffToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $staffToken, 'expires_at' => now()->addDay()]);
        $this->asParent($staffToken)->getJson(self::URL)->assertStatus(401);
    }

    public function test_the_parent_endpoint_cannot_write(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        $task = $this->makeTask($child);
        $token = $this->guardianToken($child);

        $this->asParent($token)->postJson(self::URL, ['title' => 'x', 'due_date' => '2026-11-18'])->assertStatus(405);
        // A parent token on the staff write routes is refused by the staff auth middleware (never a 2xx).
        $this->assertContains($this->asParent($token)->patchJson("/api/v1/study-tasks/{$task->id}", ['done' => true])->status(), [401, 403]);
        $this->assertContains($this->asParent($token)->deleteJson("/api/v1/study-tasks/{$task->id}")->status(), [401, 403]);
        $this->assertNull($task->fresh()->done_at);
    }

    public function test_a_bad_week_start_is_rejected(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $token = $this->guardianToken($this->makeStudent($campus));

        $this->asParent($token)->getJson(self::URL . '?week_start=11/09')->assertStatus(422);
        $this->asParent($token)->getJson(self::URL . '?week_start=2026-13-45')->assertStatus(422);
    }

    public function test_a_week_returns_at_most_100_tasks(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        for ($i = 0; $i < 105; $i++) {
            $this->makeTask($child, ['title' => "任務{$i}", 'due_date' => '2026-11-18']);
        }

        $res = $this->asParent($this->guardianToken($child))->getJson(self::URL . '?week_start=2026-11-16')->assertOk();
        $this->assertCount(100, $res->json('data'));
    }

    public function test_a_session_whose_guardian_link_was_revoked_is_refused(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        $this->makeTask($child);
        $line = 'U' . bin2hex(random_bytes(16));
        $guardian = Guardian::create(['display_name' => '爸', 'line_user_id' => $line]);
        $link = StudentGuardian::create([
            'student_id' => $child->id, 'guardian_id' => $guardian->id, 'campus_id' => $campus,
            'role' => StudentGuardian::ROLE_FATHER, 'is_primary' => true, 'status' => StudentGuardian::STATUS_ACTIVE,
            'notify_learning_feedback' => true, 'notify_tuition' => true, 'source' => StudentGuardian::SOURCE_STAFF,
        ]);
        $raw = Str::random(48);
        ParentSession::create(['StudentID' => $child->id, 'line_user_id' => $line, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addDays(30)]);

        $this->asParent($raw)->getJson(self::URL . '?week_start=2026-11-16')->assertOk()->assertJsonCount(1, 'data');
        app(GuardianSyncService::class)->revoke($link->fresh(['guardian']));
        $this->asParent($raw)->getJson(self::URL . '?week_start=2026-11-16')->assertStatus(401);
    }
}
