<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserEngagement;
use Illuminate\Support\Facades\Schema;

final class UserEngagementPresenter
{
    public const DEFAULT_RANK_KEY = 'private_second';

    private const SUPER_ONLY_RANK_KEY = 'general_five_star';

    /**
     * 中華民國正式軍階中文名對照
     * 士兵（3）→ 士官（6）→ 軍官（6）→ 將官（4）+ super_admin 五星上將
     *
     * @var array<string, string>
     */
    private const RANK_LABELS_ZH = [
        'private_second'         => '二兵',
        'private_first'          => '一兵',
        'private_specialist'     => '上等兵',
        'corporal'               => '下士',
        'sergeant'               => '中士',
        'staff_sergeant'         => '上士',
        'master_sergeant_third'  => '三等士官長',
        'master_sergeant_second' => '二等士官長',
        'master_sergeant_first'  => '一等士官長',
        'second_lieutenant'      => '少尉',
        'first_lieutenant'       => '中尉',
        'captain'                => '上尉',
        'major'                  => '少校',
        'lieutenant_colonel'     => '中校',
        'colonel'                => '上校',
        'major_general'          => '少將',
        'lieutenant_general'     => '中將',
        'general'                => '上將',
        'general_first_class'    => '一級上將',
        self::SUPER_ONLY_RANK_KEY => '五星上將',
    ];

    public static function rankLabel(string $rankKey): string
    {
        return self::RANK_LABELS_ZH[$rankKey] ?? self::RANK_LABELS_ZH[self::DEFAULT_RANK_KEY];
    }

    public static function isKnownRankKey(string $rankKey): bool
    {
        return isset(self::RANK_LABELS_ZH[$rankKey]);
    }

    /**
     * @return array<string, mixed>|null Omitted when role should not see engagement or table missing.
     */
    public static function forMe(User $user, string $authRole): ?array
    {
        if (!in_array($authRole, ['teacher', 'director', 'super_admin'], true)) {
            return null;
        }
        if (!Schema::hasTable('user_engagement')) {
            return null;
        }

        if ($authRole === 'super_admin') {
            return [
                'role_track' => 'staff',
                'rank_key' => self::SUPER_ONLY_RANK_KEY,
                'rank_label' => self::RANK_LABELS_ZH[self::SUPER_ONLY_RANK_KEY],
            ];
        }

        $defaultTrack = $authRole === 'teacher' ? 'teacher' : 'staff';
        $row = UserEngagement::query()->where('user_id', (int) $user->id)->first();

        $roleTrack = (string) ($row->role_track ?? $defaultTrack);
        if (!in_array($roleTrack, ['teacher', 'staff'], true)) {
            $roleTrack = $defaultTrack;
        }

        $xp = (int) ($row->xp_total ?? 0);
        $rankKey = EngagementRankProgression::rankKeyForXp($xp, $roleTrack);
        if (!self::isKnownRankKey($rankKey)) {
            $rankKey = self::DEFAULT_RANK_KEY;
        }

        return [
            'role_track' => $roleTrack,
            'rank_key' => $rankKey,
            'rank_label' => self::rankLabel($rankKey),
            'xp_total' => $xp,
        ];
    }
}
