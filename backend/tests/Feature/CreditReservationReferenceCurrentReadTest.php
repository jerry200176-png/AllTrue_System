<?php

namespace Tests\Feature;

use App\Models\{ClassSession, Student};
use App\Services\Scheduling\ClassSessionDeleteGuard;
use Illuminate\Foundation\Testing\{DatabaseTruncation, RefreshDatabaseState};
use Illuminate\Support\Facades\DB;
use Tests\Support\SubjectCreditFixtures;
use Tests\TestCase;

/** Financial reservation checks must see references committed after an earlier MySQL RR snapshot. */
class CreditReservationReferenceCurrentReadTest extends TestCase
{
    use DatabaseTruncation;
    use SubjectCreditFixtures;

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        DB::purge('credit_reference_peer');
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public static function referenceKinds(): array { return [['learning'], ['dated_booking']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('referenceKinds')]
    public function test_locking_reference_read_observes_a_later_committed_reference(string $kind): void
    {
        [, $source] = $this->fixture();
        $this->appendClassSession($source, '2026-10-01', 'scheduled');
        $lesson = ClassSession::where('StudentClassID', $source->ID)->where('SessionDate', '2026-10-01')->firstOrFail();
        $campus = (int) Student::findOrFail($source->StudentID)->CampusID;
        require_once base_path('tests/Support/alltrueTestDatabase.php');
        config(['database.connections.credit_reference_peer' => alltrue_race_database_config(config('database.connections.mysql'))]);
        $peer = DB::connection('credit_reference_peer');
        $this->assertSame('REPEATABLE-READ', strtoupper(alltrue_race_isolation_level()));
        DB::beginTransaction();
        $table = $kind === 'learning' ? 'LearningRecord' : 'schedules';
        // Deliberately establish the old snapshot before the second connection commits.
        DB::table($table)->get(['id']);
        $reference = $kind === 'learning'
            ? ['StudentClassID' => $source->ID, 'ClassSessionID' => $lesson->id,
                'TeacherID' => $source->TeacherID, 'Content' => '', 'Subject' => 'synthetic reference',
                'SessionDate' => '2026-10-01', 'StartTime' => '08:00', 'EndTime' => '09:00', 'Status' => 'pending']
            : ['student_id' => $source->StudentID, 'student_course_id' => $source->ID,
                'teacher_id' => $source->TeacherID, 'branch_id' => $campus, 'subject' => 'synthetic reference',
                'day_of_week' => 4, 'schedule_date' => '2026-10-01', 'start_time' => '08:00', 'end_time' => '09:00',
                'duration_hours' => 1, 'status' => 'scheduled', 'type' => 'normal'];
        $peer->table($table)->insert($reference);
        if ($kind === 'learning') {
            $this->assertSame([], ClassSessionDeleteGuard::referencedSessionIds([$lesson->id]));
            $current = ClassSessionDeleteGuard::referencedSessionIds([$lesson->id], true);
        } else {
            $this->assertSame([], ClassSessionDeleteGuard::datedScheduleSessionIds([$lesson]));
            $current = ClassSessionDeleteGuard::datedScheduleSessionIds([$lesson], true);
        }
        $this->assertArrayHasKey((int) $lesson->id, $current);
        $peer->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $peer->table($table)->insert($reference);
            $this->fail('A competing new reference must wait until the guarded transaction finishes.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1], 'Actual InnoDB lock wait, not a fixture/constraint error.');
        }
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(\LogicException::class);
        if ($kind === 'learning') ClassSessionDeleteGuard::referencedSessionIds([], true);
        else ClassSessionDeleteGuard::datedScheduleSessionIds([], true);
    }
}
