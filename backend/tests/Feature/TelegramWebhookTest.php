<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function postWebhook(string $code, array $payload)
    {
        return $this->call(
            'POST',
            "/api/v1/telegram/webhook/{$code}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::WEBHOOK_SECRET,
            ],
            json_encode($payload)
        );
    }

    private function campusWithTelegram(array $overrides = []): Campus
    {
        return Campus::factory()->create(array_merge([
            'TelegramToken' => 'telegram-token',
            'TelegramWebhookSecret' => self::WEBHOOK_SECRET,
        ], $overrides));
    }

    private function makeStudent(int $campusId, string $name, array $overrides = []): Student
    {
        return Student::create(array_merge([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'TelegramID' => '',
        ], $overrides));
    }

    public function test_binds_telegram_chat_id_to_student_by_campus_code(): void
    {
        $campus = $this->campusWithTelegram([
            'name' => '測試新莊',
            'code' => 'tw_bind_by_code',
        ]);
        $student = $this->makeStudent($campus->id, '王小明');

        $this->postWebhook('tw_bind_by_code', [
            'message' => [
                'chat' => ['id' => 123456789],
                'text' => '王小明',
            ],
        ])->assertOk();

        $this->assertSame('123456789', $student->fresh()->TelegramID);
        Http::assertSent(fn ($request) =>
            str_contains($request->url(), 'api.telegram.org/bottelegram-token/sendMessage') &&
            $request['chat_id'] === '123456789' &&
            $request['text'] === '王小明 綁定成功'
        );
    }

    public function test_binds_to_next_available_telegram_slot_by_campus_id(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_bind_by_id']);
        $student = $this->makeStudent($campus->id, '陳小華', [
            'TelegramID' => '111',
            'TelegramID1' => null,
            'TelegramID2' => null,
        ]);

        $this->postWebhook((string) $campus->id, [
            'message' => [
                'chat' => ['id' => 222],
                'text' => '陳小華',
            ],
        ])->assertOk();

        $fresh = $student->fresh();
        $this->assertSame('111', $fresh->TelegramID);
        $this->assertSame('222', $fresh->TelegramID1);
        $this->assertNull($fresh->TelegramID2);
    }

    public function test_does_not_bind_same_name_from_another_campus(): void
    {
        $xinzhuang = $this->campusWithTelegram(['code' => 'tw_cross_a']);
        $daan = $this->campusWithTelegram(['code' => 'tw_cross_b']);
        $daanStudent = $this->makeStudent($daan->id, '李同名');

        $this->postWebhook('tw_cross_a', [
            'message' => [
                'chat' => ['id' => 333],
                'text' => '李同名',
            ],
        ])->assertOk();

        $this->assertSame('', $daanStudent->fresh()->TelegramID);
        Http::assertSent(fn ($request) =>
            $request['chat_id'] === '333' &&
            $request['text'] === '查無此學生：李同名'
        );
    }

    public function test_refuses_when_all_telegram_slots_are_full(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_full_slots']);
        $student = $this->makeStudent($campus->id, '滿額學生', [
            'TelegramID' => '111',
            'TelegramID1' => '222',
            'TelegramID2' => '333',
        ]);

        $this->postWebhook('tw_full_slots', [
            'message' => [
                'chat' => ['id' => 444],
                'text' => '滿額學生',
            ],
        ])->assertOk();

        $fresh = $student->fresh();
        $this->assertSame('111', $fresh->TelegramID);
        $this->assertSame('222', $fresh->TelegramID1);
        $this->assertSame('333', $fresh->TelegramID2);
        Http::assertSent(fn ($request) =>
            $request['chat_id'] === '444' &&
            $request['text'] === '滿額學生 的通知名額已滿（最多三個 Telegram），請洽櫃台。'
        );
    }
}
