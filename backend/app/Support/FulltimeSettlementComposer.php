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
     * @return array{base_salary: float, multiplier_pct: float, weighted_bonus_amount: float,
     *               weekly_segment_bonus_amount: float, total_payout: float, review_required: bool}
     */
    public static function compose(array $components, ?float $baseSalary): array
    {
        $baseSalary ??= 0.0;

        $reviewRequired = collect($components)->contains(fn ($c) => ($c['status'] ?? null) === 'review');

        $subjectMetrics = $components['subject_count_bonus']['metrics'] ?? [];
        $rawSubjectBonus = (float) ($subjectMetrics['subject_count_bonus'] ?? 0);
        $rawOneToThreeBonus = (float) ($subjectMetrics['one_to_three_bonus'] ?? 0);
        $subjectCount = $subjectMetrics['subject_count'] ?? null;
        $subjectCountRate = ($subjectCount !== null && (float) $subjectCount >= self::SUBJECT_COUNT_THRESHOLD)
            ? self::SUBJECT_COUNT_MULTIPLIER_BONUS_PCT
            : 0.0;

        $multiplierPct = 100.0
            + (float) ($components['holiday_16_hours']['rate'] ?? 0)
            + (float) ($components['weekday_afternoon']['rate'] ?? 0)
            + (float) ($components['special_performance']['rate'] ?? 0)
            + (float) ($components['deductions']['rate'] ?? 0)
            + $subjectCountRate;

        $weightedBonus = round(($rawSubjectBonus + $rawOneToThreeBonus) * ($multiplierPct / 100.0), 2);
        $weeklyBonus = (float) ($components['weekly_16_segments']['amount'] ?? 0);

        return [
            'base_salary' => $baseSalary,
            'multiplier_pct' => round($multiplierPct, 2),
            'weighted_bonus_amount' => $weightedBonus,
            'weekly_segment_bonus_amount' => $weeklyBonus,
            'total_payout' => round($baseSalary + $weeklyBonus + $weightedBonus, 2),
            'review_required' => $reviewRequired,
        ];
    }
}
