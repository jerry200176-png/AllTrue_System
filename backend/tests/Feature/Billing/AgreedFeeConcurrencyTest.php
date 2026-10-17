<?php

namespace Tests\Feature\Billing;

use App\Models\{Invoice, Payment, PaymentReport};
use App\Services\Billing\AgreedFeeCorrection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Support\AgreedFeeFixture;
use Tests\TestCase;

/** Real InnoDB REPEATABLE READ, separate PHP processes/PDO connections, deterministic lock barriers. */
class AgreedFeeConcurrencyTest extends TestCase
{
    use AgreedFeeFixture;
    private array $files = [];
    protected function setUp(): void
    {
        parent::setUp(); config(['billing.agreed_fee_correction_enabled' => true, 'app.key' => str_repeat('t', 32)]);
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('AllTrue_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
    }
    protected function tearDown(): void { foreach ($this->files as $p) @unlink($p); RefreshDatabaseState::$migrated = false; parent::tearDown(); }
    private function file(): string { $p = '/tmp/fee-'.Str::uuid(); $this->files[] = $p; return $p; }
    private function actor(array $f): array { return ['id' => (int) $f['user']->id, 'role' => 'director', 'campus_ids' => [1], 'student_id' => (int) $f['student']->id]; }
    private function preview(array $f, ?array $body = null): array
    {
        $body ??= $this->feePayload($f['invoice']);
        return $body + ['preview_token' => app(AgreedFeeCorrection::class)->run($f['course']->ID, $body, $this->actor($f))['preview_token']];
    }
    private function spawn(array $f, string $op, array $input, ?int $id = null, ?array $pause = null): array
    {
        $started = $this->file();
        $process = proc_open([PHP_BINARY, base_path('tests/Support/agreed-fee-worker.php')], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes, base_path());
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode(['db' => config('database.connections.mysql'), 'actor' => $this->actor($f),
            'course_id' => $f['course']->ID, 'op' => $op, 'input' => $input, 'id' => $id, 'pause' => $pause, 'started' => $started]));
        fclose($pipes[0]);
        $this->await($started);
        return [$process, $pipes];
    }
    private function await(string $file): void
    {
        $deadline = microtime(true)+15;
        while (!file_exists($file) && microtime(true)<$deadline) usleep(5000);
        $this->assertFileExists($file, 'worker reached deterministic barrier');
    }
    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $this->assertSame(0, proc_close($process), $err);
        $result = json_decode($out, true); $this->assertIsArray($result, $out.$err); return $result;
    }
    private function pause(string $match): array { return ['match' => $match, 'ready' => $this->file(), 'release' => $this->file()]; }

    /** @dataProvider scopesAndKeys */
    public function test_two_fee_processes_have_one_winner_and_permanent_replay(string $scope, bool $sameKey): void
    {
        $f = $this->fixture();
        if ($scope === 'unbilled') { DB::table('InvoiceItem')->delete(); $f['invoice']->delete(); }
        $body = $scope === 'billed' ? $this->feePayload($f['invoice']) : ['scope' => 'unbilled', 'new_charge' => 500, 'reason' => 'Synthetic', 'idempotency_key' => (string) Str::uuid()];
        $first = $this->preview($f, $body);
        $second = $sameKey ? $first : $this->preview($f, array_replace($body, ['idempotency_key' => (string) Str::uuid()]));
        $pause = $this->pause('from `studentclass` where `studentclass`.`id` =');
        $one = $this->spawn($f, 'fee', $first, null, $pause); $this->await($pause['ready']);
        $two = $this->spawn($f, 'fee', $second);
        touch($pause['release']);
        $a = $this->finish($one); $b = $this->finish($two);
        $this->assertSame(200, $a['status'], json_encode($a)); $this->assertSame($sameKey ? 200 : 409, $b['status'], json_encode($b));
        if ($sameKey) $this->assertSame($a['body'], $b['body']);
        $this->assertSame(1, DB::table('invoice_corrections')->count());
        $this->assertSame($scope === 'billed' ? 2 : 0, Invoice::count());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'billing.agreed_fee_corrected')->count());
        $this->assertSame(0, Payment::count()); $this->assertSame(0, PaymentReport::count());
    }
    public static function scopesAndKeys(): array { return [['billed', true], ['billed', false], ['unbilled', true], ['unbilled', false]]; }

    public function test_direct_receipt_wins_then_stale_fee_fails_and_fee_wins_then_old_bill_receipt_fails(): void
    {
        foreach ([true, false] as $paymentWins) {
            $f = $this->fixture(); $input = $this->preview($f);
            $pause = $this->pause($paymentWins ? 'from `invoice` where `invoice`.`id`' : 'from `studentclass` where `studentclass`.`id` =');
            $first = $paymentWins ? $this->spawn($f, 'payment', ['Amount' => 500], $f['invoice']->id, $pause) : $this->spawn($f, 'fee', $input, null, $pause);
            $this->await($pause['ready']);
            $second = $paymentWins ? $this->spawn($f, 'fee', $input) : $this->spawn($f, 'payment', ['Amount' => 500], $f['invoice']->id);
            touch($pause['release']);
            $a = $this->finish($first); $b = $this->finish($second);
            $this->assertSame(200, $a['status'], json_encode($a)); $this->assertSame($paymentWins ? 409 : 422, $b['status'], json_encode($b));
            $this->assertSame($paymentWins ? 'partial' : 'void', $f['invoice']->fresh()->Status);
            $this->assertSame($paymentWins ? 8000 : 500, (int) $f['course']->fresh()->Charge);
        }
    }

    public function test_report_confirmation_serializes_with_stale_fee_apply(): void
    {
        $f = $this->fixture(); $input = $this->preview($f); $report = $this->reportFor($f);
        $pause = $this->pause('from `studentclass` where `studentclass`.`id` =');
        $confirm = $this->spawn($f, 'confirm', [], $report->id, $pause); $this->await($pause['ready']);
        $fee = $this->spawn($f, 'fee', $input); touch($pause['release']);
        $this->assertSame(200, $this->finish($confirm)['status']); $this->assertSame(409, $this->finish($fee)['status']);
        $this->assertSame('confirmed', $report->fresh()->status); $this->assertSame(1, Payment::count());
        $this->assertSame(0, DB::table('invoice_corrections')->count());
    }

    public function test_delayed_stale_void_cannot_duplicate_reversal_or_reopen_a_corrected_bill(): void
    {
        $f = $this->fixture(); $report = $this->receiptFor($f);
        // Pause after the old request has fetched confirmed, before its transaction/status recheck.
        $pause = $this->pause('from `payment_reports` where `payment_reports`.`id` ='); $pause['locked'] = false;
        $stale = $this->spawn($f, 'void', ['void_reason' => 'Delayed synthetic reversal'], $report->id, $pause); $this->await($pause['ready']);
        $winner = $this->spawn($f, 'void', ['void_reason' => 'Winning synthetic reversal'], $report->id);
        $this->assertSame(200, $this->finish($winner)['status']);
        $input = $this->preview($f); $fee = $this->spawn($f, 'fee', $input); $this->assertSame(200, $this->finish($fee)['status']);
        touch($pause['release']); $this->assertSame(422, $this->finish($stale)['status']);
        $this->assertSame(1, Payment::where('Method', 'void')->count()); $this->assertSame(2, Payment::count());
        $this->assertSame('void', $f['invoice']->fresh()->Status); $this->assertSame('voided', $report->fresh()->status);
        $this->assertSame(500, (int) $f['course']->fresh()->Charge);
    }

    /** @dataProvider voidKinds */
    public function test_invoice_void_writers_and_fee_cannot_overwrite_each_other(string $operation): void
    {
        foreach ([true, false] as $voidWins) {
            $f = $this->fixture(); $input = $this->preview($f);
            $pause = $this->pause($voidWins ? 'from `invoice` where `invoice`.`id`' : 'from `studentclass` where `studentclass`.`id` =');
            $first = $voidWins ? $this->spawn($f, $operation, ['reason' => 'Synthetic invoice void'], $f['invoice']->id, $pause) : $this->spawn($f, 'fee', $input, null, $pause);
            $this->await($pause['ready']);
            $second = $voidWins ? $this->spawn($f, 'fee', $input) : $this->spawn($f, $operation, ['reason' => 'Synthetic invoice void'], $f['invoice']->id);
            touch($pause['release']);
            $this->assertSame(200, $this->finish($first)['status']); $this->assertSame($voidWins ? 409 : 422, $this->finish($second)['status']);
            $this->assertSame('void', $f['invoice']->fresh()->Status); $this->assertSame($voidWins ? 8000 : 500, (int) $f['course']->fresh()->Charge);
        }
    }
    public static function voidKinds(): array { return [['invoice_void'], ['exception_void']]; }

    public function test_report_creation_and_fee_are_serialized_in_both_orders(): void
    {
        foreach ([true, false] as $recordWins) {
            $f = $this->fixture(); $input = $this->preview($f);
            $recordBody = ['student_class_id' => $f['course']->ID, 'invoice_id' => $f['invoice']->id, 'amount' => 500, 'payment_method' => 'cash', 'payment_date' => '2026-10-17'];
            $pause = $this->pause('from `studentclass` where `studentclass`.`id` =');
            $first = $recordWins ? $this->spawn($f, 'record', $recordBody, null, $pause) : $this->spawn($f, 'fee', $input, null, $pause);
            $this->await($pause['ready']);
            $second = $recordWins ? $this->spawn($f, 'fee', $input) : $this->spawn($f, 'record', $recordBody);
            touch($pause['release']);
            $this->assertSame(200, $this->finish($first)['status']); $this->assertSame($recordWins ? 409 : 422, $this->finish($second)['status']);
            $this->assertSame($recordWins ? 1 : 0, PaymentReport::where('StudentClassID', $f['course']->ID)->count());
            $this->assertSame(0, Payment::count());
        }
    }

    public function test_unbilled_correction_and_normal_invoice_issuance_serialize_in_both_orders(): void
    {
        foreach ([true, false] as $issueWins) {
            $f = $this->fixture(); DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->delete(); $f['invoice']->delete();
            $body = ['scope' => 'unbilled', 'new_charge' => 500, 'reason' => 'Synthetic unbilled', 'idempotency_key' => (string) Str::uuid()];
            $input = $this->preview($f, $body);
            $issueBody = ['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID, 'IssueDate' => '2026-10-17', 'TotalAmount' => 500];
            $pause = $this->pause('from `student` where');
            $first = $issueWins ? $this->spawn($f, 'issue', $issueBody, null, $pause) : $this->spawn($f, 'fee', $input, null, $pause);
            $this->await($pause['ready']);
            $second = $issueWins ? $this->spawn($f, 'fee', $input) : $this->spawn($f, 'issue', $issueBody);
            touch($pause['release']);
            $this->assertSame($issueWins ? 201 : 200, $this->finish($first)['status']);
            $this->assertSame($issueWins ? 409 : 201, $this->finish($second)['status']);
            $this->assertSame(1, Invoice::where('StudentClassID', $f['course']->ID)->count());
            $this->assertSame($issueWins ? 8000 : 500, (int) $f['course']->fresh()->Charge);
        }
    }


    public function test_missing_extension_fails_closed_and_legacy_shared_table_adoption_keeps_receipt_kind(): void
    {
        $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        DB::statement('RENAME TABLE invoice_corrections TO synthetic_missing_corrections');
        try {
            $this->withToken($f['token'])->postJson('/api/v1/student-classes/'.$f['course']->ID.'/agreed-fee-correction/preview', $body)->assertStatus(503);
        } finally { DB::statement('RENAME TABLE synthetic_missing_corrections TO invoice_corrections'); }
        $extension = require database_path('migrations/2026_10_17_120000_extend_invoice_corrections_for_agreed_fee.php');
        $extension->down();
        DB::table('invoice_corrections')->insert(['original_invoice_id' => 10001, 'replacement_invoice_id' => 10002,
            'student_class_id' => $f['course']->ID, 'amount' => 7600, 'reason' => 'Synthetic receipt prerequisite', 'idempotency_key' => (string) Str::uuid()]);
        $extension->up();
        $row = DB::table('invoice_corrections')->sole(); $this->assertSame('receipt_reissue', $row->kind);
        $this->assertSame(7600, (int) $row->amount); $this->assertNull($row->new_charge);
    }

}
