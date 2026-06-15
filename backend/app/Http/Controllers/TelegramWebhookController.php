<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
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

        $update = $request->json()->all();
        if (!is_array($update) || !isset($update['message']['chat']['id'])) {
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
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Telegram sendMessage failed: ' . $e->getMessage());
            }
        };

        if ($recvText === '' || strcasecmp($recvText, '/start') === 0) {
            $campusName = $campus->name ?? '本校';
            $sendMessage($chatId, "歡迎使用 {$campusName} 通知綁定。\n請輸入學生「姓名」以綁定此 Telegram（與系統學生姓名需一致）。");
            return response('ok', 200);
        }

        $matches = Student::query()
            ->where('name', $recvText)
            ->where('enable', 1)
            ->where('CampusID', $campus->id)
            ->get();

        if ($matches->isEmpty()) {
            $sendMessage($chatId, '查無此學生：' . $recvText);
            return response('ok', 200);
        }

        if ($matches->count() > 1) {
            $sendMessage($chatId, '校內有多位同名學生，請洽櫃台協助綁定。');
            return response('ok', 200);
        }

        /** @var Student $student */
        $student = $matches->first();
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
