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

    public function test_bug_intake_and_triage_response_contract(): void
    {
        [$reporterToken, $reporter] = $this->createUserToken([1], 'contractReporter@test.com', 'T');
        [$adminToken] = $this->createUserToken([1], 'contractAdmin@test.com', 'S');
        $reporterHeaders = [
            'Authorization' => "Bearer {$reporterToken}",
            'Accept' => 'application/json',
        ];
        $adminHeaders = [
            'Authorization' => "Bearer {$adminToken}",
            'Accept' => 'application/json',
        ];

        $created = $this->withHeaders($reporterHeaders)->postJson('/api/v1/bugs', [
            'title' => 'Response contract',
            'description' => 'Keep the bug intake payload stable.',
            'severity' => 'high',
            'page_key' => 'bug-reports',
            'branch_id' => 1,
        ]);
        $created->assertCreated()->assertJsonStructure([
            'id', 'status', 'created_at', 'attachment_errors',
        ])->assertJsonPath('status', 'new');
        $bugId = (int) $created->json('id');
        $this->assertGreaterThan(0, $bugId);
        $this->assertIsString($created->json('created_at'));
        $this->assertIsInt($created->json('attachment_errors'));

        $list = $this->withHeaders($reporterHeaders)->getJson('/api/v1/bugs?branch_id=1');
        $list->assertOk()->assertJsonStructure([
            'current_page', 'data' => [[
                'id', 'campus_id', 'reporter_user_id', 'title', 'severity', 'status',
                'page_key', 'attachments_count', 'comments_count', 'created_at', 'updated_at',
            ]], 'last_page', 'per_page', 'total',
        ]);
        $this->assertSame($bugId, $list->json('data.0.id'));

        $detail = $this->withHeaders($reporterHeaders)->getJson("/api/v1/bugs/{$bugId}");
        $detail->assertOk()->assertJsonStructure([
            'id', 'campus_id', 'reporter_user_id', 'reporter_name', 'title', 'description',
            'severity', 'status', 'page_key', 'url', 'client_info', 'created_at', 'updated_at',
            'comments', 'status_logs', 'attachments',
        ]);
        $this->assertSame($reporter->id, $detail->json('reporter_user_id'));

        $comment = $this->withHeaders($reporterHeaders)->postJson("/api/v1/bugs/{$bugId}/comments", [
            'body' => '已補充可重現步驟。',
        ]);
        $comment->assertCreated()->assertJsonStructure(['id', 'created_at']);

        $triaged = $this->withHeaders($adminHeaders)->postJson("/api/v1/bugs/{$bugId}/status", [
            'status' => 'triaged',
        ]);
        $triaged->assertOk()->assertJson(['ok' => true]);

        $this->withHeaders($adminHeaders)->postJson("/api/v1/bugs/{$bugId}/status", [
            'status' => 'in_progress',
        ])->assertOk()->assertJson(['ok' => true]);
        $resolved = $this->withHeaders($adminHeaders)->postJson("/api/v1/bugs/{$bugId}/status", [
            'status' => 'resolved',
            'production_revision' => 'abcdef1',
            'deploy_run_id' => '123456',
        ]);
        $resolved->assertOk()->assertJson(['ok' => true]);

        $verified = $this->withHeaders($reporterHeaders)->postJson("/api/v1/bugs/{$bugId}/reporter-verify", [
            'verdict' => 'confirmed',
        ]);
        $verified->assertOk()->assertJson(['ok' => true, 'new_status' => 'closed']);

        $badge = $this->withHeaders($reporterHeaders)->getJson('/api/v1/bugs/unread-badge?branch_id=1');
        $badge->assertOk()->assertJsonStructure(['unread_count']);
        $this->assertIsInt($badge->json('unread_count'));
    }

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

    /**
     * #1055: a teacher submitting for a branch outside their authorized campus set
     * must still be denied (authz unchanged), but with an ACTIONABLE response —
     * not an opaque "Forbidden". This is the Xinzhuang teacher failure mode.
     */
    public function test_submit_bug_report_for_unauthorized_campus_returns_actionable_403(): void
    {
        [$token] = $this->createUserToken([1], 'bugAuthz@test.com', 'T');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/bugs', [
            'title' => '跨分校回報',
            'description' => '老師在未授權分校送出回報。',
            'severity' => 'medium',
            'branch_id' => 2,
        ]);

        // Authorization is preserved: still 403 for an unauthorized campus.
        $res->assertStatus(403);
        // ...but the payload is now actionable, not a bare "Forbidden".
        $res->assertJson([
            'code' => 'campus_not_authorized',
            'branch_id' => 2,
            'allowed_campus_ids' => [1],
        ]);
        $this->assertNotSame('Forbidden', $res->json('message'));

        $this->assertDatabaseMissing('bug_reports', [
            'title' => '跨分校回報',
        ]);
    }

    public function test_unauthorized_campus_cannot_succeed_via_client_fallback_or_retry(): void
    {
        [$token] = $this->createUserToken([1], 'bugAuthzRetry@test.com', 'T');
        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];

        // 1. Initial attempt with unauthorized campus #99
        $res1 = $this->withHeaders($headers)->postJson('/api/v1/bugs', [
            'title' => '未授權分校嘗試 #1',
            'description' => '嘗試提交非授權分校 #99',
            'branch_id' => 99,
        ]);
        $res1->assertStatus(403)->assertJson([
            'code' => 'campus_not_authorized',
            'branch_id' => 99,
            'allowed_campus_ids' => [1],
        ]);
        $this->assertDatabaseMissing('bug_reports', ['title' => '未授權分校嘗試 #1']);

        // 2. Erroneous or forged client retry with another unauthorized campus #88
        $res2 = $this->withHeaders($headers)->postJson('/api/v1/bugs', [
            'title' => '未授權分校重試 #2',
            'description' => '客戶端 fallback 重試仍為未授權分校 #88',
            'branch_id' => 88,
        ]);
        $res2->assertStatus(403)->assertJson([
            'code' => 'campus_not_authorized',
            'branch_id' => 88,
            'allowed_campus_ids' => [1],
        ]);
        $this->assertDatabaseMissing('bug_reports', ['title' => '未授權分校重試 #2']);

        // 3. Legitimate retry with verified authorized campus #1 succeeds
        $res3 = $this->withHeaders($headers)->postJson('/api/v1/bugs', [
            'title' => '合法分校重試 #3',
            'description' => '切換至實際授權分校 #1',
            'branch_id' => 1,
        ]);
        $res3->assertCreated();
        $this->assertDatabaseHas('bug_reports', [
            'title' => '合法分校重試 #3',
            'CampusID' => 1,
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
        $this->assertSame(0, $res->json('attachment_errors'));
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

    /**
     * RC-1 regression: when the public storage disk is broken / symlink missing,
     * the bug report itself must still be created (HTTP 201) and attachment_errors
     * must reflect the failure count rather than bubbling up as a 500.
     */
    public function test_submit_bug_report_with_screenshot_returns_201_even_when_storage_fails(): void
    {
        Storage::fake('public');

        // Replace the 'public' disk with a mock that throws on every write.
        $throwingDisk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $throwingDisk->shouldReceive('putFileAs')->andThrow(new \RuntimeException('Simulated disk failure'));
        // Allow any other calls (e.g. exists) to succeed silently.
        $throwingDisk->shouldReceive('exists')->andReturn(false);
        $throwingDisk->shouldIgnoreMissing();
        Storage::set('public', $throwingDisk);

        [$token] = $this->createUserToken([1], 'bugStorageFail@test.com', 'T');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post('/api/v1/bugs', [
            'title' => '存檔失敗回退測試',
            'description' => '附件存檔失敗時主回報仍應成功',
            'severity' => 'low',
            'branch_id' => 1,
            'attachments' => [
                UploadedFile::fake()->image('fail.png', 40, 40),
            ],
        ]);

        // The bug report itself must be created even if attachment storage fails.
        $res->assertStatus(201);
        $this->assertGreaterThan(0, $res->json('attachment_errors'));
        $this->assertDatabaseCount('bug_report_attachments', 0);
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

    public function test_director_bug_list_shows_only_own_reports(): void
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

    public function test_director_unread_badge_ignores_others_new_submissions(): void
    {
        [$tokenDirector, $director] = $this->createUserToken([1], 'dirBadge@test.com', 'A');
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'teaBadge@test.com', 'T');

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'From teacher', 'description' => 'Desc', 'severity' => 'medium', 'status' => 'new',
        ]);

        $badge = $this->withHeaders([
            'Authorization' => "Bearer {$tokenDirector}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');

        $badge->assertOk();
        $this->assertEquals(0, $badge->json('unread_count'));

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $director->id,
            'title' => 'My own new', 'description' => 'Desc', 'severity' => 'low', 'status' => 'new',
        ]);

        $badge2 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenDirector}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');

        $badge2->assertOk();
        $this->assertEquals(0, $badge2->json('unread_count'));
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

    public function test_director_cannot_update_bug_comment_visibility(): void
    {
        [$tokenDirector, $director] = $this->createUserToken([1], 'dirVis@test.com', 'A');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $director->id,
            'title' => 'Visibility Permission Test', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $commentRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenDirector}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => '一般留言',
            'is_internal_note' => false,
        ]);
        $commentRes->assertStatus(201);
        $commentId = (int) $commentRes->json('id');

        $toggleRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenDirector}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/bugs/{$bug->id}/comments/{$commentId}/visibility", [
            'is_internal_note' => true,
        ]);

        $toggleRes->assertStatus(403);
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
        ])->getJson('/api/v1/bugs?branch_id=1')->assertOk();

        $badge2 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');
        $badge2->assertOk();
        $this->assertEquals(0, $badge2->json('unread_count'));
    }

    public function test_reporter_unread_badge_after_status_change_only_and_cleared_on_view(): void
    {
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'statT@test.com', 'T');
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'statA@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'Status badge', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'triaged',
            'note' => 'Queued',
        ])->assertOk();

        $badge1 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');
        $badge1->assertOk();
        $this->assertEquals(1, $badge1->json('unread_count'));

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1')->assertOk();

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

    public function test_reporter_cannot_view_internal_comment_in_bug_detail(): void
    {
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'intViewT@test.com', 'T');
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'intViewA@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'Internal visibility', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => '只給內部看',
            'is_internal_note' => true,
        ])->assertStatus(201);

        $detail = $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/bugs/{$bug->id}?branch_id=1");

        $detail->assertOk();
        $comments = collect($detail->json('comments'));
        $this->assertFalse($comments->contains(fn ($c) => ($c['body'] ?? null) === '只給內部看'));
    }

    public function test_super_admin_can_toggle_comment_visibility_for_reporter(): void
    {
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'visT@test.com', 'T');
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'visA@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'Toggle visibility', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $createComment = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => '可切換可見性',
            'is_internal_note' => true,
        ]);
        $createComment->assertStatus(201);
        $commentId = (int) $createComment->json('id');

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/bugs/{$bug->id}/comments/{$commentId}/visibility", [
            'is_internal_note' => false,
        ])->assertOk();

        $detail = $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/bugs/{$bug->id}?branch_id=1");

        $detail->assertOk();
        $comments = collect($detail->json('comments'));
        $this->assertTrue($comments->contains(fn ($c) => ($c['body'] ?? null) === '可切換可見性'));
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

    // ── Phase 2: Pagination, keyword, date, sort, multi-status ──

    public function test_pagination_meta_fields(): void
    {
        [$token, $user] = $this->createUserToken([1], 'pagMeta@test.com', 'S');

        for ($i = 0; $i < 25; $i++) {
            BugReport::create([
                'CampusID' => 1, 'reporter_user_id' => $user->id,
                'title' => "Pag Bug {$i}", 'description' => 'Desc',
                'severity' => 'low', 'status' => 'new',
            ]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&per_page=10&page=1');

        $res->assertOk();
        $json = $res->json();
        $this->assertCount(10, $json['data']);
        $this->assertEquals(1, $json['current_page']);
        $this->assertEquals(3, $json['last_page']);
        $this->assertEquals(25, $json['total']);

        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&per_page=10&page=3');

        $res2->assertOk();
        $this->assertGreaterThan(0, count($res2->json('data')));
        $this->assertLessThanOrEqual(10, count($res2->json('data')));
        $this->assertGreaterThanOrEqual(1, (int) $res2->json('current_page'));
    }

    public function test_per_page_capped_at_100(): void
    {
        [$token, $user] = $this->createUserToken([1], 'pagCap@test.com', 'S');

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'Cap Test', 'description' => 'Desc',
            'severity' => 'low', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&per_page=999');

        $res->assertOk();
        $this->assertEquals(100, $res->json('per_page'));
    }

    public function test_multi_status_filter(): void
    {
        [$token, $user] = $this->createUserToken([1], 'multiSt@test.com', 'S');

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'New One', 'description' => 'Desc',
            'severity' => 'low', 'status' => 'new',
        ]);
        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'In Progress One', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'in_progress',
        ]);
        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'Closed One', 'description' => 'Desc',
            'severity' => 'low', 'status' => 'closed',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&status=new,in_progress');

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('New One', $titles);
        $this->assertContains('In Progress One', $titles);
        $this->assertNotContains('Closed One', $titles);
    }

    public function test_keyword_search_title_and_description(): void
    {
        [$token, $user] = $this->createUserToken([1], 'kwSearch@test.com', 'S');

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => '排課頁面空白', 'description' => '切換分校後空白',
            'severity' => 'high', 'status' => 'new',
        ]);
        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => '帳單問題', 'description' => '金額計算錯誤',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&keyword=' . urlencode('排課'));

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('排課頁面空白', $titles);
        $this->assertNotContains('帳單問題', $titles);

        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&keyword=' . urlencode('金額'));

        $res2->assertOk();
        $titles2 = collect($res2->json('data'))->pluck('title')->all();
        $this->assertContains('帳單問題', $titles2);
        $this->assertNotContains('排課頁面空白', $titles2);
    }

    public function test_date_range_filter(): void
    {
        [$token, $user] = $this->createUserToken([1], 'dateR@test.com', 'S');

        $oldBug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'Old Bug', 'description' => 'Desc',
            'severity' => 'low', 'status' => 'new',
        ]);
        $oldBug->created_at = '2026-01-15 10:00:00';
        $oldBug->save();

        $newBug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'Recent Bug', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&date_from=2026-04-01');

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('Recent Bug', $titles);
        $this->assertNotContains('Old Bug', $titles);

        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&date_to=2026-02-01');

        $res2->assertOk();
        $titles2 = collect($res2->json('data'))->pluck('title')->all();
        $this->assertContains('Old Bug', $titles2);
        $this->assertNotContains('Recent Bug', $titles2);
    }

    public function test_sort_by_created_at_asc(): void
    {
        [$token, $user] = $this->createUserToken([1], 'sortAsc@test.com', 'S');

        $b1 = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'First', 'description' => 'Desc',
            'severity' => 'low', 'status' => 'new',
        ]);
        $b1->created_at = '2026-04-01 10:00:00';
        $b1->save();

        $b2 = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'Second', 'description' => 'Desc',
            'severity' => 'medium', 'status' => 'new',
        ]);
        $b2->created_at = '2026-04-10 10:00:00';
        $b2->save();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&sort=created_at_asc');

        $res->assertOk();
        $data = $res->json('data');
        $this->assertEquals('First', $data[0]['title']);
        $this->assertEquals('Second', $data[1]['title']);
    }

    public function test_keyword_search_does_not_leak_cross_campus(): void
    {
        [$tokenA, $userA] = $this->createUserToken([1], 'kwCampA@test.com', 'A');
        [$tokenB, $userB] = $this->createUserToken([2], 'kwCampB@test.com', 'A');

        BugReport::create([
            'CampusID' => 2, 'reporter_user_id' => $userB->id,
            'title' => '秘密排課問題', 'description' => '機密',
            'severity' => 'high', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&keyword=' . urlencode('秘密'));

        $res->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_empty_result_returns_valid_pagination(): void
    {
        [$token, $user] = $this->createUserToken([1], 'emptyPag@test.com', 'S');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1&keyword=nonexistent_impossible_keyword');

        $res->assertOk();
        $json = $res->json();
        $this->assertCount(0, $json['data']);
        $this->assertEquals(1, $json['current_page']);
        $this->assertEquals(1, $json['last_page']);
        $this->assertEquals(0, $json['total']);
    }

    // ── Reporter Verify (待驗收流程) ───────────────────────────────

    public function test_reporter_can_confirm_resolved_bug(): void
    {
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'rv-confirm@test.com', 'T');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'Verify confirm', 'description' => 'D',
            'severity' => 'low', 'status' => 'resolved',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/reporter-verify", [
            'verdict' => 'confirmed',
        ]);

        $res->assertOk();
        $this->assertEquals('closed', $res->json('new_status'));
        $this->assertDatabaseHas('bug_reports', ['id' => $bug->id, 'status' => 'closed']);
    }

    public function test_reporter_can_reopen_resolved_bug(): void
    {
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'rv-reopen@test.com', 'T');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'Verify reopen', 'description' => 'D',
            'severity' => 'low', 'status' => 'resolved',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/reporter-verify", [
            'verdict' => 'reopened',
            'note'    => '重啟按鈕還是失靈',
        ]);

        $res->assertOk();
        $this->assertEquals('in_progress', $res->json('new_status'));
        $this->assertDatabaseHas('bug_reports', ['id' => $bug->id, 'status' => 'in_progress']);
    }

    public function test_non_reporter_cannot_verify_bug(): void
    {
        [$tokenOwner, $owner] = $this->createUserToken([1], 'rv-owner@test.com', 'T');
        [$tokenOther, ] = $this->createUserToken([1], 'rv-other@test.com', 'T');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $owner->id,
            'title' => 'Verify forbidden', 'description' => 'D',
            'severity' => 'low', 'status' => 'resolved',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenOther}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/reporter-verify", [
            'verdict' => 'confirmed',
        ])->assertStatus(403);
    }

    public function test_reporter_cannot_verify_non_resolved_bug(): void
    {
        [$tokenTeacher, $teacher] = $this->createUserToken([1], 'rv-nostate@test.com', 'T');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $teacher->id,
            'title' => 'Wrong state', 'description' => 'D',
            'severity' => 'low', 'status' => 'in_progress',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenTeacher}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/reporter-verify", [
            'verdict' => 'confirmed',
        ])->assertStatus(422);
    }

    /**
     * Regression: reporter-verify must use same cross-branch scope as show() (#378).
     * Frontend list is scoped by branch_id; verify must not 404 when bug was filed at another campus.
     */
    public function test_reporter_can_verify_resolved_bug_from_another_branch_with_branch_id(): void
    {
        [$tokenMulti, $userMulti] = $this->createUserToken([1, 2], 'rv-cross-branch@test.com', 'D');

        $bug = BugReport::create([
            'CampusID' => 2, 'reporter_user_id' => $userMulti->id,
            'title' => 'Resolved at campus 2', 'description' => 'D',
            'severity' => 'low', 'status' => 'resolved',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenMulti}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/reporter-verify?branch_id=1", [
            'verdict' => 'confirmed',
        ]);

        $res->assertOk();
        $this->assertEquals('closed', $res->json('new_status'));
    }

    // ── Cross-branch reporter visibility (#378) ──────────────────

    /**
     * Reporter belonging to multiple campuses sees ALL their own reports
     * regardless of currently-selected branch_id (industry norm: GitHub
     * Issues / Linear / ServiceNow / Jira all let the requester see their
     * own filings across workspaces).
     */
    public function test_reporter_sees_own_bugs_across_all_their_campuses(): void
    {
        [$tokenMulti, $userMulti] = $this->createUserToken([1, 2, 3], 'multi-camp@test.com', 'T');

        $bug1 = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $userMulti->id,
            'title' => 'Filed at campus 1', 'description' => 'D', 'severity' => 'low', 'status' => 'new',
        ]);
        $bug2 = BugReport::create([
            'CampusID' => 2, 'reporter_user_id' => $userMulti->id,
            'title' => 'Filed at campus 2', 'description' => 'D', 'severity' => 'low', 'status' => 'resolved',
        ]);
        $bug3 = BugReport::create([
            'CampusID' => 3, 'reporter_user_id' => $userMulti->id,
            'title' => 'Filed at campus 3', 'description' => 'D', 'severity' => 'low', 'status' => 'closed',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenMulti}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1');

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();

        $this->assertContains('Filed at campus 1', $titles, 'Reporter must see own bug from current branch');
        $this->assertContains('Filed at campus 2', $titles, 'Reporter must see own bug from another branch they belong to');
        $this->assertContains('Filed at campus 3', $titles, 'Reporter must see own bug from a third branch they belong to');
    }

    /**
     * Reporter still cannot see other people's bugs even within own campuses.
     * Regression guard for `reporter_user_id` filter — must NOT be relaxed.
     */
    public function test_reporter_cross_branch_does_not_leak_other_users_bugs(): void
    {
        [$tokenSelf, $userSelf] = $this->createUserToken([1, 2], 'self-multi@test.com', 'T');
        [, $userOther] = $this->createUserToken([1, 2], 'other-multi@test.com', 'T');

        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $userOther->id,
            'title' => 'Other user bug at campus 1', 'description' => 'D', 'severity' => 'low', 'status' => 'new',
        ]);
        BugReport::create([
            'CampusID' => 2, 'reporter_user_id' => $userOther->id,
            'title' => 'Other user bug at campus 2', 'description' => 'D', 'severity' => 'low', 'status' => 'new',
        ]);
        BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $userSelf->id,
            'title' => 'Mine at campus 1', 'description' => 'D', 'severity' => 'low', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenSelf}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1');

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();

        $this->assertContains('Mine at campus 1', $titles);
        $this->assertNotContains('Other user bug at campus 1', $titles);
        $this->assertNotContains('Other user bug at campus 2', $titles);
    }

    /**
     * Reporter cannot see bug from a campus they do NOT belong to,
     * even if branch_id query param mentions only one of theirs.
     * Regression guard for analyst/teacher cross-tenant boundary.
     */
    public function test_reporter_does_not_see_own_bug_from_unbound_campus(): void
    {
        // user belongs to campus 1 only
        [$tokenSingle, $userSingle] = $this->createUserToken([1], 'single-camp@test.com', 'T');

        // Pretend an old report exists in campus 99 (e.g. they used to belong, but were removed)
        BugReport::create([
            'CampusID' => 99, 'reporter_user_id' => $userSingle->id,
            'title' => 'Old bug from removed campus', 'description' => 'D', 'severity' => 'low', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenSingle}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs?branch_id=1');

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertNotContains('Old bug from removed campus', $titles, 'Reporter must NOT see bugs from campuses they no longer belong to');
    }

    /**
     * Reporter can open detail of own bug filed at a different campus,
     * as long as they still belong to that campus today.
     */
    public function test_reporter_can_open_own_bug_detail_from_another_branch(): void
    {
        [$tokenMulti, $userMulti] = $this->createUserToken([1, 2], 'detail-multi@test.com', 'T');

        $bug = BugReport::create([
            'CampusID' => 2, 'reporter_user_id' => $userMulti->id,
            'title' => 'Filed at campus 2', 'description' => 'D', 'severity' => 'low', 'status' => 'new',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenMulti}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/bugs/{$bug->id}?branch_id=1");

        $res->assertOk();
        $this->assertEquals('Filed at campus 2', $res->json('title'));
    }

    /**
     * unread-badge counts replies/status changes across reporter's all branches,
     * not just current branch_id.
     */
    public function test_reporter_unread_badge_counts_replies_across_branches(): void
    {
        [$tokenSelf, $userSelf] = $this->createUserToken([1, 2], 'badge-multi@test.com', 'T');
        [, $admin] = $this->createUserToken([1, 2], 'badge-admin@test.com', 'S');

        // Two bugs, one per branch
        $bug1 = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $userSelf->id,
            'title' => 'B1', 'description' => 'D', 'severity' => 'low', 'status' => 'new',
        ]);
        $bug2 = BugReport::create([
            'CampusID' => 2, 'reporter_user_id' => $userSelf->id,
            'title' => 'B2', 'description' => 'D', 'severity' => 'low', 'status' => 'new',
        ]);

        // Admin replies on both
        \App\Models\BugReportComment::create([
            'bug_report_id' => $bug1->id, 'author_user_id' => $admin->id,
            'body' => 'reply on b1', 'is_internal_note' => false,
            'created_at' => now(),
        ]);
        \App\Models\BugReportComment::create([
            'bug_report_id' => $bug2->id, 'author_user_id' => $admin->id,
            'body' => 'reply on b2', 'is_internal_note' => false,
            'created_at' => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenSelf}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/bugs/unread-badge?branch_id=1');

        $res->assertOk();
        $this->assertEquals(2, $res->json('unread_count'), 'Badge must count both replies even when on branch 1');
    }

    public function test_resolve_without_public_reply_rejected(): void
    {
        [$tokenAdmin] = $this->createUserToken([1], 'resNoPub@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => 1,
            'title' => 'No public', 'description' => 'D',
            'severity' => 'low', 'status' => 'in_progress',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'resolved',
            'production_revision' => '662960e58535da8e693dcf0b895f32c834c8a039',
        ]);

        $res->assertStatus(422);
        $res->assertJson(['code' => 'missing_public_reply']);
        $this->assertEquals('in_progress', $bug->fresh()->status);
    }

    public function test_resolve_with_only_internal_comment_rejected(): void
    {
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'resInt@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $admin->id,
            'title' => 'Internal only', 'description' => 'D',
            'severity' => 'low', 'status' => 'in_progress',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => 'internal rca',
            'is_internal_note' => true,
        ])->assertStatus(201);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'resolved',
            'production_revision' => '662960e5',
        ]);

        $res->assertStatus(422);
        $res->assertJson(['code' => 'missing_public_reply']);
    }

    public function test_resolve_without_production_evidence_rejected(): void
    {
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'resNoEv@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $admin->id,
            'title' => 'No sha', 'description' => 'D',
            'severity' => 'low', 'status' => 'in_progress',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => '請再試一次',
            'is_internal_note' => false,
        ])->assertStatus(201);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'resolved',
        ]);

        $res->assertStatus(422);
        $res->assertJson(['code' => 'missing_production_evidence']);
        $this->assertEquals('in_progress', $bug->fresh()->status);
    }

    public function test_resolve_with_full_evidence_succeeds(): void
    {
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'resOk@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $admin->id,
            'title' => 'Full evidence', 'description' => 'D',
            'severity' => 'low', 'status' => 'in_progress',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => '已上線，請驗收',
            'is_internal_note' => false,
        ])->assertStatus(201);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'resolved',
            'production_revision' => '662960e5',
            'deploy_run_id' => '29630243852',
            'note' => 'PR #1299',
        ]);

        $res->assertOk();
        $this->assertEquals('resolved', $bug->fresh()->status);
        $this->assertDatabaseHas('bug_report_status_logs', [
            'bug_report_id' => $bug->id,
            'to_status' => 'resolved',
            'changed_by' => $admin->id,
        ]);
        $logNote = \App\Models\BugReportStatusLog::where('bug_report_id', $bug->id)
            ->where('to_status', 'resolved')->value('note');
        $this->assertStringContainsString('[resolution_evidence]', (string) $logNote);
        $this->assertStringContainsString('662960e5', (string) $logNote);
    }

    public function test_resolve_with_exception_reason_super_admin(): void
    {
        [$tokenAdmin, $admin] = $this->createUserToken([1], 'resEx@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $admin->id,
            'title' => 'Exception path', 'description' => 'D',
            'severity' => 'low', 'status' => 'in_progress',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/comments", [
            'body' => '文件修正，無 deploy',
            'is_internal_note' => false,
        ])->assertStatus(201);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'resolved',
            'evidence_exception_reason' => 'docs-only fix; no production deploy required by policy',
        ]);

        $res->assertOk();
        $this->assertEquals('resolved', $bug->fresh()->status);
    }

    public function test_triaged_transition_unchanged_without_evidence(): void
    {
        [$tokenAdmin] = $this->createUserToken([1], 'triagedOk@test.com', 'S');

        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => 1,
            'title' => 'Triage only', 'description' => 'D',
            'severity' => 'low', 'status' => 'new',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenAdmin}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/bugs/{$bug->id}/status", [
            'status' => 'triaged',
        ])->assertOk();

        $this->assertEquals('triaged', $bug->fresh()->status);
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
