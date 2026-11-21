<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ScheduleGuardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** One teacher cannot teach at two campuses at the same time: every guarded write sees the other campus. */
class ScheduleGuardCrossCampusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_future_lesson_at_another_campus_blocks_an_overlapping_lesson(): void
    {
        $teacher = $this->teacher();
        $date = Carbon::today()->addDays(7)->toDateString();
        $other = $this->course($teacher, 2, '甲');
        $this->lesson($other, $date, '18:00', '20:00');
        $mine = $this->course($teacher, 1, '乙');
        $guard = app(ScheduleGuardService::class);

        $conflicts = $guard->validateCourseOccurrence($mine, 1, $date, '19:00', '21:00');
        $this->assertSame(['cross_campus'], array_column($conflicts, 'type'));
        $this->assertSame(2, $conflicts[0]['campus_id']);
        $this->assertSame('18:00', $conflicts[0]['start_time']);
        $this->assertStringContainsString('不能排在兩個分校', $conflicts[0]['message']);
        $this->assertStringNotContainsString('甲', $conflicts[0]['message'], 'other campus student names stay private');

        // Back to back is fine.
        $this->assertSame([], $guard->validateCourseOccurrence($mine, 1, $date, '20:00', '22:00'));
    }

    public function test_freed_or_past_lessons_never_block(): void
    {
        $teacher = $this->teacher();
        $guard = app(ScheduleGuardService::class);
        $mine = $this->course($teacher, 1, '乙');

        // Student leave at the other campus frees the teacher.
        $date = Carbon::today()->addDays(7)->toDateString();
        $this->lesson($this->course($teacher, 2, '請假生'), $date, '18:00', '20:00', 'leave');
        $this->lesson($this->course($teacher, 2, '取消生'), $date, '18:00', '20:00', 'cancelled');
        $this->assertSame([], $guard->validateCourseOccurrence($mine, 1, $date, '18:00', '20:00'));

        // A lesson that already ended can be backfilled even when it clashed.
        $past = Carbon::today()->subDays(3)->toDateString();
        $this->lesson($this->course($teacher, 2, '甲'), $past, '18:00', '20:00');
        $this->assertSame([], $guard->validateCourseOccurrence($mine, 1, $past, '18:00', '20:00'));
    }

    public function test_same_campus_load_stays_a_capacity_question(): void
    {
        $teacher = $this->teacher();
        $date = Carbon::today()->addDays(7)->toDateString();
        $this->lesson($this->course($teacher, 1, '甲', 'one_on_three'), $date, '18:00', '20:00');
        $mine = $this->course($teacher, 1, '乙', 'one_on_three');

        $this->assertSame([], app(ScheduleGuardService::class)->validateCourseOccurrence($mine, 1, $date, '18:00', '20:00'));
    }

    public function test_a_weekly_course_at_another_campus_blocks_the_same_weekly_slot(): void
    {
        $teacher = $this->teacher();
        $this->course($teacher, 2, '甲', 'one_on_one', ['week' => 7, 'time' => '10:00']);
        $mine = $this->course($teacher, 1, '乙');
        $guard = app(ScheduleGuardService::class);
        $payload = fn (int $day, string $start) => [
            'teacher_id' => $teacher, 'class_type' => 'one_on_one', 'branch_id' => 1,
            'slots' => [['day_of_week' => $day, 'start_time' => $start, 'end_time' => Carbon::parse($start)->addHours(2)->format('H:i')]],
            'exclude_student_class_id' => (int) $mine->ID, 'exclude_student_id' => (int) $mine->StudentID,
            'start_date' => Carbon::today()->toDateString(),
        ];

        $conflicts = $guard->validateRecurringCourse($payload(7, '11:00'));
        $this->assertSame(['cross_campus'], array_column($conflicts, 'type'));
        $this->assertStringContainsString('每週日', $conflicts[0]['message']);
        $this->assertSame([], $guard->validateRecurringCourse($payload(6, '11:00')));
        $this->assertSame([], $guard->validateRecurringCourse($payload(7, '12:00')));
    }

    private function teacher(): int
    {
        $teacher = User::create([
            'LoginName' => 'cross-campus-teacher@example.com', 'Name' => '老師測試', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922000000', 'MustChangePassword' => false,
        ]);
        foreach ([1, 2] as $campus) {
            UserCampus::create(['CampusID' => $campus, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        }

        return (int) $teacher->id;
    }

    private function course(int $teacher, int $campus, string $student, string $type = 'one_on_one', array $extra = []): StudentClass
    {
        $studentId = Student::create([
            'name' => $student, 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ])->id;
        $id = DB::table('StudentClass')->insertGetId($extra + [
            'StudentID' => $studentId, 'TeacherID' => $teacher, 'ClassType' => $type,
            'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4, 'StartDate' => Carbon::today()->subMonth()->toDateString(),
            'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8,
            'UsedSessions' => 0, 'Charge' => 500, 'Pay' => 0, 'Paid' => 0, 'Rate' => 500, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        return StudentClass::findOrFail($id);
    }

    private function lesson(StudentClass $course, string $date, string $start, string $end, string $status = 'scheduled'): void
    {
        ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date,
            'StartTime' => $start . ':00', 'EndTime' => $end . ':00', 'Status' => $status,
        ]);
    }
}
