<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
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

/** Synthetic investigation, not a reconstruction of the unidentified report. */
class Reported423SlotReleaseInvestigationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @dataProvider modes */
    public function test_stop_releases_only_that_courses_teacher_slot(bool $v2, bool $materialized): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-20 08:00:00', 'Asia/Taipei'));
        config(['feature_flags.values.FEATURE_SCHEDULE_OCCURRENCE_V2' => $v2]);
        $campus = Campus::factory()->create();
        $director = User::create(['LoginName' => 'director-423@example.test', 'Name' => 'D', 'PSW' => 'x', 'type' => 'A', 'phone' => '0900000423']);
        $teacher = User::create(['LoginName' => 'teacher-423@example.test', 'Name' => 'T', 'PSW' => 'x', 'type' => 'T', 'phone' => '0900000424']);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        AuthToken::create(['user_id' => $director->id, 'token' => 'synthetic-423', 'expires_at' => now()->addDay()]);
        $student = Student::factory()->create(['CampusID' => $campus->id, 'name' => 'Before']);
        $course = $this->course($student, $teacher->id);
        $room = DB::table('rooms')->insertGetId(['name' => 'Synthetic room', 'campus_id' => $campus->id, 'capacity' => 2]);
        DB::table('StudentClass')->where('ID', $course->ID)->update(['room_id' => $room]);
        $history = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-12-15', 'StartTime' => '17:00', 'EndTime' => '19:00', 'Status' => 'attended']);
        if ($materialized) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-12-29', 'StartTime' => '17:00', 'EndTime' => '19:00', 'Status' => 'scheduled']);
        }
        $anchor = DB::table('schedules')->insertGetId(['student_id' => $student->id, 'student_course_id' => $course->ID,
            'teacher_id' => $teacher->id, 'branch_id' => $campus->id, 'schedule_date' => '2026-12-29',
            'day_of_week' => 2, 'subject' => 'synthetic', 'start_time' => '17:00', 'end_time' => '19:00',
            'duration_hours' => 2, 'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => 'normal', 'deduction' => 0]);
        $other = Student::factory()->create(['CampusID' => $campus->id, 'name' => 'Other']);
        $otherCourse = $this->course($other, $teacher->id);
        ClassSession::create(['StudentClassID' => $otherCourse->ID, 'SessionDate' => '2026-12-29', 'StartTime' => '19:00', 'EndTime' => '21:00', 'Status' => 'scheduled']);
        $headers = ['Authorization' => 'Bearer synthetic-423', 'Accept' => 'application/json'];
        $guard = app(ScheduleGuardService::class);
        $payload = ['teacher_id' => $teacher->id, 'branch_id' => $campus->id, 'schedule_date' => '2026-12-29', 'start_time' => '17:00', 'end_time' => '19:00', 'class_type' => 'one_on_one'];
        $starts = fn () => array_column($this->withHeaders($headers)->getJson("/api/v1/teachers/{$teacher->id}/availability?date=2026-12-29")->assertOk()->json('busy_slots'), 'start_time');

        $this->assertContains('17:00', $starts());
        $this->assertNotEmpty($guard->validateScheduleOccurrence($payload));
        $this->assertContains('room_capacity', array_column($guard->validateScheduleOccurrence($payload + ['room_id' => $room]), 'type'));
        // A display-name change alone does not end or move the course.
        $student->name = 'After';
        $student->save();
        $this->assertContains('17:00', $starts());
        $this->assertNotEmpty($guard->validateScheduleOccurrence($payload));

        $this->withHeaders($headers)->putJson("/api/v1/student-classes/{$course->ID}", ['status' => 'inactive'])->assertOk();
        $this->assertSame(1, (int) $course->fresh()->Stop);
        if ($materialized) {
            $this->assertDatabaseHas('ClassSession', ['StudentClassID' => $course->ID, 'SessionDate' => '2026-12-29', 'Status' => 'cancelled']);
        }
        $this->assertSame('attended', $history->fresh()->Status);
        $this->assertNotContains('17:00', $starts());
        $this->assertContains('19:00', $starts());
        $this->assertNotEmpty($guard->validateScheduleOccurrence(array_replace($payload, ['start_time' => '19:00', 'end_time' => '21:00'])));
        // Ambiguous extra and linked rows, plus a row with no course identity,
        // remain conservative: this change must not silently free their slots.
        foreach ([['13:00', '14:00', 'extra', null, $course->ID],
            ['14:00', '15:00', 'normal', $anchor, $course->ID],
            ['15:00', '16:00', 'normal', null, null]] as [$start, $end, $type, $original, $courseId]) {
            DB::table('schedules')->insert(['student_id' => $student->id, 'student_course_id' => $courseId,
                'teacher_id' => $teacher->id, 'branch_id' => $campus->id, 'schedule_date' => '2026-12-29',
                'day_of_week' => 2, 'subject' => 'synthetic', 'start_time' => $start, 'end_time' => $end,
                'duration_hours' => 1, 'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => $type,
                'original_schedule_id' => $original, 'deduction' => 0]);
            $this->assertNotEmpty($guard->validateScheduleOccurrence(array_replace($payload, ['start_time' => $start, 'end_time' => $end])));
        }
        $recurring = $payload + ['start_date' => '2026-12-29', 'end_date' => '2026-12-29',
            'slots' => [['day_of_week' => 2, 'start_time' => '17:00', 'end_time' => '19:00']]];
        $counts = [count($guard->validateScheduleOccurrence($payload)),
            count(DB::transaction(fn () => $guard->validateScheduleOccurrence($payload, true))),
            count($guard->validateRecurringCourse($recurring)),
            count($guard->validateScheduleOccurrence($payload + ['room_id' => $room])),
            count($guard->validateRecurringCourse($recurring + ['room_id' => $room]))];
        $this->assertSame([0, 0, 0, 0, 0], $counts, 'stopped slot must be free in unlocked, locked and recurring validation');
    }

    private function course(Student $student, int $teacherId): StudentClass
    {
        return StudentClass::create(['StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one', 'by1' => 1, 'Period' => 4, 'Stop' => 0,
            'StartDate' => '2026-12-01', 'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'Charge' => 1600, 'Pay' => 0, 'Paid' => 0,
            'Rate' => 200, 'MDate' => now(), 'ScheduleMode' => 'count']);
    }

    public static function modes(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }
}
