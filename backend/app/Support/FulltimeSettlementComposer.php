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
     * @param array{regular?:float,tutoring_trial?:float,one_to_three?:float,payroll_total?:float} $subjectUnits
     * @return array{
     *   base_salary: float,
     *   multiplier_pct: float,
     *   weighted_bonus_amount: float,
     *   weekly_segment_bonus_amount: float,
     *   total_payout: float,
     *   review_required: bool,
     *   regular_subject_count: float|null,
     *   tutoring_trial_subject_count: float|null,
     *   payroll_subject_count: float|null,
     *   one_to_three_count: float|null,
     *   subject_count_bonus: float,
     *   one_to_three_bonus: float,
     *   multiplier_parts: list<array{key:string,label:string,pct:float}>,
     *   adjustments: list<array{label:string,amount:float}>
     * }
     */
    public static function compose(array $components, ?float $baseSalary, array $subjectUnits = []): array
    {
        $baseSalary ??= 0.0;

        $reviewRequired = collect($components)->contains(fn ($c) => ($c['status'] ?? null) === 'review');

        $subjectMetrics = $components['subject_count_bonus']['metrics'] ?? [];
        $rawSubjectBonus = (float) ($subjectMetrics['subject_count_bonus'] ?? 0);
        $rawOneToThreeBonus = (float) ($subjectMetrics['one_to_three_bonus'] ?? 0);
        $subjectCount = $subjectUnits['payroll_total'] ?? $subjectMetrics['subject_count'] ?? null;
        $subjectCountRate = ($subjectCount !== null && (float) $subjectCount >= self::SUBJECT_COUNT_THRESHOLD)
            ? self::SUBJECT_COUNT_MULTIPLIER_BONUS_PCT
            : 0.0;

        $holidayRate = (float) ($components['holiday_16_hours']['rate'] ?? 0);
        $weekdayRate = (float) ($components['weekday_afternoon']['rate'] ?? 0);
        $specialRate = (float) ($components['special_performance']['rate'] ?? 0);
        $deductionRate = (float) ($components['deductions']['rate'] ?? 0);
        $adminAllowanceRate = (float) ($components['admin_allowance']['rate'] ?? 0);

        $multiplierPct = 100.0
            + $holidayRate
            + $weekdayRate
            + $specialRate
            + $deductionRate
            + $subjectCountRate
            + $adminAllowanceRate;

        // Backer floors the multiplier-weighted bonus before adding the base
        // salary and the existing adjustment lines. Keep this rounding local
        // to the parity boundary; component rates and source amounts remain
        // unchanged and continue to use AllTrue's existing sources.
        $weightedBonus = floor(($rawSubjectBonus + $rawOneToThreeBonus) * ($multiplierPct / 100.0));
        $weeklyBonus = (float) ($components['weekly_16_segments']['amount'] ?? 0);
        $cashAmount = (float) ($components['cash_adjustments']['amount'] ?? 0);

        $adjustments = [];
        if ($weeklyBonus != 0.0) {
            $adjustments[] = ['label' => '16段課', 'amount' => $weeklyBonus];
        }
        if ($cashAmount != 0.0) {
            $adjustments[] = ['label' => '現金加扣款', 'amount' => $cashAmount];
        }

        return [
            'base_salary' => $baseSalary,
            'multiplier_pct' => round($multiplierPct, 2),
            'weighted_bonus_amount' => $weightedBonus,
            'weekly_segment_bonus_amount' => $weeklyBonus,
            'total_payout' => round($baseSalary + $weeklyBonus + $weightedBonus + $cashAmount, 2),
            'review_required' => $reviewRequired,
            'regular_subject_count' => self::nullableFloat($subjectUnits['regular'] ?? $subjectMetrics['regular_subject_count'] ?? null),
            'tutoring_trial_subject_count' => self::nullableFloat($subjectUnits['tutoring_trial'] ?? $subjectMetrics['tutoring_trial_subject_count'] ?? null),
            'payroll_subject_count' => self::nullableFloat($subjectCount),
            'one_to_three_count' => self::nullableFloat($subjectUnits['one_to_three'] ?? $subjectMetrics['one_to_three_count'] ?? null),
            'subject_count_bonus' => $rawSubjectBonus,
            'one_to_three_bonus' => $rawOneToThreeBonus,
            'multiplier_parts' => [
                ['key' => 'holiday_16_hours', 'label' => '假日16小時倍率', 'pct' => $holidayRate],
                ['key' => 'weekday_afternoon', 'label' => '平日下午課倍率', 'pct' => $weekdayRate],
                ['key' => 'special_performance', 'label' => '特殊表現倍率', 'pct' => $specialRate],
                ['key' => 'subject_count_threshold', 'label' => '科目數20科倍率', 'pct' => $subjectCountRate],
                ['key' => 'deductions', 'label' => '扣除案件', 'pct' => $deductionRate],
                ['key' => 'admin_allowance', 'label' => '行政加給', 'pct' => $adminAllowanceRate],
            ],
            'adjustments' => $adjustments,
            'payout_is_draft' => $reviewRequired,
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
