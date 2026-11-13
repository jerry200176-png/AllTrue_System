<?php

namespace Tests\Unit;

use App\Services\LearningPlanScheduler;
use PHPUnit\Framework\TestCase;

/** 學習計畫日期演算法 (#4970 step 4a). Pure: no DB. */
class LearningPlanSchedulerTest extends TestCase
{
    private function ranges(array $out): array
    {
        return array_map(fn ($r) => $r['start'] . '..' . $r['end'], $out);
    }

    private function exam(int $seq, string $start): array
    {
        return ['sequence' => $seq, 'start_date' => $start];
    }

    public function test_no_exams_is_one_even_segment_with_remainder_to_earlier_units(): void
    {
        $out = LearningPlanScheduler::schedule([[], [], []], '2026-09-01', '2026-09-10', []);

        $this->assertSame([0 => '2026-09-01..2026-09-04', 1 => '2026-09-05..2026-09-07', 2 => '2026-09-08..2026-09-10'], $this->ranges($out));
    }

    public function test_three_exams_split_into_segments_and_untagged_units_inherit(): void
    {
        $units = [['exam_sequence' => 1], [], ['exam_sequence' => 2], ['exam_sequence' => 3], []];
        $exams = [$this->exam(3, '2026-12-01'), $this->exam(1, '2026-10-01'), $this->exam(2, '2026-11-01')];

        $out = LearningPlanScheduler::schedule($units, '2026-09-01', '2026-12-31', $exams);

        $this->assertSame([
            0 => '2026-09-01..2026-09-15', 1 => '2026-09-16..2026-09-30',
            2 => '2026-10-01..2026-10-31',
            3 => '2026-11-01..2026-11-15', 4 => '2026-11-16..2026-11-30',
        ], $this->ranges($out));
        foreach ($out as $i => $r) { // never reaches the exam that closes its segment
            $this->assertLessThan('2026-12-01', $r['end'], "unit $i");
        }
    }

    public function test_unit_tagged_to_a_missing_exam_falls_to_nearest_later_else_last_segment(): void
    {
        $units = [['exam_sequence' => 1], ['exam_sequence' => 3]];

        $out = LearningPlanScheduler::schedule($units, '2026-09-01', '2026-12-31', [$this->exam(2, '2026-10-15')]);

        $this->assertSame([0 => '2026-09-01..2026-10-14', 1 => '2026-10-15..2026-12-31'], $this->ranges($out));
    }

    public function test_more_units_than_days_share_the_last_day(): void
    {
        $out = LearningPlanScheduler::schedule([[], [], [], [], []], '2026-09-01', '2026-09-03', []);

        $this->assertSame([
            0 => '2026-09-01..2026-09-01', 1 => '2026-09-02..2026-09-02', 2 => '2026-09-03..2026-09-03',
            3 => '2026-09-03..2026-09-03', 4 => '2026-09-03..2026-09-03',
        ], $this->ranges($out));
    }

    public function test_manual_units_are_skipped_but_still_pass_on_their_sequence(): void
    {
        $units = [[], ['exam_sequence' => 2, 'dates_manual' => true], []];

        $out = LearningPlanScheduler::schedule($units, '2026-09-01', '2026-09-10', [$this->exam(2, '2026-09-06')]);

        $this->assertArrayNotHasKey(1, $out);
        // Unit 2 inherits exam 2 from the manual unit, so both free units share the segment before the exam.
        $this->assertSame([0 => '2026-09-01..2026-09-03', 2 => '2026-09-04..2026-09-05'], $this->ranges($out));
    }

    public function test_exams_on_or_beyond_the_plan_range_are_ignored(): void
    {
        $exams = [$this->exam(1, '2026-09-01'), $this->exam(2, '2026-09-30'), $this->exam(3, '2026-10-05')];

        $out = LearningPlanScheduler::schedule([['exam_sequence' => 1], ['exam_sequence' => 3]], '2026-09-01', '2026-09-30', $exams);

        $this->assertSame([0 => '2026-09-01..2026-09-15', 1 => '2026-09-16..2026-09-30'], $this->ranges($out));
    }

    public function test_leap_day_counts_as_a_day(): void
    {
        $units = [['exam_sequence' => 1], ['exam_sequence' => 1], ['exam_sequence' => 3]];

        $out = LearningPlanScheduler::schedule($units, '2028-02-20', '2028-03-02', [$this->exam(1, '2028-03-01')]);

        $this->assertSame([0 => '2028-02-20..2028-02-24', 1 => '2028-02-25..2028-02-29', 2 => '2028-03-01..2028-03-02'], $this->ranges($out));
    }

    public function test_range_crossing_a_year_boundary(): void
    {
        $out = LearningPlanScheduler::schedule([[], []], '2026-12-28', '2027-01-03', []);

        $this->assertSame([0 => '2026-12-28..2026-12-31', 1 => '2027-01-01..2027-01-03'], $this->ranges($out));
    }

    public function test_inverted_range_or_no_units_gives_nothing(): void
    {
        $this->assertSame([], LearningPlanScheduler::schedule([[]], '2026-09-10', '2026-09-01', []));
        $this->assertSame([], LearningPlanScheduler::schedule([], '2026-09-01', '2026-09-10', []));
    }
}
