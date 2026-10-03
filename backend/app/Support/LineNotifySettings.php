<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * 每間分校「哪些事要用 LINE 通知」的開關，由分校主任自己決定。
 * 存在 SystemSetting（key = line_notify.campus.{id}，JSON），不需 migration。
 * 沒設定過 = DEFAULTS：刷卡通知維持開（跟上線前一樣）。
 */
final class LineNotifySettings
{
    /** @var array<string,bool> type => 預設 */
    public const DEFAULTS = [
        'swipe' => true,
    ];

    /** @return array<string,bool> */
    public static function get(int $campusId): array
    {
        $saved = json_decode((string) SystemSetting::get(self::key($campusId), '{}'), true);
        $saved = is_array($saved) ? $saved : [];

        $out = [];
        foreach (self::DEFAULTS as $type => $default) {
            $out[$type] = array_key_exists($type, $saved) ? (bool) $saved[$type] : $default;
        }

        return $out;
    }

    public static function enabled(int $campusId, string $type): bool
    {
        return self::get($campusId)[$type] ?? false;
    }

    /** @param array<string,bool> $values 只接受 DEFAULTS 裡的 key（呼叫端先驗證） */
    public static function set(int $campusId, array $values): void
    {
        // 保留 JSON 裡其他 key，未來加類型不會互相覆蓋。
        $saved = json_decode((string) SystemSetting::get(self::key($campusId), '{}'), true);
        $saved = is_array($saved) ? $saved : [];
        SystemSetting::set(self::key($campusId), json_encode(array_merge($saved, array_intersect_key($values, self::DEFAULTS))));
    }

    private static function key(int $campusId): string
    {
        return "line_notify.campus.{$campusId}";
    }
}
