<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * 每間分校「哪些事要用 LINE 通知」的開關。LINE 免費方案每月 200 則、一個收件人算一則，
 * 所以由分校主任自己決定。存在 SystemSetting（key = line_notify.campus.{id}，JSON），不需 migration。
 * 沒設定過 = DEFAULTS：全部維持開，既有分校（含只打 swipe-rfid 的讀卡機）通知不中斷；主任明確關掉才停。
 */
final class LineNotifySettings
{
    /** @var array<string,bool> type => 預設 */
    public const DEFAULTS = [
        'swipe_in' => true,
        'swipe_out' => true,
        'feedback_reply' => true,
        'tuition_reminder' => true,
        'staff_schedule_discrepancy' => true,
        'staff_high_alert' => true,
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
        $merged = array_merge(self::get($campusId), array_intersect_key($values, self::DEFAULTS));
        SystemSetting::set(self::key($campusId), json_encode($merged));
    }

    private static function key(int $campusId): string
    {
        return "line_notify.campus.{$campusId}";
    }
}
