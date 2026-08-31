<?php

namespace Tests\Unit;

use App\Services\TeacherEligibilityPolicy;
use PHPUnit\Framework\TestCase;

class TeacherEligibilityPolicyTest extends TestCase
{
    private TeacherEligibilityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new TeacherEligibilityPolicy(require __DIR__ . '/../../config/teacher_salary.php');
    }

    public function test_weekly_sixteen_segments_and_forty_hours_qualifies(): void
    {
        $result = $this->policy->weekly16([
            'segments' => 16,
            'work_hours' => 40,
            'exception' => ['official_event' => false, 'leave_eligible' => false],
        ]);

        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $result['status']);
        $this->assertSame(1000, $result['amount']);
    }

    public function test_weekly_below_either_threshold_does_not_qualify(): void
    {
        $result = $this->policy->weekly16([
            'segments' => 15.5,
            'work_hours' => 40,
            'exception' => ['official_event' => false, 'leave_eligible' => false],
        ]);

        $this->assertSame(TeacherEligibilityPolicy::NOT_QUALIFIES, $result['status']);
        $this->assertSame(0, $result['amount']);
    }

    public function test_weekly_segment_rule_qualifies_without_the_legacy_forty_hour_gate(): void
    {
        $result = $this->policy->weekly16([
            'segments' => 16,
            'work_hours' => 20,
            'segment_rule' => true,
            'exception' => null,
        ]);

        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $result['status']);
        $this->assertSame(1000, $result['amount']);
        $this->assertSame(20.0, $result['metrics']['work_hours']);
    }

    public function test_weekly_segment_rule_reviews_only_when_segment_total_is_unknown(): void
    {
        $result = $this->policy->weekly16([
            'segments' => null,
            'work_hours' => null,
            'segment_rule' => true,
        ]);

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $result['status']);
        $this->assertSame(['weekly_segments'], $result['missing_fields']);
    }

    public function test_official_closure_can_preserve_weekly_bonus_without_signin_hours(): void
    {
        $result = $this->policy->weekly16([
            'segments' => 0,
            'work_hours' => null,
            'exception' => ['official_event' => true, 'leave_eligible' => false],
        ]);

        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $result['status']);
        $this->assertSame(1000, $result['amount']);
    }

    public function test_holiday_leave_can_offset_short_regular_hours_to_keep_ten_percent(): void
    {
        $pass = $this->policy->holiday16([
            ['date' => '2026-08-01', 'regular_scheduled_hours' => 8, 'worked_hours' => 8, 'holiday_leave_hours' => 8],
            ['date' => '2026-08-02', 'regular_scheduled_hours' => 16, 'worked_hours' => 16, 'holiday_leave_hours' => 0],
        ]);
        $fail = $this->policy->holiday16([
            ['date' => '2026-08-01', 'regular_scheduled_hours' => 8, 'worked_hours' => 8, 'holiday_leave_hours' => 4],
            ['date' => '2026-08-02', 'regular_scheduled_hours' => 16, 'worked_hours' => 16, 'holiday_leave_hours' => 0],
        ]);

        $this->assertSame(10.0, $pass['rate']);
        $this->assertSame(0, $fail['rate']);
        $this->assertSame(TeacherEligibilityPolicy::NOT_QUALIFIES, $fail['status']);
        $this->assertSame('offsets_toward_required_hours', $pass['metrics']['holiday_leave_effect']);
    }

    public function test_holiday_multiplier_requires_regular_schedule_baseline(): void
    {
        $result = $this->policy->holiday16([
            ['date' => '2026-08-01', 'worked_hours' => 16, 'holiday_leave_hours' => 0],
        ]);

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $result['status']);
        $this->assertContains('holiday_days.0.regular_scheduled_hours', $result['missing_fields']);
    }

    public function test_weekday_after_four_hour_low_consumption_is_capped_at_five_percent(): void
    {
        $result = $this->policy->weekdayAfternoon([
            '2026-08-03' => 4,
            '2026-08-04' => 5,
            '2026-08-05' => 5.5,
            '2026-08-06' => 6,
            '2026-08-07' => 20,
        ]);

        $this->assertSame(5.0, $result['rate']);
    }

    public function test_weekday_afternoon_examples_keep_half_quarter_and_full_segment_values(): void
    {
        foreach ([[4.0, 0.0], [5.0, 0.5], [5.5, 0.75], [6.0, 1.0]] as [$hours, $expectedSegments]) {
            $result = $this->policy->weekdayAfternoon(['2026-08-03' => $hours]);
            $this->assertSame($expectedSegments, $result['metrics']['extra_segments']);
            $this->assertSame($expectedSegments, $result['rate']);
        }
    }

    public function test_unverified_achievement_is_review_and_verified_achievement_adds_five_percent(): void
    {
        $review = $this->policy->specialPerformance([
            ['outcome_key' => 'ntu', 'status' => 'pending', 'evidence' => null],
        ], '2026-08-01', '2026-08-31');
        $pass = $this->policy->specialPerformance([
            [
                'outcome_key' => 'ntu',
                'status' => 'verified',
                'evidence' => 'evidence.pdf',
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-08-31',
            ],
        ], '2026-08-01', '2026-08-31');

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $review['status']);
        $this->assertSame(5.0, $pass['rate']);
    }

    public function test_achievement_without_active_period_is_review(): void
    {
        $result = $this->policy->specialPerformance([
            ['outcome_key' => 'ntu', 'status' => 'verified', 'evidence' => 'evidence.pdf'],
        ], '2026-08-01', '2026-08-31');

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $result['status']);
        $this->assertContains('achievement_evidence_or_approval', $result['missing_fields']);
    }

    public function test_student_achievement_longer_than_three_months_is_review(): void
    {
        $result = $this->policy->specialPerformance([
            [
                'outcome_key' => 'student_outcome',
                'status' => 'verified',
                'evidence' => 'students.pdf',
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-04-30',
            ],
        ], '2026-02-01', '2026-02-28');

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $result['status']);
    }

    public function test_employee_of_year_before_2027_is_not_applied(): void
    {
        $result = $this->policy->specialPerformance([
            [
                'outcome_key' => 'employee_of_year',
                'status' => 'verified',
                'evidence' => 'award.pdf',
                'award_year' => 2026,
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-12-31',
            ],
        ], '2026-08-01', '2026-08-31');

        $this->assertSame(0.0, $result['rate']);
        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $result['status']);
    }

    public function test_employee_of_year_requires_award_year_and_next_year_period(): void
    {
        $missingYear = $this->policy->specialPerformance([
            [
                'outcome_key' => 'employee_of_year',
                'status' => 'verified',
                'evidence' => 'award.pdf',
                'starts_on' => '2028-01-01',
                'ends_on' => '2028-12-31',
            ],
        ], '2028-01-01', '2028-12-31');
        $wrongPeriod = $this->policy->specialPerformance([
            [
                'outcome_key' => 'employee_of_year',
                'status' => 'verified',
                'evidence' => 'award.pdf',
                'award_year' => 2027,
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-12-31',
            ],
        ], '2027-01-01', '2027-12-31');

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $missingYear['status']);
        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $wrongPeriod['status']);
    }

    public function test_saturday_leave_without_sunday_makeup_blocks_weekly_bonus(): void
    {
        $result = $this->policy->weekly16([
            'segments' => 16,
            'work_hours' => 40,
            'exception' => [
                'official_event' => false,
                'leave_eligible' => false,
                'saturday_leave_blocked' => true,
            ],
        ]);

        $this->assertSame(TeacherEligibilityPolicy::NOT_QUALIFIES, $result['status']);
        $this->assertSame(0, $result['amount']);
    }

    public function test_deductions_require_both_approvals_and_accumulate(): void
    {
        $result = $this->policy->deductions([
            ['deduction_key' => 'complaint', 'status' => 'approved', 'starts_on' => '2026-08-01', 'ends_on' => null],
            ['deduction_key' => 'late', 'status' => 'approved', 'starts_on' => '2026-08-01', 'ends_on' => null],
        ], '2026-08-01', '2026-08-31');

        $this->assertSame(TeacherEligibilityPolicy::NOT_QUALIFIES, $result['status']);
        $this->assertSame(-20.0, $result['rate']);
    }

    public function test_withdrawn_achievement_and_deduction_do_not_force_review(): void
    {
        $achievement = $this->policy->specialPerformance([
            ['outcome_key' => 'ntu', 'status' => 'withdrawn', 'evidence' => null],
        ], '2026-08-01', '2026-08-31');
        $deduction = $this->policy->deductions([
            ['deduction_key' => 'complaint', 'status' => 'withdrawn', 'starts_on' => '2026-08-01', 'ends_on' => null],
        ], '2026-08-01', '2026-08-31');

        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $achievement['status']);
        $this->assertSame(0.0, $achievement['rate']);
        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $deduction['status']);
        $this->assertSame(0.0, $deduction['rate']);
    }

    public function test_subject_count_uses_exact_attachment_row_and_rejects_out_of_range(): void
    {
        $row = $this->policy->subjectCountBonus(20);
        $outOfRange = $this->policy->subjectCountBonus(51);

        $this->assertSame(16790, $row['amount']);
        $this->assertSame(115, $row['metrics']['multiplier']);
        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $outOfRange['status']);
    }

    public function test_missing_subject_count_is_review_instead_of_zero_bonus(): void
    {
        $result = $this->policy->subjectCountBonus(null);

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $result['status']);
        $this->assertContains('approved_learning_records', $result['missing_fields']);
        $this->assertSame(0, $result['amount']);
    }

    public function test_fractional_subject_count_interpolates_adjacent_table_rows(): void
    {
        $result = $this->policy->subjectCountBonus(5.625, 4.5);

        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $result['status']);
        $this->assertSame(0, $result['metrics']['subject_count_bonus']);
        $this->assertEquals(450, $result['metrics']['one_to_three_bonus']);
        $this->assertEquals(5.625, $result['metrics']['subject_count']);
        $this->assertEquals(4.5, $result['metrics']['one_to_three_count']);
    }

    public function test_missing_holiday_calendar_is_review_instead_of_no_holiday_zero(): void
    {
        $result = $this->policy->holiday16(null);

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $result['status']);
        $this->assertContains('holiday_calendar', $result['missing_fields']);
    }

    public function test_pending_admin_allowance_is_review(): void
    {
        $result = $this->policy->adminAllowance([
            ['role_key' => 'head_tutor', 'rate' => 8, 'status' => 'pending', 'starts_on' => '2026-08-01'],
        ], '2026-08-01', '2026-08-31');

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $result['status']);
    }

    public function test_approved_admin_allowance_caps_at_ten(): void
    {
        $result = $this->policy->adminAllowance([
            ['role_key' => 'admin_assist', 'rate' => 8, 'status' => 'approved', 'starts_on' => '2026-08-01'],
            ['role_key' => 'head_tutor', 'rate' => 5, 'status' => 'approved', 'starts_on' => '2026-08-01'],
        ], '2026-08-01', '2026-08-31');

        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $result['status']);
        $this->assertSame(10.0, $result['rate']);
    }
}
