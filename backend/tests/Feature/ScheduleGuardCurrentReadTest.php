<?php

namespace Tests\Feature;

use App\Models\{Campus, Student, StudentClass};
use App\Services\ScheduleGuardService;
use Illuminate\Foundation\Testing\{RefreshDatabase, RefreshDatabaseState};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleGuardCurrentReadTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        try { parent::tearDown(); }
        finally {
            // This committed two-connection fixture must not leak base seeds to transactional tests.
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_current_guard_sees_commits_after_a_proven_stale_snapshot(): void
    {
        $original = DB::getDefaultConnection();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        config(['database.connections.occupancy_current' => config('database.connections.' . $original)]);
        $reader = DB::connection('occupancy_current');
        $campus = Campus::factory()->create();
        $targetTeacher = 78171;
        $date = '2026-12-29';
        try {
            foreach (['class_session', 'schedule', 'reactivated', 'makeup_substitute', 'returned_teacher'] as $kind) {
                config(['feature_flags.values.FEATURE_SCHEDULE_OCCURRENCE_V2' => in_array($kind, ['makeup_substitute', 'returned_teacher'], true)]);
                $student = Student::factory()->create(['CampusID' => $campus->id]);
                $course = StudentClass::create([
                    'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
                    'TeacherID' => $kind === 'makeup_substitute' ? $targetTeacher + 1 : $targetTeacher,
                    'ClassType' => 'one_on_one', 'by1' => 1, 'Period' => 4, 'Stop' => 0,
                    'StartDate' => '2026-12-01', 'TotalHours' => 16, 'SessionCount' => 8,
                    'SessionDuration' => 120, 'RemainingSessions' => 8, 'UsedSessions' => 0,
                    'Charge' => 1600, 'Pay' => 0, 'Paid' => 0, 'Rate' => 200,
                    'MDate' => now(), 'ScheduleMode' => 'count',
                ]);
                $slot = ['StudentClassID' => $course->ID, 'SessionDate' => $date,
                    'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled'];
                $schedule = ['student_course_id' => $course->ID, 'student_id' => $student->id,
                    'teacher_id' => $targetTeacher, 'branch_id' => $campus->id,
                    'schedule_date' => $date, 'day_of_week' => 2, 'subject' => 'synthetic',
                    'start_time' => '13:00', 'end_time' => '15:00', 'duration_hours' => 2,
                    'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => 'extra', 'deduction' => 1];
                $sessionId = $scheduleId = $recordId = null;
                if ($kind === 'reactivated') {
                    $sessionId = DB::table('ClassSession')->insertGetId(array_replace($slot, ['Status' => 'cancelled']));
                    $scheduleId = DB::table('schedules')->insertGetId($schedule);
                } elseif ($kind === 'makeup_substitute') {
                    $sessionId = DB::table('ClassSession')->insertGetId($slot);
                    $scheduleId = DB::table('schedules')->insertGetId(array_replace($schedule, ['teacher_id' => $targetTeacher + 1]));
                    $recordId = DB::table('LearningRecord')->insertGetId([
                        'StudentClassID' => $course->ID, 'ClassSessionID' => $sessionId,
                        'TeacherID' => $targetTeacher + 1, 'Status' => 'pending', 'Content' => '',
                        'SessionDate' => $date, 'StartTime' => '13:00', 'EndTime' => '15:00',
                    ]);
                } elseif ($kind === 'returned_teacher') {
                    $sessionId = DB::table('ClassSession')->insertGetId($slot);
                    $scheduleId = DB::table('schedules')->insertGetId(array_replace($schedule, [
                        'teacher_id' => $targetTeacher + 1, 'type' => 'regular',
                        'original_schedule_date' => $date, 'original_start_time' => '13:00',
                    ]));
                }
                $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $reader->beginTransaction();
                DB::setDefaultConnection('occupancy_current');
                $guard = app(ScheduleGuardService::class);
                $payload = ['teacher_id' => $targetTeacher, 'branch_id' => $campus->id,
                    'schedule_date' => $date, 'start_time' => '13:00', 'end_time' => '15:00', 'class_type' => 'one_on_one'];
                $this->assertSame([], $guard->validateScheduleOccurrence($payload), $kind . ': initial slot free');
                DB::setDefaultConnection($original);
                if ($kind === 'class_session') $sessionId = DB::table('ClassSession')->insertGetId($slot);
                if ($kind === 'schedule') $scheduleId = DB::table('schedules')->insertGetId($schedule);
                if ($kind === 'reactivated') DB::table('ClassSession')->where('id', $sessionId)->update(['Status' => 'scheduled']);
                if ($kind === 'makeup_substitute') DB::table('LearningRecord')->where('id', $recordId)->update(['TeacherID' => $targetTeacher]);
                if ($kind === 'returned_teacher') DB::table('schedules')->where('id', $scheduleId)->update(['teacher_id' => $targetTeacher]);
                DB::setDefaultConnection('occupancy_current');
                $this->assertSame([], $guard->validateScheduleOccurrence($payload), $kind . ': prove snapshot remains stale');
                $conflicts = $guard->validateScheduleOccurrence($payload, true);
                $this->assertSame('teacher_capacity', $conflicts[0]['type'] ?? null, $kind . ': current read sees conflict');
                $proposed = new StudentClass(['TeacherID' => $targetTeacher, 'ClassType' => 'one_on_one']);
                $this->assertSame('teacher_capacity', $guard->validateCourseOccurrence($proposed, $campus->id, $date, '13:00', '15:00', true)[0]['type'] ?? null);
                $this->assertSame([], $guard->validateCourseOccurrence($course, $campus->id, $date, '13:00', '15:00', true), 'self exclusion survives current reads');
                $this->assertSame([], $guard->validateScheduleOccurrence(array_replace($payload, ['branch_id' => $campus->id + 100000]), true), 'campus scope survives current reads');
                $reader->rollBack();
                DB::setDefaultConnection($original);
                if ($recordId) DB::table('LearningRecord')->where('id', $recordId)->delete();
                if ($scheduleId) DB::table('schedules')->where('id', $scheduleId)->delete();
                if ($sessionId) DB::table('ClassSession')->where('id', $sessionId)->delete();
            }
        } finally {
            while ($reader->transactionLevel() > 0) $reader->rollBack();
            DB::setDefaultConnection($original);
            DB::purge('occupancy_current');
        }
    }
}
