<?php

namespace App\Support;

final class EngagementRankProgression
{
    public const DEFAULT_RANK_KEY = 'private_second';

    /**
     * 中華民國正式軍階（共 19 可解鎖階 + 五星上將 super_admin 專屬）
     *
     * 士兵：二等兵 一等兵 上等兵
     * 士官：下士 中士 上士 三等士官長 二等士官長 一等士官長
     * 軍官：少尉 中尉 上尉 少校 中校 上校
     * 將官：少將 中將 上將 一級上將
     * （五星上將由 super_admin 固定持有，不在此表）
     */
    private const TEACHER_MIN_XP = [
        'private_second'           => 0,
        'private_first'            => 25,
        'private_specialist'       => 55,
        'corporal'                 => 95,
        'sergeant'                 => 145,
        'staff_sergeant'           => 205,
        'master_sergeant_third'    => 275,
        'master_sergeant_second'   => 355,
        'master_sergeant_first'    => 445,
        'second_lieutenant'        => 545,
        'first_lieutenant'         => 655,
        'captain'                  => 775,
        'major'                    => 910,
        'lieutenant_colonel'       => 1060,
        'colonel'                  => 1230,
        'major_general'            => 1425,
        'lieutenant_general'       => 1650,
        'general'                  => 1910,
        'general_first_class'      => 2210,
    ];

    private const STAFF_MIN_XP = [
        'private_second'           => 0,
        'private_first'            => 40,
        'private_specialist'       => 90,
        'corporal'                 => 150,
        'sergeant'                 => 225,
        'staff_sergeant'           => 320,
        'master_sergeant_third'    => 430,
        'master_sergeant_second'   => 555,
        'master_sergeant_first'    => 700,
        'second_lieutenant'        => 860,
        'first_lieutenant'         => 1040,
        'captain'                  => 1240,
        'major'                    => 1460,
        'lieutenant_colonel'       => 1705,
        'colonel'                  => 1980,
        'major_general'            => 2290,
        'lieutenant_general'       => 2640,
        'general'                  => 3035,
        'general_first_class'      => 3480,
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
