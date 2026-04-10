<?php
/**
 * Telegram Webhook：依分校綁定學生 Telegram Chat ID（Student.TelegramID / TelegramID1 / TelegramID2）
 *
 * 設定方式（每個分校使用各自的 Bot，於 BotFather 取得 Token 後填入後台 Campus.TelegramToken）：
 *   向 Telegram 註冊 Webhook URL 時請帶上分校代碼，例如：
 *   https://您的網域/admin/TelegramWebHook/index.php?code=daan
 *   其中 code 須與資料表 Campus.code 一致（如 daan、muzha、xinglong、xindian 等）。
 *
 * 相容舊參數名：若未帶 code，會嘗試讀取 Query 參數 Token（與舊版日誌相同）。
 */

declare(strict_types=1);

use App\Models\Campus;
use App\Models\Student;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

header('Content-Type: text/plain; charset=utf-8');

$basePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend';
if (!is_readable($basePath . '/vendor/autoload.php')) {
    http_response_code(500);
    echo 'Laravel backend not found';
    exit;
}

require $basePath . '/vendor/autoload.php';
/** @var \Illuminate\Foundation\Application $app */
$app = require_once $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$code = '';
if (isset($_GET['code']) && is_string($_GET['code'])) {
    $code = trim($_GET['code']);
}
if ($code === '' && isset($_GET['Token']) && is_string($_GET['Token'])) {
    $code = trim($_GET['Token']);
}

if ($code === '') {
    http_response_code(400);
    echo 'Missing campus identifier: add ?code=分校代碼 to the webhook URL';
    exit;
}

$campus = Campus::where('code', $code)->first();
if (!$campus && ctype_digit($code)) {
    $campus = Campus::find((int) $code);
}

if (!$campus) {
    http_response_code(404);
    echo 'Unknown campus';
    exit;
}

$botToken = $campus->TelegramToken ?? '';
if ($botToken === '' || $botToken === null) {
    http_response_code(503);
    echo 'Telegram bot not configured for this campus';
    exit;
}

$rawInput = file_get_contents('php://input');
$update = json_decode($rawInput ?: 'null', true);

if (!is_array($update)) {
    http_response_code(200);
    echo 'ok';
    exit;
}

if (isset($update['message'])) {
    Log::debug('Telegram webhook message', [
        'campus_id' => $campus->id,
        'campus_code' => $campus->code,
        'payload' => $update['message'],
    ]);
}

if (!isset($update['message']['chat']['id'])) {
    http_response_code(200);
    echo 'ok';
    exit;
}

$chatId = (string) $update['message']['chat']['id'];
$recvText = isset($update['message']['text']) ? trim((string) $update['message']['text']) : '';

$sendMessage = static function (string $chatIdTo, string $message) use ($botToken): void {
    try {
        Http::timeout(15)
            ->asJson()
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatIdTo,
                'text' => $message,
            ]);
    } catch (\Throwable $e) {
        Log::warning('Telegram sendMessage failed: ' . $e->getMessage());
    }
};

if ($recvText === '' || strcasecmp($recvText, '/start') === 0) {
    $campusName = $campus->name ?? '本校';
    $sendMessage($chatId, "歡迎使用 {$campusName} 通知綁定。\n請輸入學生「姓名」以綁定此 Telegram（與系統學生姓名需一致）。\n若同名無法判斷，請洽櫃台。");
    http_response_code(200);
    echo 'ok';
    exit;
}

$student = Student::query()
    ->where('name', $recvText)
    ->where('enable', 1)
    ->where('CampusID', $campus->id)
    ->first();

if (!$student) {
    Log::info('Telegram bind: student not found', [
        'campus_id' => $campus->id,
        'name' => $recvText,
    ]);
    $sendMessage($chatId, '查無此學生：' . $recvText);
    http_response_code(200);
    echo 'ok';
    exit;
}

$t0 = (string) ($student->TelegramID ?? '');
$t1 = $student->TelegramID1;
$t2 = $student->TelegramID2;
$t1s = $t1 === null ? '' : (string) $t1;
$t2s = $t2 === null ? '' : (string) $t2;

if ($t0 === $chatId || $t1s === $chatId || $t2s === $chatId) {
    $sendMessage($chatId, $recvText . ' 已綁定此 Telegram');
    http_response_code(200);
    echo 'ok';
    exit;
}

if ($t0 === '') {
    $student->TelegramID = $chatId;
} elseif ($t1s === '') {
    $student->TelegramID1 = $chatId;
} elseif ($t2s === '') {
    $student->TelegramID2 = $chatId;
} else {
    $sendMessage($chatId, $recvText . ' 的通知名額已滿（最多三個 Telegram），請洽櫃台。');
    http_response_code(200);
    echo 'ok';
    exit;
}

$student->MDT = now();
try {
    $student->save();
    Log::info('Telegram bind success', ['student_id' => $student->id, 'campus_id' => $campus->id]);
    $sendMessage($chatId, $recvText . ' 綁定成功');
} catch (\Throwable $e) {
    Log::error('Telegram bind save failed: ' . $e->getMessage());
    $sendMessage($chatId, $recvText . ' 綁定失敗，請稍後再試或洽櫃台');
}

http_response_code(200);
echo 'ok';
