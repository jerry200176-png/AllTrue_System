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

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_binds_telegram_chat_id_to_student_by_campus_code(): void
    {
        $campus = Campus::factory()->create([
            'name' => '新莊分校',
            'code' => 'xinzhuang',
            'TelegramToken' => 'telegram-token',
        ]);
        $student = Student::factory()->create([
            'name' => '王小明',
            'CampusID' => $campus->id,
            'TelegramID' => '',
        ]);

        $this->postJson('/api/v1/telegram/webhook/xinzhuang', [
            'message' => [
                'chat' => ['id' => 123456789],
                'text' => '王小明',
            ],
        ])->assertOk();

        $this->assertSame('123456789', $student->fresh()->TelegramID);
        Http::assertSent(fn($request) =>
            str_contains($request->url(), 'api.telegram.org/bottelegram-token/sendMessage') &&
            $request['chat_id'] === '123456789' &&
            $request['text'] === '王小明 綁定成功'
        );
    }

    public function test_binds_to_next_available_telegram_slot_by_campus_id(): void
    {
        $campus = Campus::factory()->create([
            'TelegramToken' => 'telegram-token',
        ]);
        $student = Student::factory()->create([
            'name' => '陳小華',
            'CampusID' => $campus->id,
            'TelegramID' => '111',
            'TelegramID1' => null,
            'TelegramID2' => null,
        ]);

        $this->postJson("/api/v1/telegram/webhook/{$campus->id}", [
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
        $xinzhuang = Campus::factory()->create([
            'code' => 'xinzhuang',
            'TelegramToken' => 'telegram-token',
        ]);
        $daan = Campus::factory()->create(['code' => 'daan']);
        $daanStudent = Student::factory()->create([
            'name' => '李同名',
            'CampusID' => $daan->id,
            'TelegramID' => '',
        ]);

        $this->postJson('/api/v1/telegram/webhook/xinzhuang', [
            'message' => [
                'chat' => ['id' => 333],
                'text' => '李同名',
            ],
        ])->assertOk();

        $this->assertSame('', $daanStudent->fresh()->TelegramID);
        Http::assertSent(fn($request) =>
            $request['chat_id'] === '333' &&
            $request['text'] === '查無此學生：李同名'
        );
    }

    public function test_refuses_when_all_telegram_slots_are_full(): void
    {
        $campus = Campus::factory()->create([
            'code' => 'xinzhuang',
            'TelegramToken' => 'telegram-token',
        ]);
        $student = Student::factory()->create([
            'name' => '滿額學生',
            'CampusID' => $campus->id,
            'TelegramID' => '111',
            'TelegramID1' => '222',
            'TelegramID2' => '333',
        ]);

        $this->postJson('/api/v1/telegram/webhook/xinzhuang', [
            'message' => [
                'chat' => ['id' => 444],
                'text' => '滿額學生',
            ],
        ])->assertOk();

        $fresh = $student->fresh();
        $this->assertSame('111', $fresh->TelegramID);
        $this->assertSame('222', $fresh->TelegramID1);
        $this->assertSame('333', $fresh->TelegramID2);
        Http::assertSent(fn($request) =>
            $request['chat_id'] === '444' &&
            $request['text'] === '滿額學生 的通知名額已滿（最多三個 Telegram），請洽櫃台。'
        );
    }
}
