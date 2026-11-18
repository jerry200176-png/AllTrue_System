<?php

namespace Tests\Feature;

use App\Models\{ClassSession, ExistingCourseCreditAllocationGroup as Group, Invoice, Payment, PaymentReport, Student, StudentClass, UserCampus};
use App\Services\Billing\ExistingCourseCreditMovement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\{DatabaseTruncation, RefreshDatabaseState};
use Illuminate\Support\Facades\DB;
use Tests\Support\{ExistingCourseCreditFixtures, PaymentReportRaceWorker};
use Tests\TestCase;

/** SourceRef563: real committed transactions and separate PDOs; only ephemeral loopback test databases. */
class ExistingCourseCreditConcurrencyTest extends TestCase
{
    use DatabaseTruncation, ExistingCourseCreditFixtures, PaymentReportRaceWorker;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
        $this->raceAssertMysqlRr();
    }

    protected function tearDown(): void
    {
        $this->raceTerminateAllWorkers(); $this->raceCleanupFiles();
        while (DB::transactionLevel() > 0) DB::rollBack();
        Carbon::setTestNow(); parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false; parent::tearDownAfterClass();
    }

    public static function orders(): array { return [[true], [false]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function test_competing_groups_commit_once_or_replay_without_releasing_or_funding_twice(bool $sameKey): void
    {
        [$source, , $input, $actor, $campus] = $this->allocation();
        $pause = $this->racePause('from `studentclass`');
        $one = $this->spawn($source, $input, $actor, $campus, $pause);
        $this->raceAwait($pause['ready']);
        $two = $this->spawn($source, array_replace($input, ['idempotency_key' => $sameKey ? $input['idempotency_key'] : 'second-group']), $actor, $campus);
        $this->awaitBlocked($two, 'student'); touch($pause['release']);
        $a = $this->raceFinish($one); $b = $this->raceFinish($two);
        $this->assertSame(201, $a['status'], json_encode($a));
        $this->assertSame($sameKey ? 200 : 422, $b['status'], json_encode($b));
        if ($sameKey) $this->assertSame($a['body']['allocation_group']['id'], $b['body']['allocation_group']['id']);
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 1);
        $this->assertDatabaseCount('contract_subject_credit_transfers', 2);
        $this->assertSame(5, ClassSession::whereIn('id', $input['released_session_ids'])->where('Status', 'cancelled')->count());
        $this->assertSame(3, (int) $source->fresh()->SessionCount);
        $this->assertSame(12000, (int) Payment::sum('Amount'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function test_receipt_void_and_group_cannot_spend_the_same_original_cash(bool $allocationFirst): void
    {
        [$source, , $input, $actor, $campus] = $this->allocation();
        $invoice = Invoice::where('StudentClassID', $source->ID)->firstOrFail();
        $cash = Payment::where('InvoiceID', $invoice->id)->firstOrFail();
        $report = PaymentReport::create(['StudentID' => $source->StudentID, 'StudentClassID' => $source->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => 'isolated payer', 'payment_date' => '2026-09-01', 'payment_method' => 'cash',
            'reported_amount' => 12000, 'status' => 'confirmed', 'confirmed_at' => now(), 'payment_id' => $cash->id,
            'report_token_hash' => hash('sha256', 'isolated group race'), 'token_expires_at' => now()->addDay()]);
        $cash->forceFill(['payment_report_id' => $report->id])->save();
        $input['state_hash'] = app(\App\Services\Billing\ExistingCourseCreditPreview::class)->run($source, $input, [$campus])['state_hash'];
        $pause = $this->racePause('from `studentclass`');
        $void = ['op' => 'void', 'report_id' => $report->id, 'input' => ['void_reason' => 'isolated correction']];
        $one = $this->spawn($source, $input, $actor, $campus, $pause, $allocationFirst ? [] : $void);
        $this->raceAwait($pause['ready']);
        $two = $this->spawn($source, $input, $actor, $campus, null, $allocationFirst ? $void : []);
        $this->awaitBlocked($two, 'studentclass'); touch($pause['release']);
        $a = $this->raceFinish($one); $b = $this->raceFinish($two);
        $this->assertSame($allocationFirst ? 201 : 200, $a['status'], json_encode($a));
        $this->assertSame($allocationFirst ? 409 : 422, $b['status'], json_encode($b));
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', $allocationFirst ? 1 : 0);
        $this->assertSame($allocationFirst ? 12000 : 0, (int) Payment::sum('Amount'));
        $this->assertSame($allocationFirst ? 'confirmed' : 'voided', $report->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function test_inverse_and_another_students_actual_booking_cannot_both_take_the_released_slot(bool $inverseFirst): void
    {
        [$source, , $input, $actor, $campus] = $this->allocation();
        UserCampus::firstOrCreate(['UserID' => $source->TeacherID, 'CampusID' => $campus], ['Admin' => 0, 'Approved' => 1]);
        $group = app(ExistingCourseCreditMovement::class)->apply($source, $input, $actor, [$campus])['body']['allocation_group'];
        $student = Student::create(['name' => 'isolated booking contender', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $other = $source->fresh()->replicate(); $other->forceFill(['StudentID' => $student->id, 'SessionCount' => 8, 'UsedSessions' => 0, 'RemainingSessions' => 8])->save();
        $inverse = ['op' => 'existing_reverse', 'group_id' => $group['id'], 'input' => ['reason' => 'isolated restore']];
        $booking = ['op' => 'schedule', 'input' => ['student_id' => $student->id, 'student_course_id' => $other->ID,
            'teacher_id' => $source->TeacherID, 'branch_id' => $campus, 'day_of_week' => 5, 'schedule_date' => '2026-10-02',
            'start_time' => '08:00', 'end_time' => '10:00', 'duration_hours' => 2, 'status' => 'scheduled', 'type' => 'normal']];
        $pause = $this->racePause($inverseFirst ? 'from schedules force index' : 'insert into `schedules`');
        if (!$inverseFirst) $pause['locked'] = false;
        $one = $this->spawn($source, [], $actor, $campus, $pause, $inverseFirst ? $inverse : $booking);
        try { $this->raceAwait($pause['ready']); }
        catch (\PHPUnit\Framework\AssertionFailedError $e) { $this->fail($e->getMessage() . ' ' . json_encode($this->raceFinish($one))); }
        $two = $this->spawn($source, [], $actor, $campus, null, $inverseFirst ? $booking : $inverse);
        $this->awaitBlocked($two, 'calendar'); touch($pause['release']);
        $a = $this->raceFinish($one); $b = $this->raceFinish($two);
        $inverseResult = $inverseFirst ? $a : $b; $bookingResult = $inverseFirst ? $b : $a;
        // Native InnoDB deadlock retries may change the winner; never accept a 500, partial inverse or double booking.
        $this->assertContains([$inverseResult['status'], $bookingResult['status']], [[200, 409], [409, 201]], json_encode([$a, $b]));
        $restored = $inverseResult['status'] === 200;
        $this->assertSame($restored, Group::findOrFail($group['id'])->reversed_at !== null);
        $this->assertSame($restored ? 0 : 1, DB::table('schedules')->count());
        $this->assertSame($restored ? 0 : 5, ClassSession::whereIn('id', $input['released_session_ids'])->where('Status', 'cancelled')->count());
        $this->assertSame(12000, (int) Payment::sum('Amount'));
    }

    private function spawn(StudentClass $source, array $input, int $actor, int $campus, ?array $pause = null, array $overrides = []): array
    {
        $connection = $this->raceFile();
        return $this->raceSpawn(base_path('tests/Support/subject-credit-worker.php'), array_replace([
            'op' => 'existing_apply', 'course_id' => (int) $source->ID, 'input' => $input, 'pause' => $pause,
            'actor_id' => $actor, 'campus_id' => $campus, 'connection_file' => $connection,
        ], $overrides)) + ['connection_file' => $connection];
    }

    private function awaitBlocked(array $worker, string $table): void
    {
        $this->raceAwait($worker['connection_file']);
        $id = (int) file_get_contents($worker['connection_file']); $deadline = microtime(true) + 3;
        do {
            $row = DB::selectOne('SELECT INFO AS sql_text FROM information_schema.PROCESSLIST WHERE ID = ?', [$id]);
            $sql = strtolower((string) ($row->sql_text ?? ''));
            $expected = $table === 'calendar'
                ? preg_match('/from (schedules force index|student as st|`student`)/', $sql) === 1
                : str_contains($sql, 'from `'.$table.'`');
            if ($expected && str_contains($sql, 'for update')) return;
            usleep(5000);
        } while (microtime(true) < $deadline);
        $this->fail('The second real transaction must be waiting on the held-row/range read before release; last SQL=' . $sql
            . '; running=' . json_encode(proc_get_status($worker['process'])['running']));
    }
}
