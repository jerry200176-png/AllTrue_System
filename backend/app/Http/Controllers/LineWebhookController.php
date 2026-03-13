<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LineWebhookController extends Controller
{
    /**
     * Handle incoming LINE webhook events.
     * POST /api/v1/line/webhook
     */
    public function handle(Request $request)
    {
        // Verify LINE signature
        $channelSecret = config('services.line.channel_secret');
        if ($channelSecret) {
            $signature = $request->header('X-Line-Signature');
            $body = $request->getContent();
            $expectedSignature = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));
            if ($signature !== $expectedSignature) {
                return response()->json(['message' => 'Invalid signature'], 400);
            }
        }

        $events = $request->input('events', []);
        foreach ($events as $event) {
            $this->processEvent($event);
        }

        return response()->json(['status' => 'ok']);
    }

    private function processEvent(array $event): void
    {
        $type = $event['type'] ?? '';
        $lineUserId = $event['source']['userId'] ?? null;

        if (!$lineUserId) {
            return;
        }

        switch ($type) {
            case 'follow':
                // User just followed the official account
                $this->handleFollow($lineUserId);
                break;

            case 'message':
                $text = $event['message']['text'] ?? '';
                $replyToken = $event['replyToken'] ?? null;
                $this->handleMessage($lineUserId, $text, $replyToken);
                break;
        }
    }

    private function handleFollow(string $lineUserId): void
    {
        // Check if already linked
        $student = Student::where('LineID', $lineUserId)->first();
        if ($student) {
            $this->sendMessage($lineUserId,
                "歡迎回來，{$student->name}！\n\n" .
                "點選以下連結查看學習狀況：\n" .
                $this->getPortalUrl()
            );
            return;
        }

        $this->sendMessage($lineUserId,
            "您好！歡迎加入補習班 LINE 官方帳號。\n\n" .
            "請輸入「綁定 學生姓名 手機號碼」來連結您孩子的帳號。\n" .
            "例如：綁定 王小明 0912345678\n\n" .
            "綁定後即可透過 LINE 查看剩餘堂數、出缺勤記錄與學習評量。"
        );
    }

    private function handleMessage(string $lineUserId, string $text, ?string $replyToken): void
    {
        $trimmed = trim($text);
        // Handle binding command: 綁定 {StudentID} {Phone}
        if (preg_match('/^綁定\s+(\d+)\s+([\d\-\+]+)$/', $trimmed, $matches)) {
            $this->handleBinding($lineUserId, (int) $matches[1], $matches[2], $replyToken);
            return;
        }
        // Handle binding by name: 綁定 {學生姓名} {Phone}
        if (preg_match('/^綁定\s+(.+?)\s+([\d\-\+]+)$/u', $trimmed, $matches)) {
            $this->handleBindingByName($lineUserId, trim($matches[1]), $matches[2], $replyToken);
            return;
        }

        // Handle portal link request
        if (str_contains($text, '查看') || str_contains($text, '堂數') || str_contains($text, '記錄')) {
            $student = Student::where('LineID', $lineUserId)->first();
            if ($student) {
                $this->replyMessage($replyToken,
                    "點選以下連結查看 {$student->name} 的學習狀況：\n" .
                    $this->getPortalUrl()
                );
            } else {
                $this->replyMessage($replyToken,
                    "請先綁定學生帳號。\n輸入：綁定 學生姓名 手機號碼"
                );
            }
            return;
        }

        // Default: show portal link
        $student = Student::where('LineID', $lineUserId)->first();
        if ($student) {
            $this->replyMessage($replyToken,
                "您好，{$student->name} 的家長！\n\n" .
                "點選以下連結查看學習狀況：\n" .
                $this->getPortalUrl()
            );
        } else {
            $this->replyMessage($replyToken,
                "請輸入「綁定 學生姓名 手機號碼」來連結帳號。\n" .
                "例如：綁定 王小明 0912345678"
            );
        }
    }

    private function handleBindingByName(string $lineUserId, string $name, string $phone, ?string $replyToken): void
    {
        $normalizedInput = preg_replace('/[^0-9]/', '', $phone);
        if ($normalizedInput === '') {
            $this->replyMessage($replyToken, "請輸入正確的手機號碼。");
            return;
        }

        $candidates = Student::where('name', $name)->get();
        $student = null;
        foreach ($candidates as $s) {
            $normalizedStored = preg_replace('/[^0-9]/', '', $s->Phone ?? '');
            if ($normalizedStored !== '' && $normalizedInput === $normalizedStored) {
                $student = $s;
                break;
            }
        }

        if (!$student) {
            $this->replyMessage($replyToken, "找不到符合「{$name}」與此手機號碼的學生，請確認姓名與手機是否正確。");
            return;
        }

        // Check if this LINE ID is already bound to another student
        $existing = Student::where('LineID', $lineUserId)->where('id', '!=', $student->id)->first();
        if ($existing) {
            Student::where('id', $existing->id)->update(['LineID' => null]);
        }

        Student::where('id', $student->id)->update(['LineID' => $lineUserId]);

        $this->replyMessage($replyToken,
            "綁定成功！{$student->name} 的帳號已連結。\n\n" .
            "點選以下連結查看學習狀況：\n" .
            $this->getPortalUrl()
        );
    }

    private function handleBinding(string $lineUserId, int $studentId, string $phone, ?string $replyToken): void
    {
        $student = Student::find($studentId);
        if (!$student) {
            $this->replyMessage($replyToken, "找不到學生代號 {$studentId}，請確認後重試。");
            return;
        }

        $normalizedInput = preg_replace('/[^0-9]/', '', $phone);
        $normalizedStored = preg_replace('/[^0-9]/', '', $student->Phone ?? '');

        if (empty($normalizedStored) || $normalizedInput !== $normalizedStored) {
            $this->replyMessage($replyToken, "手機號碼不符，請確認後重試。");
            return;
        }

        // Check if this LINE ID is already bound to another student
        $existing = Student::where('LineID', $lineUserId)->where('id', '!=', $student->id)->first();
        if ($existing) {
            Student::where('id', $existing->id)->update(['LineID' => null]);
        }

        Student::where('id', $student->id)->update(['LineID' => $lineUserId]);

        $this->replyMessage($replyToken,
            "綁定成功！{$student->name} 的帳號已連結。\n\n" .
            "點選以下連結查看學習狀況：\n" .
            $this->getPortalUrl()
        );
    }

    private function sendMessage(string $lineUserId, string $text): void
    {
        $token = config('services.line.channel_access_token');
        if (!$token) {
            Log::warning('LINE channel access token not configured');
            return;
        }

        try {
            Http::withToken($token)
                ->post('https://api.line.me/v2/bot/message/push', [
                    'to' => $lineUserId,
                    'messages' => [['type' => 'text', 'text' => $text]],
                ]);
        } catch (\Exception $e) {
            Log::error('LINE push message failed: ' . $e->getMessage());
        }
    }

    private function replyMessage(?string $replyToken, string $text): void
    {
        if (!$replyToken) {
            return;
        }

        $token = config('services.line.channel_access_token');
        if (!$token) {
            Log::warning('LINE channel access token not configured');
            return;
        }

        try {
            Http::withToken($token)
                ->post('https://api.line.me/v2/bot/message/reply', [
                    'replyToken' => $replyToken,
                    'messages' => [['type' => 'text', 'text' => $text]],
                ]);
        } catch (\Exception $e) {
            Log::error('LINE reply message failed: ' . $e->getMessage());
        }
    }

    private function getPortalUrl(): string
    {
        $liffId = config('services.line.liff_id');
        if ($liffId) {
            return "https://liff.line.me/{$liffId}";
        }
        return config('app.url') . '/#/parent';
    }
}
