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
use App\Services\Billing\BillingCorrection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentReportRaceWorker;
use Tests\TestCase;

/**
 * #4248: real two-process void race evidence on this independent slice.
 * Sequential double-void would pass the outer status check on old code and does not prove the race.
 * Here the delayed worker reads confirmed (unlocked preflight), pauses, the winner voids, then the
 * delayed worker is released and must refuse with a fresh status — only one void reversal payment.
 *
 * Mixed-writer (void × BillingCorrection) was not covered by the prior source review on 15b319d;
 * this file now proves course-first lock order under real two-process interleaving.
 *
 * Process plumbing (bounded non-blocking finish + tearDown terminate) lives in PaymentReportRaceWorker.
 */
class PaymentReportVoidRaceTest extends TestCase
{
    use PaymentReportRaceWorker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->raceAssertMysqlRr();
        // Committed rows visible to separate PDO workers — no RefreshDatabase outer wrap.
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->assertSame('REPEATABLE-READ', alltrue_race_isolation_level());
    }

    protected function tearDown(): void
    {
        $this->raceTerminateAllWorkers();
        $this->raceCleanupFiles();
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_delayed_second_void_after_confirmed_preflight_does_not_duplicate_reversal(): void
    {
        [$actorId, $report, $invoice] = $this->confirmedReportFixture(paidFlag: 1, reportAmount: 8800, invoicePaid: 8800);

        // Delayed worker pauses on the unlocked preflight SELECT (no FOR UPDATE) after seeing confirmed.
        $pause = $this->racePause('from `payment_reports`');
        $pause['locked'] = false;
        $delayed = $this->spawnVoid($actorId, (int) $report->id, 'Delayed synthetic reversal', $pause);
        $this->raceAwait($pause['ready']);
        $this->assertSame('confirmed', $report->fresh()->status, 'winner has not voided yet; delayed already saw confirmed');

        $winner = $this->spawnVoid($actorId, (int) $report->id, 'Winning synthetic reversal');
        try {
            $winnerResult = $this->raceFinish($winner);
            $this->assertSame(200, $winnerResult['status'], json_encode($winnerResult));
            $this->assertSame('voided', $report->fresh()->status);

            touch($pause['release']);
            $delayedResult = $this->raceFinish($delayed);
            $this->assertSame(422, $delayedResult['status'], json_encode($delayedResult));
            $this->assertStringContainsString('已處理過', (string) ($delayedResult['body']['message'] ?? ''));
            $this->assertNotSame(500, $delayedResult['status']);
        } finally {
            $this->raceTerminateAllWorkers();
        }

        $this->assertSame(1, Payment::where('Method', 'void')->where('InvoiceID', $invoice->id)->count());
        $this->assertSame(2, Payment::where('InvoiceID', $invoice->id)->count());
        $this->assertSame('voided', $report->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->Status);
    }

    /**
     * Deterministic mixed-writer: correction holds StudentClass FOR UPDATE then pauses;
     * void signals via beforeExecuting immediately before its authoritative StudentClass
     * FOR UPDATE (course-first: report not held yet; report-first: report already held);
     * parent releases correction only after that marker — not after worker-started / sleep.
     * Correction then locks reports → 409; void voids once. Deadlock/500 ≠ success.
     */
    public function test_mixed_writer_correction_then_void_does_not_deadlock_and_voids_once(): void
    {
        [$actorId, $report, $invoice, $course] = $this->confirmedReportFixture(
            paidFlag: 0,
            reportAmount: 4400,
            invoicePaid: 4400,
            invoiceStatus: 'partial'
        );

        $svc = app(BillingCorrection::class);
        $token = $svc->confirmationToken($course->fresh(), 6, 6600, 0, [], $actorId);

        // Pause after correction acquires the course lock (same prefix void now uses first).
        $pause = $this->racePause('from `studentclass`');
        $correction = $this->spawnCorrection([
            'class_id' => (int) $course->ID,
            'new_count' => 6,
            'new_charge' => 6600,
            'old_count' => 8,
            'old_charge' => 8800,
            'confirmation_token' => $token,
            'actor_id' => $actorId,
            'reason' => 'mixed-writer correction',
        ], $pause);
        $this->raceAwait($pause['ready']);

        $voidAtCourseLock = $this->raceFile();
        $void = $this->spawnVoid(
            $actorId,
            (int) $report->id,
            'Mixed-writer void after correction pause',
            null,
            $voidAtCourseLock
        );
        // Handshake: void reached authoritative StudentClass FOR UPDATE (not merely process start).
        $this->raceAwait($voidAtCourseLock);

        touch($pause['release']);
        try {
            $correctionResult = $this->raceFinish($correction);
            $voidResult = $this->raceFinish($void);

            $this->assertNotSame(500, $correctionResult['status'], json_encode($correctionResult));
            $this->assertNotSame(500, $voidResult['status'], json_encode($voidResult));
            $this->assertSame(409, $correctionResult['status'], json_encode($correctionResult));
            $this->assertSame(
                'billing_correction_payment_report_locked',
                $correctionResult['body']['code'] ?? null,
                json_encode($correctionResult)
            );
            $this->assertSame(200, $voidResult['status'], json_encode($voidResult));
        } finally {
            $this->raceTerminateAllWorkers();
        }

        $this->assertSame('voided', $report->fresh()->status);
        $this->assertSame(1, Payment::where('Method', 'void')->where('InvoiceID', $invoice->id)->count());
        $this->assertSame(2, Payment::where('InvoiceID', $invoice->id)->count());
        $this->assertSame(0, (int) $invoice->fresh()->PaidAmount);
        $this->assertSame(8, (int) $course->fresh()->SessionCount, 'correction must not stamp after 409');
        $this->assertSame(8800, (int) $course->fresh()->Charge);
    }

    /** @return array{0:int,1:PaymentReport,2:Invoice,3:StudentClass} */
    private function confirmedReportFixture(
        int $paidFlag,
        int $reportAmount,
        int $invoicePaid,
        string $invoiceStatus = 'paid'
    ): array {
        $user = User::create([
            'LoginName' => 'void-race-'.Str::uuid().'@example.com',
            'Name' => 'void-race',
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
            // students.name is varchar(32) strict — keep synthetic name ≤32 (uuid alone is 36).
            'name' => 'vr-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 28),
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
            'Paid' => $paidFlag,
            'PayDate' => $paidFlag === 1 ? '2026-04-10' : null,
            'Stop' => 0,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'TotalAmount' => 8800,
            'PaidAmount' => $invoicePaid,
            'Status' => $invoiceStatus,
        ]);
        $payment = Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => $reportAmount,
            'PaidAt' => '2026-04-10',
            'Method' => 'cash',
        ]);
        $report = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'InvoiceID' => $invoice->id,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'cash',
            'reported_amount' => $reportAmount,
            'status' => 'confirmed',
            'confirmed_by' => $user->id,
            'confirmed_at' => Carbon::now(),
            'payment_id' => $payment->id,
            'report_token_hash' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        return [(int) $user->id, $report, $invoice, $sc];
    }

    private function spawnVoid(
        int $actorId,
        int $reportId,
        string $reason,
        ?array $pause = null,
        ?string $signalBeforeStudentClassForUpdate = null
    ): array {
        $payload = [
            'actor' => ['id' => $actorId, 'role' => 'director', 'campus_ids' => [1]],
            'id' => $reportId,
            'input' => ['void_reason' => $reason],
            'pause' => $pause,
        ];
        if ($signalBeforeStudentClassForUpdate !== null) {
            $payload['signal_before_studentclass_for_update'] = $signalBeforeStudentClassForUpdate;
        }

        return $this->raceSpawn(
            base_path('tests/Support/payment-void-race-worker.php'),
            $payload
        );
    }

    /** @param array<string, mixed> $payload */
    private function spawnCorrection(array $payload, ?array $pause = null): array
    {
        return $this->raceSpawn(
            base_path('tests/Support/payment-void-correction-race-worker.php'),
            ['pause' => $pause] + $payload
        );
    }
}
