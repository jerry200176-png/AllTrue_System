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

    /**
     * @param array<string, array{status:string,rate:float,amount:float,metrics:array}> $components
     * @param array{regular?:float,tutoring_trial?:float,one_to_three?:float,payroll_total?:float}|null $subjectUnits
     * @return array<string, mixed>
     */
    public static function compose(array $components, ?float $baseSalary, ?array $subjectUnits = null, ?array $adjustments = null): array
    {
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
        ];
        $multiplierPct = 100.0;
        $multiplierParts = [];
        $pendingItems = [];
        foreach ($rateComponents as $key => $label) {
            $component = $components[$key] ?? null;
            if (($component['status'] ?? null) === 'review') {
                $pendingItems[] = self::pendingItem($key, $label, $component);
                continue;
            }
            $rate = (float) ($component['rate'] ?? 0);
            $multiplierPct += $rate;
            $multiplierParts[] = ['key' => $key, 'label' => $label, 'pct' => $rate];
        }
        if ($subjectCount !== null) {
            $multiplierPct += $subjectCountRate;
        }
        $multiplierParts[] = ['key' => 'subject_count_threshold', 'label' => '科目數20科倍率', 'pct' => $subjectCountRate];

        $weightedBonus = round(($rawSubjectBonus + $rawOneToThreeBonus) * ($multiplierPct / 100.0), 2);
        $weeklyComponent = $components['weekly_16_segments'] ?? null;
        $weeklyKnown = ($weeklyComponent['status'] ?? null) !== 'review';
        $weeklyBonus = $weeklyKnown ? (float) ($weeklyComponent['amount'] ?? 0) : 0.0;
        if (($weeklyComponent['status'] ?? null) === 'review') {
            $pendingItems[] = self::pendingItem('weekly_16_segments', '每週16段課獎金', $weeklyComponent);
        }

        $subjectComponent = $components['subject_count_bonus'] ?? null;
        $subjectKnown = ($subjectComponent['status'] ?? null) !== 'review'
            && $subjectComponent !== null
            && $subjectCount !== null;
        if (!$subjectKnown) {
            $pendingItems[] = self::pendingItem('subject_count_bonus', '正課／輔導試聽／一對三科目數', $subjectComponent, true);
        }
        if ($baseSalary === null) {
            $pendingItems[] = [
                'code' => 'base_salary',
                'label' => '固定底薪',
                'missing_fields' => ['base_salary'],
                'impact' => 'unknown',
                'blocking' => true,
            ];
        }
        if ($adjustments === null) {
            $pendingItems[] = [
                'code' => 'cash_adjustments_source',
                'label' => '現金加扣款資料來源',
                'missing_fields' => ['cash_adjustments_source'],
                'impact' => 'unknown',
                'blocking' => false,
            ];
        }

        $adjustmentLines = [];
        foreach ($adjustments ?? [] as $adjustment) {
            if (!array_key_exists('amount', $adjustment) || $adjustment['amount'] === null) {
                $pendingItems[] = [
                    'code' => 'cash_adjustment',
                    'label' => (string) ($adjustment['label'] ?? '加扣款'),
                    'missing_fields' => ['amount'],
                    'impact' => 'unknown',
                    'blocking' => false,
                ];
                continue;
            }
            $adjustmentLines[] = [
                'label' => (string) ($adjustment['label'] ?? '加扣款'),
                'amount' => round((float) $adjustment['amount'], 2),
            ];
        }
        $cashAdjustmentTotal = collect($adjustmentLines)->sum('amount');
        if ($weeklyKnown && $weeklyBonus != 0.0) {
            $adjustmentLines[] = ['label' => '16段課', 'amount' => $weeklyBonus];
        }

        $coreBlocked = $baseSalary === null || !$subjectKnown;
        $calculatedPayout = $coreBlocked
            ? null
            : round((float) $baseSalary + $weeklyBonus + $weightedBonus + $cashAdjustmentTotal, 2);
        $reviewRequired = $pendingItems !== [];
        $calculationStatus = $coreBlocked ? 'blocked' : ($reviewRequired ? 'partial' : 'calculated');

        return [
            'base_salary' => $baseSalary,
            'multiplier_pct' => round($multiplierPct, 2),
            'multiplier_complete' => !collect($pendingItems)->contains(fn ($item) => in_array($item['code'], array_keys($rateComponents), true)),
            'weighted_bonus_amount' => $weightedBonus,
            'weekly_segment_bonus_amount' => $weeklyBonus,
            'total_payout' => $calculatedPayout,
            'calculated_payout' => $calculatedPayout,
            'calculation_status' => $calculationStatus,
            'payout_is_draft' => $calculationStatus !== 'calculated',
            'review_required' => $reviewRequired,
            'pending_items' => array_values($pendingItems),
            'regular_subject_count' => self::nullableFloat($subjectUnits['regular'] ?? $subjectMetrics['regular_subject_count'] ?? null),
            'tutoring_trial_subject_count' => self::nullableFloat($subjectUnits['tutoring_trial'] ?? $subjectMetrics['tutoring_trial_subject_count'] ?? null),
            'payroll_subject_count' => self::nullableFloat($subjectCount),
            'one_to_three_count' => self::nullableFloat($subjectUnits['one_to_three'] ?? $subjectMetrics['one_to_three_count'] ?? null),
            'subject_count_bonus' => $rawSubjectBonus,
            'one_to_three_bonus' => $rawOneToThreeBonus,
            'multiplier_parts' => $multiplierParts,
            'adjustments' => $adjustmentLines,
            'total_adjustments' => round($cashAdjustmentTotal, 2),
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
