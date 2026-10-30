<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentClass;
use App\Services\SessionDeductionService;
use App\Support\DeadlockRetry;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * #4470: deductOnAttendance() still swallows ordinary failures (the attendance itself is kept), but a deadlock inside a
 * caller's transaction has already rolled that whole transaction back on the server, so it must reach the caller's
 * outermost transaction, which runs it again (DeadlockRetry::ATTEMPTS). The real two-connection deadlock is in
 * PackageSlotCurrentReadTest.
 */
class DeductionDeadlockPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deadlock_inside_the_callers_transaction_reaches_it_without_a_nested_re_run(): void
    {
        [$course, $lesson] = $this->courseWithLesson();
        $inserts = $this->failCourseLedgerInserts(fn () => $this->deadlock());

        try {
            DB::transaction(fn () => SessionDeductionService::deductOnAttendance($course, null, $lesson));
            $this->fail('the deadlock was swallowed inside the caller\'s transaction');
        } catch (DeadlockException $e) {
            $this->assertTrue(DeadlockRetry::causedBy($e));
            // Laravel 12 wraps each nested boundary; preserve the original query failure.
            $cause = $e;
            while ($cause instanceof DeadlockException && $cause->getPrevious()) {
                $cause = $cause->getPrevious();
            }
            $this->assertInstanceOf(QueryException::class, $cause);
        }
        $this->assertSame(1, $inserts(), 'a nested transaction is not run again: only the outermost one can be');
        $this->assertSame(1, DB::transactionLevel(), 'every level the deadlock passed through is closed');
    }

    public function test_other_failures_are_still_swallowed_as_not_deducted(): void
    {
        [$course, $lesson] = $this->courseWithLesson();
        $this->failCourseLedgerInserts(fn () => new \RuntimeException('synthetic ledger failure'));

        $this->assertFalse(DB::transaction(fn () => SessionDeductionService::deductOnAttendance($course, null, $lesson)));
    }

    public function test_an_outermost_transaction_runs_a_deadlock_victim_again_up_to_the_attempts(): void
    {
        // A second connection is at transaction level 0 even inside the test transaction.
        config(['database.connections.deadlock_probe' => config('database.connections.' . DB::getDefaultConnection())]);
        $probe = DB::connection('deadlock_probe');
        $run = function (callable $body) use ($probe): array {
            $runs = 0;
            try {
                $result = $probe->transaction(function () use (&$runs, $body) {
                    return $body(++$runs);
                }, DeadlockRetry::ATTEMPTS);
            } catch (QueryException $e) {
                $result = $e;
            }

            return [$runs, $result];
        };

        $this->assertSame([2, 'ok'], $run(fn (int $n) => $n === 1 ? throw $this->deadlock() : 'ok'));
        [$runs, $error] = $run(fn () => throw $this->deadlock());
        $this->assertSame(DeadlockRetry::ATTEMPTS, $runs, 'bounded');
        $this->assertInstanceOf(QueryException::class, $error);
        [$runs] = $run(fn () => throw new QueryException('mysql', 'insert', [], new PDOException('SQLSTATE[23000]: Duplicate entry')));
        $this->assertSame(1, $runs, 'only a concurrency error runs again');
        DB::purge('deadlock_probe');
    }

    /** @return array{0: StudentClass, 1: int} */
    private function courseWithLesson(): array
    {
        $course = StudentClass::create([
            'StudentID' => Student::factory()->create(['CampusID' => 1])->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => 78171, 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 10, 'RemainingSessions' => 10, 'UsedSessions' => 0,
            'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'Rate' => 500, 'rate_unit' => 'session',
            'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
        ]);
        $lesson = DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID, 'SessionDate' => '2026-04-16',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended']);

        return [$course, $lesson];
    }

    /** Fails every course ledger insert with $error() and counts the attempts. */
    private function failCourseLedgerInserts(callable $error): \Closure
    {
        $count = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$count, $error) {
            if (str_starts_with(strtolower($sql), 'insert into `session_deduction_ledger`')) {
                $count++;
                throw $error();
            }
        });

        return function () use (&$count): int {
            return $count;
        };
    }

    private function deadlock(): QueryException
    {
        $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

        return new QueryException('mysql', 'insert into `session_deduction_ledger`', [], $pdo);
    }
}
