<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\OrdinaryCloseBillingCensus;
use Illuminate\Support\Facades\DB;
use Tests\Support\{AgreedFeeFixture, AgreedFeeRefreshDatabase};
use Tests\TestCase;

class OrdinaryCloseBillingCensusTest extends TestCase
{
    use AgreedFeeFixture, AgreedFeeRefreshDatabase;

    private function capture(array $f, int $budget = 1000): array
    {
        return DB::transaction(fn () => app(OrdinaryCloseBillingCensus::class)->captureLocked(
            (int) $f['course']->ID, (int) $f['student']->id, $budget
        ));
    }

    public function test_manifest_includes_void_zero_and_history_rows_without_exposing_source_values(): void
    {
        $f = $this->fixture();
        DB::table('Invoice')->where('id', $f['invoice']->id)->update(['Status' => 'void', 'Note' => 'PRIVATE invoice note']);
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => 0, 'PaidAt' => now(), 'Method' => 'void', 'Note' => 'PRIVATE account suffix']);
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => -8000, 'PaidAt' => now(), 'Method' => 'void', 'Note' => 'PRIVATE reversed payment']);
        $before = $this->capture($f);
        $this->assertSame('PARTIAL', $before['captured_sources']['completeness']);
        $this->assertSame(['invoices', 'invoice_items', 'payments', 'payment_reports', 'invoice_corrections', 'pricing_amendments', 'payment_restatement_proofs'], $before['captured_sources']['tables']);
        $this->assertCount(2, $before['id_sets']['payments']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($before));
        $this->assertSame(0, (int) DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->value('Amount'));
        $this->assertSame('PRIVATE account suffix', DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->value('Note'));
        DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->update(['Note' => 'PRIVATE account suffix changed']);
        $after = $this->capture($f);
        $this->assertNotSame($before['table_digests']['payments'], $after['table_digests']['payments']);
        $this->assertSame($before['id_sets'], $after['id_sets']);
    }

    public function test_header_null_invoice_is_owned_only_through_explicit_course_items(): void
    {
        $f = $this->fixture();
        DB::table('Invoice')->where('id', $f['invoice']->id)->update(['StudentClassID' => null]);
        $manifest = $this->capture($f);
        $this->assertContains((int) $f['invoice']->id, $manifest['id_sets']['invoices']);
        DB::table('payment_reports')->insert(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
            'InvoiceID' => $f['invoice']->id, 'reported_by_name' => 'Synthetic', 'payment_date' => '2026-10-01',
            'payment_method' => 'cash', 'reported_amount' => 10, 'status' => 'pending', 'report_token_hash' => str_repeat('b', 64),
            'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->update(['StudentClassID' => null]);
        try { $this->capture($f); $this->fail('header-null invoice with ownerless item must refuse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('unowned_invoice_item', $e->getMessage()); }
    }

    public function test_foreign_invoice_item_and_missing_transaction_fail_closed(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $beforeForeignLink = $this->capture($f);
        $this->assertNotContains((int) $other['invoice']->id, $beforeForeignLink['id_sets']['invoices']);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $f['invoice']->id, 'StudentClassID' => $other['course']->ID,
            'Description' => 'PRIVATE foreign item', 'Amount' => 1, 'created_at' => now(), 'updated_at' => now()]);
        try { $this->capture($f); $this->fail('foreign item must refuse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('foreign_invoice_item', $e->getMessage()); }
    }

    public function test_reusing_one_reader_resets_the_per_call_fact_budget(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        DB::table('Payment')->insert(['InvoiceID' => $second['invoice']->id, 'Amount' => 10, 'PaidAt' => now(), 'Method' => 'cash', 'Note' => 'synthetic']);
        $reader = app(OrdinaryCloseBillingCensus::class);
        $capture = static fn (array $f): array => DB::transaction(fn () => $reader->captureLocked(
            (int) $f['course']->ID, (int) $f['student']->id, 3
        ));
        $firstResult = $capture($first);
        $secondResult = $capture($second);
        $this->assertSame(2, $firstResult['fact_count']);
        $this->assertSame(3, $secondResult['fact_count']);
    }

    public function test_payment_report_two_way_link_is_captured_and_conflicts_refuse(): void
    {
        $f = $this->fixture();
        DB::table('payment_reports')->insert(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
            'InvoiceID' => $f['invoice']->id, 'reported_by_name' => 'Synthetic', 'payment_date' => '2026-10-01',
            'payment_method' => 'cash', 'reported_amount' => 7600, 'status' => 'confirmed', 'report_token_hash' => str_repeat('a', 64),
            'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $reportId = (int) DB::getPdo()->lastInsertId();
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => 7600, 'PaidAt' => now(), 'Method' => 'cash',
            'Note' => 'PRIVATE receipt path', 'payment_report_id' => $reportId, 'created_at' => now(), 'updated_at' => now()]);
        $paymentId = (int) DB::getPdo()->lastInsertId();
        DB::table('payment_reports')->where('id', $reportId)->update(['payment_id' => $paymentId]);
        $m = $this->capture($f);
        $this->assertCount(1, $m['id_sets']['payment_reports']);
        $this->assertCount(1, $m['id_sets']['payments']);
        DB::table('payment_reports')->where('id', $reportId)->update(['payment_id' => null]);
        $oneWay = $this->capture($f);
        $this->assertSame($m['id_sets'], $oneWay['id_sets']);
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => 7600, 'PaidAt' => now(), 'Method' => 'cash',
            'Note' => 'duplicate association', 'payment_report_id' => $reportId, 'created_at' => now(), 'updated_at' => now()]);
        try { $this->capture($f); $this->fail('duplicate report association must refuse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('duplicate_report_payment', $e->getMessage()); }
    }

    // #4492: the reciprocal payment ID must not overwrite a numeric list index.
    public function test_duplicate_receipts_with_reciprocal_pointer_refuse(): void
    {
        $validate = new \ReflectionMethod(OrdinaryCloseBillingCensus::class, 'validatePaymentsAndReports');
        $payment = ['InvoiceID' => 1, 'payment_report_id' => 1, 'Amount' => 50, 'Method' => 'cash'];
        $this->expectExceptionMessage('ordinary_close_billing_duplicate_report_payment');
        $validate->invoke(new OrdinaryCloseBillingCensus(), [['id' => 1] + $payment, ['id' => 2] + $payment],
            [['id' => 1, 'StudentID' => 1, 'StudentClassID' => 1, 'InvoiceID' => 1, 'payment_id' => 1]], [1], 1, 1);
    }

    public function test_legacy_report_to_payment_one_way_link_is_kept(): void
    {
        $f = $this->fixture();
        DB::table('payment_reports')->insert(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
            'InvoiceID' => $f['invoice']->id, 'reported_by_name' => 'Synthetic', 'payment_date' => '2026-10-01',
            'payment_method' => 'cash', 'reported_amount' => 25, 'status' => 'confirmed', 'report_token_hash' => str_repeat('e', 64),
            'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $reportId = (int) DB::getPdo()->lastInsertId();
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => 25, 'PaidAt' => now(), 'Method' => 'cash',
            'Note' => 'PRIVATE one way', 'created_at' => now(), 'updated_at' => now()]);
        $paymentId = (int) DB::getPdo()->lastInsertId();
        DB::table('payment_reports')->where('id', $reportId)->update(['payment_id' => $paymentId]);
        $manifest = $this->capture($f);
        $this->assertCount(1, $manifest['id_sets']['payment_reports']);
        $this->assertCount(1, $manifest['id_sets']['payments']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($manifest));
    }

    public function test_valid_void_payment_history_shares_report_pointer_but_competing_crosslink_refuses(): void
    {
        $f = $this->fixture();
        DB::table('payment_reports')->insert(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
            'InvoiceID' => $f['invoice']->id, 'reported_by_name' => 'Synthetic', 'payment_date' => '2026-10-01',
            'payment_method' => 'cash', 'reported_amount' => 7600, 'status' => 'voided', 'report_token_hash' => str_repeat('1', 64),
            'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $reportId = (int) DB::getPdo()->lastInsertId();
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => 7600, 'PaidAt' => now(), 'Method' => 'cash',
            'Note' => 'PRIVATE retained receipt', 'payment_report_id' => $reportId, 'created_at' => now(), 'updated_at' => now()]);
        $receiptId = (int) DB::getPdo()->lastInsertId();
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => -7600, 'PaidAt' => now(), 'Method' => 'void',
            'Note' => 'PRIVATE retained reversal', 'payment_report_id' => $reportId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_reports')->where('id', $reportId)->update(['payment_id' => $receiptId]);
        $valid = $this->capture($f);
        $this->assertCount(2, $valid['id_sets']['payments']);

        DB::table('payment_reports')->insert(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
            'InvoiceID' => $f['invoice']->id, 'reported_by_name' => 'Synthetic', 'payment_date' => '2026-10-01',
            'payment_method' => 'cash', 'reported_amount' => 7600, 'status' => 'pending', 'report_token_hash' => str_repeat('2', 64),
            'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $otherReportId = (int) DB::getPdo()->lastInsertId();
        DB::table('Payment')->where('id', $receiptId)->update(['payment_report_id' => $otherReportId]);
        try { $this->capture($f); $this->fail('report pointer to payment linked to a different report must refuse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('payment_report_conflict', $e->getMessage()); }
    }

    public function test_two_reports_cannot_claim_the_same_positive_payment(): void
    {
        $f = $this->fixture();
        foreach (['3', '4'] as $tokenChar) {
            DB::table('payment_reports')->insert(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
                'InvoiceID' => $f['invoice']->id, 'reported_by_name' => 'Synthetic', 'payment_date' => '2026-10-01',
                'payment_method' => 'cash', 'reported_amount' => 50, 'status' => 'confirmed', 'report_token_hash' => str_repeat($tokenChar, 64),
                'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $reports = DB::table('payment_reports')->where('StudentClassID', $f['course']->ID)->orderBy('id')->pluck('id');
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => 50, 'PaidAt' => now(), 'Method' => 'cash',
            'Note' => 'PRIVATE disputed receipt', 'created_at' => now(), 'updated_at' => now()]);
        $paymentId = (int) DB::getPdo()->lastInsertId();
        DB::table('payment_reports')->whereIn('id', $reports)->update(['payment_id' => $paymentId]);
        try { $this->capture($f); $this->fail('one payment cannot be claimed by two reports'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('duplicate_report_payment', $e->getMessage()); }
    }

    public function test_dangling_payment_report_and_foreign_report_owner_are_rejected(): void
    {
        $f = $this->fixture();
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => 500, 'PaidAt' => now(), 'Method' => 'cash',
            'Note' => 'PRIVATE dangling link', 'payment_report_id' => 99999999, 'created_at' => now(), 'updated_at' => now()]);
        try { $this->capture($f); $this->fail('missing report must refuse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('payment_report_conflict', $e->getMessage()); }
        DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->update(['payment_report_id' => null]);
        DB::table('payment_reports')->insert(['StudentID' => $f['student']->id + 1000, 'StudentClassID' => $f['course']->ID,
            'InvoiceID' => $f['invoice']->id, 'reported_by_name' => 'PRIVATE foreign owner', 'payment_date' => '2026-10-01',
            'payment_method' => 'cash', 'reported_amount' => 500, 'status' => 'pending', 'report_token_hash' => str_repeat('f', 64),
            'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        try { $this->capture($f); $this->fail('foreign report student must refuse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('foreign_report', $e->getMessage()); }
    }

    public function test_correction_to_missing_report_is_not_silently_dropped(): void
    {
        $f = $this->fixture();
        DB::table('invoice_corrections')->insert(['original_invoice_id' => $f['invoice']->id, 'replacement_invoice_id' => $f['invoice']->id,
            'student_class_id' => $f['course']->ID, 'payment_report_id' => 99999999, 'amount' => 7600, 'reason' => 'PRIVATE dangling report',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'operator_user_id' => null, 'created_at' => now()]);
        try { $this->capture($f); $this->fail('missing report history must refuse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('dangling_correction_report', $e->getMessage()); }
    }

    public function test_cross_student_invoice_discovered_through_permanent_correction_is_rejected(): void
    {
        $f = $this->fixture(); $other = $this->fixture();
        DB::table('invoice_corrections')->insert(['original_invoice_id' => $other['invoice']->id, 'replacement_invoice_id' => $f['invoice']->id,
            'student_class_id' => $f['course']->ID, 'payment_report_id' => null, 'amount' => 7600, 'reason' => 'PRIVATE foreign correction',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'operator_user_id' => null, 'created_at' => now()]);
        $armed = true; $foreignChildrenRead = false;
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$armed, &$foreignChildrenRead, $other): void {
            if ($armed && str_contains($sql, 'from `InvoiceItem`') && str_contains($sql, '`InvoiceID` in')
                && in_array($other['invoice']->id, $bindings)) $foreignChildrenRead = true;
        });
        try { $this->capture($f); $this->fail('cross-student invoice must refuse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('foreign_invoice', $e->getMessage()); }
        finally { $armed = false; }
        $this->assertFalse($foreignChildrenRead, 'Reject foreign header before querying/locking its children.');
    }

    public function test_missing_invoice_referenced_by_correction_refuses_without_partial_output(): void
    {
        $f = $this->fixture();
        DB::table('invoice_corrections')->insert(['original_invoice_id' => 99999999, 'replacement_invoice_id' => $f['invoice']->id,
            'student_class_id' => $f['course']->ID, 'payment_report_id' => null, 'amount' => 7600, 'reason' => 'PRIVATE missing header',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'operator_user_id' => null, 'created_at' => now()]);
        try { $this->capture($f); $this->fail('dangling invoice must refuse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('dangling_invoice', $e->getMessage()); }
    }

    public function test_combined_fact_budget_is_enforced_at_boundary(): void
    {
        $f = $this->fixture();
        try { $this->capture($f, 0); $this->fail('zero budget cannot admit invoice facts'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('fact_budget_exceeded', $e->getMessage()); }
        $m = $this->capture($f, 2);
        $this->assertSame(2, $m['fact_count']);
    }

    public function test_restatement_uuid_history_is_a_distinct_proof_source(): void
    {
        $f = $this->fixture();
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => -7600, 'PaidAt' => now(), 'Method' => 'void', 'Note' => 'PRIVATE rollback note']);
        $paymentId = (int) DB::getPdo()->lastInsertId();
        $proofId = (string) \Illuminate\Support\Str::uuid();
        DB::table('pop_execution_records')->insert(['id' => $proofId, 'operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'execution_id' => (string) \Illuminate\Support\Str::uuid(), 'phase' => 'reversal-proof', 'result' => 'succeeded',
            'idempotency_key' => 'synthetic-proof-key', 'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'snapshot' => json_encode(['payment_id' => $paymentId, 'scope' => ['payment_id' => $paymentId, 'course_id' => $f['course']->ID,
                'invoice_id' => $f['invoice']->id, 'student_id' => $f['student']->id, 'campus_id' => 1]], JSON_THROW_ON_ERROR),
            'payload' => '{}', 'actor' => 'synthetic', 'created_at' => now()]);
        // #4492: PopOperationService stores ordinary receipts beside the strategy proof.
        $proof = (array) DB::table('pop_execution_records')->where('id', $proofId)->first();
        foreach (['execute', 'verify', 'rollback'] as $phase) {
            $receiptId = (string) \Illuminate\Support\Str::uuid();
            DB::table('pop_execution_records')->insert(array_replace($proof, ['id' => $receiptId, 'execution_id' => $receiptId,
                'phase' => $phase, 'idempotency_key' => $receiptId, 'snapshot' => $phase === 'execute' ? '{"proof_id":"'.$proofId.'"}' : null]));
        }
        $manifest = $this->capture($f);
        $this->assertSame([$proofId], $manifest['id_sets']['payment_restatement_proofs']);
        $this->assertSame(4, $manifest['fact_count']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($manifest));
    }

    public function test_restated_then_rolled_back_same_payment_values_still_change_proof_manifest(): void
    {
        $f = $this->fixture();
        DB::table('Payment')->insert(['InvoiceID' => $f['invoice']->id, 'Amount' => -7600, 'PaidAt' => '2026-10-01 12:00:00',
            'Method' => 'void', 'Note' => 'Original synthetic reversal', 'created_at' => now(), 'updated_at' => '2026-10-01 12:00:00']);
        $paymentId = (int) DB::getPdo()->lastInsertId();
        $before = $this->capture($f);
        $operation = (string) \Illuminate\Support\Str::uuid(); $originalId = (string) \Illuminate\Support\Str::uuid();
        $rollbackId = (string) \Illuminate\Support\Str::uuid();
        $scope = ['payment_id' => $paymentId, 'course_id' => $f['course']->ID, 'invoice_id' => $f['invoice']->id,
            'student_id' => $f['student']->id, 'campus_id' => 1];
        $insertProof = static function (string $id, string $phase, array $snapshot) use ($operation): void {
            DB::table('pop_execution_records')->insert(['id' => $id, 'operation_id' => $operation,
                'execution_id' => (string) \Illuminate\Support\Str::uuid(), 'phase' => $phase, 'result' => 'succeeded',
                'idempotency_key' => 'synthetic-'.$phase, 'correlation_id' => $operation, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'payload' => '{}', 'actor' => 'synthetic', 'created_at' => now()]);
        };
        $insertProof($originalId, 'reversal-proof', ['payment_id' => $paymentId, 'scope' => $scope]);
        // Simulate the net-zero ABA: the persisted Payment is back at its original raw values.
        DB::table('Payment')->where('id', $paymentId)->update(['Amount' => -7600, 'Method' => 'void', 'Note' => 'Original synthetic reversal', 'updated_at' => '2026-10-01 12:00:00']);
        $insertProof($rollbackId, 'reversal-proof-rollback', ['payment_id' => $paymentId, 'original_proof_id' => $originalId]);
        $after = $this->capture($f);
        $this->assertSame($before['table_digests']['payments'], $after['table_digests']['payments']);
        $this->assertNotSame($before['table_digests']['payment_restatement_proofs'], $after['table_digests']['payment_restatement_proofs']);
        $this->assertEqualsCanonicalizing([$originalId, $rollbackId], $after['id_sets']['payment_restatement_proofs']);
    }

    public function test_invoice_correction_replacement_and_void_pricing_history_are_hashed(): void
    {
        $f = $this->fixture();
        DB::table('Invoice')->insert(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
            'IssueDate' => '2026-10-10', 'DueDate' => null, 'TotalAmount' => 7600, 'PaidAmount' => 0,
            'Status' => 'void', 'Note' => 'PRIVATE replacement', 'created_at' => now(), 'updated_at' => now()]);
        $replacementId = (int) DB::getPdo()->lastInsertId();
        DB::table('invoice_corrections')->insert(['original_invoice_id' => $f['invoice']->id, 'replacement_invoice_id' => $replacementId,
            'student_class_id' => $f['course']->ID, 'payment_report_id' => null, 'amount' => 7600, 'reason' => 'PRIVATE reason',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'operator_user_id' => null, 'created_at' => now()]);
        DB::table('invoice_corrections')->insert(['original_invoice_id' => null, 'replacement_invoice_id' => null,
            'student_class_id' => $f['course']->ID, 'payment_report_id' => null, 'amount' => null, 'reason' => 'PRIVATE unbilled reason',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'operator_user_id' => null, 'created_at' => now(),
            'kind' => 'agreed_fee', 'campus_id' => 1, 'old_charge' => 8000, 'new_charge' => 7600,
            'original_invoice_total' => null, 'request_hash' => str_repeat('c', 64), 'state_hash' => str_repeat('d', 64)]);
        DB::table('student_class_pricing_amendments')->insert(['student_class_id' => $f['course']->ID, 'effective_from' => '2026-10-01',
            'rate' => 950, 'rate_unit' => 'session', 'source_reference' => 'synthetic', 'reason' => 'PRIVATE pricing note',
            'created_by_user_id' => null, 'created_at' => now(), 'voided_at' => now(), 'voided_by_user_id' => null, 'void_reason' => 'PRIVATE void']);
        $before = $this->capture($f);
        $this->assertCount(2, $before['id_sets']['invoice_corrections']);
        $this->assertCount(1, $before['id_sets']['pricing_amendments']);
        $this->assertCount(2, $before['id_sets']['invoices']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($before));
        DB::table('student_class_pricing_amendments')->update(['void_reason' => 'PRIVATE changed void']);
        $after = $this->capture($f);
        $this->assertNotSame($before['table_digests']['pricing_amendments'], $after['table_digests']['pricing_amendments']);
    }

    public function test_driver_failure_is_private_and_required_link_column_cannot_be_absent(): void
    {
        $f = $this->fixture(); $armed = true; $attempted = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$attempted): void {
            if ($armed && str_contains($sql, 'from `Invoice`')) { $attempted = true; throw new \PDOException('PRIVATE SQL and bindings'); }
        });
        try { $this->capture($f); $this->fail('driver failure must refuse'); }
        catch (\RuntimeException $e) { $this->assertSame('ordinary_close_billing_source_unavailable', $e->getMessage()); $this->assertNull($e->getPrevious()); }
        finally { $armed = false; }
        $this->assertTrue($attempted);
        $this->endOuterTestingTransactions();
        $f = $this->fixture();
        DB::statement('ALTER TABLE Payment DROP COLUMN payment_report_id');
        try { $this->capture($f); $this->fail('missing discovery column must refuse even without any report'); }
        catch (\RuntimeException $e) { $this->assertSame('ordinary_close_billing_source_unavailable', $e->getMessage()); }
        finally { $this->rebuildIsolatedSchema(); }
    }

    public function test_final_current_read_refuses_a_late_noncooperating_report(): void
    {
        $this->endOuterTestingTransactions(); $f = $this->fixture();
        config(['database.connections.census_late' => config('database.connections.'.config('database.default'))]);
        $b = DB::connection('census_late'); $armed = true; $reads = 0; $inserted = false;
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        DB::listen(function ($q) use ($b, $f, &$armed, &$reads, &$inserted): void {
            if ($armed && str_contains($q->sql, 'from `payment_reports`') && ++$reads === 2) {
                $armed = false;
                $b->table('payment_reports')->insert(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID,
                    'InvoiceID' => $f['invoice']->id, 'reported_by_name' => 'PRIVATE late reporter', 'payment_date' => '2026-10-01',
                    'payment_method' => 'cash', 'reported_amount' => 1, 'status' => 'pending', 'report_token_hash' => str_repeat('9', 64),
                    'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
                $inserted = true;
            }
        });
        try { $this->capture($f); $this->fail('late selector membership must not yield a stale manifest'); }
        catch (\RuntimeException $e) { $this->assertSame('ordinary_close_billing_source_changed', $e->getMessage()); }
        finally { $armed = false; DB::disconnect('census_late'); DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $this->rebuildIsolatedSchema(); }
        $this->assertTrue($inserted, 'Separate PDO must actually commit after the discovery read.');
    }

    public function test_proof_scope_types_owner_and_related_rollback_cannot_be_omitted(): void
    {
        $f = $this->fixture();
        $pid = DB::table('Payment')->insertGetId(['InvoiceID' => $f['invoice']->id, 'Amount' => -1, 'PaidAt' => now(), 'Method' => 'void']);
        $id = (string) \Illuminate\Support\Str::uuid(); $operation = (string) \Illuminate\Support\Str::uuid();
        $scope = ['course_id' => (int) $f['course']->ID, 'payment_id' => $pid, 'invoice_id' => (int) $f['invoice']->id,
            'student_id' => (int) $f['student']->id, 'campus_id' => 1];
        $insert = static function (string $id, string $op, string $phase, array $snapshot): void {
            DB::table('pop_execution_records')->insert(['id' => $id, 'operation_id' => $op, 'execution_id' => (string) \Illuminate\Support\Str::uuid(),
                'phase' => $phase, 'result' => 'succeeded', 'idempotency_key' => $id, 'correlation_id' => $op,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'payload' => '{}', 'actor' => 'synthetic', 'created_at' => now()]);
        };
        $insert($id, $operation, 'reversal-proof', ['payment_id' => $pid, 'scope' => $scope]);
        $this->assertSame([$id], $this->capture($f)['id_sets']['payment_restatement_proofs']);
        foreach (['student_id' => $scope['student_id'] + 1, 'campus_id' => 99, 'payment_id' => $pid + 1,
            'course_id' => $scope['course_id'].'junk', 'invoice_id' => (string) $scope['invoice_id']] as $key => $value) {
            DB::table('pop_execution_records')->where('id', $id)->update(['snapshot' => json_encode(['payment_id' => $pid, 'scope' => array_replace($scope, [$key => $value])])]);
            try { $this->capture($f); $this->fail('malformed/foreign scope must refuse: '.$key); }
            catch (\RuntimeException $e) { $this->assertMatchesRegularExpression('/ordinary_close_billing_(malformed|foreign)_proof/', $e->getMessage()); }
        }
        DB::table('pop_execution_records')->where('id', $id)->update(['snapshot' => json_encode(['payment_id' => $pid, 'scope' => $scope])]);
        foreach ([$operation, (string) \Illuminate\Support\Str::uuid()] as $relatedOperation) {
            $rollback = (string) \Illuminate\Support\Str::uuid();
            $insert($rollback, $relatedOperation, 'reversal-proof-rollback', ['original_proof_id' => $id]);
            try { $this->capture($f); $this->fail('operation/reference-linked rollback lacking payment must not disappear'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('proof_history_incomplete', $e->getMessage()); }
            DB::table('pop_execution_records')->where('id', $rollback)->delete();
        }
        DB::table('pop_execution_records')->where('id', $id)->update(['id' => 'not-a-uuid']);
        try { $this->capture($f); $this->fail('proof category must contain real UUIDs'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('malformed_proof', $e->getMessage()); }
    }
}
