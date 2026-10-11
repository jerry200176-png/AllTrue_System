<?php

namespace Tests\Feature;

use App\Models\Campus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * #1139: the webhook controller already fails closed on a missing secret
 * (SEC-AUDIT #1021) — this locks the command that closes the operational
 * gap: register secret_token with Telegram, only persist on success.
 */
class RotateTelegramWebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    /** Campus 15 may be pre-seeded by migrations; reuse the row when present. */
    private function campus15(array $attrs): Campus
    {
        $c = Campus::find(15) ?? Campus::factory()->create(['id' => 15]);
        DB::table('Campus')->where('id', 15)->update($attrs);
        return $c->refresh();
    }

    public function test_rotate_registers_with_telegram_and_saves_secret_on_success(): void
    {
        $campus = $this->campus15(['code' => 'rotatebranch', 'TelegramToken' => 'bot-token']);
        DB::table('Campus')->where('id', $campus->id)->update(['TelegramWebhookSecret' => null]);

        Http::fake([
            '*setWebhook' => Http::response(['ok' => true], 200),
            '*getWebhookInfo' => fn ($r) => Http::response(['ok' => true, 'result' => ['url' => rtrim(config('app.url'), '/') . '/api/v1/telegram/webhook/rotatebranch']], 200),
        ]);

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
        $campus = $this->campus15(['code' => 'rotatefail', 'TelegramToken' => 'bot-token']);
        DB::table('Campus')->where('id', $campus->id)->update(['TelegramWebhookSecret' => null]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad token'], 401)]);

        $this->artisan('telegram:rotate-webhook-secret', ['campus' => (string) $campus->id])
            ->assertExitCode(1);

        $campus->refresh();
        $this->assertEmpty($campus->TelegramWebhookSecret);
    }

    public function test_dry_run_does_not_call_telegram_or_persist(): void
    {
        $campus = $this->campus15(['code' => 'rotatedry', 'TelegramToken' => 'bot-token']);
        DB::table('Campus')->where('id', $campus->id)->update(['TelegramWebhookSecret' => null]);

        Http::fake();

        $this->artisan('telegram:rotate-webhook-secret', ['campus' => (string) $campus->id, '--dry-run' => true])
            ->assertExitCode(0);

        Http::assertNothingSent();
        $campus->refresh();
        $this->assertEmpty($campus->TelegramWebhookSecret);
    }

    public function test_non_15_campus_is_rejected_without_http_call(): void
    {
        $campus = Campus::find(16) ?? Campus::factory()->create(['id' => 16]);
        DB::table('Campus')->where('id', 16)->update(['code' => 'other', 'TelegramToken' => 'bot-token', 'TelegramWebhookSecret' => null]);
        Http::fake();

        foreach (['16', 'other', '015x'] as $id) {
            $this->artisan('telegram:rotate-webhook-secret', ['campus' => $id])->assertExitCode(1);
        }
        $this->artisan('telegram:rotate-webhook-secret', ['campus' => '16', '--dry-run' => true])->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertEmpty($campus->refresh()->TelegramWebhookSecret);
    }

    public function test_transport_exception_message_does_not_leak_token(): void
    {
        $token = '123456:FAKE-token_abc';
        $campus = $this->campus15(['code' => 'rotateleak', 'TelegramToken' => $token]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException("cURL error 28: timeout for https://api.telegram.org/bot{$token}/setWebhook"));
        Log::spy();

        $this->assertSame(1, Artisan::call('telegram:rotate-webhook-secret', ['campus' => '15']));
        $out = Artisan::output();
        $this->assertStringContainsString('Transport error', $out);
        $this->assertStringNotContainsString('123456', $out);
        $this->assertStringNotContainsString('FAKE-token', $out);

        Log::shouldHaveReceived('critical')->withArgs(fn ($m, $ctx) => !str_contains(json_encode($ctx), '123456'))->once();
    }

    public function test_db_failure_after_set_webhook_restores_previous_registration(): void
    {
        $campus = $this->campus15(['code' => 'rotatedbfail', 'TelegramToken' => 'bot-token']);
        DB::table('Campus')->where('id', 15)->update(['TelegramWebhookSecret' => 'old-secret']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        Campus::saving(fn () => throw new \RuntimeException('db down'));

        $this->artisan('telegram:rotate-webhook-secret', ['campus' => '15'])->assertExitCode(1);

        $sent = Http::recorded()->filter(fn ($p) => str_contains($p[0]->url(), 'setWebhook'))->values();
        $this->assertCount(2, $sent);
        $this->assertSame('old-secret', $sent[1][0]['secret_token']);
        $this->assertNotSame('old-secret', $sent[0][0]['secret_token']);
        $this->assertSame('old-secret', DB::table('Campus')->where('id', 15)->value('TelegramWebhookSecret'));
    }

    public function test_db_failure_and_restore_failure_gives_recovery_message(): void
    {
        $this->campus15(['code' => 'rotateboth', 'TelegramToken' => 'bot-token']);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true], 200)->push(['ok' => false, 'description' => 'down'], 500)]);
        Campus::saving(fn () => throw new \RuntimeException('db down'));

        $this->assertSame(1, Artisan::call('telegram:rotate-webhook-secret', ['campus' => '15']));
        $this->assertStringContainsString('RESTORE FAILED', Artisan::output());
    }

    public function test_ambiguous_transport_failure_restores_previous_registration(): void
    {
        $this->campus15(['code' => 'rotateamb', 'TelegramToken' => 'bot-token', 'TelegramWebhookSecret' => 'old-secret']);
        $n = 0;
        Http::fake(function ($request) use (&$n) {
            if (++$n === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            }
            return Http::response(['ok' => true], 200);
        });

        $this->assertSame(1, Artisan::call('telegram:rotate-webhook-secret', ['campus' => '15']));
        $sent = Http::recorded()->values();
        $this->assertSame(2, $n);  // the thrown first call is not recorded; the second is the restore
        $this->assertCount(1, $sent);
        $this->assertSame('old-secret', $sent[0][0]['secret_token']);
        $this->assertSame('old-secret', DB::table('Campus')->where('id', 15)->value('TelegramWebhookSecret'));
    }

    public function test_verification_failure_exits_non_zero(): void
    {
        $this->campus15(['code' => 'rotateverify', 'TelegramToken' => 'bot-token', 'TelegramWebhookSecret' => null]);
        Http::fake([
            '*setWebhook' => Http::response(['ok' => true], 200),
            '*getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://elsewhere', 'last_error_message' => 'x']], 200),
        ]);

        $this->assertSame(1, Artisan::call('telegram:rotate-webhook-secret', ['campus' => '15']));
        $this->assertStringContainsString('verification FAILED', Artisan::output());
    }

    public function test_rollback_deletes_webhook_and_clears_secret(): void
    {
        $this->campus15(['code' => 'rotaterb', 'TelegramToken' => 'bot-token', 'TelegramWebhookSecret' => 'live-secret']);
        Http::fake(['*deleteWebhook' => Http::response(['ok' => true], 200)]);

        $this->assertSame(0, Artisan::call('telegram:rotate-webhook-secret', ['campus' => '15', '--rollback' => true]));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'deleteWebhook'));
        $this->assertEmpty(DB::table('Campus')->where('id', 15)->value('TelegramWebhookSecret'));
    }

    public function test_rollback_keeps_db_when_telegram_unreachable(): void
    {
        $this->campus15(['code' => 'rotaterb2', 'TelegramToken' => 'bot-token', 'TelegramWebhookSecret' => 'live-secret']);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        $this->assertSame(1, Artisan::call('telegram:rotate-webhook-secret', ['campus' => '15', '--rollback' => true]));
        $this->assertSame('live-secret', DB::table('Campus')->where('id', 15)->value('TelegramWebhookSecret'));
    }
}
