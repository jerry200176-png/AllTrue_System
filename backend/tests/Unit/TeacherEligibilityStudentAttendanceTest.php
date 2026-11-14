<?php

namespace Tests\Unit;

use App\Http\Controllers\TeacherEligibilityController;
use App\Services\TeacherEligibilityPolicy;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TeacherEligibilityStudentAttendanceTest extends TestCase
{
    /** #2833: seconds cannot silently change the existing whole-minute payroll input. */
    public function test_duration_fallbacks_keep_whole_minutes_at_second_boundaries(): void
    {
        $row = (object) [
            'start_time' => '16:00:00', 'end_time' => '18:00:45',
            'student_sign_in_at' => '2026-09-01 16:00:00',
            'student_sign_out_at' => '2026-09-01 18:00:45',
        ];
        self::assertSame(2.0, $this->invoke('studentAttendanceDurationHours', $row));
        self::assertSame(2.0, $this->invoke('durationHours', $row));
        $row->student_sign_out_at = null;
        self::assertSame(2.0, $this->invoke('studentAttendanceDurationHours', $row));
    }

    private TeacherEligibilityController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new TeacherEligibilityController(
            new TeacherEligibilityPolicy(require __DIR__ . '/../../config/teacher_salary.php')
        );
    }

    public function test_present_student_attendance_is_teaching_time_and_uses_actual_student_window(): void
    {
        $row = (object) [
            'class_type' => 'one_on_one',
            'attendance_status' => 'present',
            'start_time' => '16:00:00',
            'end_time' => '18:00:00',
            'student_sign_in_at' => '2026-08-10 16:10:00',
            'student_sign_out_at' => '2026-08-10 18:00:00',
        ];

        self::assertSame(1.83, $this->invoke('studentAttendanceDurationHours', $row));
    }

    public function test_missing_student_sign_out_falls_back_to_class_session_window(): void
    {
        $row = (object) [
            'class_type' => 'one_on_one',
            'attendance_status' => 'late',
            'start_time' => '16:00:00',
            'end_time' => '18:00:00',
            'student_sign_in_at' => '2026-08-10 16:10:00',
            'student_sign_out_at' => null,
        ];

        self::assertSame(2.0, $this->invoke('studentAttendanceDurationHours', $row));
    }

    /** Founder 2026-11-15: 段 follow clock time; lessons in the same 2 hours are one 段. */
    public function test_weekly_segments_merge_overlapping_lessons_and_count_every_class_type_by_hours(): void
    {
        $row = fn (string $date, string $start, string $end, array $extra = []) => (object) ([
            'schedule_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'status' => 'scheduled', 'type' => 'normal', 'class_type' => 'one_on_one',
        ] + $extra);
        $rows = collect([
            // Two 一對二 courses in the same two hours: one 段, not two.
            $row('2026-08-03', '16:00:00', '18:00:00', ['class_type' => 'one_on_two']),
            $row('2026-08-03', '16:00:00', '18:00:00', ['class_type' => 'one_on_two']),
            // Partly overlapping lessons cover 18:00-21:00 = 3h.
            $row('2026-08-03', '18:00:00', '20:00:00'),
            $row('2026-08-03', '19:00:00', '21:00:00'),
            // 1h 試聽 = 0.5 段, 2h 輔導 = 1 段.
            $row('2026-08-04', '10:00:00', '11:00:00', ['class_type' => 'trial']),
            $row('2026-08-04', '13:00:00', '15:00:00', ['class_type' => 'tutoring', 'type' => 'tutoring']),
            // A student's leave still counts.
            $row('2026-08-05', '16:00:00', '18:00:00', ['status' => 'leave']),
            // Moved lessons, admin time and other weeks do not.
            $row('2026-08-05', '08:00:00', '10:00:00', ['status' => 'rescheduled']),
            $row('2026-08-06', '08:00:00', '12:00:00', ['type' => 'admin']),
            $row('2026-08-10', '08:00:00', '12:00:00'),
        ]);

        $week = $this->invoke('evaluateWeek', Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09')->endOfDay(), $rows);

        self::assertSame(['2026-08-03' => 5.0, '2026-08-04' => 3.0, '2026-08-05' => 2.0], $week['metrics']['daily_hours']);
        self::assertSame(5.0, $week['metrics']['total_segments']);
        self::assertFalse($week['metrics']['meets_16_segments']);
        self::assertSame(TeacherEligibilityPolicy::NOT_QUALIFIES, $week['status']);
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

    public function test_weekday_afternoon_merges_overlapping_schedule_windows_before_low_consumption(): void
    {
        $rows = collect([
            ['start_time' => '14:00:00', 'end_time' => '16:00:00'],
            ['start_time' => '16:00:00', 'end_time' => '18:00:00'],
            ['start_time' => '17:00:00', 'end_time' => '19:00:00'],
            ['start_time' => '19:00:00', 'end_time' => '21:00:00'],
            ['start_time' => '20:00:00', 'end_time' => '22:00:00'],
        ])->map(fn ($row) => (object) array_merge($row, [
            'schedule_date' => '2026-08-10',
            'class_type' => 'one_on_one',
            'type' => 'normal',
        ]));

        self::assertSame(
            ['2026-08-10' => 8.0],
            $this->invoke('weekdayHours', $rows, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'))
        );
    }

    public function test_weekday_afternoon_excludes_makeup_schedule_from_recurring_hours(): void
    {
        $rows = collect([
            ['start_time' => '14:00:00', 'end_time' => '16:00:00', 'type' => 'normal'],
            ['start_time' => '16:00:00', 'end_time' => '18:00:00', 'type' => 'extra'],
            ['start_time' => '18:00:00', 'end_time' => '20:00:00', 'type' => 'normal'],
        ])->map(fn ($row) => (object) array_merge($row, [
            'schedule_date' => '2026-08-10',
            'class_type' => 'one_on_one',
        ]));

        self::assertSame(
            ['2026-08-10' => 4.0],
            $this->invoke('weekdayHours', $rows, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'))
        );
    }

    public function test_holiday_baseline_uses_recurring_schedule_not_leave_hours_as_worked_time(): void
    {
        $attendance = collect([
            (object) [
                'session_date' => '2026-08-01',
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
            ],
        ]);
        $schedules = collect([
            ['start_time' => '07:00:00', 'end_time' => '11:00:00', 'type' => 'normal'],
            ['start_time' => '11:00:00', 'end_time' => '15:00:00', 'type' => 'normal'],
            ['start_time' => '15:00:00', 'end_time' => '19:00:00', 'type' => 'normal'],
            ['start_time' => '19:00:00', 'end_time' => '23:00:00', 'type' => 'normal'],
        ])->map(fn ($row) => (object) array_merge($row, [
            'schedule_date' => '2026-08-01',
            'class_type' => 'one_on_one',
        ]));
        $events = collect([
            (object) ['event_type' => 'holiday', 'event_date' => '2026-08-01'],
            (object) [
                'event_type' => 'leave',
                'event_date' => '2026-08-01',
                'holiday_leave_hours' => 8,
            ],
        ]);

        self::assertSame([
            [
                'date' => '2026-08-01',
                'regular_scheduled_hours' => 16.0,
                'worked_hours' => 8.0,
                'holiday_leave_hours' => 8.0,
            ],
        ], $this->invoke(
            'holidayDays',
            $attendance,
            $schedules,
            $events,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-01'),
            true
        ));
    }

    public function test_campus_closed_day_is_inferred_when_every_session_is_leave(): void
    {
        self::assertSame(['2026-08-31'], $this->invoke('campusClosedDatesFromSessionRows', [
            ['date' => '2026-08-31', 'status' => 'leave'],
            ['date' => '2026-08-31', 'status' => 'leave_adjusted'],
            ['date' => '2026-08-30', 'status' => 'leave'],
            ['date' => '2026-08-30', 'status' => 'scheduled'],
        ]));
    }

    public function test_holiday_hours_still_count_when_recurring_plan_was_marked_leave(): void
    {
        $schedules = collect([
            (object) [
                'schedule_date' => '2026-08-31',
                'start_time' => '06:00:00',
                'end_time' => '22:00:00',
                'class_type' => 'one_on_one',
                'type' => 'normal',
                'status' => 'leave',
            ],
        ]);
        $events = collect([
            (object) ['event_type' => 'holiday', 'event_date' => '2026-08-31'],
        ]);

        $result = $this->invoke(
            'holidayDays',
            collect(),
            $schedules,
            $events,
            Carbon::parse('2026-08-31'),
            Carbon::parse('2026-08-31'),
            true
        );

        self::assertSame('2026-08-31', $result[0]['date']);
        self::assertSame(16.0, $result[0]['regular_scheduled_hours']);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($this->controller, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->controller, ...$arguments);
    }
}
