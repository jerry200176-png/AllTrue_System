<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BugReport;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BugReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_bug_report(): void
    {
        [$token, $user] = $this->createUserToken([1], 'bug1@test.com', 'T');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/bugs', [
            'title' => '頁面載入空白',
            'description' => '在課程管理頁面切換分校後，頁面變成空白。',
            'severity' => 'high',
            'page_key' => 'course-mgmt',
            'branch_id' => 1,
        ]);

        $res->assertStatus(201);
        $this->assertEquals('new', $res->json('status'));

        $this->assertDatabaseHas('bug_reports', [
            'title' => '頁面載入空白',
            'severity' => 'high',
            'status' => 'new',
            'CampusID' => 1,
            'reporter_user_id' => $user->id,
        ]);
    }

    public function test_submit_bug_report_with_screenshot(): void
    {
        Storage::fake('public');

        [$token, $user] = $this->createUserToken([1], 'bugImg@test.com', 'T');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post('/api/v1/bugs', [
            'title' => '畫面異常',
            'description' => '如截圖',
            'severity' => 'medium',
            'branch_id' => 1,
            'attachments' => [
                UploadedFile::fake()->image('screen.png', 80, 60),
            ],
        ]);

        $res->assertStatus(201);
        $bugId = (int) $res->json('id');
        $this->assertGreaterThan(0, $bugId);

        $this->assertDatabaseCount('bug_report_attachments', 1);

        $detail = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/bugs/{$bugId}");

        $detail->assertOk();
        $atts = $detail->json('attachments');
        $this->assertIsArray($atts);
        $this->assertCount(1, $atts);
        $this->assertNotEmpty($atts[0]['url'] ?? null);
    }

    public function test_list_bugs_for_teacher_sees_own_only(): void
    {
        [$tokenA, $userA] = $this->createUserToken([1], 'bugA@test.com', 'T');
        [$tokenB, $userB] = $this->createUserToken([1], 'bugB@test.com', 'T');

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $userA->id,
            'title' => 'Bug from A', 'description' => 'Desc A', 'severity' => 'low', 'status' => 'new',
        ]);
        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $userB->id,
            'title' => 'Bug from B', 'description' => 'Desc B', 'severity' => 'medium', 'status' => 'new',
        ]);

        $resA = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1');

        $resA->assertOk();
        $titles = collect($resA->json('data'))->pluck('title')->all();
        $this->assertContains('Bug from A', $titles);
        $this->assertNotContains('Bug from B', $titles);
    }

    public function test_super_admin_sees_all_bugs_in_campus(): void
    {
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'admin@test.com', 'S');
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'teacher@test.com', 'T');

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'Teacher Bug', 'description' => 'Desc', 'severity' => 'medium', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1');

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('Teacher Bug', $titles);
    }

    public function test_super_admin_can_update_status_and_add_internal_comment(): void
    {
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'statusAdmin@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $admin->id,
            'title' => 'Status Test', 'description' => 'Test desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $statusRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'triaged',
            'note' => 'Confirmed and triaged',
        ]);

        $statusRes->assertOk();

        $bug->refresh();
        $this->assertEquals('triaged', $bug->status);

        $this->assertDatabaseHas('bug_report_status_logs', [
            'bug_report_id' => $bug->id,
            'from_status' => 'new',
            'to_status' => 'triaged',
        ]);

        $commentRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => '已確認，排入修復。',
            'is_internal_note' => true,
        ]);

        $commentRes->assertStatus(201);

        $this->assertDatabaseHas('bug_report_comments', [
            'bug_report_id' => $bug->id,
            'body' => '已確認，排入修復。',
            'is_internal_note' => 1,
        ]);
    }

    public function test_invalid_status_transition_rejected_for_super_admin(): void
    {
        [$token, $user] = $this->createUserToken([1], 'invalidT@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'Invalid Transition', 'description' => 'Desc',
            'severity' => 'low', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'resolved',
        ]);

        $res->assertStatus(422);
    }

    public function test_cross_campus_bug_isolation(): void
    {
        [$tokenA, $userA] = $this->createUserToken([1], 'campA@test.com', 'A');
        [$tokenB, $userB] = $this->createUserToken([2], 'campB@test.com', 'A');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $userA->id,
            'title' => 'Campus 1 Bug', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/bugs/{$bug->id}?branch_id=2");

        $res->assertStatus(404);
    }

    public function test_director_sees_only_own_bug_reports(): void
    {
        [$tokenDirector, $director] = $this->createUserToken([1], 'dirOwn@test.com', 'A');
        [$tokenOther, $other] = $this->createUserToken([1], 'dirOther@test.com', 'A');

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $director->id,
            'title' => 'My campus bug', 'description' => 'Desc', 'severity' => 'low', 'status' => 'new',
        ]);
        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $other->id,
            'title' => 'Someone else bug', 'description' => 'Desc', 'severity' => 'medium', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenDirector}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1');

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('My campus bug', $titles);
        $this->assertNotContains('Someone else bug', $titles);
    }

    public function test_director_cannot_update_bug_status(): void
    {
        [$tokenDirector, $director] = $this->createUserToken([1], 'dir@test.com', 'A');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $director->id,
            'title' => 'Permission Test', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $statusRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenDirector}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'triaged',
        ]);
        $statusRes->assertStatus(403);
    }

    public function test_reporter_unread_badge_after_reply_and_cleared_on_view(): void
    {
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'badgeT@test.com', 'T');
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'badgeA@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'Badge flow', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => '我們已收到',
            'is_internal_note' => false,
        ])->assertStatus(201);

        $badge1 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');
        $badge1->assertOk();
        $this->assertEquals(1, $badge1->json('unread_count'));

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/bugs/{$bug->id}")->assertOk();

        $badge2 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');
        $badge2->assertOk();
        $this->assertEquals(0, $badge2->json('unread_count'));
    }

    public function test_internal_comment_does_not_count_for_reporter_badge(): void
    {
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'intT@test.com', 'T');
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'intA@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'Internal only', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => '內部備註',
            'is_internal_note' => true,
        ])->assertStatus(201);

        $badge = $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');
        $badge->assertOk();
        $this->assertEquals(0, $badge->json('unread_count'));
    }

    public function test_super_admin_new_bug_badge_and_mark_inbox_seen(): void
    {
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'inboxA@test.com', 'S');
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'inboxT@test.com', 'T');

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'New for admin', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $b1 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');
        $b1->assertOk();
        $this->assertGreaterThanOrEqual(1, $b1->json('unread_count'));

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/bugs/mark-inbox-seen')->assertOk();

        $b2 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');
        $b2->assertOk();
        $this->assertEquals(0, $b2->json('unread_count'));

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'After seen', 'description' => 'Desc',
            'severity' => 'low', 'status' => 'new',
        ]);

        $b3 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');
        $b3->assertOk();
        $this->assertEquals(1, $b3->json('unread_count'));
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function createUserToken(array $campusIds, string $loginName, string $type = 'A'): array
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'Test ' . $loginName,
            'PSW' => 'secret',
            'type' => $type,
            'phone' => rand(900000000, 999999999),
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

        return [$token, $user];
    }
}
