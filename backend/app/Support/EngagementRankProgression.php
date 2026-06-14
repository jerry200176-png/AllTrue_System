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
    /*
     * 長期養成曲線（2026-06-14 重設）：原曲線升官過快（每筆評量審核 +10 XP，
     * 士官長僅需 275 XP ≈ 數日），故拉成數月～一年以上的養成節奏，使早期階級
     * 仍有即時回饋（兵階數日可達），中段（士官長）需穩定貢獻數月，將官為長期
     * 榮譽（約 1.5～2 年）。各階間距遞增，呈平滑成長曲線。
     */
    private const TEACHER_MIN_XP = [
        'private_second'           => 0,
        'private_first'            => 50,
        'private_specialist'       => 130,
        'corporal'                 => 260,
        'sergeant'                 => 450,
        'staff_sergeant'           => 700,
        'master_sergeant_third'    => 1050,
        'master_sergeant_second'   => 1500,
        'master_sergeant_first'    => 2100,
        'second_lieutenant'        => 2850,
        'first_lieutenant'         => 3750,
        'captain'                  => 4850,
        'major'                    => 6200,
        'lieutenant_colonel'       => 7850,
        'colonel'                  => 9850,
        'major_general'            => 12300,
        'lieutenant_general'       => 15300,
        'general'                  => 19000,
        'general_first_class'      => 23500,
    ];

    private const STAFF_MIN_XP = [
        'private_second'           => 0,
        'private_first'            => 70,
        'private_specialist'       => 180,
        'corporal'                 => 360,
        'sergeant'                 => 630,
        'staff_sergeant'           => 980,
        'master_sergeant_third'    => 1470,
        'master_sergeant_second'   => 2100,
        'master_sergeant_first'    => 2940,
        'second_lieutenant'        => 3990,
        'first_lieutenant'         => 5250,
        'captain'                  => 6790,
        'major'                    => 8680,
        'lieutenant_colonel'       => 10990,
        'colonel'                  => 13790,
        'major_general'            => 17220,
        'lieutenant_general'       => 21420,
        'general'                  => 26600,
        'general_first_class'      => 32900,
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

    /** @return array{teacher: array<string,int>, staff: array<string,int>} */
    public static function allThresholds(): array
    {
        $labels = \App\Support\UserEngagementPresenter::allRankLabels();

        $format = function (array $table) use ($labels): array {
            $result = [];
            $level = 1;
            foreach ($table as $key => $minXp) {
                $result[] = [
                    'level' => $level++,
                    'key' => $key,
                    'min_xp' => $minXp,
                    'label' => $labels[$key] ?? $key,
                ];
            }
            return $result;
        };

        return [
            'teacher' => $format(self::TEACHER_MIN_XP),
            'staff' => $format(self::STAFF_MIN_XP),
        ];
    }

    /** @return array<string,int> */
    public static function thresholdsForTrack(string $roleTrack): array
    {
        return $roleTrack === 'staff' ? self::STAFF_MIN_XP : self::TEACHER_MIN_XP;
    }
}
