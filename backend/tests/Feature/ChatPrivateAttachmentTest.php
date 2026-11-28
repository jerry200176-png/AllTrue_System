<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ChatThreadMember;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * #889 Storage-layer PII protection for chat attachments:
 * new uploads live on the private `local` disk and are served via root-relative temporary signed URLs;
 * deleted messages immediately return 404 even with a valid signature; legacy `public` disk files remain readable
 * and `privacy:prune-logs` scrubs both disks.
 */
class ChatPrivateAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function createUserHeaders(string $email): array
    {
        $user = User::create([
            'LoginName' => $email,
            'Name' => '聊天附件測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0900000021',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return [['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'], $user];
    }

    public function test_chat_attachment_stored_on_private_local_disk_and_gated_by_signature_and_deletion(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$headersA, $userA] = $this->createUserHeaders('chat-priv-a@example.com');
        [, $userB] = $this->createUserHeaders('chat-priv-b@example.com');

        $threadId = (int) $this->withHeaders($headersA)->postJson('/api/v1/chat/threads/dm', [
            'other_user_id' => $userB->id,
            'branch_id' => 1,
        ])->assertCreated()->json('id');

        $upload = $this->withHeaders($headersA)->post("/api/v1/chat/threads/{$threadId}/attachments", [
            'file' => UploadedFile::fake()->image('evidence.png', 40, 40),
        ])->assertCreated();

        $msgId = (int) $upload->json('id');
        $signedUrl = (string) $upload->json('media_url');
        $storedPath = (string) ChatMessage::findOrFail($msgId)->media_url;

        Storage::disk('local')->assertExists($storedPath);
        Storage::disk('public')->assertMissing($storedPath);
        $this->assertStringStartsWith("/api/v1/chat/threads/{$threadId}/messages/{$msgId}/attachment?", $signedUrl);
        $this->assertStringNotContainsString('/storage/', $signedUrl);

        // Valid signed URL serves image with private cache + nosniff.
        $ok = $this->get($signedUrl)->assertOk();
        $ok->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $ok->headers->get('Cache-Control'));

        // Unsigned, tampered, or expired URLs return 403.
        $this->get("/api/v1/chat/threads/{$threadId}/messages/{$msgId}/attachment")->assertForbidden();
        $this->get($signedUrl . 'tampered')->assertForbidden();
        $expired = URL::temporarySignedRoute(
            'chat.attachment.show',
            now()->subMinute(),
            ['threadId' => $threadId, 'messageId' => $msgId],
            false
        );
        $this->get($expired)->assertForbidden();

        // Cross-thread pairing returns 404 even with a valid signature.
        $otherThread = ChatThread::create(['CampusID' => 1, 'created_by' => $userA->id]);
        $cross = ChatService::signedAttachmentUrl((int) $otherThread->id, $msgId);
        $this->get($cross)->assertNotFound();

        // Authorized member can refresh a fresh signed URL; non-member is rejected with 403.
        $refreshed = $this->withHeaders($headersA)
            ->getJson("/api/v1/chat/threads/{$threadId}/messages/{$msgId}/attachment-url")
            ->assertOk()
            ->json('url');
        $this->assertStringStartsWith("/api/v1/chat/threads/{$threadId}/messages/{$msgId}/attachment?", (string) $refreshed);
        $this->get((string) $refreshed)->assertOk();

        [$headersOutsider] = $this->createUserHeaders('chat-priv-outsider@example.com');
        $this->withHeaders($headersOutsider)
            ->getJson("/api/v1/chat/threads/{$threadId}/messages/{$msgId}/attachment-url")
            ->assertForbidden();

        // Soft-deleting the message immediately blocks both attachment download and URL refresh.
        ChatService::softDeleteMessage($msgId, $userA->id);
        $this->get($signedUrl)->assertNotFound();
        $this->withHeaders($headersA)
            ->getJson("/api/v1/chat/threads/{$threadId}/messages/{$msgId}/attachment-url")
            ->assertNotFound();
    }

    public function test_legacy_public_disk_chat_file_is_readable_as_download_and_pruner_cleans_local_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $thread = ChatThread::create(['CampusID' => 1, 'created_by' => 1]);
        ChatThreadMember::create(['thread_id' => $thread->id, 'user_id' => 1]);

        $legacyPath = "chat-attachments/{$thread->id}/legacy.pdf";
        Storage::disk('public')->put($legacyPath, '%PDF-1.4 fake');
        $legacyMsg = ChatMessage::create([
            'thread_id' => $thread->id,
            'sender_user_id' => 1,
            'sender_name_snapshot' => '老師',
            'body' => 'legacy.pdf',
            'message_type' => 'file',
            'media_url' => $legacyPath,
            'media_type' => 'application/pdf',
            'media_name' => 'legacy.pdf',
            'created_at' => now(),
        ]);

        $signed = ChatService::signedAttachmentUrl((int) $thread->id, (int) $legacyMsg->id);
        $res = $this->get($signed)->assertOk();
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString('attachment;', (string) $res->headers->get('Content-Disposition'));

        // PruneSystemLogs scrubs soft-deleted attachments on private local disk as well.
        $localPath = "chat-attachments/{$thread->id}/old-private.jpg";
        Storage::disk('local')->put($localPath, 'JPG');
        $oldMsg = ChatMessage::create([
            'thread_id' => $thread->id,
            'sender_user_id' => 1,
            'sender_name_snapshot' => '老師',
            'body' => 'old-private.jpg',
            'message_type' => 'image',
            'media_url' => $localPath,
            'media_type' => 'image/jpeg',
            'media_name' => 'old-private.jpg',
            'created_at' => now()->subDays(45),
            'deleted_at' => now()->subDays(31),
        ]);

        $this->artisan('privacy:prune-logs')->assertSuccessful();
        Storage::disk('local')->assertMissing($localPath);
        $this->assertNull($oldMsg->fresh()->media_url);
    }
}
