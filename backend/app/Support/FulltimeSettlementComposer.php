<?php

namespace App\Support;

/**
 * Combines TeacherEligibilityPolicy::evaluate()'s per-component output (rates
 * and amounts) with base salary into one 總發放金額.
 *
 * Deliberately does NOT use subject_count_bonus's own `amount`/`rate` fields:
 * those are pinned by test_subject_count_uses_exact_attachment_row_and_rejects_out_of_range
 * to the announcement's illustrative example row (which bakes in an assumed
 * +10%/+15% from holiday+subject-count combined), not a given teacher's real
 * multiplier stack. The raw per-tier amounts live in
 * components.subject_count_bonus.metrics.subject_count_bonus /
 * .one_to_three_bonus and are multiplier-composed here instead.
 */
final class FulltimeSettlementComposer
{
    private const SUBJECT_COUNT_THRESHOLD = 20;
    private const SUBJECT_COUNT_MULTIPLIER_BONUS_PCT = 5.0;

    public static function compose(array $components, ?float $baseSalary, ?array $subjectUnits = null, ?float $manualMultiplierPct = null): array
    {
        $subjectUnits ??= [];
        $subjectMetrics = $components['subject_count_bonus']['metrics'] ?? [];
        $rawSubjectBonus = (float) ($subjectMetrics['subject_count_bonus'] ?? 0);
        $rawOneToThreeBonus = (float) ($subjectMetrics['one_to_three_bonus'] ?? 0);
        $subjectCount = $subjectUnits['payroll_total'] ?? $subjectMetrics['subject_count'] ?? null;
        $subjectCountRate = ($subjectCount !== null && (float) $subjectCount >= self::SUBJECT_COUNT_THRESHOLD)
            ? self::SUBJECT_COUNT_MULTIPLIER_BONUS_PCT
            : 0.0;

        $rateComponents = [
            'holiday_16_hours' => '假日16小時倍率',
            'weekday_afternoon' => '平日下午課倍率',
            'special_performance' => '特殊表現倍率',
            'deductions' => '扣除案件',
            'admin_allowance' => '行政加給',
        ];
        $multiplierPct = 100.0;
        $multiplierParts = [];
        $pendingItems = [];
        $multiplierComplete = true;
        foreach ($rateComponents as $key => $label) {
            $component = $components[$key] ?? null;
            if ($manualMultiplierPct === null && $component !== null && $component['status'] === 'review') {
                $multiplierComplete = false;
                $pendingItems[] = self::pendingItem($key, $label, $component);
                continue;
            }
            $rate = (float) ($component['rate'] ?? 0);
            $multiplierPct += $rate;
            $multiplierParts[] = ['key' => $key, 'label' => $label, 'pct' => $rate];
        }
        if ($subjectCount !== null) $multiplierPct += $subjectCountRate;
        $multiplierParts[] = ['key' => 'subject_count_threshold', 'label' => '科目數20科倍率', 'pct' => $subjectCountRate];
        if ($manualMultiplierPct !== null) {
            $multiplierPct = $manualMultiplierPct;
            $multiplierComplete = true;
            $multiplierParts = [['key' => 'manual_teacher_multiplier', 'label' => '手動教師總倍率', 'pct' => $manualMultiplierPct]];
        }

        // Backer floors the weighted bonus at the parity boundary.
        $weightedBonus = floor(round(($rawSubjectBonus + $rawOneToThreeBonus) * ($multiplierPct / 100.0), 8));
        $weeklyComponent = $components['weekly_16_segments'] ?? null;
        $weeklyKnown = $weeklyComponent === null || $weeklyComponent['status'] !== 'review';
        $weeklyBonus = $weeklyKnown ? (float) ($weeklyComponent['amount'] ?? 0) : 0.0;
        if (!$weeklyKnown) $pendingItems[] = self::pendingItem('weekly_16_segments', '每週16段課獎金', $weeklyComponent);

        $subjectComponent = $components['subject_count_bonus'] ?? null;
        $subjectKnown = $subjectComponent === null || ($subjectComponent['status'] !== 'review' && $subjectCount !== null);
        if (!$subjectKnown) $pendingItems[] = self::pendingItem('subject_count_bonus', '正課／輔導試聽／一對三科目數', $subjectComponent, true);

        $cashComponent = $components['cash_adjustments'] ?? null;
        $cashKnown = $cashComponent === null || $cashComponent['status'] !== 'review';
        $cashAmount = $cashKnown ? (float) ($cashComponent['amount'] ?? 0) : 0.0;
        if (!$cashKnown) $pendingItems[] = self::pendingItem('cash_adjustments', '現金加扣款', $cashComponent);

        if ($baseSalary === null) {
            $pendingItems[] = ['code' => 'base_salary', 'label' => '固定底薪', 'missing_fields' => ['base_salary'], 'impact' => 'unknown', 'blocking' => true];
        }

        $adjustments = [];
        if ($weeklyBonus != 0.0) {
            $adjustments[] = ['label' => '16段課', 'amount' => $weeklyBonus];
        }
        if ($cashKnown && $cashAmount != 0.0) {
            $adjustments[] = ['label' => '現金加扣款', 'amount' => $cashAmount];
        }

        $coreBlocked = $baseSalary === null || !$subjectKnown;
        $calculatedPayout = $coreBlocked
            ? null
            : round((float) $baseSalary + $weeklyBonus + $weightedBonus + $cashAmount, 2);
        $reviewRequired = $pendingItems !== [] || collect($components)->contains(fn ($c) => ($c['status'] ?? null) === 'review');

        return [
            'base_salary' => $baseSalary,
            'multiplier_pct' => $multiplierComplete ? round($multiplierPct, 2) : null,
            'known_multiplier_pct' => round($multiplierPct, 2),
            'multiplier_complete' => $multiplierComplete,
            'weighted_bonus_amount' => $weightedBonus,
            'weighted_bonus_complete' => $subjectKnown && $multiplierComplete,
            'weekly_segment_bonus_amount' => $weeklyKnown ? $weeklyBonus : null,
            'weekly_bonus_complete' => $weeklyKnown,
            'total_payout' => $calculatedPayout,
            'calculated_payout' => $calculatedPayout,
            'calculation_status' => $coreBlocked ? 'blocked' : ($reviewRequired ? 'partial' : 'calculated'),
            'review_required' => $reviewRequired,
            'pending_items' => $pendingItems,
            'payout_is_draft' => $coreBlocked || $reviewRequired,
            'regular_subject_count' => self::nullableFloat($subjectUnits['regular'] ?? $subjectMetrics['regular_subject_count'] ?? null),
            'tutoring_trial_subject_count' => self::nullableFloat($subjectUnits['tutoring_trial'] ?? $subjectMetrics['tutoring_trial_subject_count'] ?? null),
            'payroll_subject_count' => self::nullableFloat($subjectCount),
            'one_to_three_count' => self::nullableFloat($subjectUnits['one_to_three'] ?? $subjectMetrics['one_to_three_count'] ?? null),
            'subject_count_bonus' => $subjectKnown ? $rawSubjectBonus : null,
            'one_to_three_bonus' => $subjectKnown ? $rawOneToThreeBonus : null,
            'multiplier_parts' => $multiplierParts,
            'adjustments' => $adjustments,
            'total_adjustments' => ($weeklyKnown && $cashKnown) ? round($weeklyBonus + $cashAmount, 2) : null,
            'adjustments_complete' => $weeklyKnown && $cashKnown,
        ];
    }

    private static function pendingItem(string $code, string $label, ?array $component, bool $blocking = false): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'missing_fields' => array_values($component['missing_fields'] ?? [$code]),
            'reason' => $component['reason'] ?? '資料尚待確認。',
            'impact' => 'unknown',
            'blocking' => $blocking,
        ];
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 4);
    }
}
