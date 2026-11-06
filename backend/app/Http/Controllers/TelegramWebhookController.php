<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Services\ParentBinding\TelegramBindCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class TelegramWebhookController extends Controller
{
    public const BINDING_TRUST_VERIFIED_GUARDIAN = 'verified_guardian';

    /** #1139 activation gate: the one-time code from a verified guardian link is the only bind path (pinned by TelegramWebhookSecurityTest). */
    public function bindingTrust(): string
    {
        return self::BINDING_TRUST_VERIFIED_GUARDIAN;
    }

    public function handle(Request $request, string $code)
    {
        $code = trim($code);
        if ($code === '') {
            return response('Missing campus code', 400);
        }

        $campus = Campus::where('code', $code)->first();
        if (!$campus && ctype_digit($code)) {
            $campus = Campus::find((int) $code);
        }
        if (!$campus) {
            return response('Unknown campus', 404);
        }

        $botToken = trim((string) ($campus->TelegramToken ?? ''));
        if ($botToken === '') {
            return response('Telegram bot not configured for this campus', 503);
        }

        // SEC-AUDIT (#1021): fail CLOSED — Telegram setWebhook secret_token must match
        // header X-Telegram-Bot-Api-Secret-Token (constant-time compare).
        $webhookSecret = trim((string) ($campus->TelegramWebhookSecret ?? ''));
        if ($webhookSecret === '') {
            Log::warning('telegram.webhook.no_secret', ['campus_id' => $campus->id, 'code' => $code]);
            return response('Webhook not configured', 503);
        }
        $headerSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if (!is_string($headerSecret) || !hash_equals($webhookSecret, $headerSecret)) {
            return response('Invalid secret token', 403);
        }

        $update = $request->json()->all();
        if (!isset($update['message']['chat']['id'])) {
            return response('ok', 200);
        }

        $chatId = (string) $update['message']['chat']['id'];
        $recvText = trim((string) ($update['message']['text'] ?? ''));

        $sendMessage = static function (string $chatIdTo, string $message) use ($botToken): void {
            try {
                $response = Http::timeout(15)
                    ->asJson()
                    ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                        'chat_id' => $chatIdTo,
                        'text' => $message,
                    ]);
                if (!$response->successful()) {
                    Log::warning('Telegram sendMessage returned non-2xx', [
                        'status' => $response->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Telegram sendMessage failed: ' . preg_replace('#bot\\d+:[A-Za-z0-9_-]+#', 'bot[REDACTED]', str_replace($botToken, '[REDACTED]', $e->getMessage())));
            }
        };

        // #1139: `/start <code>` is the only bind path; names are never trusted.
        if (!preg_match('/^\/start(?:@\w+)?\s+([A-Za-z0-9_-]{6,32})$/', $recvText, $m)) {
            $sendMessage($chatId, "請從家長入口取得「Telegram 綁定碼」，再傳送「/start 綁定碼」給本機器人（不接受學生姓名）。");
            return response('ok', 200);
        }

        // 10 attempts / 10 min per chat so a code cannot be guessed.
        if (!RateLimiter::attempt('tg-bind:' . $campus->id . ':' . $chatId, 10, static fn () => true, 600)) {
            $sendMessage($chatId, '嘗試次數過多，請 10 分鐘後再試。');
            return response('ok', 200);
        }

        $student = app(TelegramBindCodeService::class)->redeem($m[1], (int) $campus->id);
        if (!$student) {
            $sendMessage($chatId, '綁定碼無效或已過期，請回到家長入口重新取得。');
            return response('ok', 200);
        }
        $recvText = (string) $student->name;
        $t0 = (string) ($student->TelegramID ?? '');
        $t1s = $student->TelegramID1 === null ? '' : (string) $student->TelegramID1;
        $t2s = $student->TelegramID2 === null ? '' : (string) $student->TelegramID2;

        if ($t0 === $chatId || $t1s === $chatId || $t2s === $chatId) {
            $sendMessage($chatId, $recvText . ' 已綁定此 Telegram');
            return response('ok', 200);
        }

        if ($t0 === '') {
            $student->TelegramID = $chatId;
        } elseif ($t1s === '') {
            $student->TelegramID1 = $chatId;
        } elseif ($t2s === '') {
            $student->TelegramID2 = $chatId;
        } else {
            $sendMessage($chatId, $recvText . ' 的通知名額已滿（最多三個 Telegram），請洽櫃台。');
            return response('ok', 200);
        }

        $student->MDT = now();
        try {
            $student->save();
            $sendMessage($chatId, $recvText . ' 綁定成功');
        } catch (\Throwable $e) {
            Log::error('Telegram bind save failed: ' . $e->getMessage());
            $sendMessage($chatId, $recvText . ' 綁定失敗，請稍後再試或洽櫃台');
        }

        return response('ok', 200);
    }
}
