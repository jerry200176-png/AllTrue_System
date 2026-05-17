<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserEngagement;
use App\Models\UserEngagementXpEvent;
use App\Support\EngagementRankProgression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EngagementXpService
{
    public const EVENTS = [
        // Teacher events (#389)
        'learning_record_submit' => ['xp' => 10, 'daily_max' => null, 'track' => 'teacher'],
        'learning_record_approved' => ['xp' => 10, 'daily_max' => null, 'track' => 'teacher'],
        'attendance_marked' => ['xp' => 5, 'daily_max' => null, 'track' => 'teacher'],
        'rfid_checkin_ontime' => ['xp' => 3, 'daily_max' => 1, 'track' => 'teacher'],
        'student_progress_view' => ['xp' => 2, 'daily_max' => 3, 'track' => 'teacher'],
        'weekly_schedule_confirm' => ['xp' => 15, 'daily_max' => null, 'track' => 'teacher'],
        'first_feature_use' => ['xp' => 20, 'daily_max' => null, 'track' => 'teacher'],

        // Director events (#390)
        'lr_review_action' => ['xp' => 8, 'daily_max' => null, 'track' => 'staff'],
        'payment_alert_resolved' => ['xp' => 5, 'daily_max' => null, 'track' => 'staff'],
        'substitute_processed' => ['xp' => 10, 'daily_max' => null, 'track' => 'staff'],
        'ar_dashboard_view' => ['xp' => 3, 'daily_max' => 1, 'track' => 'staff'],
        'parent_feedback_reply' => ['xp' => 8, 'daily_max' => null, 'track' => 'staff'],
        'period_close' => ['xp' => 30, 'daily_max' => null, 'track' => 'staff'],
        'student_data_update' => ['xp' => 2, 'daily_max' => 10, 'track' => 'staff'],
        'cross_campus_substitute' => ['xp' => 15, 'daily_max' => null, 'track' => 'staff'],
    ];

    private const DAILY_XP_CAP = 200;

    public function award(int $userId, string $eventType, ?string $entityId = null): ?array
    {
        if (!Schema::hasTable('user_engagement') || !Schema::hasTable('user_engagement_xp_events')) {
            return null;
        }

        $config = self::EVENTS[$eventType] ?? null;
        if (!$config) {
            return null;
        }

        $user = User::find($userId);
        if (!$user) {
            return null;
        }

        $idempotencyKey = $eventType . ':' . ($entityId ?? uniqid('', true));

        return DB::transaction(function () use ($userId, $eventType, $config, $idempotencyKey, $entityId) {
            if ($entityId !== null) {
                $already = UserEngagementXpEvent::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->exists();
                if ($already) {
                    return null;
                }
            }

            if ($config['daily_max'] !== null) {
                $todayCount = UserEngagementXpEvent::query()
                    ->where('user_id', $userId)
                    ->where('event_type', $eventType)
                    ->whereDate('created_at', now()->toDateString())
                    ->count();
                if ($todayCount >= $config['daily_max']) {
                    return null;
                }
            }

            $todayXp = (int) UserEngagementXpEvent::query()
                ->where('user_id', $userId)
                ->whereDate('created_at', now()->toDateString())
                ->sum('xp_delta');
            if ($todayXp >= self::DAILY_XP_CAP) {
                return null;
            }

            $delta = $config['xp'];

            UserEngagementXpEvent::query()->create([
                'idempotency_key' => $idempotencyKey,
                'user_id' => $userId,
                'event_type' => $eventType,
                'xp_delta' => $delta,
            ]);

            $eng = UserEngagement::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            $track = $config['track'];
            if (!$eng) {
                $eng = new UserEngagement([
                    'user_id' => $userId,
                    'role_track' => $track,
                    'rank_key' => EngagementRankProgression::DEFAULT_RANK_KEY,
                    'xp_total' => 0,
                    'rank_display_opt_out' => false,
                ]);
            }

            $oldRankKey = $eng->rank_key;
            $eng->xp_total = (int) $eng->xp_total + $delta;
            $eng->rank_key = EngagementRankProgression::rankKeyForXp((int) $eng->xp_total, $track);
            $eng->save();

            return [
                'xp_gained' => $delta,
                'total_xp' => (int) $eng->xp_total,
                'rank_key' => $eng->rank_key,
                'level_up' => $eng->rank_key !== $oldRankKey,
            ];
        });
    }
}
