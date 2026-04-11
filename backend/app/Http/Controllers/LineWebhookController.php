<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LineWebhookController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Webhook: POST /api/v1/line/webhook/{campusId}
    // ─────────────────────────────────────────────────────────────────────────

    // Domain-based webhook: POST /api/v1/line/webhook (no campusId, detect from Host header)
    public function handleDomainBased(Request $request)
    {
        $host = $request->getHost(); // e.g. daan.lifenet.com.tw
        // Match against Campus.URL — strip scheme and trailing slash, compare host portion
        $campus = \Illuminate\Support\Facades\DB::table('Campus')
            ->whereNotNull('URL')
            ->where('URL', '!=', '')
            ->get()
            ->first(function ($c) use ($host) {
                $parsed = parse_url($c->URL ?? '', PHP_URL_HOST);
                // Also allow subdomain match: daan.lifenet.com.tw matches alltrue.daan.lifenet.com.tw
                if (!$parsed) return false;
                return $parsed === $host
                    || str_ends_with($parsed, '.' . $host)
                    || str_ends_with($host, '.' . ltrim($parsed, 'alltrue.'));
            });

        if (!$campus) {
            // Fallback: use current app campus (single-campus deployment)
            $appUrl = config('app.url', '');
            $appHost = parse_url($appUrl, PHP_URL_HOST);
            $campus = \Illuminate\Support\Facades\DB::table('Campus')
                ->where('URL', 'LIKE', '%' . $host . '%')
                ->orWhere('URL', 'LIKE', '%' . ($appHost ?? '') . '%')
                ->first();
        }

        if (!$campus) {
            return response()->json(['message' => 'Campus not found for host: ' . $host], 404);
        }

        return $this->handle($request, $campus->id);
    }

    public function handle(Request $request, int $campusId)
    {
        $campus = $this->getCampus($campusId);
        if (!$campus) {
            return response()->json(['message' => 'Campus not found'], 404);
        }

        // Verify LINE signature using this campus's channel secret
        $channelSecret = $campus->messaging_channel_secret ?? '';
        if ($channelSecret) {
            $signature = $request->header('X-Line-Signature');
            $body      = $request->getContent();
            $expected  = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));
            if ($signature !== $expected) {
                return response()->json(['message' => 'Invalid signature'], 400);
            }
        }

        $events = $request->input('events', []);
        foreach ($events as $event) {
            $this->processEvent($event, $campus);
        }

        return response()->json(['status' => 'ok']);
    }

    private function processEvent(array $event, object $campus): void
    {
        $type       = $event['type'] ?? '';
        $lineUserId = $event['source']['userId'] ?? null;
        if (!$lineUserId) return;

        switch ($type) {
            case 'follow':
                $this->handleFollow($lineUserId, $campus);
                break;
            case 'message':
                $text        = $event['message']['text'] ?? '';
                $replyToken  = $event['replyToken'] ?? null;
                $this->handleMessage($lineUserId, $text, $replyToken, $campus);
                break;
        }
    }

    // ── Event handlers ───────────────────────────────────────────────────────

    private function handleFollow(string $lineUserId, object $campus): void
    {
        $students = Student::where('LineID', $lineUserId)
            ->where('CampusID', $campus->id)
            ->get();

        if ($students->isNotEmpty()) {
            $names = $students->pluck('name')->implode('、');
            $this->sendFlexMessage(
                $lineUserId,
                "歡迎回來！",
                "已綁定學生：{$names}\n點選下方按鈕查看 {$campus->name} 的學習狀況 📚\n\n如需綁定其他孩子，請輸入「綁定 學生姓名」。",
                $this->getPortalUrl($campus),
                $campus
            );
            return;
        }

        $this->sendMessage(
            $lineUserId,
            "您好！歡迎加入 {$campus->name} LINE 官方帳號。\n\n" .
            "請輸入「綁定 學生姓名」來連結您孩子的帳號。\n" .
            "例如：綁定 王小明\n\n" .
            "綁定後即可透過 LINE 查看剩餘堂數、出缺勤記錄與學習評量。\n" .
            "如有多位孩子，可重複綁定。",
            $campus
        );
    }

    private function handleMessage(string $lineUserId, string $text, ?string $replyToken, object $campus): void
    {
        $trimmed = trim($text);

        // 綁定 {StudentID} {Phone}
        if (preg_match('/^綁定\s+(\d+)\s+([\d\-\+]+)$/', $trimmed, $m)) {
            $this->handleBindingById($lineUserId, (int) $m[1], $m[2], $replyToken, $campus);
            return;
        }
        // 綁定 {StudentID} only (no phone)
        if (preg_match('/^綁定\s+(\d+)$/', $trimmed, $m)) {
            $this->handleBindingByIdOnly($lineUserId, (int) $m[1], $replyToken, $campus);
            return;
        }
        // 綁定 {姓名} {Phone}
        if (preg_match('/^綁定\s+(.+?)\s+([\d\-\+]+)$/u', $trimmed, $m)) {
            $this->handleBindingByName($lineUserId, trim($m[1]), $m[2], $replyToken, $campus);
            return;
        }
        // 綁定 {姓名} only (no phone)
        if (preg_match('/^綁定\s+(.+)$/u', $trimmed, $m)) {
            $this->handleBindingByNameOnly($lineUserId, trim($m[1]), $replyToken, $campus);
            return;
        }

        // Any other message → show portal link if already bound
        $students = Student::where('LineID', $lineUserId)
            ->where('CampusID', $campus->id)
            ->get();

        if ($students->isNotEmpty()) {
            $names = $students->pluck('name')->implode('、');
            $this->replyFlexMessage(
                $replyToken,
                "查看學習狀況",
                "已綁定學生：{$names}\n點選下方按鈕立即查看 📚\n\n如需綁定其他孩子，請輸入「綁定 學生姓名」。",
                $this->getPortalUrl($campus),
                $campus
            );
        } else {
            $this->replyMessage(
                $replyToken,
                "請輸入「綁定 學生姓名」來連結帳號。\n" .
                "例如：綁定 王小明\n\n" .
                "若有同名學生，請改用「綁定 學號」方式。",
                $campus
            );
        }
    }

    private function handleBindingByNameOnly(string $lineUserId, string $name, ?string $replyToken, object $campus): void
    {
        $candidates = Student::where('name', $name)
            ->where('CampusID', $campus->id)
            ->get();

        if ($candidates->isEmpty()) {
            $this->replyMessage($replyToken, "在 {$campus->name} 找不到「{$name}」的學生，請確認姓名是否正確。", $campus);
            return;
        }

        if ($candidates->count() === 1) {
            $student = $candidates->first();
            if ($student->LineID === $lineUserId) {
                $this->replyMessage($replyToken, "「{$student->name}」已經綁定過了喔！如需綁定其他孩子，請輸入「綁定 學生姓名」。", $campus);
                return;
            }
            $this->bindStudent($student, $lineUserId);
            $boundCount = Student::where('LineID', $lineUserId)->where('CampusID', $campus->id)->count();
            $extra = $boundCount > 1 ? "\n\n目前已綁定 {$boundCount} 位學生，如需綁定更多孩子，請繼續輸入「綁定 學生姓名」。" : "\n\n如有多位孩子，可繼續輸入「綁定 學生姓名」。";
            $this->replyFlexMessage(
                $replyToken,
                "✅ 綁定成功！",
                "{$student->name} 的帳號已連結至 {$campus->name}，點選下方按鈕查看學習狀況 📚" . $extra,
                $this->getPortalUrl($campus),
                $campus
            );
            return;
        }

        // Multiple students with same name → list them with IDs
        $list = $candidates->map(fn($s) => "・學號 {$s->id}：{$s->name}")->implode("\n");
        $this->replyMessage(
            $replyToken,
            "找到多位同名學生，請用「綁定 學號」指定：\n{$list}\n\n例：綁定 {$candidates->first()->id}",
            $campus
        );
    }

    private function handleBindingByIdOnly(string $lineUserId, int $studentId, ?string $replyToken, object $campus): void
    {
        $student = Student::where('id', $studentId)
            ->where('CampusID', $campus->id)
            ->first();

        if (!$student) {
            $this->replyMessage($replyToken, "在 {$campus->name} 找不到學號 {$studentId} 的學生，請確認後重試。", $campus);
            return;
        }

        if ($student->LineID === $lineUserId) {
            $this->replyMessage($replyToken, "「{$student->name}」已經綁定過了喔！如需綁定其他孩子，請輸入「綁定 學生姓名」。", $campus);
            return;
        }

        $this->bindStudent($student, $lineUserId);
        $boundCount = Student::where('LineID', $lineUserId)->where('CampusID', $campus->id)->count();
        $extra = $boundCount > 1 ? "\n\n目前已綁定 {$boundCount} 位學生，如需綁定更多孩子，請繼續輸入「綁定 學生姓名」。" : "\n\n如有多位孩子，可繼續輸入「綁定 學生姓名」。";
        $this->replyFlexMessage(
            $replyToken,
            "✅ 綁定成功！",
            "{$student->name} 的帳號已連結至 {$campus->name}，點選下方按鈕查看學習狀況 📚" . $extra,
            $this->getPortalUrl($campus),
            $campus
        );
    }

    private function handleBindingByName(string $lineUserId, string $name, string $phone, ?string $replyToken, object $campus): void
    {
        $normalized = preg_replace('/[^0-9]/', '', $phone);
        if ($normalized === '') {
            $this->replyMessage($replyToken, "請輸入正確的手機號碼。", $campus);
            return;
        }

        // Scope to this campus
        $candidates = Student::where('name', $name)
            ->where('CampusID', $campus->id)
            ->get();

        $student = null;
        foreach ($candidates as $s) {
            if (!empty($s->Phone) && preg_replace('/[^0-9]/', '', $s->Phone) === $normalized) {
                $student = $s;
                break;
            }
        }

        if (!$student) {
            $this->replyMessage($replyToken, "在 {$campus->name} 找不到「{$name}」與此手機號碼的學生，請確認姓名與手機是否正確。", $campus);
            return;
        }

        if ($student->LineID === $lineUserId) {
            $this->replyMessage($replyToken, "「{$student->name}」已經綁定過了喔！如需綁定其他孩子，請輸入「綁定 學生姓名」。", $campus);
            return;
        }

        $this->bindStudent($student, $lineUserId);
        $boundCount = Student::where('LineID', $lineUserId)->where('CampusID', $campus->id)->count();
        $extra = $boundCount > 1 ? "\n\n目前已綁定 {$boundCount} 位學生，如需綁定更多孩子，請繼續輸入「綁定 學生姓名」。" : "\n\n如有多位孩子，可繼續輸入「綁定 學生姓名」。";
        $this->replyFlexMessage(
            $replyToken,
            "✅ 綁定成功！",
            "{$student->name} 的帳號已連結至 {$campus->name}，點選下方按鈕查看學習狀況 📚" . $extra,
            $this->getPortalUrl($campus),
            $campus
        );
    }

    private function handleBindingById(string $lineUserId, int $studentId, string $phone, ?string $replyToken, object $campus): void
    {
        $student = Student::where('id', $studentId)
            ->where('CampusID', $campus->id)
            ->first();

        if (!$student) {
            $this->replyMessage($replyToken, "在 {$campus->name} 找不到學生代號 {$studentId}，請確認後重試。", $campus);
            return;
        }

        $normalized       = preg_replace('/[^0-9]/', '', $phone);
        $normalizedStored = preg_replace('/[^0-9]/', '', $student->Phone ?? '');

        if (empty($normalizedStored) || $normalized !== $normalizedStored) {
            $this->replyMessage($replyToken, "手機號碼不符，請確認後重試。", $campus);
            return;
        }

        if ($student->LineID === $lineUserId) {
            $this->replyMessage($replyToken, "「{$student->name}」已經綁定過了喔！如需綁定其他孩子，請輸入「綁定 學生姓名」。", $campus);
            return;
        }

        $this->bindStudent($student, $lineUserId);
        $boundCount = Student::where('LineID', $lineUserId)->where('CampusID', $campus->id)->count();
        $extra = $boundCount > 1 ? "\n\n目前已綁定 {$boundCount} 位學生，如需綁定更多孩子，請繼續輸入「綁定 學生姓名」。" : "\n\n如有多位孩子，可繼續輸入「綁定 學生姓名」。";
        $this->replyFlexMessage(
            $replyToken,
            "✅ 綁定成功！",
            "{$student->name} 的帳號已連結至 {$campus->name}，點選下方按鈕查看學習狀況 📚" . $extra,
            $this->getPortalUrl($campus),
            $campus
        );
    }

    private function bindStudent(Student $student, string $lineUserId): void
    {
        $student->update(['LineID' => $lineUserId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Director API: GET /api/v1/line/status
    // Director API: POST /api/v1/line/settings
    // ─────────────────────────────────────────────────────────────────────────

    public function status(Request $request): \Illuminate\Http\JsonResponse
    {
        // Accept explicit branch_id from query string (frontend passes currentBranch)
        $campusId = $request->query('branch_id') ? (int) $request->query('branch_id') : $this->getDirectorCampusId($request);
        if (!$campusId) {
            return response()->json(['message' => 'Campus not found'], 404);
        }
        // Verify director has access to this campus
        $authCampusIds = $request->attributes->get('auth_campus_ids', []);
        $role = $request->attributes->get('auth_role');
        if ($role !== 'super_admin' && !empty($authCampusIds) && !in_array($campusId, $authCampusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $campus = $this->getCampus($campusId);
        if (!$campus) {
            return response()->json(['message' => 'Campus not found'], 404);
        }
        return response()->json($this->buildStatus($campus, $request));
    }

    public function saveSettings(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'branch_id'                => 'nullable|integer',
            'messaging_channel_token'  => 'nullable|string|max:512',
            'messaging_channel_secret' => 'nullable|string|max:64',
            'liff_id'                  => 'nullable|string|max:64',
        ]);

        $campusId = !empty($data['branch_id']) ? (int) $data['branch_id'] : $this->getDirectorCampusId($request);
        if (!$campusId) {
            return response()->json(['message' => 'Campus not found'], 404);
        }

        $authCampusIds = $request->attributes->get('auth_campus_ids', []);
        $role = $request->attributes->get('auth_role');
        if ($role !== 'super_admin' && !empty($authCampusIds) && !in_array($campusId, $authCampusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $update = [];
        if (array_key_exists('messaging_channel_token', $data)) {
            $update['messaging_channel_token'] = $data['messaging_channel_token'] ?? '';
        }
        if (array_key_exists('messaging_channel_secret', $data)) {
            $update['messaging_channel_secret'] = $data['messaging_channel_secret'] ?? '';
        }
        if (array_key_exists('liff_id', $data)) {
            $liffId = trim($data['liff_id'] ?? '');
            $update['LIFFID']   = $liffId;
            $update['LIFF_URL'] = $liffId ? "https://liff.line.me/{$liffId}" : '';
        }

        if (!empty($update)) {
            DB::table('Campus')->where('id', $campusId)->update($update);
        }

        $campus = $this->getCampus($campusId);
        return response()->json(['message' => 'LINE 設定已儲存', 'status' => $this->buildStatus($campus, $request)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function getCampus(int $campusId): ?object
    {
        return DB::table('Campus')->where('id', $campusId)->first() ?: null;
    }

    private function getDirectorCampusId(Request $request): ?int
    {
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        return !empty($campusIds) ? (int) $campusIds[0] : null;
    }

    private function buildStatus(object $campus, ?Request $request = null): array
    {
        $campusUrl = $this->resolveCampusBaseUrl($campus, $request);
        $liffId = $campus->LIFFID ?? '';

        // Prefer URL with campus id so LINE Verify works without relying on Host→Campus.URL matching
        // (Domain-only /line/webhook remains supported via handleDomainBased for existing setups.)
        $webhookUrl = "{$campusUrl}/api/v1/line/webhook/{$campus->id}";

        return [
            'campus_id'              => $campus->id,
            'campus_name'            => $campus->name,
            'channel_configured'     => !empty($campus->messaging_channel_token) && !empty($campus->messaging_channel_secret),
            'liff_configured'        => !empty($liffId),
            'webhook_url'            => $webhookUrl,
            'liff_url'               => $liffId ? "https://liff.line.me/{$liffId}" : null,
            'portal_url'             => $this->getPortalUrl($campus),
            'bound_count'            => Student::where('CampusID', $campus->id)
                                            ->whereNotNull('LineID')
                                            ->where('LineID', '!=', '')
                                            ->count(),
            'has_channel_token'      => !empty($campus->messaging_channel_token),
            'has_channel_secret'     => !empty($campus->messaging_channel_secret),
            'liff_id_value'          => $liffId,
        ];
    }

    private function getPortalUrl(object $campus): string
    {
        if (!empty($campus->LIFF_URL)) {
            return $campus->LIFF_URL;
        }
        if (!empty($campus->LIFFID)) {
            return "https://liff.line.me/{$campus->LIFFID}";
        }
        $baseUrl = $this->resolveCampusBaseUrl($campus, null);
        return $baseUrl . '/#/parent';
    }

    private function resolveCampusBaseUrl(object $campus, ?Request $request = null): string
    {
        $isLocalHost = static function (?string $url): bool {
            if (empty($url)) {
                return true;
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (empty($host)) {
                return true;
            }
            $host = strtolower($host);
            return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        };

        $campusUrl = !empty($campus->URL) ? rtrim((string) $campus->URL, '/') : '';
        if (!$isLocalHost($campusUrl)) {
            return $campusUrl;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if (!$isLocalHost($appUrl)) {
            return $appUrl;
        }

        if ($request) {
            return rtrim($request->getSchemeAndHttpHost(), '/');
        }

        // Last-resort fallback to preserve previous behavior if request context is unavailable.
        return $appUrl ?: $campusUrl ?: 'http://localhost';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LINE messaging helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function buildFlexMessage(string $title, string $body, string $url): array
    {
        return [
            'type'     => 'flex',
            'altText'  => $title,
            'contents' => [
                'type'   => 'bubble',
                'size'   => 'mega',
                'header' => [
                    'type'            => 'box',
                    'layout'          => 'vertical',
                    'backgroundColor' => '#06C755',
                    'paddingAll'      => '16px',
                    'contents'        => [[
                        'type'   => 'text',
                        'text'   => $title,
                        'weight' => 'bold',
                        'size'   => 'lg',
                        'color'  => '#ffffff',
                    ]],
                ],
                'body'   => [
                    'type'       => 'box',
                    'layout'     => 'vertical',
                    'paddingAll' => '16px',
                    'contents'   => [[
                        'type'  => 'text',
                        'text'  => $body,
                        'wrap'  => true,
                        'size'  => 'sm',
                        'color' => '#475569',
                    ]],
                ],
                'footer' => [
                    'type'       => 'box',
                    'layout'     => 'vertical',
                    'paddingAll' => '12px',
                    'contents'   => [[
                        'type'   => 'button',
                        'style'  => 'primary',
                        'color'  => '#06C755',
                        'action' => ['type' => 'uri', 'label' => '查看學習狀況', 'uri' => $url],
                    ]],
                ],
            ],
        ];
    }

    private function sendFlexMessage(string $lineUserId, string $title, string $body, string $url, object $campus): void
    {
        $token = $campus->messaging_channel_token ?? '';
        if (!$token) return;
        try {
            Http::withToken($token)->post('https://api.line.me/v2/bot/message/push', [
                'to'       => $lineUserId,
                'messages' => [$this->buildFlexMessage($title, $body, $url)],
            ]);
        } catch (\Exception $e) {
            Log::error("LINE push flex [{$campus->name}]: " . $e->getMessage());
        }
    }

    private function replyFlexMessage(?string $replyToken, string $title, string $body, string $url, object $campus): void
    {
        if (!$replyToken) return;
        $token = $campus->messaging_channel_token ?? '';
        if (!$token) return;
        try {
            Http::withToken($token)->post('https://api.line.me/v2/bot/message/reply', [
                'replyToken' => $replyToken,
                'messages'   => [$this->buildFlexMessage($title, $body, $url)],
            ]);
        } catch (\Exception $e) {
            Log::error("LINE reply flex [{$campus->name}]: " . $e->getMessage());
        }
    }

    private function sendMessage(string $lineUserId, string $text, object $campus): void
    {
        $token = $campus->messaging_channel_token ?? '';
        if (!$token) {
            Log::warning("LINE token not configured for campus {$campus->name}");
            return;
        }
        try {
            Http::withToken($token)->post('https://api.line.me/v2/bot/message/push', [
                'to'       => $lineUserId,
                'messages' => [['type' => 'text', 'text' => $text]],
            ]);
        } catch (\Exception $e) {
            Log::error("LINE push [{$campus->name}]: " . $e->getMessage());
        }
    }

    private function replyMessage(?string $replyToken, string $text, object $campus): void
    {
        if (!$replyToken) return;
        $token = $campus->messaging_channel_token ?? '';
        if (!$token) return;
        try {
            Http::withToken($token)->post('https://api.line.me/v2/bot/message/reply', [
                'replyToken' => $replyToken,
                'messages'   => [['type' => 'text', 'text' => $text]],
            ]);
        } catch (\Exception $e) {
            Log::error("LINE reply [{$campus->name}]: " . $e->getMessage());
        }
    }
}
