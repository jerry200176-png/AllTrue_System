<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\BillingCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4217 claim 9: MySQL REPEATABLE READ + second connection committing an open invoice
 * after the first multi-open guard / token check. Pre-stamp locking re-read must refuse.
 *
 * Opts out of RefreshDatabase wrapping so the competing INSERT can commit on another connection.
 */
class BillingCorrectionInvoiceRaceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    public function test_pre_stamp_refuses_invoice_committed_on_second_connection(): void
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

        config(['database.connections.billing_rr_b' => config('database.connections.'.config('database.default'))]);
        DB::purge('billing_rr_b');
        $b = DB::connection('billing_rr_b');
        $svc = app(BillingCorrection::class);
        $token = $svc->confirmationToken($course->fresh(), 6, 3000, 0, [], 7);
        $injected = false;
        // Bypass writer: commit a second open invoice WITHOUT taking the course lock (Issuer would wait).
        $svc->setAfterConfirmationTokenForTests(function (int $classId) use ($b, $student, &$injected): void {
            $b->table('Invoice')->insert([
                'StudentID' => $student->id, 'StudentClassID' => $classId, 'IssueDate' => '2032-04-02',
                'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => 'rr-b',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $injected = true;
        });

        try {
            $svc->applyCountCorrection($course->fresh(), 6, 3000, 'rr race', 8, 4000, $token, 7);
            $this->fail('expected pre-stamp multi-open refuse under RR');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('billing_correction_multiple_open_invoices', json_decode($e->getResponse()->getContent(), true)['code']);
        } finally {
            $svc->setAfterConfirmationTokenForTests(null);
            $b->table('Invoice')->where('StudentClassID', $course->ID)->delete();
            DB::table('Invoice')->where('StudentClassID', $course->ID)->delete();
            DB::table('StudentClass')->where('ID', $course->ID)->delete();
            DB::table('Student')->where('id', $student->id)->delete();
            DB::disconnect('billing_rr_b');
        }

        $this->assertTrue($injected, 'second connection must commit before pre-stamp');
        $this->assertSame(4000, (int) $first->fresh()->TotalAmount);
    }
}
