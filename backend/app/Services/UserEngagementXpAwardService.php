<?php

namespace App\Services;

use App\Models\LearningRecord;
use App\Models\User;
use App\Models\UserEngagement;
use App\Models\UserEngagementXpEvent;
use App\Support\EngagementRankProgression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserEngagementXpAwardService
{
    public const EVENT_LR_APPROVED = 'learning_record_approved';

    public const XP_PER_APPROVED_LR = 10;

    public static function idempotencyKeyForLearningRecord(int $learningRecordId): string
    {
        return 'lr_approved:' . $learningRecordId;
    }

    public function learningRecordHasAwardableProgress(LearningRecord $lr): bool
    {
        return trim((string) ($lr->Progress ?? '')) !== '';
    }

    public function awardOnLearningRecordApprovedIfEligible(LearningRecord $lr): void
    {
        if (!Schema::hasTable('user_engagement') || !Schema::hasTable('user_engagement_xp_events')) {
            return;
        }
        if ($lr->VoidedAt !== null) {
            return;
        }
        if ($lr->Status !== 'approved') {
            return;
        }
        if (!$this->learningRecordHasAwardableProgress($lr)) {
            return;
        }
        $teacherUserId = (int) ($lr->TeacherID ?? 0);
        if ($teacherUserId <= 0) {
            return;
        }
        $teacher = User::query()->find($teacherUserId);
        if (!$teacher || (string) $teacher->type !== 'T') {
            return;
        }

        $key = self::idempotencyKeyForLearningRecord((int) $lr->id);
        $delta = self::XP_PER_APPROVED_LR;

        DB::transaction(function () use ($key, $teacherUserId, $delta, $lr) {
            $already = UserEngagementXpEvent::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->exists();
            if ($already) {
                return;
            }

            UserEngagementXpEvent::query()->create([
                'idempotency_key' => $key,
                'user_id' => $teacherUserId,
                'event_type' => self::EVENT_LR_APPROVED,
                'xp_delta' => $delta,
                'learning_record_id' => (int) $lr->id,
            ]);

            $eng = UserEngagement::query()
                ->where('user_id', $teacherUserId)
                ->lockForUpdate()
                ->first();
            if (!$eng) {
                $eng = new UserEngagement([
                    'user_id' => $teacherUserId,
                    'role_track' => 'teacher',
                    'rank_key' => EngagementRankProgression::DEFAULT_RANK_KEY,
                    'xp_total' => 0,
                    'rank_display_opt_out' => false,
                ]);
            }
            $eng->role_track = 'teacher';
            $eng->xp_total = (int) $eng->xp_total + $delta;
            $eng->rank_key = EngagementRankProgression::rankKeyForXp((int) $eng->xp_total, 'teacher');
            $eng->save();
        });
    }

    public function revokeLearningRecordApproved(int $learningRecordId): void
    {
        if (!Schema::hasTable('user_engagement') || !Schema::hasTable('user_engagement_xp_events')) {
            return;
        }
        $key = self::idempotencyKeyForLearningRecord($learningRecordId);

        DB::transaction(function () use ($key) {
            $event = UserEngagementXpEvent::query()->where('idempotency_key', $key)->first();
            if (!$event) {
                return;
            }
            $uid = (int) $event->user_id;
            $delta = (int) $event->xp_delta;
            $event->delete();

            $eng = UserEngagement::query()->where('user_id', $uid)->lockForUpdate()->first();
            if (!$eng) {
                return;
            }
            $eng->xp_total = max(0, (int) $eng->xp_total - $delta);
            $track = in_array((string) $eng->role_track, ['teacher', 'staff'], true)
                ? (string) $eng->role_track
                : 'teacher';
            $eng->rank_key = EngagementRankProgression::rankKeyForXp((int) $eng->xp_total, $track);
            $eng->save();
        });
    }
}
