<?php

namespace Tests\Feature\Billing;

use App\Models\{Campus, Invoice, InvoiceItem, Payment};
use Tests\Support\AgreedFeeRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgreedFeeFixture;
use Tests\TestCase;

class FeeCaseLedgerProbeTest extends TestCase
{
    use AgreedFeeRefreshDatabase, AgreedFeeFixture;

    protected function setUp(): void
    {
        parent::setUp();
        // The server must start a genuine READ ONLY transaction, not a nested savepoint.
        // Existing helper invalidates schema reuse so committed fixtures are freshly rebuilt.
        $this->endOuterTestingTransactions();
    }

    private function probe(array $f, ?int $campusId = null, string $name = 'Synthetic campus'): array
    {
        require_once base_path('../scripts/lib/fee-case-ledger.php');
        return alltrue_fee_case_ledger(DB::connection(), $f['course']->ID, $campusId ?? $f['student']->CampusID, $name);
    }

    private function scopedFixture(): array
    {
        $campus = Campus::factory()->create(['name' => 'Synthetic campus']);
        $f = $this->fixture();
        $f['student']->update(['CampusID' => $campus->id]);
        return $f;
    }

    public function test_projects_real_net_ledger_and_keeps_private_text_out(): void
    {
        $f = $this->scopedFixture();
        $report = $this->receiptFor($f);
        $f['invoice']->update(['zero_amount_reason' => 'gift']);
        Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => -7600, 'Method' => 'void',
            'PaidAt' => '2026-10-02', 'payment_report_id' => $report->id]);
        $report->update(['status' => 'voided']);
        $before = [$f['course']->fresh()->getRawOriginal(), Payment::count(), Invoice::count()];
        $r = $this->probe($f);
        $this->assertTrue($r['snapshot_complete']);
        $this->assertTrue($r['read_only_transaction']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, $r['invoices'][0]['net_paid']);
        $this->assertSame(7600, (int) $r['invoices'][0]['stored_paid_amount']);
        $this->assertCount(2, $r['payments']); $this->assertCount(1, $r['reports']);
        $this->assertSame('voided', $r['reports'][0]['status']);
        $this->assertTrue($r['invoices'][0]['has_zero_amount_reason']);
        $this->assertTrue($r['agreed_fee_extension_columns_present']);
        $json = json_encode($r);
        foreach (['Synthetic fee fixture', 'Original retained note', 'Original retained line', 'Fixture', 'report_token_hash'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertSame($before, [$f['course']->fresh()->getRawOriginal(), Payment::count(), Invoice::count()]);
        foreach (['transfer_out' => -200, 'transfer_in' => 200] as $method => $amount) {
            Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => $amount, 'Method' => $method, 'PaidAt' => '2026-10-01']);
        }
        $r = $this->probe($f);
        $this->assertContains('transfer_out', array_column($r['payments'], 'Method'));
        $this->assertContains('transfer_in', array_column($r['payments'], 'Method'));
        $this->assertSame(0, $r['invoices'][0]['net_paid']);
        $replacement = Invoice::create(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
            'IssueDate' => '2026-10-01', 'TotalAmount' => 500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $correction = DB::table('invoice_corrections')->insertGetId(['student_class_id' => $f['course']->ID,
            'original_invoice_id' => $f['invoice']->id, 'replacement_invoice_id' => $replacement->id,
            'payment_report_id' => $report->id, 'amount' => 500, 'reason' => 'Private correction reason',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid()]);
        $r = $this->probe($f);
        $this->assertTrue($r['snapshot_complete']); $this->assertCount(1, $r['corrections']);
        $this->assertStringNotContainsString('Private correction reason', json_encode($r));
        DB::table('invoice_corrections')->where('id', $correction)->update(['replacement_invoice_id' => $replacement->id + 100]);
        $r = $this->probe($f);
        $this->assertFalse($r['snapshot_complete']); $this->assertArrayNotHasKey('contract', $r);
        $this->assertSame('correction_history', $r['failure_stage']);
        DB::table('invoice_corrections')->where('id', $correction)->update(['replacement_invoice_id' => $replacement->id]);
        $legacy = Invoice::create(['StudentID' => $f['student']->id, 'StudentClassID' => null,
            'IssueDate' => '2026-10-01', 'TotalAmount' => 500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $r = $this->probe($f);
        $this->assertFalse($r['snapshot_complete']);
        $this->assertSame('unlinked_obligations', $r['completeness']);
        $this->assertSame([(int) $legacy->id], array_map('intval', $r['unlinked_invoice_ids']));
    }

    public function test_wrong_campus_or_campus_identity_does_not_export_contract(): void
    {
        $f = $this->scopedFixture();
        $other = Campus::factory()->create(['name' => 'Other synthetic campus']);
        foreach ([[$other->id, $other->name], [$f['student']->CampusID, 'Wrong campus name']] as [$id, $name]) {
            $r = $this->probe($f, $id, $name);
            $this->assertFalse($r['snapshot_complete']); $this->assertArrayNotHasKey('contract', $r);
            $this->assertSame(0, DB::transactionLevel());
        }
        foreach ([['date', 'tutoring'], ['date', 'regular'], ['count', 'one_on_two']] as [$mode, $type]) {
            $f['course']->update(['ScheduleMode' => $mode, 'ClassType' => $type]);
            $r = $this->probe($f);
            $this->assertTrue($r['snapshot_complete']);
            $this->assertSame($mode, $r['contract']['ScheduleMode']);
            $this->assertSame($type, $r['contract']['ClassType']);
        }
        DB::beginTransaction();
        $r = $this->probe($f);
        $this->assertSame('existing_transaction', $r['reason']);
        $this->assertSame(1, DB::transactionLevel(), 'never roll back a caller transaction');
        DB::rollBack();
    }

    public function test_mismatched_invoice_and_report_owners_fail_without_partial_payload(): void
    {
        $f = $this->scopedFixture(); $other = $this->scopedFixture();
        $f['invoice']->update(['StudentID' => $other['student']->id]);
        $r = $this->probe($f);
        $this->assertFalse($r['snapshot_complete']); $this->assertArrayNotHasKey('contract', $r);
        $f['invoice']->update(['StudentID' => $f['student']->id]);
        $report = $this->reportFor($f); $report->update(['StudentID' => $other['student']->id]);
        $r = $this->probe($f);
        $this->assertFalse($r['snapshot_complete']); $this->assertArrayNotHasKey('reports', $r);
    }

    public function test_shared_invoice_exports_only_target_lines_and_other_course_count(): void
    {
        $f = $this->scopedFixture(); $other = $this->scopedFixture();
        $other['course']->update(['StudentID' => $f['student']->id]);
        InvoiceItem::create(['InvoiceID' => $f['invoice']->id, 'StudentClassID' => $other['course']->ID,
            'Description' => 'Private shared text', 'Amount' => 12345]);
        $r = $this->probe($f);
        $this->assertTrue($r['snapshot_complete']);
        $this->assertSame(1, $r['invoices'][0]['other_course_item_count']);
        $this->assertCount(1, $r['items']);
        $this->assertStringNotContainsString('12345', json_encode($r));
        $this->assertStringNotContainsString('Private shared text', json_encode($r));
        InvoiceItem::create(['InvoiceID' => $f['invoice']->id, 'StudentClassID' => null,
            'Description' => 'Private unscoped text', 'Amount' => 6789]);
        $r = $this->probe($f);
        $this->assertFalse($r['snapshot_complete']);
        $this->assertSame(1, $r['invoices'][0]['unscoped_item_count']);
        $this->assertSame('ambiguous_obligations', $r['completeness']);
        $this->assertStringNotContainsString('6789', json_encode($r));
    }

    public function test_orphan_items_and_cross_owner_typed_items_are_incomplete(): void
    {
        $f = $this->scopedFixture(); $other = $this->scopedFixture();
        $line = InvoiceItem::create(['InvoiceID' => Invoice::max('id') + 100, 'StudentClassID' => $f['course']->ID,
            'Description' => 'Private orphan', 'Amount' => 500]);
        $orphan = $this->probe($f);
        $line->delete();
        $line = InvoiceItem::create(['InvoiceID' => $f['invoice']->id, 'StudentClassID' => $other['course']->ID,
            'Description' => 'Private foreign item', 'Amount' => 500]);
        $foreign = $this->probe($f);
        $line->update(['StudentClassID' => $other['course']->ID + 100]);
        $missing = $this->probe($f);
        $line->delete();
        $f['invoice']->update(['StudentClassID' => $other['course']->ID]);
        $header = $this->probe($f);
        $this->assertSame([false, false, false, false], array_column([$orphan, $foreign, $missing, $header], 'snapshot_complete'));
        foreach ([$orphan, $foreign, $missing, $header] as $r) $this->assertArrayNotHasKey('contract', $r);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_package_waiver_and_effective_amendments_are_explicit_without_private_text(): void
    {
        $f = $this->scopedFixture();
        $package = \App\Models\CoursePackage::create(['student_id' => $f['student']->id,
            'campus_id' => $f['student']->CampusID, 'name' => 'Private package', 'billing_mode' => 'count', 'total_sessions' => 8]);
        $f['course']->update(['PackageID' => $package->id, 'closed_reason' => 'waived', 'rate_unit' => 'hour']);
        foreach (['2026-09-01', '2026-10-01'] as $date) {
            \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $f['course']->ID,
                'effective_from' => $date, 'rate' => 900, 'rate_unit' => 'hour',
                'source_reference' => 'Private amendment source', 'reason' => 'Private amendment reason',
                'voided_at' => $date === '2026-10-01' ? now() : null]);
        }
        $r = $this->probe($f);
        $this->assertSame([(int) $package->id, 'waived', 'hour', 2], [
            (int) ($r['contract']['PackageID'] ?? 0), $r['contract']['closed_reason'] ?? null,
            $r['contract']['rate_unit'] ?? null, count($r['pricing_amendments'] ?? []),
        ]);
        $this->assertTrue($r['snapshot_complete'], 'complete evidence does not mean eligible for correction');
        $this->assertNull($r['pricing_amendments'][0]['voided_at']);
        $this->assertNotNull($r['pricing_amendments'][1]['voided_at']);
        $this->assertSame('hour', $r['pricing_amendments'][0]['rate_unit']);
        $this->assertStringNotContainsString('Private', json_encode($r));
    }

    public function test_legacy_report_payment_link_requires_a_selected_payment_on_its_invoice(): void
    {
        $f = $this->scopedFixture(); $report = $this->receiptFor($f);
        $paymentId = $report->payment_id;
        $report->update(['payment_id' => $paymentId + 100000]);
        $r = $this->probe($f);
        $this->assertFalse($r['snapshot_complete']); $this->assertArrayNotHasKey('reports', $r);
        $other = Invoice::create(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
            'IssueDate' => '2026-10-01', 'TotalAmount' => 500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $legacy = Payment::create(['InvoiceID' => $other->id, 'Amount' => 500, 'Method' => 'cash', 'PaidAt' => '2026-10-01']);
        $report->update(['payment_id' => $legacy->id]);
        $r = $this->probe($f);
        $this->assertFalse($r['snapshot_complete']); $this->assertArrayNotHasKey('payments', $r);
        $report->update(['payment_id' => $paymentId]);
        $legacy->update(['payment_report_id' => $report->id]);
        $r = $this->probe($f);
        $this->assertFalse($r['snapshot_complete']);
        $legacy->update(['payment_report_id' => null]);
        Payment::findOrFail($paymentId)->update(['payment_report_id' => null]);
        $r = $this->probe($f);
        $this->assertTrue($r['snapshot_complete'], 'valid legacy inverse-only link is retained');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_row_budget_discards_payload_and_rolls_back_transaction(): void
    {
        $f = $this->scopedFixture();
        foreach (range(1, 101) as $n) Payment::create(['InvoiceID' => $f['invoice']->id,
            'Amount' => 1, 'PaidAt' => '2026-10-01', 'Method' => 'cash']);
        $r = $this->probe($f);
        $this->assertFalse($r['snapshot_complete']);
        $this->assertSame('row_budget_exceeded', $r['reason']);
        $this->assertArrayNotHasKey('payments', $r); $this->assertArrayNotHasKey('contract', $r);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_server_read_only_transaction_rejects_accidental_writes(): void
    {
        $f = $this->scopedFixture(); $attempted = false; $sqlState = null;
        DB::listen(function ($query) use (&$attempted, &$sqlState) {
            if ($attempted || !str_contains($query->sql, '`Campus`')) return;
            $attempted = true;
            try { DB::table('Invoice')->where('id', -1)->update(['TotalAmount' => 1]); }
            catch (\Illuminate\Database\QueryException $e) { $sqlState = $e->errorInfo[0]; throw $e; }
        });
        $r = $this->probe($f);
        $this->assertTrue($attempted); $this->assertSame('25006', $sqlState);
        $this->assertFalse($r['snapshot_complete']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(7600, (int) $f['invoice']->fresh()->TotalAmount);
    }
}
