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

    public function test_weekly_sixteen_scheduled_segments_qualifies(): void
    {
        $result = $this->policy->weekly16(['segments' => 16]);

        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $result['status']);
        $this->assertSame(16.0, $result['metrics']['weekly_segments']);
    }

    public function test_weekly_below_sixteen_segments_does_not_qualify(): void
    {
        $result = $this->policy->weekly16(['segments' => 15.75]);

        $this->assertSame(TeacherEligibilityPolicy::NOT_QUALIFIES, $result['status']);
        $this->assertSame(0, $result['amount']);
    }

    public function test_weekly_reviews_only_when_segment_total_is_unknown(): void
    {
        $result = $this->policy->weekly16(null);

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $result['status']);
        $this->assertSame(['weekly_segments'], $result['missing_fields']);
    }

    /** HQ 115.07: 假日未滿 16 小時者，假日倍率調整為 0%. Every judged week needs 16 hours across Saturday and Sunday. */
    public function test_holiday_rate_needs_sixteen_weekend_hours_in_every_judged_week(): void
    {
        $pass = $this->policy->holiday16([16, 18.5, 16, 20]);
        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $pass['status']);
        $this->assertSame(10.0, $pass['rate']);

        $fail = $this->policy->holiday16([16, 15.5, 16, 20]);
        $this->assertSame(TeacherEligibilityPolicy::NOT_QUALIFIES, $fail['status']);
        $this->assertSame(0, $fail['rate']);

        // Closure weeks are waived: the other weeks decide; a period made only of closure weeks counts as met.
        $this->assertSame(10.0, $this->policy->holiday16([16, 16], 2)['rate']);
        $this->assertSame(0, $this->policy->holiday16([12], 3)['rate']);
        $this->assertSame(10.0, $this->policy->holiday16([], 1)['rate']);
        $this->assertSame(0, $this->policy->holiday16([])['rate']);
    }

    /** HQ 115.07: 週一至週五 18:00 前可接 5 段課 → 0–5%. The HQ sheet shows averaged values such as 1.5 and 2.25. */
    public function test_weekday_rate_is_one_percent_per_early_segment_capped_per_week_then_averaged(): void
    {
        $this->assertSame(1.5, $this->policy->weekdayAfternoon([1, 2, 1, 2])['rate']);
        $this->assertSame(2.25, $this->policy->weekdayAfternoon([2, 3, 2, 2])['rate']);
        $this->assertSame(5.0, $this->policy->weekdayAfternoon([9, 5, 6, 12])['rate']);
        // A week above five 段 is capped before the average: (5 + 2) / 2, not (9 + 2) / 2.
        $this->assertSame(3.5, $this->policy->weekdayAfternoon([9, 2])['rate']);
        $this->assertSame(0.0, $this->policy->weekdayAfternoon([])['rate']);
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

    /** HQ 115.07 特殊表現: 其他值得特別表揚事項，每項 +5%（三個月為限）。 */
    public function test_other_commendation_adds_five_percent_per_item_for_at_most_three_months(): void
    {
        $item = fn (string $from, string $to) => ['outcome_key' => 'other_commendation', 'status' => 'verified', 'evidence' => '表揚事由', 'starts_on' => $from, 'ends_on' => $to];

        $two = $this->policy->specialPerformance([$item('2026-08-01', '2026-10-31'), $item('2026-07-01', '2026-08-31')], '2026-08-01', '2026-08-31');
        $this->assertSame(10.0, $two['rate']);
        // Outside its dates it adds nothing; longer than three months it waits for correction instead of paying.
        $this->assertSame(0.0, $this->policy->specialPerformance([$item('2026-08-01', '2026-10-31')], '2026-11-01', '2026-11-30')['rate']);
        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $this->policy->specialPerformance([$item('2026-08-01', '2026-11-30')], '2026-08-01', '2026-08-31')['status']);
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
        $lastRow = $this->policy->subjectCountBonus(50.9);
        $outOfRange = $this->policy->subjectCountBonus(51);

        $this->assertSame(10000, $row['metrics']['subject_count_bonus']);
        // No 一對三 count given means no 一對三獎金; the attachment's estimate column is not a rule.
        $this->assertSame(0, $row['metrics']['one_to_three_bonus']);
        $this->assertSame(10000, $row['amount']);
        $this->assertSame(0, $row['rate']);
        $this->assertSame(55000, $lastRow['metrics']['subject_count_bonus']);
        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $outOfRange['status']);
    }

    /**
     * Rows read off the HQ settlement sheet (numbers only):
     * [核薪總科目數, 一對三總計, 正課科目數, 科目數獎金, 一對三獎金, 正課20科倍率].
     */
    public function test_hq_sheet_rows_floor_the_subject_count_and_pay_one_to_three_per_unit(): void
    {
        $rows = [
            [16.9063, 24.5, 15.2813, 6000, 2450, 0],
            [15.9688, 22.25, 15.0, 5500, 2225, 0],
            [19.5625, 30.25, 17.0938, 9000, 3025, 0],
            [9.1875, 13.5, 8.5625, 0, 1350, 0],
            [1.625, 2.5, 1.5, 0, 250, 0],
            // 核薪總 is over 20 but 正課 alone is not: no +5%.
            [20.2813, 30.5, 18.0313, 10000, 3050, 0],
            [24.1875, 42.5, 20.3438, 16000, 4250, 5],
        ];

        foreach ($rows as [$payrollTotal, $oneToThree, $regularTotal, $subjectBonus, $oneToThreeBonus, $rate]) {
            $result = $this->policy->subjectCountBonus($payrollTotal, $oneToThree, ['regular_total' => $regularTotal]);

            $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $result['status']);
            $this->assertSame($subjectBonus, $result['metrics']['subject_count_bonus'], "科目數獎金 for {$payrollTotal}");
            $this->assertSame($oneToThreeBonus, $result['metrics']['one_to_three_bonus'], "一對三獎金 for {$oneToThree}");
            $this->assertSame($rate, $result['metrics']['subject_count_rate'], "正課20科倍率 for {$regularTotal}");
            $this->assertSame($subjectBonus + $oneToThreeBonus, $result['amount']);
        }
    }

    public function test_twenty_subject_rate_needs_the_regular_count(): void
    {
        $this->assertSame(0, $this->policy->subjectCountBonus(25)['metrics']['subject_count_rate']);
        $this->assertSame(5, $this->policy->subjectCountBonus(25, 0, ['regular_total' => 20])['metrics']['subject_count_rate']);
        $this->assertSame(0, $this->policy->subjectCountBonus(25, 0, ['regular_total' => 19.9999])['metrics']['subject_count_rate']);
    }

    public function test_missing_subject_count_is_review_instead_of_zero_bonus(): void
    {
        $result = $this->policy->subjectCountBonus(null);

        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $result['status']);
        $this->assertContains('approved_learning_records', $result['missing_fields']);
        $this->assertSame(0, $result['amount']);
    }

    public function test_fractional_subject_count_below_ten_has_no_subject_bonus(): void
    {
        $result = $this->policy->subjectCountBonus(5.625, 4.5);

        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $result['status']);
        $this->assertSame(0, $result['metrics']['subject_count_bonus']);
        $this->assertEquals(450, $result['metrics']['one_to_three_bonus']);
        $this->assertEquals(5.625, $result['metrics']['subject_count']);
        $this->assertEquals(4.5, $result['metrics']['one_to_three_count']);
    }

    /** Founder 2026-11-14: a director may replace the system's 假日／平日下午 rate inside the range; HQ approves. */
    public function test_approved_rate_adjustment_replaces_the_system_rate_and_a_pending_one_is_review(): void
    {
        $evaluate = fn (array $rows) => $this->policy->evaluate([
            'holiday_weekend_hours' => [16, 16], 'weekday_early_segments' => [1, 2],
            'admin_allowances' => $rows, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'subject_count' => 12,
        ])['components'];
        $row = fn (string $key, float $rate, string $status, string $from = '2026-08-01') => ['role_key' => $key, 'rate' => $rate, 'status' => $status, 'starts_on' => $from, 'ends_on' => '2026-08-31'];

        $system = $evaluate([]);
        $this->assertSame(10.0, $system['holiday_16_hours']['rate']);
        $this->assertSame(1.5, $system['weekday_afternoon']['rate']);

        // Approved: the newest row wins, the value is held inside the range, and the system's value stays visible.
        $adjusted = $evaluate([$row('holiday_16h', 4, 'approved'), $row('holiday_16h', 6, 'approved', '2026-08-15'), $row('weekday_afternoon', 9, 'approved')]);
        $this->assertSame(6.0, $adjusted['holiday_16_hours']['rate']);
        $this->assertSame(10.0, $adjusted['holiday_16_hours']['metrics']['system_rate']);
        $this->assertSame(5.0, $adjusted['weekday_afternoon']['rate']);
        // They are not admin allowances: that item stays at 0.
        $this->assertSame(0.0, $adjusted['admin_allowance']['rate']);

        // Pending: that item waits for approval; the other keeps the system's value. Withdrawn rows are ignored.
        $pending = $evaluate([$row('holiday_16h', 4, 'pending'), $row('weekday_afternoon', 3, 'withdrawn')]);
        $this->assertSame(TeacherEligibilityPolicy::REVIEW, $pending['holiday_16_hours']['status']);
        $this->assertContains('rate_adjustment_approval', $pending['holiday_16_hours']['missing_fields']);
        $this->assertSame(1.5, $pending['weekday_afternoon']['rate']);
        $this->assertSame(TeacherEligibilityPolicy::QUALIFIES, $pending['admin_allowance']['status']);
    }

    /** HQ 115.07: 新進教師前三個月保底獎金 2000 元；任一週未滿 16 段歸零. Month 1 is the month of the first lesson. */
    public function test_new_hire_guarantee_covers_the_first_three_months_when_every_week_is_full(): void
    {
        $amount = fn (?string $firstLesson, string $periodStart, bool $everyWeekFull) => $this->policy->newHireGuarantee($firstLesson, $periodStart, $everyWeekFull)['amount'];

        $this->assertSame(2000.0, $amount('2026-08-20', '2026-08-01', true));
        $this->assertSame(2000.0, $amount('2026-08-20', '2026-10-01', true));
        $this->assertSame(0, $amount('2026-08-20', '2026-11-01', true), 'month 4');
        $this->assertSame(0, $amount('2026-08-20', '2026-09-01', false), 'one short week removes it');
        $this->assertSame(0, $amount('2026-08-20', '2026-07-01', true), 'before the first lesson');
        $this->assertSame(0, $amount(null, '2026-08-01', true), 'no lesson on record');
        $this->assertSame(3, $this->policy->newHireGuarantee('2026-08-20', '2026-10-01', true)['metrics']['guarantee_month']);
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
