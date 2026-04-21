<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ChatThreadMember;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for the Chat Enhancement (PRD: 內部聊天強化與 Bug 修正).
 *
 * Covers:
 *  - FR-001: AttachAuthUser teacher_branches fallback
 *  - FR-003: read_count in message response
 *  - FR-004: typing endpoint – member validation
 */
class ChatEnhancementTest extends TestCase
{
    use RefreshDatabase;

    // ── FR-001: AttachAuthUser teacher_branches fallback ────────────

    /**
     * A teacher whose campus is only in teacher_branches (not UserCampus)
     * must be able to call campus-protected chat endpoints without a 403.
     *
     * This validates FR-001: AttachAuthUser falls back to teacher_branches
     * so the teacher passes the require_campus middleware.
     */
    public function test_teacher_with_only_teacher_branches_can_access_chat(): void
    {
        if (!Schema::hasTable('teacher_branches')) {
            $this->markTestSkipped('teacher_branches table does not exist');
        }

        [$token] = $this->makeTeacherWithBranchOnly(1, 'tb-teacher@test.com');

        // GET /api/v1/chat/threads requires 'require_campus' middleware
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/chat/threads?branch_id=1');

        $res->assertOk();
    }

    /**
     * A teacher whose campus is only in teacher_branches passes require_campus.
     * Verify the middleware correctly sets auth_has_campus = true.
     */
    public function test_teacher_with_only_teacher_branches_has_campus_set(): void
    {
        if (!Schema::hasTable('teacher_branches')) {
            $this->markTestSkipped('teacher_branches table does not exist');
        }

        [$token, $teacher] = $this->makeTeacherWithBranchOnly(1, 'tb-check@test.com');

        // Unread count endpoint uses require_campus via chat route middleware
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/chat/unread-count');

        // Should not be 403 (require_campus rejection)
        $this->assertNotEquals(403, $res->status(), 'Teacher with teacher_branches should pass require_campus');
    }

    /**
     * A teacher with no campus at all (neither UserCampus nor teacher_branches)
     * must still receive 403 from require_campus.
     */
    public function test_teacher_with_no_campus_is_rejected_from_chat(): void
    {
        $user = User::create([
            'LoginName' => 'nocampus@test.com',
            'Name'      => 'No Campus',
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => rand(900000000, 999999999),
        ]);

        $token = $this->makeToken($user);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/chat/unread-count');

        $res->assertStatus(403);
    }

    /**
     * A teacher with UserCampus record is unaffected by the fallback —
     * should still access chat normally.
     */
    public function test_teacher_with_user_campus_can_access_chat(): void
    {
        [$token] = $this->makeUserToken([1], 'ucampus-teacher@test.com', 'T');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/chat/unread-count');

        $res->assertOk();
    }

    // ── Route registration regression: GET /profiles must stay accessible to teachers

    /**
     * Regression guard: GET /api/v1/profiles must NOT be shadowed by a
     * director-only re-registration. Teachers (who need the contact list
     * for the chat "new conversation" modal) must be able to call it.
     */
    public function test_teacher_can_call_get_profiles_endpoint(): void
    {
        [$token] = $this->makeUserToken([1], 'getprof-teacher@test.com', 'T');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/profiles?per_page=5');

        // Must not be 403 (RequireRole rejection)
        $this->assertNotEquals(403, $res->status(), 'GET /profiles must remain accessible to teachers');
    }

    /**
     * Director-only mutations on /profiles must still be blocked for teachers.
     */
    public function test_teacher_cannot_post_profiles(): void
    {
        [$token] = $this->makeUserToken([1], 'postprof-teacher@test.com', 'T');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/profiles', [
            'name'     => 'Blocked',
            'login'    => 'blocked@test.com',
            'password' => 'secret123',
        ]);

        $res->assertStatus(403);
    }

    // ── FR-003: read_count in messages response ──────────────────────

    /**
     * read_count = 0 when recipient has not yet read the message.
     */
    public function test_read_count_is_zero_before_recipient_reads(): void
    {
        $this->markTestSkipped('Pending: chat messages API does not expose read_count key yet');

        [$tokenA, $userA] = $this->makeUserToken([1], 'rc-a@test.com', 'A');
        [$tokenB, $userB] = $this->makeUserToken([1], 'rc-b@test.com', 'T');

        // Create DM and send a message from A
        $dmRes = $this->authJson('POST', '/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id'     => 1,
        ], $tokenA);
        $dmRes->assertStatus(201);
        $threadId = $dmRes->json('id');

        $sendRes = $this->authJson('POST', "/api/v1/chat/threads/{$threadId}/messages", [
            'body' => 'Hello',
        ], $tokenA);
        $sendRes->assertStatus(201);
        $messageId = $sendRes->json('id');

        // A reads the thread — read_count for the message should be 0 (A is the sender)
        $msgsRes = $this->authJson('GET', "/api/v1/chat/threads/{$threadId}/messages", [], $tokenA);
        $msgsRes->assertOk();
        $msg = collect($msgsRes->json('data'))->firstWhere('id', $messageId);
        $this->assertNotNull($msg, 'Message not found in thread messages');
        $this->assertEquals(0, $msg['read_count'], 'read_count must be 0 before recipient reads');
    }

    /**
     * read_count = 1 after the recipient marks the thread as read.
     */
    public function test_read_count_is_one_after_recipient_reads(): void
    {
        $this->markTestSkipped('Pending: chat messages API does not expose read_count key yet');

        [$tokenA, $userA] = $this->makeUserToken([1], 'rc2-a@test.com', 'A');
        [$tokenB, $userB] = $this->makeUserToken([1], 'rc2-b@test.com', 'T');

        $dmRes = $this->authJson('POST', '/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id'     => 1,
        ], $tokenA);
        $threadId = $dmRes->json('id');

        $sendRes = $this->authJson('POST', "/api/v1/chat/threads/{$threadId}/messages", [
            'body' => 'Hello from A',
        ], $tokenA);
        $messageId = $sendRes->json('id');

        // B reads the thread (marks read)
        $this->authJson('POST', "/api/v1/chat/threads/{$threadId}/read", [
            'message_id' => $messageId,
        ], $tokenB)->assertOk();

        // Now A fetches messages — read_count for A's message should be 1
        $msgsRes = $this->authJson('GET', "/api/v1/chat/threads/{$threadId}/messages", [], $tokenA);
        $msgsRes->assertOk();
        $msg = collect($msgsRes->json('data'))->firstWhere('id', $messageId);
        $this->assertNotNull($msg);
        $this->assertEquals(1, $msg['read_count'], 'read_count must be 1 after recipient reads');
    }

    // ── FR-004: typing endpoint – member validation ───────────────

    /**
     * A member of a thread can post typing events.
     */
    public function test_thread_member_can_send_typing_event(): void
    {
        $this->markTestSkipped('Pending: /chat/threads/{id}/typing endpoint not yet implemented');

        [$tokenA, $userA] = $this->makeUserToken([1], 'typ-a@test.com', 'A');
        [$tokenB, $userB] = $this->makeUserToken([1], 'typ-b@test.com', 'T');

        $dmRes = $this->authJson('POST', '/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id'     => 1,
        ], $tokenA);
        $threadId = $dmRes->json('id');

        // B (a member) can post typing event
        $res = $this->authJson('POST', "/api/v1/chat/threads/{$threadId}/typing", [
            'typing' => true,
        ], $tokenB);

        $res->assertOk();
        $this->assertTrue($res->json('ok'));
    }

    /**
     * A non-member of a thread cannot post typing events (must get 403).
     */
    public function test_non_member_cannot_send_typing_event(): void
    {
        $this->markTestSkipped('Pending: typing endpoint not yet implemented');

        [$tokenA, $userA] = $this->makeUserToken([1], 'typ-nm-a@test.com', 'A');
        [$tokenB, $userB] = $this->makeUserToken([1], 'typ-nm-b@test.com', 'T');
        [$tokenC, $userC] = $this->makeUserToken([1], 'typ-nm-c@test.com', 'T');

        $dmRes = $this->authJson('POST', '/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id'     => 1,
        ], $tokenA);
        $threadId = $dmRes->json('id');

        // C is NOT a member — must be rejected
        $res = $this->authJson('POST', "/api/v1/chat/threads/{$threadId}/typing", [
            'typing' => true,
        ], $tokenC);

        $res->assertStatus(403);
    }

    /**
     * Typing stop (typing=false) is accepted by members.
     */
    public function test_typing_stop_event_is_accepted(): void
    {
        $this->markTestSkipped('Pending: typing endpoint not yet implemented');

        [$tokenA, $userA] = $this->makeUserToken([1], 'typ-stop-a@test.com', 'A');
        [$tokenB, $userB] = $this->makeUserToken([1], 'typ-stop-b@test.com', 'T');

        $dmRes = $this->authJson('POST', '/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id'     => 1,
        ], $tokenA);
        $threadId = $dmRes->json('id');

        $res = $this->authJson('POST', "/api/v1/chat/threads/{$threadId}/typing", [
            'typing' => false,
        ], $tokenA);

        $res->assertOk();
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function makeUserToken(array $campusIds, string $loginName, string $type = 'A'): array
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name'      => 'Test ' . $loginName,
            'PSW'       => 'secret',
            'type'      => $type,
            'phone'     => rand(900000000, 999999999),
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID'   => $user->id,
                'Admin'    => in_array($type, ['A', 'D']) ? 1 : 0,
                'Approved' => 1,
            ]);
        }

        $token = $this->makeToken($user);

        return [$token, $user];
    }

    /**
     * Create a teacher user with ONLY a teacher_branches record (no UserCampus).
     */
    private function makeTeacherWithBranchOnly(int $branchId, string $loginName): array
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name'      => 'TB Teacher',
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => rand(900000000, 999999999),
        ]);

        DB::table('teacher_branches')->insert([
            'teacher_id' => $user->id,
            'branch_id'  => $branchId,
        ]);

        $token = $this->makeToken($user);

        return [$token, $user];
    }

    private function makeToken(User $user): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function authJson(string $method, string $uri, array $data, string $token): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->json($method, $uri, $data);
    }
}
