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

    // ── 錯誤回饋強化（W31 P2-3）：各類失敗場景須回覆明確中文提示 ────────

    private function assertReplyText(string $expected): void
    {
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.line.me/v2/bot/message/reply')
            && ($req->data()['messages'][0]['text'] ?? null) === $expected);
    }

    public function test_failure_by_name_student_not_found_replies_clear_hint(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();

        $this->postBindingMessage($campus, 'U' . str_repeat('n', 32), '綁定 查無此人 0912345678')->assertOk();

        $this->assertReplyText('找不到「查無此人」的學生，請確認姓名是否正確。');
        $this->assertFalse(StudentLineBinding::where('line_user_id', 'U' . str_repeat('n', 32))->exists());
    }

    public function test_failure_by_name_phone_mismatch_replies_clear_hint(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        Student::create([
            'name' => '電話不符生', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '', 'parent_phone' => '0911222333',
        ]);

        $this->postBindingMessage($campus, 'U' . str_repeat('p', 32), '綁定 電話不符生 0912345678')->assertOk();

        $this->assertReplyText('手機號碼不符，請確認。');
        $this->assertFalse(StudentLineBinding::where('line_user_id', 'U' . str_repeat('p', 32))->exists());
    }

    public function test_failure_by_name_invalid_format_replies_format_example(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();

        // 手機號碼全為非數字 → INVALID_INPUT
        $this->postBindingMessage($campus, 'U' . str_repeat('f', 32), '綁定 王小明 +++')->assertOk();

        $this->assertReplyText('綁定格式錯誤，請輸入「綁定 學生姓名 手機號碼」，例如：綁定 王小明 0912345678。');
    }

    public function test_failure_by_name_cross_campus_student_replies_director_hint(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campusA = Campus::factory()->create();
        $campusB = Campus::factory()->create();
        Student::create([
            'name' => '跨校生', 'CampusID' => $campusB->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '', 'parent_phone' => '0912345678',
        ]);

        $this->postBindingMessage($campusA, 'U' . str_repeat('c', 32), '綁定 跨校生 0912345678')->assertOk();

        $this->assertReplyText('此學生已在其他分校綁定，請聯繫主任。');
        $this->assertFalse(StudentLineBinding::where('line_user_id', 'U' . str_repeat('c', 32))->exists());
    }

    public function test_failure_by_id_student_not_found_replies_clear_hint(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();

        $this->postBindingMessage($campus, 'U' . str_repeat('i', 32), '綁定 99999999 0912345678')->assertOk();

        $this->assertReplyText('找不到學生代號 99999999 的學生，請確認後重試。');
    }

    public function test_failure_by_id_phone_mismatch_replies_clear_hint(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '代號電話不符', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '', 'parent_phone' => '0911222333',
        ]);

        $this->postBindingMessage($campus, 'U' . str_repeat('j', 32), "綁定 {$student->id} 0912345678")->assertOk();

        $this->assertReplyText('手機號碼不符，請確認。');
        $this->assertFalse(StudentLineBinding::where('line_user_id', 'U' . str_repeat('j', 32))->exists());
    }

    public function test_failure_by_id_cross_campus_student_replies_director_hint(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campusA = Campus::factory()->create();
        $campusB = Campus::factory()->create();
        $student = Student::create([
            'name' => '代號跨校生', 'CampusID' => $campusB->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '', 'parent_phone' => '0912345678',
        ]);

        $this->postBindingMessage($campusA, 'U' . str_repeat('k', 32), "綁定 {$student->id} 0912345678")->assertOk();

        $this->assertReplyText('此學生已在其他分校綁定，請聯繫主任。');
        $this->assertFalse(StudentLineBinding::where('line_user_id', 'U' . str_repeat('k', 32))->exists());
    }

    public function test_failure_by_id_invalid_format_replies_format_example(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '代號格式錯', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '', 'parent_phone' => '0912345678',
        ]);

        $this->postBindingMessage($campus, 'U' . str_repeat('m', 32), "綁定 {$student->id} +++")->assertOk();

        $this->assertReplyText("綁定格式錯誤，請輸入「綁定 學生代號 手機號碼」，例如：綁定 {$student->id} 0912345678。");
    }

    public function test_failure_by_name_missing_stored_phone_replies_director_hint(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        Student::create([
            'name' => '無手機生', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '', 'Phone' => '', 'parent_phone' => '',
        ]);

        $this->postBindingMessage($campus, 'U' . str_repeat('z', 32), '綁定 無手機生 0912345678')->assertOk();

        $this->assertReplyText('「無手機生」的學生未登記手機號碼，請聯繫分校確認。');
        $this->assertFalse(StudentLineBinding::where('line_user_id', 'U' . str_repeat('z', 32))->exists());
    }
}
