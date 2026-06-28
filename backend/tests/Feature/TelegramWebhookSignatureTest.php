<?php

namespace Tests\Feature;

use App\Models\Campus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SEC-AUDIT (#1021): Telegram webhook must verify X-Telegram-Bot-Api-Secret-Token.
 */
class TelegramWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function postWebhook(string $code, array $payload, ?string $secretHeader)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secretHeader !== null) {
            $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secretHeader;
        }

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        return $this->call(
            'POST',
            "/api/v1/telegram/webhook/{$code}",
            [],
            [],
            [],
            $server,
            json_encode($payload)
        );
    }

    public function test_webhook_without_configured_secret_is_rejected(): void
    {
        $campus = Campus::factory()->create([
            'code' => 'testbranch',
            'TelegramToken' => 'bot-token',
        ]);
        DB::table('Campus')->where('id', $campus->id)->update(['TelegramWebhookSecret' => '']);

        $res = $this->postWebhook('testbranch', [
            'message' => ['chat' => ['id' => '123'], 'text' => '/start'],
        ], 'anything');

        $res->assertStatus(503);
    }

    public function test_webhook_with_invalid_secret_is_rejected(): void
    {
        $campus = Campus::factory()->create([
            'code' => 'branch2',
            'TelegramToken' => 'bot-token',
        ]);
        DB::table('Campus')->where('id', $campus->id)->update(['TelegramWebhookSecret' => 'expected-secret']);

        $res = $this->postWebhook('branch2', [
            'message' => ['chat' => ['id' => '123'], 'text' => '/start'],
        ], 'wrong-secret');

        $res->assertStatus(403);
    }

    public function test_webhook_with_valid_secret_is_processed(): void
    {
        $campus = Campus::factory()->create([
            'code' => 'branch3',
            'TelegramToken' => 'bot-token',
        ]);
        DB::table('Campus')->where('id', $campus->id)->update(['TelegramWebhookSecret' => 'valid-webhook-secret']);

        $res = $this->postWebhook('branch3', [
            'message' => ['chat' => ['id' => '123'], 'text' => '/start'],
        ], 'valid-webhook-secret');

        $res->assertOk();
    }
}
