<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BugReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * #889 Storage-layer PII protection for bug report attachments:
 * new uploads live on the private `local` disk and are served via root-relative temporary signed URLs;
 * legacy `public` disk attachments remain readable through the same signed endpoint and triage API.
 */
class BugReportPrivateAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function createUserHeaders(string $email): array
    {
        $user = User::create([
            'LoginName' => $email,
            'Name' => '附件測試員',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0900000011',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return [['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'], $user];
    }

    public function test_new_upload_is_stored_on_private_local_disk_and_served_via_signed_url(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$headers] = $this->createUserHeaders('bug-priv-1@example.com');

        $createRes = $this->withHeaders($headers)->post('/api/v1/bugs', [
            'title' => '私有附件測試',
            'description' => '截圖不應進 public disk',
            'severity' => 'medium',
            'branch_id' => 1,
            'attachments' => [UploadedFile::fake()->image('shot.png', 40, 40)],
        ])->assertCreated();

        $bugId = (int) $createRes->json('id');
        $att = BugReportAttachment::query()->where('bug_report_id', $bugId)->firstOrFail();

        Storage::disk('local')->assertExists($att->stored_path);
        Storage::disk('public')->assertMissing($att->stored_path);

        $detail = $this->withHeaders($headers)->getJson("/api/v1/bugs/{$bugId}")->assertOk();
        $signedUrl = (string) $detail->json('attachments.0.url');

        $this->assertStringStartsWith("/api/v1/bug-reports/{$bugId}/attachments/{$att->id}?", $signedUrl);
        $this->assertStringNotContainsString('/storage/', $signedUrl);

        // Valid signed URL works without Bearer header (browser <img :src>).
        $ok = $this->get($signedUrl)->assertOk();
        $ok->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $ok->headers->get('Cache-Control'));

        // Unsigned or tampered URL is rejected with 403.
        $this->get("/api/v1/bug-reports/{$bugId}/attachments/{$att->id}")->assertForbidden();
        $this->get($signedUrl . 'tampered')->assertForbidden();

        // Expired URL is rejected with 403.
        $expiredUrl = URL::temporarySignedRoute(
            'bug-reports.attachment.show',
            now()->subMinute(),
            ['bugReport' => $bugId, 'attachment' => $att->id],
            false
        );
        $this->get($expiredUrl)->assertForbidden();

        // Cross-report attachment pairing returns 404 even with a valid signature.
        $otherBug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => 1,
            'title' => '另一筆回報',
            'description' => 'cross check',
            'severity' => 'low',
            'status' => 'new',
        ]);
        $crossUrl = BugReportService::signedAttachmentUrl((int) $otherBug->id, (int) $att->id);
        $this->get($crossUrl)->assertNotFound();
    }

    public function test_legacy_public_disk_attachment_remains_readable_via_signed_url_and_non_image_is_forced_download(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $bug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => 1,
            'title' => '舊版公開磁碟相容測試',
            'description' => 'legacy fallback',
            'severity' => 'low',
            'status' => 'new',
        ]);

        // Legacy text/html file on public disk must be served as attachment + octet-stream (no stored XSS).
        $legacyPath = "bug-reports/{$bug->id}/legacy.txt";
        Storage::disk('public')->put($legacyPath, 'plain text evidence');
        $att = BugReportAttachment::create([
            'bug_report_id' => $bug->id,
            'stored_path' => $legacyPath,
            'original_name' => 'legacy.txt',
            'mime_type' => 'text/plain',
            'size' => 19,
        ]);

        $signedUrl = BugReportService::signedAttachmentUrl((int) $bug->id, (int) $att->id);
        $res = $this->get($signedUrl)->assertOk();
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString('attachment;', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_triage_download_reads_private_local_disk_attachment(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $bug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => 1,
            'title' => 'Triage 私有磁碟讀取',
            'description' => 'triage local read',
            'severity' => 'low',
            'status' => 'new',
        ]);
        $path = "bug-reports/{$bug->id}/private-shot.png";
        Storage::disk('local')->put($path, 'PNGDATA');
        $att = BugReportAttachment::create([
            'bug_report_id' => $bug->id,
            'stored_path' => $path,
            'original_name' => 'private-shot.png',
            'mime_type' => 'image/png',
            'size' => 7,
        ]);

        $admin = User::create([
            'LoginName' => 'triage-priv-admin@example.com',
            'Name' => '超管測試',
            'PSW' => 'secret',
            'type' => 'S',
            'phone' => '0900000012',
        ]);
        $adminToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $admin->id, 'token' => $adminToken, 'expires_at' => now()->addDay()]);
        $triageToken = (string) $this->postJson(
            '/api/v1/admin/triage-tokens',
            ['name' => 'priv-attach-test'],
            ['Authorization' => "Bearer {$adminToken}"]
        )->assertCreated()->json('token');

        $res = $this->withHeaders(['Authorization' => "Bearer {$triageToken}"])
            ->get("/api/v1/triage/bug-reports/{$bug->id}/attachments/{$att->id}")
            ->assertOk();
        $this->assertSame('PNGDATA', $res->streamedContent());
    }
}
