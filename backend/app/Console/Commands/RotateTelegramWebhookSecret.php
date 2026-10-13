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
        {--rollback : Reverse the external registration: deleteWebhook on Telegram, then clear Campus.TelegramWebhookSecret (fail-closed state)}
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

        if ($this->option('rollback') && $this->option('dry-run')) {
            $this->error('--rollback cannot be combined with --dry-run; nothing was done.');
            return self::FAILURE;
        }

        if ($this->option('rollback')) {
            return $this->rollback($campus, $botToken);
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

        $startedAt = time();
        $result = $this->setWebhook($botToken, $webhookUrl, $secret, $error);
        if ($result === 'rejected') {
            Log::error('telegram.webhook.rotate_secret.failed', ['campus_id' => $campus->getKey(), 'error' => $error]);
            $this->error('Telegram setWebhook rejected the request — DB NOT updated. ' . $error);
            return self::FAILURE;
        }
        if ($result === 'unknown') {
            // Transport failure: Telegram may have applied the new secret. Restore before exiting.
            return $this->compensate($campus, $botToken, $webhookUrl, $previous, 'setWebhook outcome unknown: ' . $error);
        }

        try {
            $campus->TelegramWebhookSecret = $secret;
            $campus->save();
        } catch (Throwable $e) {
            return $this->compensate($campus, $botToken, $webhookUrl, $previous, 'Database save failed after setWebhook: ' . $this->mask($e->getMessage(), [$botToken, $secret, $previous]));
        }

        $this->info("Campus {$campus->getKey()} ({$campus->name}): webhook secret rotated and registered with Telegram.");
        if ($this->verifyRegistration($botToken, $webhookUrl, $startedAt)) {
            return self::SUCCESS;
        }
        // Verification failed after persisting: reconcile both sides back to the previous state.
        try {
            $campus->TelegramWebhookSecret = $previous === '' ? null : $previous;
            $campus->save();
        } catch (Throwable $e) {
            // DB still holds the new secret, which Telegram also has: leave Telegram alone so both sides stay in sync.
            Log::critical('telegram.webhook.rotate_secret.verify_restore_failed', ['campus_id' => $campus->getKey()]);
            $this->error('Could not restore the database secret: ' . $this->mask($e->getMessage(), [$botToken, $secret, $previous])
                . ' Telegram was NOT reverted, so Telegram and the DB both keep the new secret. '
                . 'Recovery: re-run telegram:rotate-webhook-secret 15 when healthy, or run it with --rollback.');
            return self::FAILURE;
        }
        return $this->compensate($campus, $botToken, $webhookUrl, $previous, 'Registration verification failed; reverting to the previous state.');
    }

    /** Reverse the external registration (the only real "undo" of an executed rotation). */
    private function rollback(Campus $campus, string $botToken): int
    {
        try {
            $response = Http::timeout(15)->asJson()->post("https://api.telegram.org/bot{$botToken}/deleteWebhook");
            $ok = $response->successful() && ($response->json('ok') ?? false);
            $err = 'HTTP ' . $response->status();
        } catch (Throwable $e) {
            $ok = false;
            $err = 'Transport error: ' . $this->mask($e->getMessage(), [$botToken]);
        }
        if (!$ok) {
            $this->error("Rollback aborted, DB NOT changed ({$err}). Re-run when Telegram is reachable.");
            return self::FAILURE;
        }
        $previous = trim((string) ($campus->TelegramWebhookSecret ?? ''));
        try {
            $campus->setAttribute('TelegramWebhookSecret', null);
            $campus->save();
        } catch (Throwable $e) {
            // Telegram already dropped the webhook but the DB still holds the secret: re-register it so both sides match.
            $url = rtrim(config('app.url'), '/') . '/api/v1/telegram/webhook/' . ($campus->code ?: $campus->getKey());
            return $this->compensate($campus, $botToken, $url, $previous, 'Database update failed after deleteWebhook: ' . $this->mask($e->getMessage(), [$botToken, $previous]));
        }
        $this->info("Campus {$campus->getKey()}: Telegram webhook deleted and secret cleared (webhook fails closed).");
        return self::SUCCESS;
    }

    /** @return 'ok'|'rejected'|'unknown' (unknown = transport failure, effect on Telegram side not known) */
    private function setWebhook(string $botToken, string $url, ?string $secret, ?string &$error = null): string
    {
        $payload = ['url' => $url] + ($secret !== null && $secret !== '' ? ['secret_token' => $secret] : []);
        try {
            $response = Http::timeout(15)->asJson()->post("https://api.telegram.org/bot{$botToken}/setWebhook", $payload);
        } catch (Throwable $e) {
            $error = 'Transport error: ' . $this->mask($e->getMessage(), [$botToken, (string) $secret]);
            return 'unknown';
        }
        if ($response->successful() && $response->json('ok') === true) {
            return 'ok';
        }
        $error = 'HTTP ' . $response->status() . ': ' . $this->mask((string) $response->json('description'), [$botToken, (string) $secret]);
        // Only an explicit Bot API {"ok": false} proves nothing was applied; 5xx / malformed bodies are indeterminate.
        return $response->json('ok') === false && $response->status() < 500 ? 'rejected' : 'unknown';
    }

    /** Setup failed after Telegram may have accepted the new secret: restore the previous registration. */
    private function compensate(Campus $campus, string $botToken, string $url, string $previous, string $reason): int
    {
        $this->error($reason);
        $restored = $this->setWebhook($botToken, $url, $previous, $err) === 'ok';
        Log::critical('telegram.webhook.rotate_secret.compensated', ['campus_id' => $campus->getKey(), 'restored' => $restored, 'reason' => $reason]);
        $this->error($restored
            ? 'Previous Telegram registration RESTORED; nothing changed. Re-run the rotation when healthy.'
            : 'RESTORE FAILED (' . $err . '). Telegram may hold a secret the DB does not know, so webhooks can be rejected. '
              . 'Recovery: when DB and Telegram are reachable, re-run telegram:rotate-webhook-secret 15 (overwrites both sides with a fresh secret), or run it with --rollback.');
        return self::FAILURE;
    }

    /** Post-check of Telegram's own view of the webhook. Prints no secret. false = verification failed. */
    private function verifyRegistration(string $botToken, string $url, int $startedAt): bool
    {
        try {
            $r = Http::timeout(15)->get("https://api.telegram.org/bot{$botToken}/getWebhookInfo");
            $info = $r->successful() && ($r->json('ok') ?? false) ? ($r->json('result') ?? []) : null;
        } catch (Throwable $e) {
            $this->error('getWebhookInfo failed: ' . $this->mask($e->getMessage(), [$botToken]));
            return false;
        }
        if ($info === null) {
            $this->error('getWebhookInfo returned an error; registration NOT verified.');
            return false;
        }
        $report = [
            'telegram_url_matches' => ($info['url'] ?? null) === $url,
            'pending_update_count' => $info['pending_update_count'] ?? null,
            // Only an error dated at/after this rotation counts; older or undated errors are historical diagnostics.
            'last_error_present'   => !empty($info['last_error_message']) && (int) ($info['last_error_date'] ?? 0) >= $startedAt,
        ];
        $this->line(json_encode($report));
        if (!$report['telegram_url_matches'] || $report['last_error_present']) {
            $this->error('Telegram registration verification FAILED (see flags above).');
            return false;
        }
        return true;
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
