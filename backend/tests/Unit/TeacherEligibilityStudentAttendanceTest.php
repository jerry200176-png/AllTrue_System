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

        self::assertTrue($this->invoke('isEligibleTeachingAttendance', $row));
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

    public function test_trial_tutoring_and_absent_student_rows_do_not_count(): void
    {
        $base = [
            'attendance_status' => 'present',
            'start_time' => '16:00:00',
            'end_time' => '18:00:00',
            'student_sign_in_at' => null,
            'student_sign_out_at' => null,
        ];

        self::assertFalse($this->invoke('isEligibleTeachingAttendance', (object) [...$base, 'class_type' => 'trial']));
        self::assertFalse($this->invoke('isEligibleTeachingAttendance', (object) [...$base, 'class_type' => 'tutoring']));
        self::assertFalse($this->invoke('isEligibleTeachingAttendance', (object) [...$base, 'class_type' => 'one_on_one', 'attendance_status' => 'absent']));
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
