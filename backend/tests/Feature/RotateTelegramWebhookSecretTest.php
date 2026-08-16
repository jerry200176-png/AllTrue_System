<?php

namespace Tests\Feature;

use App\Models\Campus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #1139: the webhook controller already fails closed on a missing secret
 * (SEC-AUDIT #1021) — this locks the command that closes the operational
 * gap: register secret_token with Telegram, only persist on success.
 */
class RotateTelegramWebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotate_registers_with_telegram_and_saves_secret_on_success(): void
    {
        $campus = Campus::factory()->create(['code' => 'rotatebranch', 'TelegramToken' => 'bot-token']);
        DB::table('Campus')->where('id', $campus->id)->update(['TelegramWebhookSecret' => null]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->artisan('telegram:rotate-webhook-secret', ['campus' => (string) $campus->id])
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'setWebhook')
                && !empty($request['secret_token']);
        });

        $campus->refresh();
        $this->assertNotEmpty($campus->TelegramWebhookSecret);
    }

    public function test_rotate_does_not_persist_secret_when_telegram_rejects(): void
    {
        $campus = Campus::factory()->create(['code' => 'rotatefail', 'TelegramToken' => 'bot-token']);
        DB::table('Campus')->where('id', $campus->id)->update(['TelegramWebhookSecret' => null]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad token'], 401)]);

        $this->artisan('telegram:rotate-webhook-secret', ['campus' => (string) $campus->id])
            ->assertExitCode(1);

        $campus->refresh();
        $this->assertEmpty($campus->TelegramWebhookSecret);
    }

    public function test_dry_run_does_not_call_telegram_or_persist(): void
    {
        $campus = Campus::factory()->create(['code' => 'rotatedry', 'TelegramToken' => 'bot-token']);
        DB::table('Campus')->where('id', $campus->id)->update(['TelegramWebhookSecret' => null]);

        Http::fake();

        $this->artisan('telegram:rotate-webhook-secret', ['campus' => (string) $campus->id, '--dry-run' => true])
            ->assertExitCode(0);

        Http::assertNothingSent();
        $campus->refresh();
        $this->assertEmpty($campus->TelegramWebhookSecret);
    }
}
