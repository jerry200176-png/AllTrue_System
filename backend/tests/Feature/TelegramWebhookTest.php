<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Guardian;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentGuardian;
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

    private const LINE = 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return array{0: Student, 1: string} student + parent bearer token of a LINE-verified active guardian */
    private function verifiedGuardian(Campus $campus, array $student = [], array $link = []): array
    {
        $s = $this->makeStudent($campus->id, '王小明', $student);
        $g = Guardian::firstOrCreate(['line_user_id' => self::LINE], ['display_name' => '家長', 'phone' => '0912000111', 'phone_normalized' => '0912000111']);
        StudentGuardian::create(array_merge([
            'student_id' => $s->id, 'guardian_id' => $g->id, 'campus_id' => $campus->id, 'role' => 'mother', 'is_primary' => true,
            'status' => StudentGuardian::STATUS_ACTIVE, 'notify_learning_feedback' => true, 'notify_tuition' => true,
        ], $link));
        $token = 'parent-token-' . $s->id;
        $session = new ParentSession(['StudentID' => $s->id, 'TokenHash' => hash('sha256', $token), 'ExpiresAt' => now()->addHour()]);
        $session->line_user_id = self::LINE;
        $session->save();

        return [$s, $token];
    }

    private function issueCode(string $token): ?string
    {
        return $this->postJson('/api/v1/parent/telegram-bind-code', [], ['Authorization' => 'Bearer ' . $token])->json('code');
    }

    private function say(string $campusCode, int $chat, string $text)
    {
        return $this->postWebhook($campusCode, ['message' => ['chat' => ['id' => $chat], 'text' => $text]])->assertOk();
    }

    public function test_name_based_bind_is_refused(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_name']);
        $student = $this->makeStudent($campus->id, '王小明');

        $this->say('tw_name', 123, '王小明');

        $this->assertSame('', $student->fresh()->TelegramID);
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], '綁定碼') && $r['chat_id'] === '123');
    }

    public function test_valid_code_binds_to_next_slot_and_is_single_use(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_ok']);
        [$student, $token] = $this->verifiedGuardian($campus, ['TelegramID' => '111']);
        $code = $this->issueCode($token);
        $this->assertNotEmpty($code);

        $this->say('tw_ok', 222, "/start {$code}");
        $this->assertSame('222', $student->fresh()->TelegramID1);
        Http::assertSent(fn ($r) => $r['text'] === '王小明 綁定成功');

        $this->say('tw_ok', 333, "/start {$code}"); // reuse
        $fresh = $student->fresh();
        $this->assertNull($fresh->TelegramID2);
        Http::assertSent(fn ($r) => $r['chat_id'] === '333' && str_contains((string) $r['text'], '無效或已過期'));
    }

    public function test_expired_code_is_refused(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_exp']);
        [$student, $token] = $this->verifiedGuardian($campus);
        $code = $this->issueCode($token);

        $this->travel(16)->minutes();
        $this->say('tw_exp', 444, "/start {$code}");

        $this->assertSame('', $student->fresh()->TelegramID);
    }

    public function test_cross_campus_code_is_refused_and_consumed(): void
    {
        $a = $this->campusWithTelegram(['code' => 'tw_a']);
        $b = $this->campusWithTelegram(['code' => 'tw_b']);
        [$student, $token] = $this->verifiedGuardian($a);
        $code = $this->issueCode($token);

        $this->say('tw_b', 555, "/start {$code}");
        $this->say('tw_a', 555, "/start {$code}");

        $this->assertSame('', $student->fresh()->TelegramID);
    }

    public function test_code_is_dead_after_the_guardian_link_is_revoked(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_rev']);
        [$student, $token] = $this->verifiedGuardian($campus);
        $code = $this->issueCode($token);
        StudentGuardian::query()->update(['status' => StudentGuardian::STATUS_REVOKED]);

        $this->say('tw_rev', 666, "/start {$code}");

        $this->assertSame('', $student->fresh()->TelegramID);
    }

    public function test_concurrent_second_redeem_sees_the_code_consumed(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_race']);
        [$student, $token] = $this->verifiedGuardian($campus);
        $code = $this->issueCode($token);
        $svc = app(\App\Services\ParentBinding\TelegramBindCodeService::class);

        $this->assertSame($student->id, $svc->redeem($code, $campus->id)?->id);
        $this->assertNull($svc->redeem($code, $campus->id));
    }

    public function test_code_is_dead_after_the_guardian_on_the_link_is_replaced(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_swap']);
        [$student, $token] = $this->verifiedGuardian($campus);
        $code = $this->issueCode($token);
        $other = Guardian::create(['display_name' => '新監護人', 'phone' => '0912000222', 'phone_normalized' => '0912000222']);
        StudentGuardian::query()->update(['guardian_id' => $other->id]);

        $this->say('tw_swap', 777, "/start {$code}");

        $this->assertSame('', $student->fresh()->TelegramID);
    }

    public function test_code_is_not_issued_without_a_verified_guardian_link(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_noissue']);
        [, $token] = $this->verifiedGuardian($campus, [], ['status' => StudentGuardian::STATUS_SUSPENDED]);

        $this->postJson('/api/v1/parent/telegram-bind-code', [], ['Authorization' => 'Bearer ' . $token])->assertStatus(403);
        $this->postJson('/api/v1/parent/telegram-bind-code', [], ['Authorization' => 'Bearer nope'])->assertStatus(401);
    }

    public function test_dashboard_exposes_only_a_boolean_when_telegram_is_configured(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_flag']);
        [, $token] = $this->verifiedGuardian($campus);

        $res = $this->getJson('/api/v1/parent/dashboard', ['Authorization' => 'Bearer ' . $token])->assertOk();
        $this->assertTrue($res->json('student.telegram_bind_available'));
        $this->assertStringNotContainsString('telegram-token', $res->getContent());

        Campus::whereKey($campus->id)->update(['TelegramToken' => '']);
        $this->assertFalse($this->getJson('/api/v1/parent/dashboard', ['Authorization' => 'Bearer ' . $token])->json('student.telegram_bind_available'));
    }

    public function test_bind_attempts_are_rate_limited_per_chat(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_rate']);
        $this->makeStudent($campus->id, '限流生');

        foreach (range(1, 11) as $_) {
            $this->say('tw_rate', 900, '/start abcdef1234');
        }

        Http::assertSent(fn ($r) => str_contains((string) $r['text'], '嘗試次數過多'));
    }

    public function test_refuses_when_all_telegram_slots_are_full(): void
    {
        $campus = $this->campusWithTelegram(['code' => 'tw_full_slots']);
        [$student, $token] = $this->verifiedGuardian($campus, ['TelegramID' => '111', 'TelegramID1' => '222', 'TelegramID2' => '333']);
        $code = $this->issueCode($token);

        $this->say('tw_full_slots', 444, "/start {$code}");

        $this->assertSame('333', $student->fresh()->TelegramID2);
        Http::assertSent(fn ($r) => $r['chat_id'] === '444' && $r['text'] === '王小明 的通知名額已滿（最多三個 Telegram），請洽櫃台。');
    }
}
