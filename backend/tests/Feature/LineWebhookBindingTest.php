<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LINE OA「綁定 姓名 手機」must honor parent_phone (§R10), not Phone-only.
 */
class LineWebhookBindingTest extends TestCase
{
    use RefreshDatabase;

    private function postBindingMessage(Campus $campus, string $lineUserId, string $text): \Illuminate\Testing\TestResponse
    {
        $secret = 'bind-test-secret';
        DB::table('Campus')->where('id', $campus->id)->update([
            'messaging_channel_secret' => $secret,
            'messaging_channel_token' => 'bind-test-token',
        ]);

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'source' => ['userId' => $lineUserId],
                'message' => ['type' => 'text', 'text' => $text],
                'replyToken' => 'reply-token-1',
            ]],
        ]);
        $sig = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return $this->call(
            'POST',
            "/api/v1/line/webhook/{$campus->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_LINE_SIGNATURE' => $sig,
            ],
            $body
        );
    }

    public function test_binding_by_name_and_phone_succeeds_with_parent_phone_only(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200),
        ]);

        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('a', 32);
        $student = Student::create([
            'name' => '綁定測試生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => '',
            'parent_phone' => '0912345678',
        ]);

        $res = $this->postBindingMessage($campus, $lineUserId, '綁定 綁定測試生 0912345678');
        $res->assertOk();

        $this->assertTrue(
            StudentLineBinding::where('student_id', $student->id)
                ->where('line_user_id', $lineUserId)
                ->whereNotNull('verified_at')
                ->where('verification_method', 'contact_phone')
                ->exists(),
            'Binding row should be created when parent_phone matches'
        );
    }

    public function test_binding_by_name_and_phone_fails_when_only_legacy_phone_differs(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200),
        ]);

        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('b', 32);
        Student::create([
            'name' => '舊手機生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => '0999888777',
            'parent_phone' => '0911222333',
        ]);

        $res = $this->postBindingMessage($campus, $lineUserId, '綁定 舊手機生 0999888777');
        $res->assertOk();

        $this->assertFalse(
            StudentLineBinding::where('line_user_id', $lineUserId)->exists(),
            'Legacy Phone must not match when parent_phone is set to a different number'
        );
    }

    public function test_binding_by_name_without_phone_is_rejected(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200),
        ]);

        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('c', 32);
        Student::create([
            'name' => '不可裸綁學生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'parent_phone' => '0912345678',
        ]);

        $this->postBindingMessage($campus, $lineUserId, '綁定 不可裸綁學生')
            ->assertOk();

        $this->assertFalse(StudentLineBinding::where('line_user_id', $lineUserId)->exists());
    }

    public function test_binding_by_student_id_without_phone_is_rejected(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200),
        ]);

        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('d', 32);
        $student = Student::create([
            'name' => '不可裸綁編號',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'parent_phone' => '0912345678',
        ]);

        $this->postBindingMessage($campus, $lineUserId, '綁定 ' . $student->id)
            ->assertOk();

        $this->assertFalse(StudentLineBinding::where('line_user_id', $lineUserId)->exists());
    }

    // ── [W31][P2-3] 綁定失敗分類 ─────────────────────────────────────────────

    private function replyTexts(): array
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair) => isset($pair[0]) && str_starts_with((string) $pair[0]->url(), 'https://api.line.me/v2/bot/message/reply'))
            ->map(fn (array $pair) => data_get($pair[0]->data(), 'messages.0.text', ''))
            ->all();
    }

    public function test_binding_failure_student_not_found_by_name(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);

        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('e', 32);

        $this->postBindingMessage($campus, $lineUserId, '綁定 不存在的學生 0912345678')
            ->assertOk();

        $this->assertStringContainsString('找不到「不存在的學生」的學生', $this->replyTexts()[0] ?? '');
        $this->assertFalse(StudentLineBinding::where('line_user_id', $lineUserId)->exists());
    }

    public function test_binding_failure_phone_mismatch_by_name(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);

        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('f', 32);
        Student::create([
            'name' => '手機不符生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'parent_phone' => '0912345678',
        ]);

        $this->postBindingMessage($campus, $lineUserId, '綁定 手機不符生 0987654321')
            ->assertOk();

        $this->assertStringContainsString('手機號碼不符，請確認', $this->replyTexts()[0] ?? '');
        $this->assertFalse(StudentLineBinding::where('line_user_id', $lineUserId)->exists());
    }

    public function test_binding_failure_student_not_found_by_id(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);

        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('a0', 16);

        $this->postBindingMessage($campus, $lineUserId, '綁定 999999 0912345678')
            ->assertOk();

        $this->assertStringContainsString('找不到學生代號 999999，請確認後重試', $this->replyTexts()[0] ?? '');
        $this->assertFalse(StudentLineBinding::where('line_user_id', $lineUserId)->exists());
    }

    public function test_binding_failure_cross_campus_conflict(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);

        $campusA = Campus::factory()->create();
        $campusB = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('b0', 16);
        $student = Student::create([
            'name' => '跨校綁定生',
            'CampusID' => $campusA->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'parent_phone' => '0912345678',
        ]);
        // 學生已在另一分校建立 verified binding
        StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => 'U' . str_repeat('c0', 16),
            'campus_id' => $campusB->id,
            'bound_at' => now(),
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
        ]);

        $this->postBindingMessage($campusA, $lineUserId, '綁定 跨校綁定生 0912345678')
            ->assertOk();

        $this->assertStringContainsString('此學生已在其他分校綁定，請聯繫主任', $this->replyTexts()[0] ?? '');
        $this->assertFalse(
            StudentLineBinding::where('student_id', $student->id)
                ->where('line_user_id', $lineUserId)
                ->exists(),
            'Cross-campus binding must be rejected'
        );
    }
}
