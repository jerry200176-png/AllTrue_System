<?php

namespace Tests\Unit;

use App\Services\ScheduleGuardService;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/** in-app565: seats are occupied simultaneously, not by everyone encountered across a longer window. */
class ScheduleGuardPeakCapacityTest extends TestCase
{
    use TestTrait;

    public function test_two_earlier_and_one_later_students_leave_a_third_seat_across_the_boundary(): void
    {
        $result = $this->decide([$this->entry(1, '18:00', '20:00'), $this->entry(2, '18:00', '20:00'),
            $this->entry(3, '20:00', '22:00')]);
        self::assertTrue($result['allowed']);
        self::assertSame(2, $result['distinct_students']);
        self::assertNull($result['conflict']);
    }

    public function test_one_minute_of_three_existing_students_still_blocks_the_fourth(): void
    {
        $result = $this->decide([$this->entry(1, '18:00', '20:01'), $this->entry(2, '18:00', '20:01'),
            $this->entry(3, '20:00', '22:00')]);
        self::assertFalse($result['allowed']);
        self::assertSame(3, $result['conflict']['current_students']);
        self::assertSame(3, $result['conflict']['allowed_students']);
    }

    public function test_duplicate_student_sources_count_once_only_while_present(): void
    {
        $first = $this->entry(1, '18:00', '20:00');
        $duplicate = $first; $duplicate['source'] = 'student_class';
        $result = $this->decide([$first, $duplicate, $this->entry(2, '20:00', '22:00')], 'one_on_two');
        self::assertTrue($result['allowed']);
        self::assertSame(1, $result['distinct_students']);
    }

    public function test_anonymous_simultaneous_occupants_are_still_distinct_and_one_on_one_still_exclusive(): void
    {
        $anonymous = $this->entry(0, '18:00', '20:00');
        $other = $anonymous; $other['source_id'] = 2;
        self::assertFalse($this->decide([$anonymous, $other], 'one_on_two')['allowed']);
        $exclusive = $this->entry(1, '18:00', '20:00'); $exclusive['class_type'] = 'one_on_one';
        self::assertFalse($this->decide([$exclusive])['allowed']);
    }

    public function test_targeted_trials_share_peak_capacity_but_partial_one_on_three_does_not_expand_trial_policy(): void
    {
        $rows = [$this->entry(1, '18:00', '20:00'), $this->entry(2, '18:00', '20:00'), $this->entry(3, '20:00', '22:00')];
        self::assertTrue($this->decide($rows, 'trial', 'one_on_three')['allowed']);
        $trial = $this->entry(4, '19:00', '21:00'); $trial['class_type'] = 'trial';
        self::assertFalse($this->decide([$rows[0], $trial], 'trial')['allowed']);
    }

    public function test_unknown_or_overnight_time_bounds_keep_the_existing_conservative_count(): void
    {
        $missing = $this->entry(1, '18:00', '20:00'); unset($missing['start_time']);
        $rows = [$missing, $this->entry(2, '18:00', '20:00'), $this->entry(3, '20:00', '22:00')];
        self::assertFalse($this->decide($rows)['allowed']);
        $rows[0]['start_time'] = '23:00'; $rows[0]['end_time'] = '01:00';
        self::assertFalse($this->decide($rows)['allowed']);
    }

    public function test_generated_regular_capacity_matches_a_minute_by_minute_unique_student_census(): void
    {
        $this->forAll(Generator\seq(Generator\tuple(Generator\choose(1, 5), Generator\choose(0, 5), Generator\choose(1, 6))))
            ->then(function (array $raw): void {
                $rows = [];
                foreach ($raw as [$id, $left, $right]) {
                    $from = min($left, $right - 1) * 10; $to = max($left + 1, $right) * 10;
                    $rows[] = $this->entry($id, sprintf('14:%02d', $from), $to === 60 ? '15:00' : sprintf('14:%02d', $to));
                }
                $peak = 0;
                for ($minute = 0; $minute < 60; $minute++) {
                    $present = [];
                    foreach ($raw as [$id, $left, $right]) {
                        if ($minute >= min($left, $right - 1) * 10 && $minute < max($left + 1, $right) * 10) $present[$id] = true;
                    }
                    $peak = max($peak, count($present));
                }
                $guard = new ScheduleGuardService();
                $slot = ['start_time' => '14:00', 'end_time' => '15:00'];
                $result = $guard->teacherCapacityDecision('one_on_three', $slot, $rows);
                self::assertSame($peak, $result['distinct_students']);
                self::assertSame($peak < 3, $result['allowed']);
                self::assertSame($result, $guard->teacherCapacityDecision('one_on_three', $slot, array_reverse($rows)));
            });
    }

    private function entry(int $id, string $start, string $end): array
    {
        return ['student_id' => $id, 'class_type' => 'one_on_three', 'source' => 'class_session', 'source_id' => $id,
            'start_time' => $start, 'end_time' => $end];
    }

    private function decide(array $rows, string $type = 'one_on_three', ?string $target = null): array
    {
        return (new ScheduleGuardService())->teacherCapacityDecision($type, ['start_time' => '19:00', 'end_time' => '21:00'], $rows, $target);
    }
}
