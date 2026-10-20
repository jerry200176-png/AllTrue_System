<?php

namespace Tests\Feature;

use App\Models\{Campus, Student, StudentClass};
use App\Services\ScheduleGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubstituteOccupancy4326Test extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider occupancyModes */
    public function test_substitute_frees_original_teacher_but_still_occupies_substitute(bool $v2, bool $materialized): void
    {
        config(['feature_flags.values.FEATURE_SCHEDULE_OCCURRENCE_V2' => $v2]);
        $campus = Campus::factory()->create();
        $student = Student::factory()->create(['CampusID' => $campus->id, 'name' => 'S-B']);
        $course = $this->course($student, 78171);
        if ($materialized) {
            DB::table('ClassSession')->insert(['StudentClassID' => $course->ID, 'SessionDate' => '2026-12-29',
                'StartTime' => '17:00', 'EndTime' => '19:00', 'Status' => 'scheduled']);
        }
        $row = ['student_id' => $student->id, 'student_course_id' => $course->ID,
            'teacher_id' => 78171, 'branch_id' => $campus->id, 'schedule_date' => '2026-12-29',
            'day_of_week' => 2, 'subject' => 'synthetic', 'start_time' => '17:00', 'end_time' => '19:00',
            'duration_hours' => 2, 'class_type' => 'one_on_two', 'status' => 'scheduled', 'type' => 'normal', 'deduction' => 0];
        // A leftover original-teacher projection at the same slot must not undo substitution.
        $anchorId = DB::table('schedules')->insertGetId($row);
        DB::table('schedules')->insert(array_replace($row, ['teacher_id' => 78172, 'original_schedule_id' => $anchorId,
            'original_schedule_date' => $v2 ? '2026-12-29' : null, 'original_start_time' => $v2 ? '17:00' : null]));

        $guard = app(ScheduleGuardService::class);
        $this->assertSame([], $guard->buildTeacherDateOccupancyEntries(78171, $campus->id, '2026-12-29'));
        $this->assertSame([], $guard->validateRecurringCourse([
            'teacher_id' => 78171, 'branch_id' => $campus->id, 'class_type' => 'one_on_one',
            'start_date' => '2026-12-29', 'end_date' => '2026-12-29',
            'slots' => [['day_of_week' => 2, 'start_time' => '17:00', 'end_time' => '19:00']],
        ]));
        $subEntries = $guard->buildTeacherDateOccupancyEntries(78172, $campus->id, '2026-12-29');
        $this->assertCount(1, $subEntries);
        $this->assertSame($student->id, $subEntries[0]['student_id']);
        // Incoming one-on-one cannot share the substitute's occupied interval.
        $payload = ['teacher_id' => 78172, 'branch_id' => $campus->id, 'schedule_date' => '2026-12-29',
            'start_time' => '18:00', 'end_time' => '20:00', 'class_type' => 'one_on_one'];
        $this->assertSame(1, $guard->validateScheduleOccurrence($payload)[0]['allowed_students']);
        $this->assertSame([], $guard->validateScheduleOccurrence(array_replace($payload, ['start_time' => '19:00', 'end_time' => '21:00'])));
    }

    public function test_same_day_atomic_reschedule_ignores_substituted_occupancy_and_preserves_real_capacity(): void
    {
        config(['feature_flags.values.FEATURE_SCHEDULE_OCCURRENCE_V2' => false]);
        $campus = Campus::factory()->create();
        $director = \App\Models\User::create(['LoginName' => 'dir-4326@example.test', 'Name' => 'D-A',
            'PSW' => 'x', 'type' => 'A', 'phone' => '0900004326', 'MustChangePassword' => false]);
        \App\Models\UserCampus::create(['CampusID' => $campus->id, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        \App\Models\AuthToken::create(['user_id' => $director->id, 'token' => 'synthetic-4326', 'expires_at' => now()->addDay()]);
        $courses = [];
        foreach (['S-A', 'S-B', 'S-C'] as $name) {
            $student = Student::factory()->create(['CampusID' => $campus->id, 'name' => $name]);
            $course = $this->course($student, 78171);
            $courses[] = $course;
            DB::table('ClassSession')->insert(['StudentClassID' => $course->ID, 'SessionDate' => '2026-12-29',
                'StartTime' => $name === 'S-A' ? '16:00' : '17:00',
                'EndTime' => $name === 'S-A' ? '18:00' : '19:00', 'Status' => 'scheduled']);
        }
        $row = ['student_id' => $courses[1]->StudentID, 'student_course_id' => $courses[1]->ID,
            'teacher_id' => 78171, 'branch_id' => $campus->id, 'schedule_date' => '2026-12-29',
            'day_of_week' => 2, 'subject' => 'synthetic', 'start_time' => '17:00', 'end_time' => '19:00',
            'duration_hours' => 2, 'class_type' => 'one_on_two', 'status' => 'scheduled', 'type' => 'normal', 'deduction' => 0];
        $anchor = DB::table('schedules')->insertGetId($row);
        DB::table('schedules')->insert(array_replace($row, ['teacher_id' => 78172, 'original_schedule_id' => $anchor]));
        $payload = ['student_class_id' => $courses[0]->ID, 'old_date' => '2026-12-29', 'old_start_time' => '16:00',
            'new_date' => '2026-12-29', 'start_time' => '17:00', 'end_time' => '19:00', 'ensure_schedule_exception' => true];
        $this->withHeaders(['Authorization' => 'Bearer synthetic-4326', 'Accept' => 'application/json'])
            ->postJson('/api/v1/learning-records/reschedule-session', $payload)->assertOk()->assertJsonPath('committed', true);
        $this->assertDatabaseHas('ClassSession', ['StudentClassID' => $courses[0]->ID, 'StartTime' => '17:00', 'EndTime' => '19:00']);

        // Another real student makes the same target genuinely full. A rejected
        // second move must preserve the source and every schedule projection.
        $student = Student::factory()->create(['CampusID' => $campus->id, 'name' => 'S-D']);
        $other = $this->course($student, 78171);
        DB::table('ClassSession')->insert(['StudentClassID' => $other->ID, 'SessionDate' => '2026-12-29',
            'StartTime' => '17:00', 'EndTime' => '19:00', 'Status' => 'scheduled']);
        $before = DB::table('schedules')->orderBy('id')->get()->toJson();
        $this->withHeaders(['Authorization' => 'Bearer synthetic-4326', 'Accept' => 'application/json'])
            ->postJson('/api/v1/learning-records/reschedule-session', array_replace($payload, ['old_start_time' => '17:00', 'start_time' => '17:30', 'end_time' => '19:30']))
            ->assertStatus(409)->assertJsonPath('code', 'schedule_conflict')
            ->assertJsonPath('conflicts.0.allowed_students', 2)->assertJsonPath('conflicts.0.current_students', 2);
        $this->assertSame($before, DB::table('schedules')->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('ClassSession', ['StudentClassID' => $courses[0]->ID, 'StartTime' => '17:00', 'EndTime' => '19:00']);
    }

    private function course(Student $student, int $teacherId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId,
            'ClassType' => 'one_on_two', 'by1' => 1, 'Period' => 4, 'Stop' => 0,
            'StartDate' => '2026-12-01', 'TotalHours' => 16, 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 8, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 0, 'Paid' => 0, 'Rate' => 200, 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
    }

    public static function occupancyModes(): array
    {
        return [[false, true], [false, false], [true, true], [true, false]];
    }
}
