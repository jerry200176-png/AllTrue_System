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

    /** #5631 (in-app #596): a makeup taken in the teacher's class at another campus is recorded with 調課 once it is over. */
    public function test_reschedule_into_an_ended_slot_at_another_campus_saves_with_a_reminder(): void
    {
        $teacher = $this->teacher();
        $past = Carbon::today()->subDays(3)->toDateString();
        $future = Carbon::today()->addDays(7)->toDateString();
        $this->lesson($this->course($teacher, 2, '甲', 'one_on_three'), $past, '18:00', '20:00');
        $mine = $this->course($teacher, 1, '乙');
        $this->lesson($mine, $future, '19:00', '21:00');

        $director = User::create([
            'LoginName' => 'cross-campus-director@example.com', 'Name' => '主任測試', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0922000001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        \App\Models\AuthToken::create(['user_id' => $director->id, 'token' => 'cross-campus-director-token', 'expires_at' => now()->addDay()]);

        $response = $this->withToken('cross-campus-director-token')->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => (int) $mine->ID, 'ensure_schedule_exception' => true,
            'old_date' => $future, 'old_start_time' => '19:00:00',
            'new_date' => $past, 'start_time' => '18:00:00', 'end_time' => '20:00:00',
        ]);

        $response->assertOk();
        $this->assertSame($past, substr((string) ClassSession::where('StudentClassID', $mine->ID)->value('SessionDate'), 0, 10));
        $this->assertStringContainsString('也有課', rawurldecode((string) $response->headers->get(ScheduleGuardService::PAST_CLASH_HEADER)));
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

    public function test_a_substituted_away_lesson_frees_the_contract_teacher_but_busies_the_substitute(): void
    {
        $owner = $this->teacher();
        $substitute = $this->teacher('cross-campus-substitute@example.com');
        $date = Carbon::today()->addDays(7)->toDateString();
        $course = $this->course($owner, 2, '甲');
        $this->lesson($course, $date, '17:00', '19:00');
        $row = ['student_id' => $course->StudentID, 'student_course_id' => $course->ID, 'teacher_id' => $owner, 'branch_id' => 2,
            'schedule_date' => $date, 'day_of_week' => Carbon::parse($date)->dayOfWeekIso, 'subject' => 'synthetic',
            'start_time' => '17:00', 'end_time' => '19:00', 'duration_hours' => 2, 'class_type' => 'one_on_one',
            'status' => 'scheduled', 'type' => 'normal', 'deduction' => 0];
        // A leftover contract-teacher projection stays next to the substitute row.
        $anchor = DB::table('schedules')->insertGetId($row);
        DB::table('schedules')->insert(array_replace($row, ['teacher_id' => $substitute, 'original_schedule_id' => $anchor]));
        $guard = app(ScheduleGuardService::class);
        $payload = fn (int $teacher) => ['teacher_id' => $teacher, 'branch_id' => 1, 'class_type' => 'one_on_one',
            'schedule_date' => $date, 'start_time' => '18:00', 'end_time' => '20:00'];

        $this->assertSame([], $guard->validateScheduleOccurrence($payload($owner)));
        $this->assertSame(['cross_campus'], array_column($guard->validateScheduleOccurrence($payload($substitute)), 'type'));
    }

    public function test_a_weekly_slot_is_blocked_by_a_one_off_lesson_at_another_campus(): void
    {
        $teacher = $this->teacher();
        $date = Carbon::today()->addDays(9)->toDateString();
        $this->lesson($this->course($teacher, 2, '補課生'), $date, '10:00', '12:00'); // no weekly template at campus 2
        $payload = fn (string $start) => ['teacher_id' => $teacher, 'class_type' => 'one_on_one', 'branch_id' => 1,
            'start_date' => Carbon::today()->toDateString(),
            'slots' => [['day_of_week' => Carbon::parse($date)->dayOfWeekIso, 'start_time' => $start, 'end_time' => Carbon::parse($start)->addHours(2)->format('H:i')]]];
        $guard = app(ScheduleGuardService::class);

        $conflicts = $guard->validateRecurringCourse($payload('11:00'));
        $this->assertSame(['cross_campus'], array_column($conflicts, 'type'));
        $this->assertSame($date, $conflicts[0]['schedule_date']);
        $this->assertSame([], $guard->validateRecurringCourse($payload('12:00')));
    }

    public function test_a_substitute_booking_at_an_unbound_campus_still_counts(): void
    {
        $owner = $this->teacher();
        $substitute = User::create(['LoginName' => 'unbound-sub@example.com', 'Name' => '代課', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922000001', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $substitute->id, 'Admin' => 0, 'Approved' => 1]); // bound to campus 1 only
        $date = Carbon::today()->addDays(7)->toDateString();
        $course = $this->course($owner, 2, '甲');
        $row = ['student_id' => $course->StudentID, 'student_course_id' => $course->ID, 'teacher_id' => $owner, 'branch_id' => 2,
            'schedule_date' => $date, 'day_of_week' => Carbon::parse($date)->dayOfWeekIso, 'subject' => 'synthetic',
            'start_time' => '17:00', 'end_time' => '19:00', 'duration_hours' => 2, 'class_type' => 'one_on_one',
            'status' => 'scheduled', 'type' => 'normal', 'deduction' => 0];
        $anchor = DB::table('schedules')->insertGetId($row);
        DB::table('schedules')->insert(array_replace($row, ['teacher_id' => $substitute->id, 'original_schedule_id' => $anchor]));

        $conflicts = app(ScheduleGuardService::class)->validateScheduleOccurrence(['teacher_id' => $substitute->id, 'branch_id' => 1,
            'class_type' => 'one_on_one', 'schedule_date' => $date, 'start_time' => '18:00', 'end_time' => '20:00']);
        $this->assertSame([['cross_campus', 2]], array_map(fn ($c) => [$c['type'], $c['campus_id']], $conflicts));
    }

    private function teacher(string $login = 'cross-campus-teacher@example.com'): int
    {
        $teacher = User::create([
            'LoginName' => $login, 'Name' => '老師測試', 'PSW' => 'secret',
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
