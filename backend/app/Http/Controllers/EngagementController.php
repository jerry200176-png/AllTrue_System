<?php

namespace App\Http\Controllers;

use App\Models\UserBadge;
use App\Models\UserEngagement;
use App\Models\UserEngagementXpEvent;
use App\Services\EngagementXpService;
use App\Support\BadgeDefinitions;
use App\Support\EngagementRankProgression;
use App\Support\UserEngagementPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class EngagementController extends Controller
{
    public function rankThresholds(): JsonResponse
    {
        return response()->json(EngagementRankProgression::allThresholds());
    }

    public function myProgress(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        $role = $request->attributes->get('auth_role', 'teacher');

        $data = UserEngagementPresenter::forMe($user, $role);
        if (!$data) {
            return response()->json(['engagement' => null]);
        }

        $track = $data['role_track'] ?? 'teacher';
        $xp = $data['xp_total'] ?? 0;
        $thresholds = EngagementRankProgression::thresholdsForTrack($track);

        $currentIdx = 0;
        $entries = array_values($thresholds);
        $keys = array_keys($thresholds);
        foreach ($entries as $i => $minXp) {
            if ($xp >= $minXp) {
                $currentIdx = $i;
            }
        }

        $nextXp = isset($entries[$currentIdx + 1]) ? $entries[$currentIdx + 1] : null;

        return response()->json([
            'engagement' => array_merge($data, [
                'next_rank_xp' => $nextXp,
                'is_max' => $nextXp === null,
            ]),
        ]);
    }

    public function xpHistory(Request $request): JsonResponse
    {
        if (!Schema::hasTable('user_engagement_xp_events')) {
            return response()->json(['events' => []]);
        }

        $user = $request->attributes->get('auth_user');
        $events = UserEngagementXpEvent::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['event_type', 'xp_delta', 'created_at']);

        return response()->json(['events' => $events]);
    }

    public function awardXp(Request $request): JsonResponse
    {
        $request->validate([
            'event' => 'required|string',
            'entity_id' => 'nullable|string',
        ]);

        $user = $request->attributes->get('auth_user');
        $service = new EngagementXpService();
        $result = $service->award((int) $user->id, $request->input('event'), $request->input('entity_id'));

        if ($result === null) {
            return response()->json(['awarded' => false, 'reason' => 'duplicate_or_cap']);
        }

        return response()->json(array_merge(['awarded' => true], $result));
    }

    public function eventTypes(): JsonResponse
    {
        $types = [];
        foreach (EngagementXpService::EVENTS as $key => $config) {
            $types[] = [
                'event' => $key,
                'xp' => $config['xp'],
                'daily_max' => $config['daily_max'],
                'track' => $config['track'],
            ];
        }
        return response()->json(['event_types' => $types]);
    }

    public function badges(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        $userId = (int) $user->id;

        if (!Schema::hasTable('user_badges')) {
            return response()->json(['earned' => [], 'available' => []]);
        }

        $earned = UserBadge::where('user_id', $userId)->get();
        $earnedKeys = $earned->pluck('badge_key')->all();

        $earnedList = $earned->map(fn ($b) => array_merge(
            BadgeDefinitions::get($b->badge_key) ?? ['title' => $b->badge_key, 'desc' => '', 'icon' => '', 'category' => 'unknown'],
            ['key' => $b->badge_key, 'hidden' => (bool) $b->hidden, 'earned_at' => $b->created_at?->toIso8601String()]
        ))->values();

        $available = collect(BadgeDefinitions::ALL)
            ->filter(fn ($def, $key) => !in_array($key, $earnedKeys, true))
            ->map(fn ($def, $key) => array_merge($def, ['key' => $key]))
            ->values();

        return response()->json(['earned' => $earnedList, 'available' => $available]);
    }

    /**
     * 批次取得一組使用者的軍階（給老師管理列表 / 老師互看）。
     *
     * 僅回傳階級徽章與中文名，不回 XP 數字 — 刻意做成「正向徽章展示」而非數字
     * 排名榜（對齊大廠 gamification：Duolingo 個人檔徽章 / Xbox gamerscore，
     * 避免把同事從高到低排序造成壓力/監控感）。
     *
     * 可見性：管理者（director/super_admin）看全部；老師看同儕，但尊重對方的
     * rank_display_opt_out（隱退者對同儕隱藏，對自己與管理者仍顯示）。
     */
    public function ranksFor(Request $request): JsonResponse
    {
        $request->validate([
            'user_ids' => 'required|array|max:500',
            'user_ids.*' => 'integer',
        ]);

        if (!Schema::hasTable('user_engagement')) {
            return response()->json(['ranks' => []]);
        }

        $role = (string) $request->attributes->get('auth_role', 'teacher');
        $authUser = $request->attributes->get('auth_user');
        $selfId = (int) ($authUser->id ?? 0);
        $isManager = in_array($role, ['director', 'super_admin'], true);

        $ids = array_values(array_unique(array_map('intval', $request->input('user_ids', []))));
        $rows = UserEngagement::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');

        $ranks = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            $optOut = (bool) ($row->rank_display_opt_out ?? false);

            if ($optOut && !$isManager && $id !== $selfId) {
                $ranks[] = ['user_id' => $id, 'hidden' => true];
                continue;
            }

            $track = (string) ($row->role_track ?? 'teacher');
            if (!in_array($track, ['teacher', 'staff'], true)) {
                $track = 'teacher';
            }
            $xp = (int) ($row->xp_total ?? 0);
            $rankKey = EngagementRankProgression::rankKeyForXp($xp, $track);
            if (!UserEngagementPresenter::isKnownRankKey($rankKey)) {
                $rankKey = EngagementRankProgression::DEFAULT_RANK_KEY;
            }

            $ranks[] = [
                'user_id' => $id,
                'hidden' => false,
                'rank_key' => $rankKey,
                'rank_label' => UserEngagementPresenter::rankLabel($rankKey),
                'role_track' => $track,
            ];
        }

        return response()->json(['ranks' => $ranks]);
    }

    public function toggleBadgeVisibility(Request $request, string $key): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (!Schema::hasTable('user_badges')) {
            return response()->json(['message' => 'not available'], 503);
        }

        $badge = UserBadge::where('user_id', (int) $user->id)->where('badge_key', $key)->first();
        if (!$badge) {
            return response()->json(['message' => 'Badge not earned'], 404);
        }

        $badge->update(['hidden' => !$badge->hidden]);
        return response()->json(['hidden' => (bool) $badge->hidden]);
    }
}
