<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ChatThreadMember;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_dm_thread_and_send_message(): void
    {
        [$tokenA, $userA] = $this->createUserToken([1], 'userA@test.com', 'A');
        [$tokenB, $userB] = $this->createUserToken([1], 'userB@test.com', 'T');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id' => 1,
        ]);

        $res->assertStatus(201);
        $threadId = $res->json('id');
        $this->assertNotNull($threadId);
        $this->assertEquals('dm', $res->json('type'));

        $this->assertDatabaseHas('chat_thread_members', [
            'thread_id' => $threadId,
            'user_id' => $userA->id,
        ]);
        $this->assertDatabaseHas('chat_thread_members', [
            'thread_id' => $threadId,
            'user_id' => $userB->id,
        ]);

        $sendRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/chat/threads/{$threadId}/messages", [
            'body' => 'Hello from A!',
        ]);

        $sendRes->assertStatus(201);
        $this->assertEquals('Hello from A!', $sendRes->json('body'));
        $this->assertEquals($userA->id, $sendRes->json('sender_user_id'));
        $this->assertArrayHasKey('sender_avatar_url', $sendRes->json());
    }

    public function test_create_group_thread(): void
    {
        [$tokenA, $userA] = $this->createUserToken([1], 'groupA@test.com', 'A');
        [$tokenB, $userB] = $this->createUserToken([1], 'groupB@test.com', 'T');
        [$tokenC, $userC] = $this->createUserToken([1], 'groupC@test.com', 'T');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/chat/threads/group', [
            'name' => '教務群',
            'member_ids' => [$userB->id, $userC->id],
            'branch_id' => 1,
        ]);

        $res->assertStatus(201);
        $this->assertEquals('group', $res->json('type'));
        $this->assertEquals('教務群', $res->json('name'));

        $this->assertDatabaseCount('chat_thread_members', 3);
    }

    public function test_read_messages_and_mark_read(): void
    {
        [$tokenA, $userA] = $this->createUserToken([1], 'readA@test.com', 'A');
        [$tokenB, $userB] = $this->createUserToken([1], 'readB@test.com', 'T');

        $createRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id' => 1,
        ]);
        $threadId = $createRes->json('id');

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/chat/threads/{$threadId}/messages", [
            'body' => 'msg1',
        ]);
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/chat/threads/{$threadId}/messages", [
            'body' => 'msg2',
        ]);

        $messagesRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/chat/threads/{$threadId}/messages");

        $messagesRes->assertOk();
        $this->assertCount(2, $messagesRes->json('data'));
        foreach ($messagesRes->json('data') as $row) {
            $this->assertArrayHasKey('sender_avatar_url', $row);
        }

        $unreadRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/chat/unread-count?branch_id=1');
        $unreadRes->assertOk();
        $this->assertEquals(2, $unreadRes->json('unread_count'));

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/chat/threads/{$threadId}/read");

        $unreadRes2 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/chat/unread-count?branch_id=1');
        $this->assertEquals(0, $unreadRes2->json('unread_count'));
    }

    public function test_cross_campus_isolation(): void
    {
        [$tokenA, $userA] = $this->createUserToken([1], 'isoA@test.com', 'A');
        [$tokenB, $userB] = $this->createUserToken([2], 'isoB@test.com', 'A');

        $createRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/chat/threads/dm', [
            'other_user_id' => $userA->id + 999,
            'branch_id' => 1,
        ]);

        $thread = ChatThread::create([
            'CampusID' => 1,
            'type' => 'dm',
            'created_by' => $userA->id,
        ]);
        ChatThreadMember::create([
            'thread_id' => $thread->id,
            'user_id' => $userA->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        $crossRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/chat/threads/{$thread->id}/messages?branch_id=2");

        $this->assertContains($crossRes->status(), [403, 404]);
    }

    public function test_non_member_cannot_read_thread(): void
    {
        [$tokenA, $userA] = $this->createUserToken([1], 'memA@test.com', 'A');
        [$tokenB, $userB] = $this->createUserToken([1], 'memB@test.com', 'T');
        [$tokenC, $userC] = $this->createUserToken([1], 'memC@test.com', 'T');

        $createRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id' => 1,
        ]);
        $threadId = $createRes->json('id');

        $readRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenC}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/chat/threads/{$threadId}/messages");

        $readRes->assertStatus(403);
    }

    public function test_thread_list_returns_correct_data(): void
    {
        [$tokenA, $userA] = $this->createUserToken([1], 'listA@test.com', 'A');
        [$tokenB, $userB] = $this->createUserToken([1], 'listB@test.com', 'T');

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id' => 1,
        ]);

        $listRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/chat/threads?branch_id=1');

        $listRes->assertOk();
        $this->assertGreaterThanOrEqual(1, count($listRes->json('data')));
        $first = $listRes->json('data.0');
        $this->assertArrayHasKey('thread_avatar_url', $first);
        $this->assertArrayHasKey('other_members', $first);
        $this->assertIsArray($first['other_members']);
        if (count($first['other_members']) > 0) {
            $this->assertArrayHasKey('avatar_url', $first['other_members'][0]);
        }
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
