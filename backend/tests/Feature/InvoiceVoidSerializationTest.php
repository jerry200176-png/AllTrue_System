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

        DB::beginTransaction();
        StudentClass::whereKey($lockedCourse->ID)->lockForUpdate()->firstOrFail();
        $signal = $this->raceFile();
        $worker = $this->raceSpawn(base_path('tests/Support/invoice-void-race-worker.php'), [
            'invoice_id' => $invoice->id, 'operation' => $operation, 'course_signal' => $signal,
        ]);
        // Real pre-execution handshake, not a sleep or a process-start assumption.
        $deadline = microtime(true) + 15;
        while (!file_exists($signal) && microtime(true) < $deadline) {
            $read = [$worker['pipes'][1]];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 0) > 0) {
                $result = $this->raceFinish($worker);
                $this->fail('Void completed before the course owner released its lock: '.json_encode($result));
            }
            usleep(5000);
        }
        $this->assertFileExists($signal, 'void reached the authoritative course lock');
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

    public static function voidPaths(): array
    {
        return [['voidInvoice', 'header'], ['exceptionVoidInvoice', 'header'],
            ['voidInvoice', 'item'], ['exceptionVoidInvoice', 'item'],
            ['voidInvoice', 'multiple'], ['exceptionVoidInvoice', 'multiple']];
    }
}
