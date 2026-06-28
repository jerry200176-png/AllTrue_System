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
}
