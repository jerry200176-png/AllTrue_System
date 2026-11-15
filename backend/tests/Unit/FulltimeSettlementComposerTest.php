<?php

namespace Tests\Unit;

use App\Support\FulltimeSettlementComposer;
use PHPUnit\Framework\TestCase;

class FulltimeSettlementComposerTest extends TestCase
{
    /**
     * The composer applies the multiplier itself: the subject component's own
     * amount/rate must not be added on top of the tier amounts in its metrics.
     */
    public function test_does_not_double_count_subject_count_bonus_component_amount(): void
    {
        $components = [
            'weekly_16_segments' => ['status' => 'qualifies', 'amount' => 4000, 'rate' => 0],
            'holiday_16_hours' => ['status' => 'not_qualifies', 'amount' => 0, 'rate' => 0], // teacher did NOT hit holiday 16hr
            'weekday_afternoon' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'special_performance' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'deductions' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'subject_count_bonus' => [
                'status' => 'qualifies', 'amount' => 14600, 'rate' => 15,
                'metrics' => ['subject_count' => 20, 'subject_count_bonus' => 10000, 'one_to_three_bonus' => 4600, 'subject_count_rate' => 5],
            ],
        ];

        $result = FulltimeSettlementComposer::compose($components);

        // multiplier = 100 + 0 (holiday, actually not_qualifies) + 0 + 0 + 0 + 5 (正課 >= 20) = 105
        $this->assertSame(105.0, $result['multiplier_pct']);
        // weighted = (10000 + 4600) * 1.05 = 15330
        $this->assertSame(15330.0, $result['weighted_bonus_amount']);
        $this->assertSame(4000.0, $result['weekly_segment_bonus_amount']);
        $this->assertSame(4000.0 + 15330, $result['total_payout']);
    }

    public function test_sums_all_five_rate_components_plus_subject_count_threshold(): void
    {
        $components = [
            'weekly_16_segments' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'holiday_16_hours' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 10],
            'weekday_afternoon' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 3],
            'special_performance' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 5],
            'deductions' => ['status' => 'qualifies', 'amount' => 0, 'rate' => -10],
            'subject_count_bonus' => [
                'status' => 'qualifies', 'amount' => 0, 'rate' => 0,
                'metrics' => ['subject_count' => 25, 'subject_count_bonus' => 17500, 'one_to_three_bonus' => 6600, 'subject_count_rate' => 5],
            ],
        ];

        $result = FulltimeSettlementComposer::compose($components);

        // 100 + 10 + 3 + 5 - 10 + 5 (>=20) = 113
        $this->assertSame(113.0, $result['multiplier_pct']);
        $this->assertSame(round((17500 + 6600) * 1.13, 2), $result['weighted_bonus_amount']);
    }

    /** 核薪總科目數 alone never adds the +5%: only the policy's 正課 rate does. */
    public function test_payroll_total_over_twenty_without_the_regular_rate_gets_no_extra_multiplier(): void
    {
        $components = [
            'subject_count_bonus' => [
                'status' => 'qualifies', 'amount' => 0, 'rate' => 0,
                'metrics' => ['subject_count' => 20.2813, 'subject_count_bonus' => 10000, 'one_to_three_bonus' => 3050, 'subject_count_rate' => 0],
            ],
        ];

        $result = FulltimeSettlementComposer::compose($components, ['payroll_total' => 20.2813]);

        $this->assertSame(100.0, $result['multiplier_pct']);
    }

    /** HQ settlement sheet rows: floor((科目數獎金 + 一對三獎金) x 倍率). */
    public function test_hq_sheet_rows_weighted_bonus(): void
    {
        $compose = fn (int $subject, int $oneToThree, int $twentyRate, float $holiday, float $weekday, float $admin, float $special) => FulltimeSettlementComposer::compose([
            'holiday_16_hours' => ['status' => 'qualifies', 'amount' => 0, 'rate' => $holiday],
            'weekday_afternoon' => ['status' => 'qualifies', 'amount' => 0, 'rate' => $weekday],
            'special_performance' => ['status' => 'qualifies', 'amount' => 0, 'rate' => $special],
            'admin_allowance' => ['status' => 'qualifies', 'amount' => 0, 'rate' => $admin],
            'subject_count_bonus' => [
                'status' => 'qualifies', 'amount' => $subject + $oneToThree, 'rate' => 0,
                'metrics' => ['subject_count' => 1, 'subject_count_bonus' => $subject, 'one_to_three_bonus' => $oneToThree, 'subject_count_rate' => $twentyRate],
            ],
        ]);

        $first = $compose(6000, 2450, 0, 10, 1.5, 0, 0);
        $this->assertSame(111.5, $first['multiplier_pct']);
        $this->assertSame(9421.0, $first['weighted_bonus_amount']);

        $second = $compose(16000, 4250, 5, 10, 2.25, 8, 5);
        $this->assertSame(130.25, $second['multiplier_pct']);
        $this->assertSame(26375.0, $second['weighted_bonus_amount']);
    }

    public function test_screenshot_row_formula_without_unspecified_cash_adjustments(): void
    {
        $components = [
            'weekly_16_segments' => ['status' => 'qualifies', 'amount' => 4000, 'rate' => 0],
            'holiday_16_hours' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 10],
            'weekday_afternoon' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 4.5],
            'special_performance' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'deductions' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'subject_count_bonus' => [
                'status' => 'qualifies', 'amount' => 0, 'rate' => 0,
                'metrics' => [
                    'subject_count' => 5.625,
                    'subject_count_bonus' => 0,
                    'one_to_three_bonus' => 450,
                    'regular_subject_count' => 5.25,
                    'tutoring_trial_subject_count' => 0.375,
                    'one_to_three_count' => 4.5,
                ],
            ],
        ];

        $result = FulltimeSettlementComposer::compose($components, [
            'regular' => 5.25,
            'tutoring_trial' => 0.375,
            'one_to_three' => 4.5,
            'payroll_total' => 5.625,
        ]);

        $this->assertSame(114.5, $result['multiplier_pct']);
        $this->assertSame(515.0, $result['weighted_bonus_amount']);
        $this->assertSame(4515.0, $result['total_payout']);
        $this->assertSame(0.0, $result['subject_count_bonus']);
        $this->assertSame(450.0, $result['one_to_three_bonus']);
        $this->assertSame([['label' => '16段課', 'amount' => 4000.0]], $result['adjustments']);
    }

    /** The page settles bonuses only: no base salary in the total, and none needed to calculate. */
    public function test_total_has_no_base_salary_and_does_not_wait_for_one(): void
    {
        $result = FulltimeSettlementComposer::compose([]);

        $this->assertArrayNotHasKey('base_salary', $result);
        $this->assertSame(0.0, $result['total_payout']);
        $this->assertSame('calculated', $result['calculation_status']);
    }

    public function test_review_required_when_any_component_is_review(): void
    {
        $components = [
            'holiday_16_hours' => ['status' => 'review', 'amount' => 0, 'rate' => 0],
        ];

        $result = FulltimeSettlementComposer::compose($components);

        $this->assertTrue($result['review_required']);
        $this->assertTrue($result['payout_is_draft']);
    }

    public function test_admin_allowance_stacks_into_multiplier(): void
    {
        $result = FulltimeSettlementComposer::compose([
            'admin_allowance' => ['status' => 'qualifies', 'rate' => 8],
            'subject_count_bonus' => [
                'status' => 'qualifies', 'amount' => 0, 'rate' => 0,
                'metrics' => ['subject_count' => 10, 'subject_count_bonus' => 0, 'one_to_three_bonus' => 0],
            ],
        ]);

        $this->assertSame(108.0, $result['multiplier_pct']);
        $part = null;
        foreach ($result['multiplier_parts'] as $row) {
            if (($row['key'] ?? '') === 'admin_allowance') {
                $part = $row;
                break;
            }
        }
        $this->assertNotNull($part);
        $this->assertSame(8.0, $part['pct']);
    }

    /** 新進保底: the weighted bonus is at least the guarantee; a higher bonus is left alone; a pending rate holds it back. */
    public function test_new_hire_guarantee_is_a_floor_for_the_weighted_bonus(): void
    {
        $compose = fn (int $subjectBonus, array $extra = []) => FulltimeSettlementComposer::compose($extra + [
            'subject_count_bonus' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0, 'metrics' => ['subject_count' => 10, 'subject_count_bonus' => $subjectBonus, 'one_to_three_bonus' => 200]],
            'weekly_16_segments' => ['status' => 'qualifies', 'amount' => 4000, 'rate' => 0],
            'new_hire_guarantee' => ['status' => 'qualifies', 'amount' => 2000.0, 'rate' => 0],
        ], ['payroll_total' => 10]);

        $low = $compose(1000);
        $this->assertTrue($low['new_hire_guarantee_applied']);
        $this->assertSame(1200.0, $low['weighted_bonus_before_guarantee']);
        $this->assertSame(2000.0, $low['weighted_bonus_amount']);
        $this->assertSame(6000.0, $low['total_payout']);

        $high = $compose(3000);
        $this->assertFalse($high['new_hire_guarantee_applied']);
        $this->assertSame(3200.0, $high['weighted_bonus_amount']);

        $pending = $compose(1000, ['holiday_16_hours' => ['status' => 'review', 'amount' => 0, 'rate' => 0]]);
        $this->assertFalse($pending['new_hire_guarantee_applied']);
        $this->assertSame(1200.0, $pending['weighted_bonus_amount']);

        $none = FulltimeSettlementComposer::compose(['subject_count_bonus' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0, 'metrics' => ['subject_count' => 10, 'subject_count_bonus' => 1000, 'one_to_three_bonus' => 0]]], ['payroll_total' => 10]);
        $this->assertFalse($none['new_hire_guarantee_applied']);
        $this->assertSame(1000.0, $none['weighted_bonus_amount']);

        // No guarantee means no floor at all: approved deductions can still take the weighted bonus below zero.
        $deducted = FulltimeSettlementComposer::compose([
            'subject_count_bonus' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0, 'metrics' => ['subject_count' => 10, 'subject_count_bonus' => 3000, 'one_to_three_bonus' => 0]],
            'deductions' => ['status' => 'not_qualifies', 'amount' => 0, 'rate' => -110],
            'new_hire_guarantee' => ['status' => 'not_qualifies', 'amount' => 0, 'rate' => 0],
        ], ['payroll_total' => 10]);
        $this->assertFalse($deducted['new_hire_guarantee_applied']);
        $this->assertSame(-300.0, $deducted['weighted_bonus_amount']);
    }
}
