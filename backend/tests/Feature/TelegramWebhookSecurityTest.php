<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #1021: the Telegram webhook must fail CLOSED. It requires a per-campus
 * `TelegramWebhookSecret` matched (constant-time) against the
 * `X-Telegram-Bot-Api-Secret-Token` header before processing any update.
 *
 * The existing TelegramWebhookTest covers the happy binding flows; this file
 * pins the rejection paths so the signature gate cannot silently regress.
 */
class TelegramWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function campus(array $overrides = []): Campus
    {
        return Campus::factory()->create(array_merge([
            'TelegramToken' => 'bot-token-123',
            'TelegramWebhookSecret' => 'webhook-secret-xyz',
        ], $overrides));
    }

    private function postTelegramWebhook(string $code, array $update, ?string $secret)
    {
        $headers = $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret];

        return $this->postJson("/api/v1/telegram/webhook/{$code}", $update, $headers);
    }

    private function update(string $chatId, string $text): array
    {
        return ['message' => ['chat' => ['id' => $chatId], 'text' => $text]];
    }

    public function test_unknown_campus_returns_404(): void
    {
        $this->postTelegramWebhook('no-such-code', $this->update('1', 'hi'), 'webhook-secret-xyz')
            ->assertStatus(404);
    }

    public function test_campus_without_bot_token_returns_503(): void
    {
        $campus = $this->campus(['TelegramToken' => '']);
        $this->postTelegramWebhook($campus->code, $this->update('1', 'hi'), 'webhook-secret-xyz')
            ->assertStatus(503);
    }

    public function test_campus_without_webhook_secret_fails_closed_503(): void
    {
        $campus = $this->campus(['TelegramWebhookSecret' => '']);
        $this->postTelegramWebhook($campus->code, $this->update('1', 'hi'), 'anything')
            ->assertStatus(503);
    }

    public function test_missing_secret_header_is_rejected_403(): void
    {
        $campus = $this->campus();
        $this->postTelegramWebhook($campus->code, $this->update('1', 'hi'), null)
            ->assertStatus(403);
    }

    public function test_wrong_secret_header_is_rejected_403(): void
    {
        $campus = $this->campus();
        $this->postTelegramWebhook($campus->code, $this->update('1', 'hi'), 'wrong-secret')
            ->assertStatus(403);
    }

    public function test_no_binding_occurs_without_valid_secret(): void
    {
        $campus = $this->campus();
        $student = Student::create([
            'name' => '未授權綁定生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $this->postTelegramWebhook($campus->code, $this->update('777', '未授權綁定生'), null)
            ->assertStatus(403);

        $this->assertNull($student->fresh()->TelegramID);
    }

    public function test_valid_secret_opens_the_gate(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $campus = $this->campus();

        $this->postTelegramWebhook($campus->code, $this->update('555', '/start'), 'webhook-secret-xyz')
            ->assertOk();
    }
}
