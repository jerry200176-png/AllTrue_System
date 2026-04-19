<?php
/**
 * Telegram Webhook：依分校綁定學生 Telegram Chat ID（Student.TelegramID / TelegramID1 / TelegramID2）
 * 同名多位時：改以 Student.Phone 或 parent_phone 二次驗證（快取狀態約 10 分鐘）。
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** 待二次驗證（同名）狀態快取鍵 */
function telegram_bind_cache_key(int $campusId, string $chatId): string
{
    return 'telegram_student_bind_verify:' . $campusId . ':' . $chatId;
}

/** 正規化手機／市話數字後比對用（支援 +886、09 開頭） */
function telegram_bind_canonical_phone(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $t = trim((string) $raw);
    if ($t === '') {
        return null;
    }
    $d = preg_replace('/\D+/', '', $t);
    if ($d === '') {
        return null;
    }
    if (str_starts_with($d, '886')) {
        $d = '0' . substr($d, 3);
    }
    if (strlen($d) === 9 && str_starts_with($d, '9')) {
        $d = '0' . $d;
    }

    return $d;
}

function telegram_bind_phone_matches_field(string $userCanon, ?string $stored): bool
{
    $c = telegram_bind_canonical_phone($stored);
    if ($c === null || $userCanon === '') {
        return false;
    }
    if ($userCanon === $c) {
        return true;
    }
    $min = 8;
    if (strlen($userCanon) >= $min && strlen($c) >= $min && (str_ends_with($c, $userCanon) || str_ends_with($userCanon, $c))) {
        return true;
    }

    return false;
}

function telegram_bind_student_matches_phone(Student $student, string $userCanon): bool
{
    return telegram_bind_phone_matches_field($userCanon, $student->Phone)
        || telegram_bind_phone_matches_field($userCanon, $student->parent_phone);
}

/** 寫入 Telegram 欄位並回覆使用者 */
function telegram_bind_assign_chat_and_save(Student $student, string $chatId, callable $sendMessage): void
{
    $label = (string) $student->name;
    $t0 = (string) ($student->TelegramID ?? '');
    $t1 = $student->TelegramID1;
    $t2 = $student->TelegramID2;
    $t1s = $t1 === null ? '' : (string) $t1;
    $t2s = $t2 === null ? '' : (string) $t2;

    if ($t0 === $chatId || $t1s === $chatId || $t2s === $chatId) {
        $sendMessage($chatId, $label . ' 已綁定此 Telegram');

        return;
    }

    if ($t0 === '') {
        $student->TelegramID = $chatId;
    } elseif ($t1s === '') {
        $student->TelegramID1 = $chatId;
    } elseif ($t2s === '') {
        $student->TelegramID2 = $chatId;
    } else {
        $sendMessage($chatId, $label . ' 的通知名額已滿（最多三個 Telegram），請洽櫃台。');

        return;
    }

    $student->MDT = now();
    try {
        $student->save();
        Log::info('Telegram bind success', ['student_id' => $student->id, 'campus_id' => $student->CampusID]);
        $sendMessage($chatId, $label . ' 綁定成功');
    } catch (\Throwable $e) {
        Log::error('Telegram bind save failed: ' . $e->getMessage());
        $sendMessage($chatId, $label . ' 綁定失敗，請稍後再試或洽櫃台');
    }
}

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

$bindCacheKey = telegram_bind_cache_key((int) $campus->id, $chatId);

if ($recvText === '' || strcasecmp($recvText, '/start') === 0) {
    Cache::forget($bindCacheKey);
    $campusName = $campus->name ?? '本校';
    $sendMessage($chatId, "歡迎使用 {$campusName} 通知綁定。\n請輸入學生「姓名」以綁定此 Telegram（與系統學生姓名需一致）。\n若校內有同名，將請您再輸入學生本人或家長手機號碼（須與櫃台建檔一致）。");
    http_response_code(200);
    echo 'ok';
    exit;
}

$pending = Cache::get($bindCacheKey);
if (is_array($pending) && isset($pending['student_ids']) && is_array($pending['student_ids']) && $pending['student_ids'] !== []) {
    $userCanon = telegram_bind_canonical_phone($recvText);
    if ($userCanon === null) {
        $sendMessage($chatId, "請輸入學生本人或家長「手機號碼」（僅數字即可，須與櫃台建檔一致）。\n若要改輸入姓名，請先傳送 /start 重新開始。");
        http_response_code(200);
        echo 'ok';
        exit;
    }

    $candidates = Student::query()
        ->whereIn('id', $pending['student_ids'])
        ->where('enable', 1)
        ->where('CampusID', $campus->id)
        ->get();

    $phoneHits = $candidates->filter(static function (Student $s) use ($userCanon): bool {
        return telegram_bind_student_matches_phone($s, $userCanon);
    })->values();

    if ($phoneHits->isEmpty()) {
        Log::info('Telegram bind: phone verify no match', [
            'campus_id' => $campus->id,
            'chat_id' => $chatId,
        ]);
        $sendMessage($chatId, '電話與建檔資料不符，請檢查後再試，或洽櫃台協助。');
        http_response_code(200);
        echo 'ok';
        exit;
    }

    if ($phoneHits->count() > 1) {
        Cache::forget($bindCacheKey);
        Log::warning('Telegram bind: phone verify still ambiguous', [
            'campus_id' => $campus->id,
            'student_ids' => $phoneHits->pluck('id')->all(),
        ]);
        $sendMessage($chatId, '電話仍對應多位同名資料，請洽櫃台協助綁定。');
        http_response_code(200);
        echo 'ok';
        exit;
    }

    Cache::forget($bindCacheKey);
    /** @var Student $student */
    $student = $phoneHits->first();
    telegram_bind_assign_chat_and_save($student, $chatId, $sendMessage);
    http_response_code(200);
    echo 'ok';
    exit;
}

$matches = Student::query()
    ->where('name', $recvText)
    ->where('enable', 1)
    ->where('CampusID', $campus->id)
    ->get();

if ($matches->isEmpty()) {
    Log::info('Telegram bind: student not found', [
        'campus_id' => $campus->id,
        'name' => $recvText,
    ]);
    $sendMessage($chatId, '查無此學生：' . $recvText);
    http_response_code(200);
    echo 'ok';
    exit;
}

if ($matches->count() > 1) {
    $hasPhoneData = $matches->contains(static function (Student $s): bool {
        $p = trim((string) ($s->Phone ?? ''));
        $pp = trim((string) ($s->parent_phone ?? ''));

        return $p !== '' || $pp !== '';
    });

    if (!$hasPhoneData) {
        Log::warning('Telegram bind: duplicate name, no phone on file', [
            'campus_id' => $campus->id,
            'name' => $recvText,
            'count' => $matches->count(),
        ]);
        $sendMessage($chatId, '校內有多位同名學生，且建檔無電話資料，無法自動驗證，請洽櫃台協助。');
        http_response_code(200);
        echo 'ok';
        exit;
    }

    Cache::put(
        $bindCacheKey,
        [
            'student_ids' => $matches->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
        ],
        now()->addMinutes(10)
    );
    Log::info('Telegram bind: duplicate name, awaiting phone', [
        'campus_id' => $campus->id,
        'name' => $recvText,
        'count' => $matches->count(),
    ]);
    $sendMessage($chatId, '校內有多位「' . $recvText . '」，請再輸入「學生本人或家長手機號碼」（須與櫃台建檔一致，可含國碼 +886）。');
    http_response_code(200);
    echo 'ok';
    exit;
}

Cache::forget($bindCacheKey);
/** @var Student $student */
$student = $matches->first();
telegram_bind_assign_chat_and_save($student, $chatId, $sendMessage);

http_response_code(200);
echo 'ok';
