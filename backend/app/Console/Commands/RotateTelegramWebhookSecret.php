<?php

namespace App\Console\Commands;

use App\Models\Campus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    private const ALLOWED_CAMPUS_ID = 15;

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

        // Hard scope (#1139 Founder approval): campus 15 only. Checked before any HTTP or DB side effect.
        if ((int) $campus->getKey() !== self::ALLOWED_CAMPUS_ID) {
            $this->error('Rotation is restricted to campus ' . self::ALLOWED_CAMPUS_ID . '; refusing campus ' . $campus->getKey() . '.');
            return self::FAILURE;
        }

        $botToken = trim((string) ($campus->TelegramToken ?? ''));
        if ($botToken === '') {
            $this->error("Campus {$campus->getKey()} ({$campus->name}) has no TelegramToken configured — nothing to register a webhook for.");
            return self::FAILURE;
        }

        $secret = bin2hex(random_bytes(32));
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Generated secret (not sent to Telegram, not saved):');
            $this->line($secret);
            return self::SUCCESS;
        }

        $webhookUrl = rtrim(config('app.url'), '/') . '/api/v1/telegram/webhook/' . ($campus->code ?: $campus->getKey());

        $previous = trim((string) ($campus->TelegramWebhookSecret ?? ''));

        // Outcome of a transport failure is unknown (timeout may still have applied), so it is compensated too.
        $registered = $this->setWebhook($botToken, $webhookUrl, $secret, $error);
        if (!$registered) {
            Log::error('telegram.webhook.rotate_secret.failed', ['campus_id' => $campus->getKey(), 'error' => $error]);
            $this->error('Telegram setWebhook rejected the request — DB NOT updated. ' . $error);
            return self::FAILURE;
        }

        try {
            $campus->TelegramWebhookSecret = $secret;
            $campus->save();
        } catch (Throwable $e) {
            return $this->compensate($campus, $botToken, $webhookUrl, $previous, [$botToken, $secret], $e);
        }

        $this->info("Campus {$campus->getKey()} ({$campus->name}): webhook secret rotated and registered with Telegram.");
        $this->verifyRegistration($botToken, $webhookUrl);
        return self::SUCCESS;
    }

    private function setWebhook(string $botToken, string $url, ?string $secret, ?string &$error = null): bool
    {
        $payload = ['url' => $url] + ($secret !== null && $secret !== '' ? ['secret_token' => $secret] : []);
        try {
            $response = Http::timeout(15)->asJson()->post("https://api.telegram.org/bot{$botToken}/setWebhook", $payload);
        } catch (Throwable $e) {
            $error = 'Transport error: ' . $this->mask($e->getMessage(), [$botToken, (string) $secret]);
            return false;
        }
        if (!$response->successful() || !($response->json('ok') ?? false)) {
            $error = 'HTTP ' . $response->status() . ': ' . $this->mask((string) $response->json('description'), [$botToken, (string) $secret]);
            return false;
        }
        return true;
    }

    /** DB save failed after Telegram accepted the new secret: restore the previous registration. */
    private function compensate(Campus $campus, string $botToken, string $url, string $previous, array $secrets, Throwable $e): int
    {
        $this->error('Database save failed after Telegram setWebhook: ' . $this->mask($e->getMessage(), $secrets));
        $restored = $this->setWebhook($botToken, $url, $previous, $err);
        Log::critical('telegram.webhook.rotate_secret.db_failed', ['campus_id' => $campus->getKey(), 'restored' => $restored]);
        $this->error($restored
            ? 'Previous Telegram registration RESTORED; nothing changed. Re-run the rotation when the database is healthy.'
            : 'RESTORE FAILED (' . $err . '). Telegram may hold a secret the DB does not know, so webhooks can be rejected. '
              . 'Recovery: when DB and Telegram are reachable, re-run telegram:rotate-webhook-secret 15 (it overwrites both sides with a fresh secret).');
        return self::FAILURE;
    }

    /** Non-fatal post-check: Telegram's view of the webhook (not just the DB column). Prints no secret. */
    private function verifyRegistration(string $botToken, string $url): void
    {
        try {
            $info = Http::timeout(15)->get("https://api.telegram.org/bot{$botToken}/getWebhookInfo")->json('result') ?? [];
        } catch (Throwable $e) {
            $this->warn('getWebhookInfo failed: ' . $this->mask($e->getMessage(), [$botToken]));
            return;
        }
        $this->line(json_encode([
            'telegram_url_matches' => ($info['url'] ?? null) === $url,
            'pending_update_count' => $info['pending_update_count'] ?? null,
            'last_error_present'   => !empty($info['last_error_message']),
        ]));
    }

    private function mask(string $text, array $secrets): string
    {
        foreach ($secrets as $s) {
            if ($s !== '') {
                $text = str_replace($s, '[REDACTED]', $text);
            }
        }
        return preg_replace('#bot\d+:[A-Za-z0-9_-]+#', 'bot[REDACTED]', $text);
    }
}
