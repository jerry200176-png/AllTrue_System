<?php

namespace Tests\Unit;

use App\Http\Controllers\TeacherEligibilityController;
use App\Services\TeacherEligibilityPolicy;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TeacherEligibilityStudentAttendanceTest extends TestCase
{
    private TeacherEligibilityController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new TeacherEligibilityController(
            new TeacherEligibilityPolicy(require __DIR__ . '/../../config/teacher_salary.php')
        );
    }

    /** Founder 2026-11-15: 段 follow clock time; lessons in the same 2 hours are one 段. */
    public function test_weekly_segments_merge_overlapping_lessons_and_count_every_class_type_by_hours(): void
    {
        $lesson = fn (string $start, string $end) => ['start' => $start, 'end' => $end];
        $lessons = [
            // Two 一對二 courses in the same two hours are one 段; partly overlapping lessons cover 18:00-21:00.
            '2026-08-03' => [$lesson('16:00', '18:00'), $lesson('16:00', '18:00'), $lesson('18:00', '20:00'), $lesson('19:00', '21:00')],
            // 1h 試聽 = 0.5 段, 2h 輔導 = 1 段: every kind of lesson counts by its hours.
            '2026-08-04' => [$lesson('10:00', '11:00'), $lesson('13:00', '15:00')],
            // No end time: the two-hour default.
            '2026-08-05' => [$lesson('16:00', '')],
            // Another week.
            '2026-08-10' => [$lesson('08:00', '12:00')],
        ];

        $week = $this->invoke('evaluateWeek', Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09')->endOfDay(), $lessons);

        self::assertSame(['2026-08-03' => 5.0, '2026-08-04' => 3.0, '2026-08-05' => 2.0], $week['metrics']['daily_hours']);
        self::assertSame(5.0, $week['metrics']['total_segments']);
        self::assertFalse($week['metrics']['meets_16_segments']);
        self::assertSame(TeacherEligibilityPolicy::NOT_QUALIFIES, $week['status']);

        // A company-wide closure day inside the week: the week is not judged and counts as full.
        $closed = $this->invoke('evaluateWeek', Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09')->endOfDay(), $lessons, ['2026-08-08', '2026-08-15']);
        self::assertSame(TeacherEligibilityPolicy::QUALIFIES, $closed['status']);
        self::assertSame(['2026-08-08'], $closed['metrics']['closure_dates']);
        self::assertSame(5.0, $closed['metrics']['total_segments']);
    }

    public function test_weeks_are_whole_monday_to_sunday_and_belong_to_their_mondays_month(): void
    {
        $weeks = fn (string $start, string $end) => array_map(
            fn ($week) => [$week[0]->toDateString(), $week[1]->toDateString()],
            $this->invoke('splitIntoWeeks', Carbon::parse($start), Carbon::parse($end)->endOfDay())
        );

        // July 2026 starts on a Wednesday: the week of 06-29 is June's; the week of 07-27 runs into August.
        self::assertSame([
            ['2026-07-06', '2026-07-12'], ['2026-07-13', '2026-07-19'],
            ['2026-07-20', '2026-07-26'], ['2026-07-27', '2026-08-02'],
        ], $weeks('2026-07-01', '2026-07-31'));
        // August does not get the 07-27 week again.
        self::assertSame('2026-08-03', $weeks('2026-08-01', '2026-08-31')[0][0]);
        self::assertCount(5, $weeks('2026-08-01', '2026-08-31'));
        self::assertSame([['2026-08-03', '2026-08-09']], $weeks('2026-08-03', '2026-08-09'));
    }

    /** 平日下午倍率 counts only Monday-to-Friday clock time before 18:00. */
    public function test_weekday_early_segments_stop_at_the_cutoff_and_skip_weekends(): void
    {
        $lesson = fn (string $start, string $end) => ['start' => $start, 'end' => $end];
        $week = $this->invoke('evaluateWeek', Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09')->endOfDay(), [
            // 14:00-19:00 with an overlap: four hours before the cutoff.
            '2026-08-03' => [$lesson('14:00', '16:00'), $lesson('15:00', '17:00'), $lesson('17:00', '19:00')],
            '2026-08-04' => [$lesson('18:00', '20:00')], // after the cutoff
            '2026-08-05' => [$lesson('17:00', '')], // no end time: two-hour default, one hour before the cutoff
            // Saturday and Sunday feed the 假日16小時 rate instead; overlapping lessons count once, and a lesson with
            // seconds in its clock (#2833) is still whole minutes.
            '2026-08-08' => [$lesson('10:00', '12:00'), $lesson('11:00', '13:00')],
            '2026-08-09' => [$lesson('16:00:00', '18:00:45')],
        ]);

        self::assertSame(2.5, $week['metrics']['weekday_early_segments']);
        self::assertSame(5.0, $week['metrics']['weekend_hours']);
        self::assertSame(7.0, $week['metrics']['total_segments']);
    }

    public function test_campus_closed_day_is_inferred_when_every_session_is_leave(): void
    {
        self::assertSame(['2026-08-31'], $this->invoke('campusClosedDatesFromSessionRows', [
            ['date' => '2026-08-31', 'status' => 'leave'],
            ['date' => '2026-08-31', 'status' => 'leave_adjusted'],
            ['date' => '2026-08-30', 'status' => 'leave'],
            ['date' => '2026-08-30', 'status' => 'scheduled'],
        ]));
        // Two called-off lessons on a quiet day are not a company-wide closure once a floor applies.
        self::assertSame([], $this->invoke('campusClosedDatesFromSessionRows', [
            ['date' => '2026-08-31', 'status' => 'leave'], ['date' => '2026-08-31', 'status' => 'leave_adjusted'],
        ], 10));
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($this->controller, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->controller, ...$arguments);
    }
}
