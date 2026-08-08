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

    public function test_holiday_multiplier_requires_every_holiday_to_reach_sixteen_hours(): void
    {
        $pass = $this->policy->holiday16([
            ['date' => '2026-08-01', 'worked_hours' => 8, 'holiday_leave_hours' => 8],
            ['date' => '2026-08-02', 'worked_hours' => 16, 'holiday_leave_hours' => 0],
        ]);
        $fail = $this->policy->holiday16([
            ['date' => '2026-08-01', 'worked_hours' => 8, 'holiday_leave_hours' => 7.5],
            ['date' => '2026-08-02', 'worked_hours' => 16, 'holiday_leave_hours' => 0],
        ]);

        $this->assertSame(10.0, $pass['rate']);
        $this->assertSame(0, $fail['rate']);
        $this->assertSame(TeacherEligibilityPolicy::NOT_QUALIFIES, $fail['status']);
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

    public function test_unverified_achievement_is_review_and_verified_achievement_adds_five_percent(): void
    {
        $review = $this->policy->specialPerformance([
            ['outcome_key' => 'ntu', 'status' => 'pending', 'evidence' => null],
        ], '2026-08-01', '2026-08-31');
        $pass = $this->policy->specialPerformance([
            ['outcome_key' => 'ntu', 'status' => 'verified', 'evidence' => 'evidence.pdf'],
        ], '2026-08-01', '2026-08-31');

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $review['status']);
        $this->assertSame(5.0, $pass['rate']);
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

    public function test_subject_count_uses_exact_attachment_row_and_rejects_out_of_range(): void
    {
        $row = $this->policy->subjectCountBonus(20);
        $outOfRange = $this->policy->subjectCountBonus(51);

        $this->assertSame(16790, $row['amount']);
        $this->assertSame(115, $row['metrics']['multiplier']);
        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $outOfRange['status']);
    }
}
