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

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($this->controller, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->controller, ...$arguments);
    }
}
