<?php

namespace Tests\Unit;

use App\Http\Controllers\TeacherEligibilityController;
use App\Services\TeacherEligibilityPolicy;
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

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($this->controller, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->controller, ...$arguments);
    }
}
