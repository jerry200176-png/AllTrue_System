<?php

namespace App\Support;

final class EngagementRankProgression
{
    public const DEFAULT_RANK_KEY = 'private_second';

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
