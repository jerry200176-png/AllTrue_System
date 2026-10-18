<?php

namespace Tests\Feature;

use App\Models\{Invoice, InvoiceItem, Payment, Student, StudentClass};
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentReportRaceWorker;
use Tests\TestCase;

/** Regression for the invoice-void writer race exposed by #4247 CI39094727324. */
class InvoiceVoidSerializationTest extends TestCase
{
    use PaymentReportRaceWorker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->raceAssertMysqlRr();
        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        $this->raceTerminateAllWorkers();
        $this->raceCleanupFiles();
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    /** @dataProvider voidPaths */
    public function test_void_waits_for_course_owner_and_rechecks_superseded_invoice(string $operation, string $references): void
    {
        [$course, $invoice] = $this->fixture($references);
        DB::beginTransaction();
        StudentClass::whereKey($course->ID)->lockForUpdate()->firstOrFail();
        [$worker, $connection, $after, $bindings] = $this->spawnVoid($invoice, $operation);
        $this->awaitBlockedQuery($worker, $connection, $after, $bindings, (int) $course->ID);
        // The course owner must still be able to supersede the old obligation;
        // the waiting void must not already own this invoice or write a reversal.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
        Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        DB::table('Invoice')->where('id', $invoice->id)->update(['Status' => 'void']);
        DB::commit();

        $result = $this->raceFinish($worker);
        $this->assertSame(422, $result['status'], json_encode($result));
        $this->assertSame('void', $invoice->fresh()->Status);
        $this->assertSame('Retain history', $invoice->fresh()->Note);
        $this->assertSame(0, Payment::where('InvoiceID', $invoice->id)->count());
        $this->assertSame(8000, (int) $course->fresh()->Charge);
    }

    /** @dataProvider moneyWriters */
    public function test_current_header_owner_is_used_after_route_binding(string $operation): void
    {
        [$course, $invoice] = $this->fixture('header');
        $newOwner = $course->replicate(); $newOwner->save();
        DB::beginTransaction();
        StudentClass::whereKey($newOwner->ID)->lockForUpdate()->firstOrFail();
        $pause = $this->racePause('from `invoice`'); $pause['locked'] = false;
        [$worker, $connection, $after, $bindings] = $this->spawnVoid($invoice, $operation, $pause);
        $this->raceAwait($pause['ready']); // worker already bound the old header
        DB::table('Invoice')->where('id', $invoice->id)->update(['StudentClassID' => $newOwner->ID]);
        DB::commit();
        DB::beginTransaction();
        StudentClass::whereKey($newOwner->ID)->lockForUpdate()->firstOrFail();
        touch($pause['release']);
        $this->awaitBlockedQuery($worker, $connection, $after, $bindings, (int) $newOwner->ID);
        DB::table('Invoice')->where('id', $invoice->id)->update(['Status' => 'void']);
        DB::commit();
        $result = $this->raceFinish($worker);
        $this->assertSame(422, $result['status'], json_encode($result));
        $this->assertSame('Retain history', $invoice->fresh()->Note);
        $this->assertSame(0, Payment::count());
    }

    /** @dataProvider moneyWriters */
    public function test_owner_change_while_waiting_refuses_without_writing(string $operation): void
    {
        [$course, $invoice] = $this->fixture('header');
        $newOwner = $course->replicate(); $newOwner->save();
        DB::beginTransaction();
        StudentClass::whereKey($course->ID)->lockForUpdate()->firstOrFail();
        [$worker, $connection, $after, $bindings] = $this->spawnVoid($invoice, $operation);
        $this->awaitBlockedQuery($worker, $connection, $after, $bindings, (int) $course->ID);
        StudentClass::whereKey($newOwner->ID)->lockForUpdate()->firstOrFail();
        DB::table('Invoice')->where('id', $invoice->id)->update(['StudentClassID' => $newOwner->ID]);
        DB::commit();
        $result = $this->raceFinish($worker);
        $this->assertSame(409, $result['status'], json_encode($result));
        $this->assertSame('invoice_owner_changed', $result['body']['code'] ?? null);
        $this->assertSame('unpaid', $invoice->fresh()->Status);
        $this->assertSame('Retain history', $invoice->fresh()->Note);
        $this->assertSame(0, Payment::count());
    }

    /** @dataProvider operations */
    public function test_consolidated_void_does_not_invert_reverse_id_transfer_locks(string $operation): void
    {
        [$course, $invoice, $higher] = $this->fixture('multiple');
        DB::beginTransaction();
        // Existing transfer owns higher-ID source before lower-ID target.
        StudentClass::whereKey($higher->ID)->lockForUpdate()->firstOrFail();
        [$worker] = $this->spawnVoid($invoice, $operation);
        $result = $this->raceFinish($worker);
        $this->assertSame(200, $result['status'], json_encode($result));
        StudentClass::whereKey($course->ID)->lockForUpdate()->firstOrFail();
        DB::commit();
        $this->assertSame('void', $invoice->fresh()->Status);
        $this->assertSame(0, Payment::count());
        $this->assertSame(8000, (int) $course->fresh()->Charge);
        $this->assertSame(8000, (int) $higher->fresh()->Charge);
    }

    private function spawnVoid(Invoice $invoice, string $operation, ?array $pause = null): array
    {
        $connection = $this->raceFile(); $after = $this->raceFile(); $bindings = $this->raceFile();
        $worker = $this->raceSpawn(base_path('tests/Support/invoice-void-race-worker.php'), [
            'invoice_id' => $invoice->id, 'operation' => $operation,
            'connection_file' => $connection, 'after_course_query' => $after,
            'bindings_file' => $bindings, 'route_pause' => $pause,
        ]);
        return [$worker, $connection, $after, $bindings];
    }

    private function awaitBlockedQuery(array $worker, string $connectionFile, string $after, string $bindingsFile, int $heldId): void
    {
        $id = (int) file_get_contents($connectionFile);
        $this->assertGreaterThan(0, $id);
        $deadline = microtime(true) + 10;
        do {
            // PROCESSLIST shows this user's own worker without PROCESS privilege.
            // It proves the server received the locking query while we own its row.
            $row = DB::selectOne('SELECT INFO AS query_text FROM information_schema.PROCESSLIST WHERE ID = ?', [$id]);
            $sql = strtolower((string) ($row->query_text ?? ''));
            if (str_contains($sql, 'studentclass') && str_contains($sql, 'for update')) {
                $this->assertFileExists($bindingsFile);
                $this->assertContains($heldId, array_map('intval', json_decode(file_get_contents($bindingsFile), true)),
                    'server-observed query must target the row this parent actually holds');
                $this->assertFileDoesNotExist($after, 'locked query cannot complete before the owner commits');
                return;
            }
            $read = [$worker['pipes'][1]]; $write = $except = [];
            if (stream_select($read, $write, $except, 0, 0) > 0) {
                $this->fail('Void completed instead of waiting: '.json_encode($this->raceFinish($worker)));
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        $this->fail('Server never observed the blocked StudentClass lock query');
    }

    private function fixture(string $references): array
    {
        $student = Student::create(['name' => 'Synthetic serialization', 'CampusID' => 1,
            'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 1,
            'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-10-01', 'TotalHours' => 16, 'Charge' => 8000, 'Rate' => 1000,
            'Paid' => 0, 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'SessionDuration' => 120]);
        $invoice = Invoice::create(['StudentID' => $student->id,
            'StudentClassID' => $references === 'item' ? null : $course->ID, 'IssueDate' => '2026-10-01',
            'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => 'Retain history']);
        $lockedCourse = $references === 'multiple' ? $course->replicate() : $course;
        if ($references === 'multiple') {
            $lockedCourse->save();
        }
        if ($references !== 'header') {
            InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $lockedCourse->ID,
                'Description' => 'Synthetic original obligation', 'Amount' => 8000]);
        }

        return [$course, $invoice, $lockedCourse];
    }

    public static function voidPaths(): array
    {
        return [['voidInvoice', 'header'], ['exceptionVoidInvoice', 'header'],
            ['voidInvoice', 'item'], ['exceptionVoidInvoice', 'item']];
    }

    public static function operations(): array { return [['voidInvoice'], ['exceptionVoidInvoice']]; }
    public static function moneyWriters(): array { return [...self::operations(), ['recordPayment']]; }
}
