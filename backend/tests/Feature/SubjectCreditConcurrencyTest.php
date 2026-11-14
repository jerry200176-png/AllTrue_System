<?php

namespace Tests\Feature;

use App\Models\{AuthToken, Payment, PaymentReport, Student, StudentClass};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\{PaymentReportRaceWorker, SubjectCreditFixtures};
use Tests\TestCase;

/** #535: committed fixtures, separate PDOs, actual InnoDB locking reads; no production database. */
class SubjectCreditConcurrencyTest extends TestCase
{
    use PaymentReportRaceWorker;
    use SubjectCreditFixtures;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
        $this->raceAssertMysqlRr();
    }

    protected function tearDown(): void
    {
        $this->raceTerminateAllWorkers();
        $this->raceCleanupFiles();
        while (DB::transactionLevel() > 0) DB::rollBack();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        // Workers commit outside RefreshDatabase's transaction. The next class must rebuild, not inherit our fixture.
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keys')]
    public function test_competing_transfers_have_one_atomic_winner(bool $sameKey): void
    {
        [$token, $source, $template, $invoice] = $this->fixture();
        $input = ['target_course_id' => $template->ID, 'sessions' => 2];
        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", $input)->assertOk();
        $input += ['reason' => 'isolated contender', 'idempotency_key' => 'isolated-same-key', 'state_hash' => $preview->json('state_hash')];
        $pause = $this->racePause('from `studentclass`');
        $one = $this->spawn($token, $source, $input, $pause, ['snapshot_invoice_id' => $invoice->id]);
        $this->raceAwait($pause['ready']);
        $two = $this->spawn($token, $source, array_replace($input, ['idempotency_key' => $sameKey ? 'isolated-same-key' : 'isolated-new-key']), null, ['snapshot_invoice_id' => $invoice->id]);
        $this->awaitBlocked($two, 'student');
        touch($pause['release']);
        $a = $this->raceFinish($one); $b = $this->raceFinish($two);
        $this->assertSame(201, $a['status'], json_encode($a));
        $this->assertSame($sameKey ? 200 : 409, $b['status'], json_encode($b));
        if ($sameKey) $this->assertSame($a['body']['transfer']['id'], $b['body']['transfer']['id']);
        $this->assertDatabaseCount('contract_subject_credit_transfers', 1);
        $this->assertSame(6, (int) $source->fresh()->SessionCount);
        $this->assertSame(12000, (int) Payment::sum('Amount'));
        $this->assertSame(1, Payment::where('Method', 'transfer_in')->count());
    }

    public static function keys(): array { return [[true], [false]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('keys')]
    public function test_receipt_void_and_transfer_cannot_both_remove_the_same_funds(bool $transferFirst): void
    {
        [$token, $source, $template, $invoice] = $this->fixture();
        $cash = Payment::where('InvoiceID', $invoice->id)->first();
        $report = PaymentReport::create(['StudentID' => $source->StudentID, 'StudentClassID' => $source->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => 'isolated payer', 'payment_date' => '2026-09-01', 'payment_method' => 'cash',
            'reported_amount' => 12000, 'status' => 'confirmed', 'confirmed_at' => now(), 'payment_id' => $cash->id,
            'report_token_hash' => hash('sha256', 'isolated race receipt'), 'token_expires_at' => now()->addDay()]);
        $cash->forceFill(['payment_report_id' => $report->id])->save();
        $input = ['target_course_id' => $template->ID, 'sessions' => 2];
        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", $input)->assertOk();
        $input += ['reason' => 'isolated contender', 'idempotency_key' => 'isolated-void-race', 'state_hash' => $preview->json('state_hash')];
        $pause = $this->racePause('from `studentclass`');
        $void = ['op' => 'void', 'report_id' => $report->id, 'input' => ['void_reason' => 'isolated correction']];
        $one = $this->spawn($token, $source, $input, $pause, $transferFirst ? [] : $void);
        $this->raceAwait($pause['ready']);
        $two = $this->spawn($token, $source, $input, null, $transferFirst ? $void : []);
        $this->awaitBlocked($two, 'studentclass');
        touch($pause['release']);
        $a = $this->raceFinish($one); $b = $this->raceFinish($two);
        $this->assertSame($transferFirst ? 201 : 200, $a['status'], json_encode($a));
        $this->assertSame($transferFirst ? 409 : 422, $b['status'], json_encode($b));
        $this->assertDatabaseCount('contract_subject_credit_transfers', $transferFirst ? 1 : 0);
        $this->assertSame($transferFirst ? 12000 : 0, (int) Payment::sum('Amount'));
        $this->assertSame($transferFirst ? 'confirmed' : 'voided', $report->fresh()->status);
    }

    public function test_actual_schedule_commit_before_materialization_cannot_lose_its_reservation(): void
    {
        foreach ([false, true] as $reverse) {
            [$token, $source, $template] = $this->fixture();
            $input = ['target_course_id' => $template->ID, 'sessions' => 2];
            $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", $input)->assertOk();
            $input += ['reason' => 'isolated projection barrier', 'idempotency_key' => 'isolated-projection-key', 'state_hash' => $preview->json('state_hash')];
            $course = $source;
            $actor = (int) AuthToken::where('token', $token)->value('user_id');
            $campus = (int) Student::find($source->StudentID)->CampusID;
            $movement = app(\App\Services\Billing\SubjectCreditMovement::class);
            $transfer = $reverse ? $movement->apply($source, $input, $actor, [$campus])['body']['transfer'] : null;
            if ($reverse) $course = StudentClass::findOrFail($transfer['target_course_id']);
            $observed = null;
            $enabled = true;
            \App\Models\Schedule::created(function ($schedule) use (&$enabled, &$observed, $course, $source, $input, $reverse, $transfer, $movement, $actor, $campus): void {
                if (!$enabled || (int) $schedule->student_course_id !== (int) $course->ID) return;
                DB::afterCommit(function () use (&$observed, $course, $source, $input, $reverse, $transfer, $movement, $actor, $campus): void {
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertSame(0, DB::table('ClassSession')->where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-10-14')->count());
                    try {
                        if ($reverse) $movement->reverse($source, $transfer['id'], 'must retain committed reservation', $actor, [$campus]);
                        else $movement->apply($source, $input, $actor, [$campus]);
                        $observed = 200;
                    } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) { $observed = $e->getResponse()->getStatusCode(); }
                });
            });
            try {
                $this->withToken($token)->postJson('/api/v1/schedules', ['student_id' => $source->StudentID, 'student_course_id' => $course->ID,
                    'teacher_id' => $course->TeacherID, 'branch_id' => $campus, 'subject' => 'isolated', 'day_of_week' => 3,
                    'schedule_date' => '2026-10-14', 'start_time' => '18:00', 'end_time' => '20:00',
                    'duration_hours' => 2, 'status' => 'scheduled', 'type' => 'normal'])->assertCreated();
            } finally { $enabled = false; }
            $this->assertSame($reverse ? 409 : 422, $observed, 'Credit decision runs after committed booking and before its ClassSession projection.');
            $this->assertSame(1, DB::table('ClassSession')->where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-10-14')->count());
            $this->assertSame($reverse ? 6 : 8, (int) $source->fresh()->SessionCount);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keys')]
    public function test_exhausted_source_and_reversed_target_cannot_accept_later_bookings(bool $reverse): void
    {
        [$token, $source, $template] = $this->fixture(used: 0);
        $input = ['target_course_id' => $template->ID, 'sessions' => 8];
        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", $input)->assertOk();
        $input += ['reason' => 'isolated exhausted credit', 'idempotency_key' => 'isolated-closed-key', 'state_hash' => $preview->json('state_hash')];
        $r = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer", $input)->assertCreated();
        $course = $source;
        if ($reverse) {
            $course = StudentClass::findOrFail($r->json('transfer.target_course_id'));
            $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/{$r->json('transfer.id')}/reverse", ['reason' => 'isolated restore'])->assertOk();
        }
        $before = $this->businessSnapshot();
        $campus = (int) Student::find($source->StudentID)->CampusID;
        foreach (['scheduled', 'SCHEDULED'] as $status) {
            $this->withToken($token)->postJson('/api/v1/schedules', ['student_id' => $source->StudentID, 'student_course_id' => $course->ID,
                'teacher_id' => $course->TeacherID, 'branch_id' => $campus, 'subject' => 'isolated', 'day_of_week' => 3,
                'schedule_date' => '2026-10-14', 'start_time' => '18:00', 'end_time' => '20:00',
                'duration_hours' => 2, 'status' => $status, 'type' => 'normal'])->assertStatus(422)->assertJsonPath('code', 'subject_credit_closed_course');
            $this->assertSame($before, $this->businessSnapshot(), 'Rejected booking must not leave a committed Schedule, including uppercase legacy inputs.');
        }
        // The materializer can receive a previously loaded active contract. It must recheck committed credit.
        try {
            app(\App\Services\ClassSessionMaterializationService::class)->upsertSlot(['StudentClassID' => $course->ID,
                'SessionDate' => '2026-10-14', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'scheduled', '_student_class' => $course]);
            $this->fail('Stale preloaded course must not bypass exhausted credit.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame('subject_credit_closed_course', $e->getResponse()->getData(true)['code']);
        }
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_partial_receipt_void_winner_cannot_be_overwritten_by_a_stale_reversal_snapshot(): void
    {
        [$token, $source, $template, $invoice] = $this->fixture();
        $cash = Payment::where('InvoiceID', $invoice->id)->first();
        $cash->forceFill(['Amount' => 6000])->save();
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 6000, 'PaidAt' => '2026-09-01', 'Method' => 'cash']);
        $report = PaymentReport::create(['StudentID' => $source->StudentID, 'StudentClassID' => $source->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => 'isolated payer', 'payment_date' => '2026-09-01', 'payment_method' => 'cash',
            'reported_amount' => 6000, 'status' => 'confirmed', 'confirmed_at' => now(), 'payment_id' => $cash->id,
            'report_token_hash' => hash('sha256', 'isolated partial receipt'), 'token_expires_at' => now()->addDay()]);
        $cash->forceFill(['payment_report_id' => $report->id])->save();
        $input = ['target_course_id' => $template->ID, 'sessions' => 2];
        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", $input)->assertOk();
        $input += ['reason' => 'isolated partial receipt race', 'idempotency_key' => 'isolated-reverse-race', 'state_hash' => $preview->json('state_hash')];
        $r = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer", $input)->assertCreated();
        $pause = $this->racePause('from `studentclass`');
        $one = $this->spawn($token, $source, ['void_reason' => 'isolated partial void'], $pause, ['op' => 'void', 'report_id' => $report->id]);
        $this->raceAwait($pause['ready']);
        $two = $this->spawn($token, $source, ['reason' => 'must not restore stale money'], null,
            ['op' => 'reverse', 'transfer_id' => $r->json('transfer.id'), 'snapshot_invoice_id' => $invoice->id]);
        $this->awaitBlocked($two, 'studentclass');
        touch($pause['release']);
        $a = $this->raceFinish($one); $b = $this->raceFinish($two);
        $this->assertSame(200, $a['status'], json_encode($a));
        $this->assertSame(409, $b['status'], json_encode($b));
        $this->assertSame('subject_credit_reverse_changed', $b['body']['code'] ?? null);
        $this->assertNull(DB::table('contract_subject_credit_transfers')->value('reversed_at'));
        $this->assertSame(3000, (int) $invoice->fresh()->PaidAmount);
        $this->assertSame(6000, (int) Payment::sum('Amount'));
        $this->assertSame(0, Payment::where('Method', 'transfer_reversal_in')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keys')]
    public function test_booking_waiting_on_the_student_mutex_rechecks_credit_after_transfer_or_reverse_wins(bool $reverse): void
    {
        [$token, $source, $template, $invoice] = $this->fixture(used: 0);
        $input = ['target_course_id' => $template->ID, 'sessions' => 8];
        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", $input)->assertOk();
        $input += ['reason' => 'isolated booking race', 'idempotency_key' => 'isolated-booking-race', 'state_hash' => $preview->json('state_hash')];
        $course = $source; $first = [];
        if ($reverse) {
            $r = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer", $input)->assertCreated();
            $course = StudentClass::findOrFail($r->json('transfer.target_course_id'));
            $first = ['op' => 'reverse', 'transfer_id' => $r->json('transfer.id'), 'input' => ['reason' => 'isolated restore']];
        }
        $pause = $this->racePause('from `studentclass`');
        $one = $this->spawn($token, $source, $input, $pause, $first);
        $this->raceAwait($pause['ready']);
        $campus = (int) Student::find($source->StudentID)->CampusID;
        $booking = ['student_id' => $source->StudentID, 'student_course_id' => $course->ID, 'teacher_id' => $course->TeacherID,
            'branch_id' => $campus, 'day_of_week' => 3, 'schedule_date' => '2026-10-14', 'start_time' => '18:00', 'end_time' => '20:00',
            'duration_hours' => 2, 'status' => 'scheduled', 'type' => 'normal'];
        $two = $this->spawn($token, $source, $booking, null, ['op' => 'schedule', 'snapshot_invoice_id' => $invoice->id]);
        $this->awaitBlocked($two, 'student');
        touch($pause['release']);
        $a = $this->raceFinish($one); $b = $this->raceFinish($two);
        $this->assertSame($reverse ? 200 : 201, $a['status'], json_encode($a));
        $this->assertSame(422, $b['status'], json_encode($b));
        $this->assertSame('subject_credit_closed_course', $b['body']['code'] ?? null);
        $this->assertSame(0, DB::table('schedules')->count());
        $this->assertSame(0, DB::table('ClassSession')->count());
        $this->assertSame(12000, (int) Payment::sum('Amount'));
    }

    private function spawn(string $token, StudentClass $source, array $input, ?array $pause = null, array $overrides = []): array
    {
        $connection = $this->raceFile();
        return $this->raceSpawn(base_path('tests/Support/subject-credit-worker.php'), array_replace([
            'op' => 'apply', 'course_id' => (int) $source->ID, 'input' => $input, 'pause' => $pause,
            'actor_id' => (int) AuthToken::where('token', $token)->value('user_id'),
            'campus_id' => (int) Student::find($source->StudentID)->CampusID, 'connection_file' => $connection,
        ], $overrides)) + ['connection_file' => $connection];
    }

    private function awaitBlocked(array $worker, string $table): void
    {
        $this->raceAwait($worker['connection_file']);
        $id = (int) file_get_contents($worker['connection_file']);
        $deadline = microtime(true) + 3;
        do {
            $row = DB::selectOne('SELECT INFO AS sql_text FROM information_schema.PROCESSLIST WHERE ID = ?', [$id]);
            $sql = strtolower((string) ($row->sql_text ?? ''));
            if (str_contains($sql, 'from `'.$table.'`') && str_contains($sql, 'for update')) return;
            usleep(5000);
        } while (microtime(true) < $deadline);
        $this->fail('Contender must be waiting on the actual held-row locking read before release.');
    }
}
