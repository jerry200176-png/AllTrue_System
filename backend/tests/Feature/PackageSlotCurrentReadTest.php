<?php

namespace Tests\Feature;

use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\SessionDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4454: callers wrap the deduction in a transaction that has already read, so under REPEATABLE READ its snapshot
 * predates the package lock. Two members of one group slot attending at once must still debit the slot once.
 * Runs without the test transaction (a second connection must see real commits) and deletes only its own rows.
 */
class PackageSlotCurrentReadTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    private string $originalConnection;
    private int $lockTimeout;
    private array $initialSubjectIds = [];
    private array $seededSubjectIds = [];
    private array $ownedStudentIds = [];
    private array $ownedCourseIds = [];
    private array $ownedPackageIds = [];

    protected function afterRefreshingDatabase(): void
    {
        $this->initialSubjectIds = DB::table('Subject')->pluck('id')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seededSubjectIds = array_values(array_diff(DB::table('Subject')->pluck('id')->all(), $this->initialSubjectIds));
        $this->originalConnection = DB::getDefaultConnection();
        $this->lockTimeout = (int) DB::selectOne('SELECT @@innodb_lock_wait_timeout AS value')->value;
        config(['database.connections.package_current' => config('database.connections.' . $this->originalConnection)]);
    }

    protected function tearDown(): void
    {
        $reader = DB::connection('package_current');
        while ($reader->transactionLevel() > 0) {
            $reader->rollBack();
        }
        DB::purge('package_current');
        DB::setDefaultConnection($this->originalConnection);
        DB::statement('SET SESSION innodb_lock_wait_timeout = ' . $this->lockTimeout);
        try {
            DB::table('package_session_ledger')->whereIn('package_id', $this->ownedPackageIds)->delete();
            DB::table('session_deduction_ledger')->whereIn('student_class_id', $this->ownedCourseIds)->delete();
            DB::table('ClassSession')->whereIn('StudentClassID', $this->ownedCourseIds)->delete();
            DB::table('StudentClass')->whereIn('ID', $this->ownedCourseIds)->delete();
            DB::table('course_packages')->whereIn('id', $this->ownedPackageIds)->delete();
            DB::table('Student')->whereIn('id', $this->ownedStudentIds)->delete();
            DB::table('Subject')->whereIn('id', $this->seededSubjectIds)->delete();
            $this->assertSame(0, DB::table('StudentClass')->whereIn('ID', $this->ownedCourseIds)->count());
        } finally {
            parent::tearDown();
        }
    }

    public function test_slot_check_sees_a_debit_committed_after_the_callers_snapshot(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $pkg = $this->makePackage();
        [$first, $second] = [$this->makeMember($pkg), $this->makeMember($pkg)];
        $lesson = fn (int $courseId) => DB::table('ClassSession')->insertGetId(['StudentClassID' => $courseId,
            'SessionDate' => '2026-04-16', 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended']);
        [$firstLesson, $secondLesson] = [$lesson($first), $lesson($second)];

        // The second member's request: its transaction reads first (as ClassSessionController::update does).
        $reader = DB::connection('package_current');
        $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $reader->beginTransaction();
        $this->assertSame(0, $reader->table('package_session_ledger')->where('package_id', $pkg)->count());

        // Meanwhile the first member's attendance commits the slot debit.
        $this->assertTrue(SessionDeductionService::deductForSession($first, $firstLesson, 'attendance'));
        $this->assertSame(0, $reader->table('package_session_ledger')->where('package_id', $pkg)->count(),
            'precondition: the second request still reads its older snapshot');

        DB::setDefaultConnection('package_current');
        try {
            $this->assertTrue(SessionDeductionService::deductForSession($second, $secondLesson, 'attendance'));
            $reader->commit();
        } finally {
            DB::setDefaultConnection($this->originalConnection);
        }

        $this->assertSame(-1, (int) DB::table('package_session_ledger')->where('package_id', $pkg)->sum('delta'),
            'one group lesson debits the shared package once');
        $this->assertSame(0, DB::table('package_session_ledger')->where('class_session_id', $secondLesson)->count());
        $this->assertSame(9, (int) DB::table('course_packages')->where('id', $pkg)->value('remaining_sessions'),
            'the counter is recomputed from current rows, not the snapshot');
    }

    public function test_the_current_read_does_not_hold_up_another_package_debiting_the_next_lesson(): void
    {
        // Lessons created together get adjacent ids; a lock on a class_session_id range would make them wait on each other.
        [$a, $b] = [$this->makePackage(), $this->makePackage()];
        [$memberA, $memberB] = [$this->makeMember($a), $this->makeMember($b)];
        $lesson = fn (int $courseId, string $date) => DB::table('ClassSession')->insertGetId(['StudentClassID' => $courseId,
            'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended']);
        SessionDeductionService::deductForSession($memberA, $lesson($memberA, '2026-04-09'), 'attendance');
        SessionDeductionService::deductForSession($memberB, $lesson($memberB, '2026-04-09'), 'attendance');
        [$nextA, $nextB] = [$lesson($memberA, '2026-04-16'), $lesson($memberB, '2026-04-16')];

        $reader = DB::connection('package_current');
        $reader->beginTransaction();
        DB::setDefaultConnection('package_current');
        try {
            $this->assertTrue(SessionDeductionService::deductForSession($memberA, $nextA, 'attendance'));
        } finally {
            DB::setDefaultConnection($this->originalConnection);
        }

        // Package A's transaction is still open, holding its locks; package B must not wait for it.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
        $this->assertTrue(SessionDeductionService::deductForSession($memberB, $nextB, 'attendance'));
        $reader->commit();

        foreach ([$a, $b] as $pkg) {
            $this->assertSame(-2, (int) DB::table('package_session_ledger')->where('package_id', $pkg)->sum('delta'));
        }
    }

    private function makePackage(): int
    {
        $pkg = CoursePackage::create([
            'student_id' => $this->makeStudent(), 'campus_id' => 1, 'name' => '並發時段方案',
            'total_sessions' => 10, 'remaining_sessions' => 10, 'used_sessions' => 0,
            'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_two',
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $this->ownedPackageIds[] = $pkg->id;

        return (int) $pkg->id;
    }

    private function makeStudent(): int
    {
        $student = Student::factory()->create(['CampusID' => 1]);
        $this->ownedStudentIds[] = $student->id;

        return (int) $student->id;
    }

    private function makeMember(int $packageId): int
    {
        $pkg = CoursePackage::findOrFail($packageId);
        $course = StudentClass::create([
            'StudentID' => $this->makeStudent(), 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 78171, 'by1' => 1,
            'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_two',
            'Rate' => 500, 'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => 10, 'PackageName' => $pkg->name,
        ]);
        $this->ownedCourseIds[] = (int) $course->ID;

        return (int) $course->ID;
    }
}
