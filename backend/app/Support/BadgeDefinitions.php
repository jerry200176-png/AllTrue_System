<?php

namespace App\Support;

final class BadgeDefinitions
{
    public const ALL = [
        'first_lesson' => [
            'title' => '初心者',
            'desc' => '完成第一堂學習評量',
            'icon' => 'badge_first',
            'category' => 'milestone',
        ],
        'week_streak_7' => [
            'title' => '七日勇者',
            'desc' => '連續登入 7 天',
            'icon' => 'badge_streak7',
            'category' => 'streak',
        ],
        'week_streak_30' => [
            'title' => '三十日鐵人',
            'desc' => '連續登入 30 天',
            'icon' => 'badge_streak30',
            'category' => 'streak',
        ],
        'all_subjects' => [
            'title' => '全科達人',
            'desc' => '教過所有科目',
            'icon' => 'badge_allsub',
            'category' => 'rare',
        ],
        'early_bird' => [
            'title' => '早鳥',
            'desc' => '連續 30 天在 8:30 前刷卡',
            'icon' => 'badge_early',
            'category' => 'streak',
        ],
        'feedback_master' => [
            'title' => '回饋達人',
            'desc' => '回覆 50 則家長回饋',
            'icon' => 'badge_feedback',
            'category' => 'milestone',
        ],
        'hundred_records' => [
            'title' => '百堂教師',
            'desc' => '完成 100 堂學習評量',
            'icon' => 'badge_100',
            'category' => 'milestone',
        ],
        'five_hundred_records' => [
            'title' => '五百堂教師',
            'desc' => '完成 500 堂學習評量',
            'icon' => 'badge_500',
            'category' => 'milestone',
        ],
        'substitute_hero' => [
            'title' => '代課英雄',
            'desc' => '接手 10 次代課',
            'icon' => 'badge_sub',
            'category' => 'milestone',
        ],
        'cross_campus' => [
            'title' => '跨校支援',
            'desc' => '在 2 個以上分校教課',
            'icon' => 'badge_cross',
            'category' => 'rare',
        ],
        'holiday_warrior' => [
            'title' => '假日戰士',
            'desc' => '週末或國定假日仍登入系統',
            'icon' => 'badge_holiday',
            'category' => 'secret',
        ],
        'night_owl' => [
            'title' => '夜貓子',
            'desc' => '晚上 10 點後仍在填寫評量',
            'icon' => 'badge_night',
            'category' => 'secret',
        ],
    ];

    public static function get(string $key): ?array
    {
        return self::ALL[$key] ?? null;
    }

    public static function allKeys(): array
    {
        return array_keys(self::ALL);
    }
}
