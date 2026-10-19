<?php

namespace Tests\Feature;

use App\Models\{Campus, Student, StudentClass};
use App\Services\ScheduleGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleGuardCurrentReadTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    private array $seededSubjectIds = [];
    private array $initialSubjectIds = [];
    private array $ownedStudentIds = [];
    private array $ownedCourseIds = [];
    private array $ownedCampusIds = [];
    private array $ownedRoomIds = [];
    private array $ownedScheduleIds = [];

    protected function afterRefreshingDatabase(): void
    {
        // TestCase seeds subjects after RefreshDatabase. Preserve any pre-existing rows.
        $this->initialSubjectIds = DB::table('Subject')->pluck('id')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seededSubjectIds = array_values(array_diff(DB::table('Subject')->pluck('id')->all(), $this->initialSubjectIds));
    }

    protected function tearDown(): void
    {
        try {
            DB::table('LearningRecord')->whereIn('StudentClassID', $this->ownedCourseIds)->delete();
            DB::table('schedules')->whereIn('student_course_id', $this->ownedCourseIds)->delete();
            DB::table('schedules')->whereIn('id', $this->ownedScheduleIds)->delete();
            DB::table('ClassSession')->whereIn('StudentClassID', $this->ownedCourseIds)->delete();
            DB::table('StudentClass')->whereIn('ID', $this->ownedCourseIds)->delete();
            DB::table('Student')->whereIn('id', $this->ownedStudentIds)->delete();
            DB::table('rooms')->whereIn('id', $this->ownedRoomIds)->delete();
            DB::table('Campus')->whereIn('id', $this->ownedCampusIds)->delete();
            DB::table('Subject')->whereIn('id', $this->seededSubjectIds)->delete();
            $this->assertSame(0, DB::table('StudentClass')->whereIn('ID', $this->ownedCourseIds)->count());
            $this->assertSame(0, DB::table('Student')->whereIn('id', $this->ownedStudentIds)->count());
            $this->assertSame(0, DB::table('Campus')->whereIn('id', $this->ownedCampusIds)->count());
            $this->assertSame(0, DB::table('Subject')->whereIn('id', $this->seededSubjectIds)->count());
        } finally {
            // Only owned data changed; the base schema remains valid for later tests.
            parent::tearDown();
        }
    }

    public function test_current_guard_sees_commits_after_a_proven_stale_snapshot(): void
    {
        $original = DB::getDefaultConnection();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        config(['database.connections.occupancy_current' => config('database.connections.' . $original)]);
        $reader = DB::connection('occupancy_current');
        $campus = Campus::factory()->create();
        $this->ownedCampusIds[] = $campus->id;
        $targetTeacher = 78171;
        $date = '2026-12-29';
        try {
            foreach (['class_session', 'schedule', 'reactivated', 'makeup_substitute', 'returned_teacher', 'room_capacity'] as $kind) {
                config(['feature_flags.values.FEATURE_SCHEDULE_OCCURRENCE_V2' => in_array($kind, ['makeup_substitute', 'returned_teacher'], true)]);
                $student = Student::factory()->create(['CampusID' => $campus->id]);
                $this->ownedStudentIds[] = $student->id;
                $roomId = $kind === 'room_capacity' ? DB::table('rooms')->insertGetId(['campus_id' => $campus->id, 'name' => 'synthetic room', 'capacity' => 4]) : null;
                if ($roomId) $this->ownedRoomIds[] = $roomId;
                $classType = $roomId ? 'one_on_three' : 'one_on_one';
                $course = StudentClass::create([
                    'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
                    'TeacherID' => $kind === 'makeup_substitute' ? $targetTeacher + 1 : $targetTeacher,
                    'ClassType' => $classType, 'room_id' => $roomId, 'by1' => 1, 'Period' => 4, 'Stop' => 0,
                    'StartDate' => '2026-12-01', 'TotalHours' => 16, 'SessionCount' => 8,
                    'SessionDuration' => 120, 'RemainingSessions' => 8, 'UsedSessions' => 0,
                    'Charge' => 1600, 'Pay' => 0, 'Paid' => 0, 'Rate' => 200,
                    'MDate' => now(), 'ScheduleMode' => 'count',
                ]);
                $this->ownedCourseIds[] = $course->ID;
                $slot = ['StudentClassID' => $course->ID, 'SessionDate' => $date,
                    'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled'];
                $schedule = ['student_course_id' => $course->ID, 'student_id' => $student->id,
                    'teacher_id' => $targetTeacher, 'branch_id' => $campus->id,
                    'schedule_date' => $date, 'day_of_week' => 2, 'subject' => 'synthetic',
                    'start_time' => '13:00', 'end_time' => '15:00', 'duration_hours' => 2,
                    'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => 'extra', 'deduction' => 1];
                $sessionId = $scheduleId = $recordId = null;
                if ($kind === 'room_capacity') {
                    $sessionId = DB::table('ClassSession')->insertGetId($slot);
                } elseif ($kind === 'reactivated') {
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
                    'schedule_date' => $date, 'start_time' => '13:00', 'end_time' => '15:00', 'class_type' => $classType, 'room_id' => $roomId];
                $this->assertSame([], $guard->validateScheduleOccurrence($payload), $kind . ': initial slot free');
                DB::setDefaultConnection($original);
                if ($roomId) DB::table('rooms')->where('id', $roomId)->update(['capacity' => 2]);
                if ($kind === 'class_session') $sessionId = DB::table('ClassSession')->insertGetId($slot);
                if ($kind === 'schedule') $scheduleId = DB::table('schedules')->insertGetId($schedule);
                if ($kind === 'reactivated') DB::table('ClassSession')->where('id', $sessionId)->update(['Status' => 'scheduled']);
                if ($kind === 'makeup_substitute') DB::table('LearningRecord')->where('id', $recordId)->update(['TeacherID' => $targetTeacher]);
                if ($kind === 'returned_teacher') DB::table('schedules')->where('id', $scheduleId)->update(['teacher_id' => $targetTeacher]);
                DB::setDefaultConnection('occupancy_current');
                $this->assertSame([], $guard->validateScheduleOccurrence($payload), $kind . ': prove snapshot remains stale');
                $conflicts = $guard->validateScheduleOccurrence($payload, true);
                $this->assertSame($roomId ? 'room_capacity' : 'teacher_capacity', $conflicts[0]['type'] ?? null, $kind . ': current read sees conflict');
                $proposed = new StudentClass(['TeacherID' => $targetTeacher, 'ClassType' => $classType, 'room_id' => $roomId]);
                $this->assertSame($roomId ? 'room_capacity' : 'teacher_capacity', $guard->validateCourseOccurrence($proposed, $campus->id, $date, '13:00', '15:00', true)[0]['type'] ?? null);
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
    public function test_current_schedule_scan_does_not_block_another_campus(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.occupancy_scope' => config('database.connections.' . $original)]);
        $reader = DB::connection('occupancy_scope');
        $timeout = (int) DB::selectOne('SELECT @@innodb_lock_wait_timeout AS value')->value;
        $campuses = [Campus::factory()->create(), Campus::factory()->create()];
        $payloads = [];
        foreach ($campuses as $campus) {
            $this->ownedCampusIds[] = $campus->id;
            $student = Student::factory()->create(['CampusID' => $campus->id]);
            $this->ownedStudentIds[] = $student->id;
            $payload = ['student_id' => $student->id, 'teacher_id' => 78171, 'branch_id' => $campus->id,
                'schedule_date' => '2026-12-29', 'day_of_week' => 2, 'subject' => 'synthetic',
                'start_time' => '13:00', 'end_time' => '15:00', 'duration_hours' => 2,
                'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => 'extra', 'deduction' => 1];
            $this->ownedScheduleIds[] = DB::table('schedules')->insertGetId($payload);
            $payloads[] = $payload;
        }
        try {
            $reader->beginTransaction();
            DB::setDefaultConnection('occupancy_scope');
            $this->assertSame('teacher_capacity', app(ScheduleGuardService::class)->validateScheduleOccurrence($payloads[0], true)[0]['type']);
            DB::setDefaultConnection($original);
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            $this->ownedScheduleIds[] = DB::table('schedules')->insertGetId($payloads[1]);
            $this->assertCount(3, $this->ownedScheduleIds, 'unrelated-campus insert completes while original scan remains locked');
        } finally {
            while ($reader->transactionLevel() > 0) $reader->rollBack();
            DB::setDefaultConnection($original);
            DB::statement('SET SESSION innodb_lock_wait_timeout = ' . $timeout);
            DB::purge('occupancy_scope');
        }
    }

    private function currentReadFixture(int $campusId, int $teacherId, array $changes = []): StudentClass
    {
        $student = Student::factory()->create(['CampusID' => $campusId]);
        $this->ownedStudentIds[] = $student->id;
        $course = StudentClass::create(array_replace([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one', 'by1' => 1, 'Period' => 4, 'Stop' => 0,
            'StartDate' => '2026-12-01', 'TotalHours' => 16, 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 8, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 0, 'Paid' => 0, 'Rate' => 200, 'MDate' => now(), 'ScheduleMode' => 'count',
        ], $changes));
        $this->ownedCourseIds[] = $course->ID;
        return $course;
    }

    /** @dataProvider unrelatedCourseScopes */
    public function test_current_class_session_scan_does_not_lock_unrelated_course_or_session(bool $sameCampus, bool $sameStudent): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.occupancy_scope' => config('database.connections.' . $original)]);
        $reader = DB::connection('occupancy_scope');
        $timeout = (int) DB::selectOne('SELECT @@innodb_lock_wait_timeout AS value')->value;
        $courses = [];
        foreach ([0, 1] as $i) {
            $campusId = $sameCampus && $i ? $courses[0][1] : Campus::factory()->create()->id;
            $this->ownedCampusIds[] = $campusId;
            $course = $this->currentReadFixture($campusId, $sameCampus && $i ? 78172 : 78171,
                $sameStudent && $i ? ['StudentID' => $courses[0][0]->StudentID] : []);
            $id = DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID,
                'SessionDate' => '2026-12-29', 'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled']);
            $courses[] = [$course, $campusId, $id];
        }
        // A sparse teacher over a populated campus makes the teacher-first join
        // a real optimizer choice, rather than relying on a two-row toy plan.
        for ($i = 0; $i < 20; $i++) $this->currentReadFixture($courses[0][1], 79000 + $i);
        try {
            $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $reader->beginTransaction();
            DB::setDefaultConnection('occupancy_scope');
            $entries = app(ScheduleGuardService::class)->buildTeacherDateOccupancyEntries(78171, $courses[0][1], '2026-12-29', null, null, null, null, null, true);
            $this->assertCount(1, $entries);
            DB::setDefaultConnection($original);
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            $this->assertSame(1, DB::table('StudentClass')->where('ID', $courses[1][0]->ID)->update(['Period' => 5]));
            $this->assertSame(1, DB::table('ClassSession')->where('id', $courses[1][2])->update(['EndTime' => '16:00']));
        } finally {
            while ($reader->transactionLevel() > 0) $reader->rollBack();
            DB::setDefaultConnection($original);
            DB::statement('SET SESSION innodb_lock_wait_timeout = ' . $timeout);
            DB::purge('occupancy_scope');
        }
    }

    public static function unrelatedCourseScopes(): array { return [[false, false], [true, false], [true, true]]; }

    public function test_current_makeup_scan_does_not_lock_another_teachers_schedule(): void
    {
        config(['feature_flags.values.FEATURE_SCHEDULE_OCCURRENCE_V2' => true]);
        $original = DB::getDefaultConnection();
        config(['database.connections.occupancy_scope' => config('database.connections.' . $original)]);
        $reader = DB::connection('occupancy_scope');
        $timeout = (int) DB::selectOne('SELECT @@innodb_lock_wait_timeout AS value')->value;
        $campus = Campus::factory()->create();
        $this->ownedCampusIds[] = $campus->id;
        $schedules = [];
        foreach ([78171, 78172] as $teacherId) {
            $course = $this->currentReadFixture($campus->id, $teacherId + 10);
            $sessionId = DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID,
                'SessionDate' => '2026-12-29', 'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled']);
            DB::table('LearningRecord')->insert(['StudentClassID' => $course->ID, 'ClassSessionID' => $sessionId,
                'TeacherID' => $teacherId, 'Status' => 'pending', 'Content' => '', 'SessionDate' => '2026-12-29',
                'StartTime' => '13:00', 'EndTime' => '15:00']);
            $schedules[] = DB::table('schedules')->insertGetId(['student_course_id' => $course->ID, 'student_id' => $course->StudentID,
                'teacher_id' => $teacherId + 10, 'branch_id' => $campus->id, 'schedule_date' => '2026-12-29',
                'day_of_week' => 2, 'subject' => 'synthetic', 'start_time' => '13:00', 'end_time' => '15:00',
                'duration_hours' => 2, 'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => 'extra', 'deduction' => 1]);
        }
        $optimizer = (string) $reader->selectOne('SELECT @@optimizer_switch AS value')->value;
        try {
            // Correlated EXISTS is also a supported plan. Lock scope must not
            // depend on the optimizer choosing a semijoin/materialized rewrite.
            $reader->statement("SET SESSION optimizer_switch = 'semijoin=off,materialization=off'");
            $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $reader->beginTransaction();
            DB::setDefaultConnection('occupancy_scope');
            $rows = \App\Services\SubstituteScheduleService::makeupOccurrencesTaughtBy(78171, '2026-12-29', [], null, $campus->id, true);
            $this->assertCount(1, $rows);
            $this->assertSame($schedules[0], (int) $rows[0]->id);
            DB::setDefaultConnection($original);
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            $this->assertSame(1, DB::table('schedules')->where('id', $schedules[1])->update(['end_time' => '16:00']));
        } finally {
            while ($reader->transactionLevel() > 0) $reader->rollBack();
            DB::setDefaultConnection($original);
            DB::statement('SET SESSION innodb_lock_wait_timeout = ' . $timeout);
            $reader->statement('SET SESSION optimizer_switch = ?', [$optimizer]);
            DB::purge('occupancy_scope');
        }
    }

    public function test_current_course_guard_refreshes_proposed_teacher_type_and_room(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.occupancy_current' => config('database.connections.' . $original)]);
        $reader = DB::connection('occupancy_current');
        $campus = Campus::factory()->create();
        $this->ownedCampusIds[] = $campus->id;
        try {
            foreach (['teacher', 'class_type', 'room'] as $kind) {
                $room = $kind === 'room' ? DB::table('rooms')->insertGetId(['campus_id' => $campus->id, 'name' => 'synthetic fresh room', 'capacity' => 1]) : null;
                if ($room) $this->ownedRoomIds[] = $room;
                $teacher = 78200 + count($this->ownedCourseIds);
                $proposed = $this->currentReadFixture($campus->id, $teacher, ['ClassType' => 'one_on_three']);
                $other = $this->currentReadFixture($campus->id, $kind === 'teacher' ? $teacher + 100 : $teacher,
                    ['ClassType' => 'one_on_three', 'room_id' => $room]);
                DB::table('ClassSession')->insert(['StudentClassID' => $other->ID, 'SessionDate' => '2026-12-29',
                    'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled']);
                $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $reader->beginTransaction();
                DB::setDefaultConnection('occupancy_current');
                $stale = StudentClass::findOrFail($proposed->ID);
                $guard = app(ScheduleGuardService::class);
                $this->assertSame([], $guard->validateCourseOccurrence($stale, $campus->id, '2026-12-29', '13:00', '15:00'));
                DB::setDefaultConnection($original);
                $changes = match ($kind) { 'teacher' => ['TeacherID' => $teacher + 100, 'ClassType' => 'one_on_one'],
                    'class_type' => ['ClassType' => 'one_on_one'], 'room' => ['room_id' => $room] };
                DB::table('StudentClass')->where('ID', $proposed->ID)->update($changes);
                DB::setDefaultConnection('occupancy_current');
                $this->assertSame([], $guard->validateCourseOccurrence($stale, $campus->id, '2026-12-29', '13:00', '15:00'), 'unlocked model remains stale');
                $conflicts = $guard->validateCourseOccurrence($stale, $campus->id, '2026-12-29', '13:00', '15:00', true);
                $this->assertSame($room ? 'room_capacity' : 'teacher_capacity', $conflicts[0]['type'] ?? null, $kind . ': proposed metadata must be current');
                $reader->rollBack();
                DB::setDefaultConnection($original);
            }
        } finally {
            while ($reader->transactionLevel() > 0) $reader->rollBack();
            DB::setDefaultConnection($original);
            DB::purge('occupancy_current');
        }
    }

    public function test_current_course_guard_refuses_a_concurrently_deleted_proposed_course(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.occupancy_current' => config('database.connections.' . $original)]);
        $reader = DB::connection('occupancy_current');
        $campus = Campus::factory()->create();
        $this->ownedCampusIds[] = $campus->id;
        $course = $this->currentReadFixture($campus->id, 78300);
        try {
            $reader->beginTransaction();
            DB::setDefaultConnection('occupancy_current');
            $stale = StudentClass::findOrFail($course->ID);
            DB::setDefaultConnection($original);
            DB::table('StudentClass')->where('ID', $course->ID)->delete();
            DB::setDefaultConnection('occupancy_current');
            $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
            app(ScheduleGuardService::class)->validateCourseOccurrence($stale, $campus->id, '2026-12-29', '13:00', '15:00', true);
        } finally {
            while ($reader->transactionLevel() > 0) $reader->rollBack();
            DB::setDefaultConnection($original);
            DB::purge('occupancy_current');
        }
    }

    /** @dataProvider provenanceReaders */
    public function test_current_provenance_locks_do_not_block_another_day_of_the_same_course(string $kind): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.occupancy_scope' => config('database.connections.' . $original),
            'feature_flags.values.FEATURE_SCHEDULE_OCCURRENCE_V2' => true]);
        $reader = DB::connection('occupancy_scope');
        $timeout = (int) DB::selectOne('SELECT @@innodb_lock_wait_timeout AS value')->value;
        $campus = Campus::factory()->create();
        $this->ownedCampusIds[] = $campus->id;
        $optimizer = (string) $reader->selectOne('SELECT @@optimizer_switch AS value')->value;
        try {
            $reader->statement("SET SESSION optimizer_switch = 'index_condition_pushdown=off'");
            {
                $course = $this->currentReadFixture($campus->id, 78400);
                $sessions = $records = $schedules = [];
                foreach (['2026-12-29', '2026-12-30'] as $date) {
                    $sessions[] = $session = DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID,
                        'SessionDate' => $date, 'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled']);
                    $records[] = DB::table('LearningRecord')->insertGetId(['StudentClassID' => $course->ID,
                        'ClassSessionID' => $session, 'TeacherID' => 78401, 'Status' => 'pending', 'Content' => '',
                        'SessionDate' => $kind === 'makeup' ? '2025-01-01' : $date, 'StartTime' => '13:00', 'EndTime' => '15:00']);
                    $schedules[] = DB::table('schedules')->insertGetId(['student_course_id' => $course->ID,
                        'student_id' => $course->StudentID, 'teacher_id' => 78400, 'branch_id' => $campus->id,
                        'schedule_date' => $date, 'day_of_week' => 2, 'subject' => 'synthetic', 'start_time' => '13:00',
                        'end_time' => '15:00', 'duration_hours' => 2, 'class_type' => 'one_on_one', 'status' => 'scheduled',
                        'type' => $kind === 'makeup' ? 'extra' : 'regular', 'deduction' => 1,
                        'original_schedule_date' => $kind === 'occurrence' ? $date : null,
                        'original_start_time' => $kind === 'occurrence' ? '13:00' : null]);
                }
                $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $reader->beginTransaction();
                DB::setDefaultConnection('occupancy_scope');
                if ($kind === 'stale') {
                    $rows = app(\App\Services\StaleScheduleExceptionFilter::class)->rejectStale([(object) [
                        'student_course_id' => $course->ID, 'start_time' => '13:00']], '2026-12-29', true);
                    $this->assertCount(1, $rows);
                } elseif ($kind === 'occurrence') {
                    $this->assertNotNull(\App\Services\SubstituteScheduleService::occurrence($course->ID, '2026-12-29', '13:00', true));
                } else {
                    $this->assertCount(1, \App\Services\SubstituteScheduleService::makeupOccurrencesTaughtBy(78401, '2026-12-29', [], null, $campus->id, true));
                }
                DB::setDefaultConnection($original);
                DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
                $updated = match ($kind) {
                    'stale' => DB::table('ClassSession')->where('id', $sessions[1])->update(['EndTime' => '16:00']),
                    'occurrence' => DB::table('schedules')->where('id', $schedules[1])->update(['end_time' => '16:00']),
                    'makeup' => DB::table('LearningRecord')->where('id', $records[1])->update(['Content' => 'unrelated day']),
                };
                $this->assertSame(1, $updated, $kind . ': unrelated date remains writable');
                $reader->rollBack();
                DB::setDefaultConnection($original);
            }
        } finally {
            while ($reader->transactionLevel() > 0) $reader->rollBack();
            DB::setDefaultConnection($original);
            DB::statement('SET SESSION innodb_lock_wait_timeout = ' . $timeout);
            $reader->statement('SET SESSION optimizer_switch = ?', [$optimizer]);
            DB::purge('occupancy_scope');
        }
    }

    public static function provenanceReaders(): array
    {
        return ['stale' => ['stale'], 'occurrence' => ['occurrence'], 'makeup' => ['makeup']];
    }

}
