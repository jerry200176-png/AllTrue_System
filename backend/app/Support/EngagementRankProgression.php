<?php

namespace App\Support;

/**
 * Threshold-driven rank from XP (#325). Teacher vs staff (主任／行政軌) 分 curve。
 * Keys 順序須由低階到高階；取「最後一個 min_xp <= xp」為目前軍階。
 */
final class EngagementRankProgression
{
    public const DEFAULT_RANK_KEY = 'private_second';

    /** @var array<string, int> rank_key => min total XP（老師軌） */
    private const TEACHER_MIN_XP = [
        'private_second' => 0,
        'private_first' => 25,
        'private_specialist' => 55,
        'corporal' => 95,
        'sergeant' => 145,
        'staff_sergeant' => 205,
        'second_lieutenant' => 280,
        'first_lieutenant' => 360,
        'captain' => 450,
        'major' => 550,
        'lieutenant_colonel' => 665,
        'colonel' => 800,
        'major_general' => 960,
        'lieutenant_general' => 1150,
        'general' => 1370,
        'general_first_class' => 1620,
    ];

    /** @var array<string, int> 主任／staff 軌（較慢晉升） */
    private const STAFF_MIN_XP = [
        'private_second' => 0,
        'private_first' => 40,
        'private_specialist' => 90,
        'corporal' => 150,
        'sergeant' => 225,
        'staff_sergeant' => 320,
        'second_lieutenant' => 430,
        'first_lieutenant' => 555,
        'captain' => 695,
        'major' => 855,
        'lieutenant_colonel' => 1040,
        'colonel' => 1250,
        'major_general' => 1490,
        'lieutenant_general' => 1765,
        'general' => 2080,
        'general_first_class' => 2430,
    ];

    public static function rankKeyForXp(int $xp, string $roleTrack): string
    {
        $table = $roleTrack === 'staff' ? self::STAFF_MIN_XP : self::TEACHER_MIN_XP;
        $selected = self::DEFAULT_RANK_KEY;
        foreach ($table as $key => $min) {
            if ($xp >= $min) {
                $selected = $key;
            }
        }

        return $selected;
    }
}
