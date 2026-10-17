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
use Tests\TestCase;

/**
 * #4248: real two-process void race evidence on this independent slice.
 * Sequential double-void would pass the outer status check on old code and does not prove the race.
 * Here the delayed worker reads confirmed (unlocked preflight), pauses, the winner voids, then the
 * delayed worker is released and must refuse with a fresh status — only one void reversal payment.
 */
class PaymentReportVoidRaceTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('AllTrue_test', DB::connection()->getDatabaseName());
        // Committed rows visible to separate PDO workers — no RefreshDatabase outer wrap.
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $p) {
            @unlink($p);
        }
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_delayed_second_void_after_confirmed_preflight_does_not_duplicate_reversal(): void
    {
        [$actorId, $report, $invoice] = $this->confirmedReportFixture();

        // Delayed worker pauses on the unlocked preflight SELECT (no FOR UPDATE) after seeing confirmed.
        $pause = $this->pause('from `payment_reports`');
        $pause['locked'] = false;
        $delayed = $this->spawn($actorId, (int) $report->id, 'Delayed synthetic reversal', $pause);
        $this->await($pause['ready']);
        $this->assertSame('confirmed', $report->fresh()->status, 'winner has not voided yet; delayed already saw confirmed');

        $winner = $this->spawn($actorId, (int) $report->id, 'Winning synthetic reversal');
        $winnerResult = $this->finish($winner);
        $this->assertSame(200, $winnerResult['status'], json_encode($winnerResult));
        $this->assertSame('voided', $report->fresh()->status);

        touch($pause['release']);
        $delayedResult = $this->finish($delayed);
        $this->assertSame(422, $delayedResult['status'], json_encode($delayedResult));
        $this->assertStringContainsString('已處理過', (string) ($delayedResult['body']['message'] ?? ''));

        $this->assertSame(1, Payment::where('Method', 'void')->where('InvoiceID', $invoice->id)->count());
        $this->assertSame(2, Payment::where('InvoiceID', $invoice->id)->count());
        $this->assertSame('voided', $report->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->Status);
    }

    /** @return array{0:int,1:PaymentReport,2:Invoice} */
    private function confirmedReportFixture(): array
    {
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
            'name' => 'void-race-'.Str::uuid(),
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
            'Paid' => 1,
            'PayDate' => '2026-04-10',
            'Stop' => 0,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'TotalAmount' => 8800,
            'PaidAmount' => 8800,
            'Status' => 'paid',
        ]);
        $payment = Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 8800,
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
            'reported_amount' => 8800,
            'status' => 'confirmed',
            'confirmed_by' => $user->id,
            'confirmed_at' => Carbon::now(),
            'payment_id' => $payment->id,
            'report_token_hash' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        return [(int) $user->id, $report, $invoice];
    }

    private function file(): string
    {
        $p = '/tmp/void-race-'.Str::uuid();
        $this->files[] = $p;

        return $p;
    }

    private function pause(string $match): array
    {
        return ['match' => $match, 'ready' => $this->file(), 'release' => $this->file()];
    }

    private function spawn(int $actorId, int $reportId, string $reason, ?array $pause = null): array
    {
        $started = $this->file();
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/payment-void-race-worker.php')],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path()
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode([
            'db' => config('database.connections.mysql'),
            'actor' => ['id' => $actorId, 'role' => 'director', 'campus_ids' => [1]],
            'id' => $reportId,
            'input' => ['void_reason' => $reason],
            'pause' => $pause,
            'started' => $started,
        ], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $this->await($started);

        return [$process, $pipes];
    }

    private function await(string $file): void
    {
        $deadline = microtime(true) + 15;
        while (!file_exists($file) && microtime(true) < $deadline) {
            usleep(5000);
        }
        $this->assertFileExists($file, 'worker reached deterministic barrier');
    }

    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);
        $result = json_decode($out, true);
        $this->assertIsArray($result, $out.$err);

        return $result;
    }
}
