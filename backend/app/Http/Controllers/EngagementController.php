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
