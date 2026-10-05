<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairUnpaidHiddenClosuresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Student')->insert(['id' => 164, 'name' => 'S', 'CampusID' => 16, 'ClassID' => 1, 'enable' => 1]);
        $base = [
            'StudentID' => 164, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'TotalHours' => 0, 'StartDate' => '2026-09-01 00:00:00', 'EndDate' => '2026-09-30 00:00:00',
            'Charge' => 6600, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1650, 'rate_unit' => 'session', 'SessionDuration' => 120,
            'ScheduleMode' => 'date', 'SessionCount' => 4, 'UsedSessions' => 0, 'RemainingSessions' => 0, 'Stop' => 1,
            'ClassType' => 'regular', 'closed_reason' => null,
        ];
        DB::table('StudentClass')->insert([
            array_merge($base, ['ID' => 3516, 'closed_reason' => 'settled']),          // unpaid, no invoice -> candidate
            array_merge($base, ['ID' => 3517, 'closed_reason' => 'completed']),        // unpaid, no invoice -> candidate
            array_merge($base, ['ID' => 3518, 'closed_reason' => 'settled', 'Paid' => 1]), // paid flag, no invoice
            array_merge($base, ['ID' => 3519, 'closed_reason' => 'settled']),          // paid by payment rows
            array_merge($base, ['ID' => 3520, 'closed_reason' => 'settled_pending']),  // already visible
            array_merge($base, ['ID' => 3521, 'closed_reason' => 'converted_trial']),  // not in scope
            array_merge($base, ['ID' => 3522, 'closed_reason' => 'settled', 'Charge' => 0]), // nothing owed
            array_merge($base, ['ID' => 3523, 'closed_reason' => 'settled', 'ClassType' => '  TUTORING  ']), // free
            array_merge($base, ['ID' => 3524, 'closed_reason' => 'settled', 'Paid' => 1]), // stored-full, payment reversed -> candidate
            array_merge($base, ['ID' => 3525, 'closed_reason' => 'settled']),          // stored-zero, rows cover it
            array_merge($base, ['ID' => 3526, 'closed_reason' => 'settled']),          // unpaid but a report is in flight
        ]);
        DB::table('payment_reports')->insert([
            'StudentID' => 164, 'StudentClassID' => 3526, 'reported_by_name' => 'P', 'payment_date' => '2026-09-05',
            'payment_method' => 'transfer', 'reported_amount' => 6600, 'status' => 'pending',
            'report_token_hash' => str_repeat('a', 64), 'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoice = fn (int $id, int $classId, int $paidAmount, string $status) => DB::table('Invoice')->insert([
            'id' => $id, 'StudentID' => 164, 'StudentClassID' => $classId, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 6600, 'PaidAmount' => $paidAmount, 'Status' => $status, 'Note' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payment = fn (int $invoiceId, int $amount, string $method) => DB::table('Payment')->insert([
            'InvoiceID' => $invoiceId, 'Amount' => $amount, 'PaidAt' => '2026-09-02 00:00:00', 'Method' => $method, 'created_at' => now(),
        ]);
        $invoice(900, 3519, 6600, 'paid');
        $payment(900, 6600, 'cash');
        $invoice(901, 3524, 6600, 'paid');
        $payment(901, 6600, 'cash');
        $payment(901, -6600, 'void');
        $invoice(902, 3525, 0, 'unpaid');
        $payment(902, 6600, 'transfer');
    }

    /** Writes the dry-run MANIFEST_JSON as a committed-style manifest; returns [relative path, sha256]. */
    private function manifest(): array
    {
        Artisan::call('repair:unpaid-hidden-closures');
        preg_match('/MANIFEST_JSON=(.+)/', Artisan::output(), $m);
        $raw = json_encode(['decision_reference' => 'repair-unpaid-hidden-closures', 'rows' => json_decode($m[1], true)], JSON_PRETTY_PRINT) . "\n";
        @mkdir(storage_path('app'), 0777, true);
        file_put_contents(storage_path('app/unpaid-hidden-test.manifest.json'), $raw);

        return ['backend/storage/app/unpaid-hidden-test.manifest.json', hash('sha256', $raw)];
    }

    private function reason(int $id): ?string
    {
        return DB::table('StudentClass')->where('ID', $id)->value('closed_reason');
    }

    public function test_dry_run_lists_only_unpaid_hidden_closures_and_changes_nothing(): void
    {
        $this->assertSame(0, Artisan::call('repair:unpaid-hidden-closures'));
        $out = Artisan::output();
        foreach ([3516, 3517] as $id) {
            $this->assertMatchesRegularExpression("/^course={$id} /m", $out);
        }
        foreach ([3518, 3519, 3520, 3521, 3522, 3523, 3524, 3525, 3526] as $id) {
            $this->assertDoesNotMatchRegularExpression("/^course={$id} /m", $out);
        }
        // Stale Paid=1 with a reversed payment is reported for review, not auto-flipped.
        $this->assertStringContainsString('STALE_PAID_REVIEW course=3524 ', $out);
        $this->assertStringContainsString('CANDIDATES=2 OUTSTANDING_TOTAL=13200 STALE_PAID=1', $out);
        $this->assertStringContainsString('MANIFEST_JSON=[{"id":3516,"closed_reason":"settled"},{"id":3517,"closed_reason":"completed"}]', $out);
        $this->assertSame('settled', $this->reason(3516));
    }

    public function test_execute_requires_the_committed_manifest_hash_and_live_rows(): void
    {
        [$path] = $this->manifest();
        $this->assertSame(1, Artisan::call('repair:unpaid-hidden-closures', ['--execute' => true, '--manifest' => $path, '--expect-manifest-sha' => str_repeat('0', 64)]));
        $this->assertSame('settled', $this->reason(3516));

        // A manifest row that stopped being a candidate (paid since) aborts the whole batch.
        [$path, $sha] = $this->manifest();
        DB::table('StudentClass')->where('ID', 3517)->update(['Paid' => 1]);
        $this->assertSame(1, Artisan::call('repair:unpaid-hidden-closures', ['--execute' => true, '--manifest' => $path, '--expect-manifest-sha' => $sha]));
        $this->assertSame('settled', $this->reason(3516));
    }

    public function test_execute_moves_manifest_rows_to_pending_and_rollback_restores(): void
    {
        [$path, $sha] = $this->manifest();
        $this->assertSame(0, Artisan::call('repair:unpaid-hidden-closures', ['--execute' => true, '--manifest' => $path, '--expect-manifest-sha' => $sha]));
        $this->assertSame('settled_pending', $this->reason(3516));
        $this->assertSame('settled_pending', $this->reason(3517));
        $this->assertSame('settled', $this->reason(3524));
        $this->assertSame(0, Artisan::call('repair:unpaid-hidden-closures', ['--verify' => true]));
        $this->assertSame($sha, json_decode((string) DB::table('session_corrections')->where('decision_reference', 'repair-unpaid-hidden-closures')->value('snapshot_before'), true)['manifest_sha256']);

        // A director confirming payment afterwards is a legitimate forward move, not a verify failure.
        DB::table('StudentClass')->where('ID', 3517)->update(['Paid' => 1, 'closed_reason' => 'settled']);
        $this->assertSame(0, Artisan::call('repair:unpaid-hidden-closures', ['--verify' => true]));
        // Falling back to any hidden reason while the ledger still shows debt is caught.
        DB::table('StudentClass')->where('ID', 3516)->update(['closed_reason' => 'completed']);
        $this->assertSame(1, Artisan::call('repair:unpaid-hidden-closures', ['--verify' => true]));
        DB::table('StudentClass')->where('ID', 3516)->update(['closed_reason' => 'settled_pending']);

        // Rollback skips the reconciled row and records the actor.
        $this->assertSame(0, Artisan::call('repair:unpaid-hidden-closures', ['--rollback' => true, '--execute' => true, '--actor' => 'gha:test']));
        $this->assertStringContainsString('restored=1', Artisan::output());
        $this->assertStringContainsString('rollback:gha:test', (string) DB::table('session_corrections')->where('decision_reference', 'repair-unpaid-hidden-closures')->value('decided_by_actor'));
        $this->assertSame('settled', $this->reason(3516));
        $this->assertSame(1, (int) DB::table('StudentClass')->where('ID', 3517)->value('Paid'));
    }
}
