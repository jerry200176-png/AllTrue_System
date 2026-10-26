<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
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
    private array $initialSubjectIds = [], $seededSubjectIds = [], $ownedStudentIds = [], $ownedCourseIds = [], $ownedPackageIds = [];
    private array $otherDebits = [], $ownedUserIds = [];
    private int $victimRuns = 0;

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
        foreach ($this->otherDebits as $other) {
            proc_terminate($other['proc']);
            proc_close($other['proc']);
        }
        $reader = DB::connection('package_current');
        while ($reader->transactionLevel() > 0) $reader->rollBack();
        DB::purge('package_current');
        DB::setDefaultConnection($this->originalConnection);
        DB::statement('SET SESSION innodb_lock_wait_timeout = ' . $this->lockTimeout);
        try {
            DB::table('package_session_ledger')->whereIn('package_id', $this->ownedPackageIds)->delete();
            $lessonIds = DB::table('ClassSession')->whereIn('StudentClassID', $this->ownedCourseIds)->pluck('id')->all();
            DB::table('schedule_audit_logs')->whereIn('session_id', $lessonIds)->delete();
            DB::table('LearningRecord')->whereIn('StudentClassID', $this->ownedCourseIds)->delete();
            DB::table('auth_tokens')->whereIn('user_id', $this->ownedUserIds)->delete();
            DB::table('UserCampus')->whereIn('UserID', $this->ownedUserIds)->delete();
            DB::table('User')->whereIn('id', $this->ownedUserIds)->delete();
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

    public function test_first_debits_of_two_packages_without_ledger_rows_survive_their_gap_lock_deadlock(): void
    {
        // #4470: with no ledger rows, both current reads lock the same index gap and then both insert into it. Package A
        // debits in another process; this lighter transaction is the deadlock victim and its outermost transaction re-runs.
        [$a, $b, $other] = $this->raceFirstDebits('attended');

        $this->assertTrue(SessionDeductionService::deductForSession($b['course'], $b['lesson'], 'attendance'));
        $this->assertBothDebitedOnce($a, $b, $other);
    }

    public function test_a_calendar_attendance_whose_debit_is_the_deadlock_victim_runs_again_whole(): void
    {
        // The deduction is nested in the request's transaction: the deadlock must reach it, not be swallowed (#4470).
        [$a, $b, $other] = $this->raceFirstDebits('scheduled');

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->directorToken(), 'Accept' => 'application/json'])
            ->patchJson("/api/v1/class-sessions/{$b['lesson']}", ['status' => 'attended'])->assertOk();
        $this->assertBothDebitedOnce($a, $b, $other);
        $this->assertSame('attended', DB::table('ClassSession')->where('id', $b['lesson'])->value('Status'));
    }

    /** Two group packages without ledger rows, a lesson each; package A's first debit holds its locks in another process. */
    private function raceFirstDebits(string $statusB): array
    {
        $lesson = function (string $status): array {
            $course = $this->makeMember($pkg = $this->makePackage());
            return ['package' => $pkg, 'course' => $course, 'lesson' => DB::table('ClassSession')->insertGetId(['StudentClassID' => $course,
                'SessionDate' => '2026-04-16', 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => $status])];
        };
        [$a, $b] = [$lesson('attended'), $lesson($statusB)];
        $other = $this->startOtherDebit($a);
        PackageSessionLedger::creating(function (PackageSessionLedger $row) use ($b, $other) {
            if ((int) $row->package_id === $b['package'] && ++$this->victimRuns === 1) {
                fwrite($other['pipes'][0], "insert\n"); // package A inserts into the gap this transaction has locked too
            }
        });
        DB::statement('SET SESSION innodb_lock_wait_timeout = 20');

        return [$a, $b, $other];
    }

    private function assertBothDebitedOnce(array $a, array $b, array $other): void
    {
        $this->assertSame('committed', $this->otherDebitLine($other), 'package A debits in the other transaction');
        $this->assertSame(2, $this->victimRuns, 'the deadlock victim runs again');
        foreach ([$a, $b] as $side) {
            $this->assertSame([-1], DB::table('package_session_ledger')->where('package_id', $side['package'])->pluck('delta')
                ->map(fn ($delta) => (int) $delta)->all());
        }
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('student_class_id', $b['course'])->count(),
            'the rolled-back run leaves no course debit behind');
    }

    /** Package A's first debit as PackageDeductionService runs it, in a race worker that waits before its insert. */
    private function startOtherDebit(array $a): array
    {
        require_once base_path('tests/Support/alltrueTestDatabase.php');
        $db = alltrue_race_database_config(config('database.connections.' . $this->originalConnection));
        $proc = proc_open([PHP_BINARY, '-r', self::OTHER_DEBIT, '--', $a['package'], $a['course'], $a['lesson']],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(),
            ['DSN' => "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}", 'USER' => $db['username'], 'PASS' => $db['password']]);
        $this->assertIsResource($proc);
        $this->otherDebits[] = $other = ['proc' => $proc, 'pipes' => $pipes];
        $this->assertSame('locked', $this->otherDebitLine($other));

        return $other;
    }

    private function otherDebitLine(array $other): string
    {
        stream_set_timeout($other['pipes'][1], 30);
        $line = fgets($other['pipes'][1]);

        return $line === false ? 'no answer: ' . stream_get_contents($other['pipes'][2]) : trim($line);
    }

    /** Locks package A and reads its ledger current (a gap lock); its extra undo makes InnoDB pick the test's side as victim. */
    private const OTHER_DEBIT = <<<'PHP'
        [, $pkg, $course, $lesson] = array_map('intval', $argv);
        $pdo = new PDO(getenv('DSN'), getenv('USER'), getenv('PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
        $pdo->beginTransaction();
        $pdo->query("SELECT id FROM course_packages WHERE id = $pkg FOR UPDATE")->fetchAll();
        for ($i = 0; $i < 100; $i++) $pdo->exec("UPDATE course_packages SET used_sessions = used_sessions + 1 WHERE id = $pkg");
        $pdo->exec("UPDATE course_packages SET used_sessions = used_sessions - 100 WHERE id = $pkg");
        $pdo->query("SELECT class_session_id, delta, reason FROM package_session_ledger WHERE package_id = $pkg LOCK IN SHARE MODE")->fetchAll();
        echo "locked\n";
        fgets(STDIN);
        try {
            $pdo->exec("INSERT INTO package_session_ledger (package_id, student_class_id, class_session_id, delta, reason, created_at, updated_at)"
                . " VALUES ($pkg, $course, $lesson, -1, 'attendance', NOW(), NOW())");
            $pdo->commit();
            echo "committed\n";
        } catch (PDOException $e) {
            echo 'failed ', $e->getMessage(), "\n";
        }
        PHP;

    private function directorToken(): string
    {
        $director = User::create(['LoginName' => 'deadlock-dir-' . uniqid() . '@t.com', 'Name' => '主任', 'PSW' => 's',
            'type' => 'A', 'phone' => 900000000, 'MustChangePassword' => false]);
        $this->ownedUserIds[] = (int) $director->id;
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $director->id, 'token' => $token = bin2hex(random_bytes(16)), 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function makePackage(): int
    {
        return $this->ownedPackageIds[] = (int) CoursePackage::create(['student_id' => $this->makeStudent(), 'campus_id' => 1,
            'name' => '並發時段方案', 'total_sessions' => 10, 'remaining_sessions' => 10, 'used_sessions' => 0, 'rate' => 500,
            'rate_unit' => 'session', 'class_type' => 'one_on_two', 'paid' => false, 'stop' => false, 'enabled' => true])->id;
    }

    private function makeStudent(): int
    {
        return $this->ownedStudentIds[] = (int) Student::factory()->create(['CampusID' => 1])->id;
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

        return $this->ownedCourseIds[] = (int) $course->ID;
    }
}
