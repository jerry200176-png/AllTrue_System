<?php

namespace App\Http\Controllers;

use App\Models\UserEngagement;
use App\Models\UserEngagementXpEvent;
use App\Services\EngagementXpService;
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
}
