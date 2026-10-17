<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\BillingCorrection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4217 claim 9 probe (MySQL REPEATABLE READ, two connections).
 *
 * After the confirmation-token check, connection B attempts an open-invoice INSERT
 * without taking the course lock. Under RR, A's earlier Invoice FOR UPDATE may already
 * hold next-key/gap locks so B waits — that is a valid observed outcome, not a reason
 * to drop locks or lower isolation. Claim 9 is VERIFIED only when CI evidence shows
 * which invariant held (commit-then-refuse vs writer lock-wait); do not pre-label.
 *
 * Opts out of RefreshDatabase wrapping. Bounded innodb_lock_wait_timeout on B.
 */
class BillingCorrectionInvoiceRaceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    public function test_pre_stamp_race_against_second_connection_invoice_insert(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL REPEATABLE READ multi-connection proof');
        }
        try {
            $iso = DB::selectOne('select @@transaction_isolation as i')->i;
        } catch (\Throwable) {
            $iso = DB::selectOne('select @@tx_isolation as i')->i;
        }
        $this->assertSame('REPEATABLE-READ', $iso);

        $student = Student::create(['name' => 'RR更正', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2032-04-01', 'TotalHours' => 20, 'Charge' => 4000, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        $first = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2032-04-01',
            'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
        ]);
        $firstId = (int) $first->id;

        config(['database.connections.billing_rr_b' => config('database.connections.'.config('database.default'))]);
        DB::purge('billing_rr_b');
        $b = DB::connection('billing_rr_b');
        // Bound B's wait so a gap/next-key conflict cannot stall the whole PHPUnit job.
        $b->statement('SET SESSION innodb_lock_wait_timeout = 2');

        $svc = app(BillingCorrection::class);
        $token = $svc->confirmationToken($course->fresh(), 6, 3000, 0, [], 7);
        $committed = false;
        $writerLockWait = false;
        $svc->setAfterConfirmationTokenForTests(function (int $classId) use ($b, $student, &$committed, &$writerLockWait): void {
            try {
                $b->table('Invoice')->insert([
                    'StudentID' => $student->id, 'StudentClassID' => $classId, 'IssueDate' => '2032-04-02',
                    'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => 'rr-b',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $committed = true;
            } catch (QueryException $e) {
                if (stripos($e->getMessage(), 'Lock wait timeout') !== false) {
                    $writerLockWait = true;
                }
                throw $e;
            }
        });

        $refuseCode = null;
        $threw = null;
        try {
            $svc->applyCountCorrection($course->fresh(), 6, 3000, 'rr race', 8, 4000, $token, 7);
            $this->fail('expected refuse or writer lock-wait under RR; apply must not stamp silently past a concurrent writer attempt');
        } catch (HttpResponseException $e) {
            $refuseCode = json_decode($e->getResponse()->getContent(), true)['code'] ?? null;
            $this->assertSame(409, $e->getResponse()->getStatusCode());
        } catch (QueryException $e) {
            $threw = $e;
            $this->assertTrue($writerLockWait, 'QueryException outside lock-wait must not be swallowed: '.$e->getMessage());
        } finally {
            $svc->setAfterConfirmationTokenForTests(null);
        }

        // Assertions before cleanup — $first->fresh() is undefined after DELETE.
        $openCount = (int) DB::table('Invoice')->where('StudentClassID', $course->ID)->where(function ($q) {
            $q->whereNull('Status')->orWhere('Status', '!=', 'void');
        })->count();
        $firstAmount = DB::table('Invoice')->where('id', $firstId)->value('TotalAmount');
        $sessionCount = (int) DB::table('StudentClass')->where('ID', $course->ID)->value('SessionCount');
        $charge = (int) DB::table('StudentClass')->where('ID', $course->ID)->value('Charge');

        $this->assertTrue($committed || $writerLockWait, 'B must either commit or hit lock-wait; neither hung nor no-op');
        if ($committed) {
            $this->assertSame('billing_correction_multiple_open_invoices', $refuseCode);
            $this->assertSame(2, $openCount);
            $this->assertSame(4000, (int) $firstAmount);
            $this->assertSame([8, 4000], [$sessionCount, $charge], 'refuse must not stamp course');
        } else {
            $this->assertTrue($writerLockWait);
            $this->assertNotNull($threw);
            $this->assertSame(1, $openCount, 'blocked writer must leave a single open invoice');
            $this->assertSame(4000, (int) $firstAmount);
            $this->assertSame([8, 4000], [$sessionCount, $charge], 'lock-wait abort must roll back the correction txn');
        }

        // Reliable cleanup (no RefreshDatabase outer txn).
        $b->table('Invoice')->where('StudentClassID', $course->ID)->delete();
        DB::table('Invoice')->where('StudentClassID', $course->ID)->delete();
        DB::table('StudentClass')->where('ID', $course->ID)->delete();
        DB::table('Student')->where('id', $student->id)->delete();
        DB::disconnect('billing_rr_b');
    }
}
