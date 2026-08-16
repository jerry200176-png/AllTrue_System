<?php

namespace App\Console\Commands;

use App\Models\Campus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * #1139: TelegramWebhookController already fails closed when
 * Campus.TelegramWebhookSecret is empty (SEC-AUDIT #1021) — the remaining
 * gap is operational: nothing ever registers a secret_token with Telegram's
 * setWebhook API. This command closes that gap, one campus at a time.
 *
 * Calls the real Telegram Bot API using the campus's live bot token — a
 * production credential action. Not run by CI or any agent; a human runs
 * this once per campus, interactively.
 */
class RotateTelegramWebhookSecret extends Command
{
    protected $signature = 'telegram:rotate-webhook-secret
        {campus : Campus ID or code}
        {--dry-run : Generate and show the secret, but do not call Telegram or write the DB}';

    protected $description = '為指定分校產生 Telegram webhook secret_token，註冊到 Telegram setWebhook，並存入 Campus.TelegramWebhookSecret';

    public function handle(): int
    {
        $identifier = (string) $this->argument('campus');
        $campus = ctype_digit($identifier)
            ? Campus::find((int) $identifier)
            : Campus::where('code', $identifier)->first();

        if (!$campus) {
            $this->error("Campus not found: {$identifier}");
            return self::FAILURE;
        }

        $botToken = trim((string) ($campus->TelegramToken ?? ''));
        if ($botToken === '') {
            $this->error("Campus {$campus->id} ({$campus->name}) has no TelegramToken configured — nothing to register a webhook for.");
            return self::FAILURE;
        }

        $secret = bin2hex(random_bytes(32));
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Generated secret (not sent to Telegram, not saved):');
            $this->line($secret);
            return self::SUCCESS;
        }

        $webhookUrl = rtrim(config('app.url'), '/') . '/api/v1/telegram/webhook/' . ($campus->code ?: $campus->id);

        $response = Http::timeout(15)->asJson()->post(
            "https://api.telegram.org/bot{$botToken}/setWebhook",
            ['url' => $webhookUrl, 'secret_token' => $secret]
        );

        if (!$response->successful() || !($response->json('ok') ?? false)) {
            Log::error('telegram.webhook.rotate_secret.failed', [
                'campus_id' => $campus->id,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
            $this->error('Telegram setWebhook rejected the request — DB NOT updated. Response: ' . $response->body());
            return self::FAILURE;
        }

        $campus->TelegramWebhookSecret = $secret;
        $campus->save();

        $this->info("Campus {$campus->id} ({$campus->name}): webhook secret rotated and registered with Telegram.");
        return self::SUCCESS;
    }
}
