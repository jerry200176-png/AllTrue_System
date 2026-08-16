<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;

/**
 * R55 (F1-adjacent): whether a voided LearningRecord may be silently brought
 * back to life is a decision made in two places — LearningRecordController::store()
 * (reactive: teacher hits the 409 and resubmits) and ClassSessionObserver-adjacent
 * restore-on-status-transition (proactive: leave -> attended). Before this class
 * existed, only the reactive path checked VoidReason against a whitelist; the
 * proactive path resurrected unconditionally, regardless of why the record was
 * voided. That's the same shape of gap as #556/R83/R84 (IsContractException) and
 * TD-060 (RemainingSessions): a decision duplicated instead of shared, so one
 * copy can silently be less strict than the other.
 *
 * Deliberately NOT used by CourseLeaveCascadeService's leave-revert restore —
 * that operation intentionally only touches records voided by VoidReason
 * '一般請假' specifically (the leave it's reverting), not any system-cascade
 * reason. Broadening it to this full whitelist would be a behavior change,
 * not a fix.
 */
class LearningRecordResurrectionPolicy
{
    /**
     * 系統自動作廢（非人工作廢）的 LearningRecord VoidReason 白名單。
     * 這些都是「請假 / 狀態調整」cascade 產生的副作用，且在作廢當下已對應沖回堂數
     * （或該堂從未扣堂）。當對應 ClassSession 後續回到 fillable 狀態時，應 resurrect
     * 舊列、避免被 409 永久擋住。人工作廢（其他 VoidReason）維持拒絕，不覆寫管理員決策。
     *
     *  - '一般請假'            CourseLeaveCascadeService（整門/批次請假，#125/#495）
     *  - '由已上調整狀態'      ClassSessionController attended→scheduled/cancelled（已 reverseForSession 沖回，#146）
     *  - '補請假：已上課改請假' ClassSessionController::handleRetroLeaveTransition（已沖回）
     *  - '單堂標記請假'        ClassSessionController scheduled→leave（scheduled 未扣堂）
     *
     * 新增系統作廢原因時，務必同步加入此白名單。
     */
    public const SYSTEM_RESURRECTABLE_VOID_REASONS = [
        CourseLeaveCascadeService::VOID_REASON_LEAVE,
        '由已上調整狀態',
        '補請假：已上課改請假',
        '單堂標記請假',
    ];

    public const FILLABLE_STATUSES = ['attended', 'scheduled', 'completed', 'late'];

    /**
     * @param string|null $voidReason The voided LearningRecord's VoidReason.
     * @param string|null $classSessionStatus The associated ClassSession's current Status.
     */
    public static function isEligibleForResurrect(?string $voidReason, ?string $classSessionStatus): bool
    {
        $isCascadeVoid = in_array((string) $voidReason, self::SYSTEM_RESURRECTABLE_VOID_REASONS, true);
        $isFillable = in_array(strtolower((string) $classSessionStatus), self::FILLABLE_STATUSES, true);

        return $isCascadeVoid && $isFillable;
    }

    /**
     * Proactive restore used when a ClassSession becomes fillable again
     * (leave→attended, attended→scheduled→attended, or re-mark attendance).
     * No-op when there is no voided row or VoidReason is not on the whitelist.
     */
    public static function restoreEligibleForSession(ClassSession $session): bool
    {
        $lr = LearningRecord::where('ClassSessionID', $session->id)->first();
        if (!$lr || !$lr->isVoided()) {
            return false;
        }
        if (!self::isEligibleForResurrect($lr->VoidReason, $session->Status)) {
            return false;
        }
        $lr->VoidedAt = null;
        $lr->VoidedByUserID = null;
        $lr->VoidReason = null;
        $lr->Status = 'pending';
        $lr->SessionDate = $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null;
        $lr->StartTime = $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null;
        $lr->EndTime = $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null;
        $lr->save();

        return true;
    }
}
