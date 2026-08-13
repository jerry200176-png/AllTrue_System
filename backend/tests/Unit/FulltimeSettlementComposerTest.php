<?php

namespace Tests\Unit;

use App\Support\FulltimeSettlementComposer;
use PHPUnit\Framework\TestCase;

class FulltimeSettlementComposerTest extends TestCase
{
    /**
     * Regression guard: subject_count_bonus.amount/rate are pinned by
     * TeacherEligibilityPolicyTest to the announcement's illustrative example
     * row (rate=15 for count=20, baking in an assumed +10% from holiday).
     * The composer must NOT sum that rate into the real multiplier on top of
     * the teacher's actual holiday_16_hours.rate, or the +10% gets counted
     * twice.
     */
    public function test_does_not_double_count_subject_count_bonus_illustrative_rate(): void
    {
        $components = [
            'weekly_16_segments' => ['status' => 'qualifies', 'amount' => 4000, 'rate' => 0],
            'holiday_16_hours' => ['status' => 'not_qualifies', 'amount' => 0, 'rate' => 0], // teacher did NOT hit holiday 16hr
            'weekday_afternoon' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'special_performance' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'deductions' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'subject_count_bonus' => [
                'status' => 'qualifies', 'amount' => 16790, 'rate' => 15, // illustrative row for count=20
                'metrics' => ['subject_count' => 20, 'subject_count_bonus' => 10000, 'one_to_three_bonus' => 4600, 'multiplier' => 115],
            ],
        ];

        $result = FulltimeSettlementComposer::compose($components, 33000.0);

        // multiplier = 100 + 0 (holiday, actually not_qualifies) + 0 + 0 + 0 + 5 (subject_count >= 20) = 105
        $this->assertSame(105.0, $result['multiplier_pct']);
        // weighted = (10000 + 4600) * 1.05 = 15330, NOT 16790 (the illustrative 115% row)
        $this->assertSame(15330.0, $result['weighted_bonus_amount']);
        $this->assertSame(4000.0, $result['weekly_segment_bonus_amount']);
        $this->assertSame(33000.0 + 4000 + 15330, $result['total_payout']);
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
                'metrics' => ['subject_count' => 25, 'subject_count_bonus' => 17500, 'one_to_three_bonus' => 6600],
            ],
        ];

        $result = FulltimeSettlementComposer::compose($components, 30000.0);

        // 100 + 10 + 3 + 5 - 10 + 5 (>=20) = 113
        $this->assertSame(113.0, $result['multiplier_pct']);
        $this->assertSame(round((17500 + 6600) * 1.13, 2), $result['weighted_bonus_amount']);
    }

    public function test_subject_count_below_threshold_gets_no_extra_multiplier(): void
    {
        $components = [
            'subject_count_bonus' => [
                'status' => 'qualifies', 'amount' => 0, 'rate' => 0,
                'metrics' => ['subject_count' => 19, 'subject_count_bonus' => 9000, 'one_to_three_bonus' => 4200],
            ],
        ];

        $result = FulltimeSettlementComposer::compose($components, 0.0);

        $this->assertSame(100.0, $result['multiplier_pct']);
    }

    public function test_missing_base_salary_defaults_to_zero_not_error(): void
    {
        $result = FulltimeSettlementComposer::compose([], null);

        $this->assertSame(0.0, $result['base_salary']);
        $this->assertSame(0.0, $result['total_payout']);
    }

    public function test_review_required_when_any_component_is_review(): void
    {
        $components = [
            'holiday_16_hours' => ['status' => 'review', 'amount' => 0, 'rate' => 0],
        ];

        $result = FulltimeSettlementComposer::compose($components, 30000.0);

        $this->assertTrue($result['review_required']);
    }
}
