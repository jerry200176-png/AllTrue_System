<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentReportRaceWorker;
use Tests\TestCase;

/**
 * #4248: REPEATABLE-READ two-process partial→full confirm.
 *
 * Source-predicted regression (not claimed production-observed): if a plain
 * StudentClassID SELECT ran inside the write transaction before waiting on the
 * course lock, that non-locking read would pin B's RR snapshot. After A commits
 * the first 4400, B's locking invoice path could still stamp PaidAmount=8800 while
 * BillingPayableResolver's non-locking invoice/payment reads miss A's row and leave
 * StudentClass.Paid=0. Routing course id is resolved outside the transaction;
 * this test proves both confirms settle the course under real interleaving.
 */
class PaymentReportConfirmRrRaceTest extends TestCase
{
    use PaymentReportRaceWorker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->raceAssertMysqlRr();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
    }

    protected function tearDown(): void
    {
        $this->raceTerminateAllWorkers();
        $this->raceCleanupFiles();
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_partial_confirms_under_rr_settle_course_paid(): void
    {
        [$actorId, $course, $invoice, $reportA, $reportB] = $this->twoPendingPartialsFixture();

        // A holds StudentClass FOR UPDATE after acquiring it.
        $pause = $this->racePause('from `studentclass`');
        $winner = $this->raceSpawn(base_path('tests/Support/payment-confirm-race-worker.php'), [
            'actor' => ['id' => $actorId, 'role' => 'director', 'campus_ids' => [1]],
            'id' => (int) $reportA->id,
            'input' => [],
            'pause' => $pause,
        ]);
        $this->raceAwait($pause['ready']);

        // B signals immediately before its authoritative course FOR UPDATE (already
        // resolved routing id outside the txn), then blocks behind A's course lock.
        $bAtCourseLock = $this->raceFile();
        $delayed = $this->raceSpawn(base_path('tests/Support/payment-confirm-race-worker.php'), [
            'actor' => ['id' => $actorId, 'role' => 'director', 'campus_ids' => [1]],
            'id' => (int) $reportB->id,
            'input' => [],
            'signal_before_studentclass_for_update' => $bAtCourseLock,
        ]);
        $this->raceAwait($bAtCourseLock);

        touch($pause['release']);
        try {
            $aResult = $this->raceFinish($winner);
            $bResult = $this->raceFinish($delayed);

            $this->assertSame(200, $aResult['status'], json_encode($aResult));
            $this->assertSame(200, $bResult['status'], json_encode($bResult));
        } finally {
            $this->raceTerminateAllWorkers();
        }

        $this->assertSame('confirmed', $reportA->fresh()->status);
        $this->assertSame('confirmed', $reportB->fresh()->status);
        $this->assertSame(2, Payment::where('InvoiceID', $invoice->id)->where('Method', '!=', 'void')->count());
        $this->assertSame(8800, (int) $invoice->fresh()->PaidAmount);
        $this->assertSame('paid', $invoice->fresh()->Status);
        $this->assertSame(1, (int) $course->fresh()->Paid, 'resolver must see both 4400 payments under RR');
        $this->assertNotNull($course->fresh()->PayDate);
    }

    /** @return array{0:int,1:StudentClass,2:Invoice,3:PaymentReport,4:PaymentReport} */
    private function twoPendingPartialsFixture(): array
    {
        $user = User::create([
            'LoginName' => 'confirm-rr-'.Str::uuid().'@example.com',
            'Name' => 'confirm-rr',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create([
            'user_id' => $user->id,
            'token' => bin2hex(random_bytes(16)),
            'expires_at' => now()->addDay(),
        ]);

        $student = Student::create([
            'name' => 'cr-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 28),
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->toDateString(),
            'TotalHours' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
            'Rate' => 1100,
            'rate_unit' => 'session',
            'Charge' => 8800,
            'Pay' => 0,
            'Paid' => 0,
            'PayDate' => null,
            'Stop' => 0,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'TotalAmount' => 8800,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);
        $makePending = static function () use ($student, $sc, $invoice): PaymentReport {
            return PaymentReport::create([
                'StudentID' => $student->id,
                'StudentClassID' => $sc->ID,
                'InvoiceID' => $invoice->id,
                'reported_by_name' => $student->name,
                'payment_date' => Carbon::today(),
                'payment_method' => 'cash',
                'reported_amount' => 4400,
                'status' => 'pending',
                'report_token_hash' => hash('sha256', (string) Str::uuid()),
                'token_expires_at' => Carbon::now()->addDay(),
            ]);
        };

        return [(int) $user->id, $sc, $invoice, $makePending(), $makePending()];
    }
}
