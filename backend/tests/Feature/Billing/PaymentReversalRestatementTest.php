<?php

namespace Tests\Feature\Billing;

use App\Models\{Invoice, Payment};
use App\Services\Billing\{AgreedFeeCorrection, ContractMoneyState, PaymentReversalRestatement};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\{AgreedFeeFixture, AgreedFeeRefreshDatabase};
use Tests\TestCase;

class PaymentReversalRestatementTest extends TestCase
{
    use AgreedFeeFixture, AgreedFeeRefreshDatabase;

    private function excessFixture(): array
    {
        $f = $this->fixture();
        $f['course']->update(['Charge' => 8800, 'Rate' => 1100, 'UsedSessions' => 5, 'RemainingSessions' => 3, 'ClassType' => 'one_on_three']);
        $f['invoice']->update(['TotalAmount' => 8000]);
        DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->delete();
        foreach ([8000, 8800] as $amount) {
            $report = $this->reportFor($f, 'voided'); $report->update(['reported_amount' => $amount]);
            $receipt = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => $amount, 'Method' => 'transfer',
                'PaidAt' => '2026-10-01', 'payment_report_id' => $report->id]);
            $report->update(['payment_id' => $receipt->id]);
            if ($amount === 8000) $f['extra'] = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => -8000, 'Method' => 'void',
                'PaidAt' => '2026-10-02', 'Note' => 'Original private synthetic reason']);
            Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => -$amount, 'Method' => 'void',
                'PaidAt' => '2026-10-02', 'payment_report_id' => $report->id]);
        }
        $f['pending'] = $this->reportFor($f); $f['pending']->update(['reported_amount' => 8000]);
        $f['scope'] = ['student_id' => (int) $f['student']->id, 'course_id' => (int) $f['course']->ID,
            'campus_id' => 1, 'invoice_id' => (int) $f['invoice']->id, 'payment_id' => (int) $f['extra']->id];
        $f['actor'] = ['id' => (int) $f['user']->id, 'role' => 'director', 'campus_ids' => [1], 'student_id' => (int) $f['student']->id];
        return $f;
    }

    private function refused(callable $action, string $code): void
    {
        try { $action(); $this->fail('Expected refusal: '.$code); }
        catch (\RuntimeException $e) { $this->assertStringContainsString($code, $e->getMessage()); }
    }

    private function execute(PaymentReversalRestatement $svc, array $plan, array $actor, string $decision, string $id): array
    {
        if (!DB::table('pop_operation_requests')->where('id', $id)->exists()) {
            $p = $svc->requestParameters($plan, $decision);
            DB::table('pop_operation_requests')->insert(['id' => $id, 'operation_id' => PaymentReversalRestatement::OPERATION,
                'strategy_id' => 'synthetic-internal-service-test', 'catalog_version' => 1, 'parameters' => json_encode($p),
                'parameters_hash' => hash('sha256', json_encode($p)), 'idempotency_key' => $id,
                'status' => 'approved', 'actor' => 'synthetic-pop', 'created_at' => now(), 'updated_at' => now()]);
        }
        return $svc->execute($plan, $actor, $decision, $id);
    }

    private function permutations(array $keys): array
    {
        if ($keys === []) return [[]];
        $out = [];
        foreach ($keys as $i => $key) {
            $rest = $keys; unset($rest[$i]);
            foreach ($this->permutations(array_values($rest)) as $tail) $out[] = [$key, ...$tail];
        }
        return $out;
    }

    public function test_permanent_restatement_is_not_a_cash_receipt_or_permission_to_correct_a_pending_report(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]);
        $f = $this->excessFixture(); $svc = app(PaymentReversalRestatement::class);
        $beforeCourse = $f['course']->fresh()->getRawOriginal(); $beforeInvoice = $f['invoice']->fresh()->getRawOriginal();
        $reports = DB::table('payment_reports')->where('StudentClassID', $f['course']->ID)->orderBy('id')->get()->toJson();
        $beforePayment = (array) DB::table('Payment')->where('id', $f['extra']->id)->first();
        $this->assertTrue(ContractMoneyState::blocksUnpaidCorrection($f['course']->ID, true));
        $plan = $svc->plan($f['scope'], $f['actor']);
        $this->assertSame(-8000, $plan['raw_net_before']); $this->assertSame(0, $plan['raw_net_after']);
        $op = (string) Str::uuid(); $result = $this->execute($svc, $plan, $f['actor'], 'SYNTHETIC-RESTATEMENT', $op);
        $this->assertSame('applied', $result['state']);
        $record = DB::table('pop_execution_records')->where('id', $result['restatement_id'])->first();
        $snapshot = json_decode($record->snapshot, true);
        $storedBefore = $snapshot['before_payment'];
        $expectedBefore = $beforePayment; ksort($storedBefore); ksort($expectedBefore);
        $this->assertSame($expectedBefore, $storedBefore);
        $this->assertSame(0, (int) $f['extra']->fresh()->Amount); $this->assertSame('other', $f['extra']->fresh()->Method);
        $this->assertStringContainsString('SYNTHETIC-RESTATEMENT', $f['extra']->fresh()->Note);
        $this->assertCount(5, DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->get());
        $this->assertSame(0, (int) DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->sum('Amount'));
        $this->assertFalse(ContractMoneyState::blocksUnpaidCorrection($f['course']->ID, true));
        $this->assertSame([], ContractMoneyState::lastPaidAtByStudentClassIds([$f['course']->ID]));
        $this->assertSame(0, (int) Invoice::whereKey($f['invoice']->id)->selectRaw(Invoice::netPaidSql().' AS net')->first()->net);
        $resolved = app(\App\Services\InvoiceAmountReconciliationService::class)->resolve($f['invoice']->fresh());
        $this->assertSame(0, $resolved['net_applied']); $this->assertSame(8000, $resolved['outstanding_amount']);
        $this->assertSame($beforeCourse, $f['course']->fresh()->getRawOriginal());
        $this->assertSame($beforeInvoice, $f['invoice']->fresh()->getRawOriginal());
        $this->assertSame($reports, DB::table('payment_reports')->where('StudentClassID', $f['course']->ID)->orderBy('id')->get()->toJson());
        $this->assertSame('already_applied', $this->execute($svc, $plan, $f['actor'], 'SYNTHETIC-RESTATEMENT', $op)['state']);
        $this->assertSame(1, DB::table('pop_execution_records')->where('phase', 'reversal-proof')->where('snapshot->payment_id', $f['extra']->id)->count());
        $this->refused(fn () => $this->execute($svc, $plan, $f['actor'], 'OTHER-DECISION', $op), 'request_mismatch');
        $this->refused(fn () => $this->execute($svc, $plan, array_replace($f['actor'], ['id' => $f['actor']['id'] + 1]), 'SYNTHETIC-RESTATEMENT', $op), 'invalid_manifest');
        try {
            app(AgreedFeeCorrection::class)->run($f['course']->ID, array_replace($this->feePayload($f['invoice']), ['new_charge' => 8800]), $f['actor']);
            $this->fail('Pending report still blocks fee correction');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame('payment_evidence_blocks', $e->getResponse()->getData(true)['code']);
        }
        $this->assertSame('pending', $f['pending']->fresh()->status);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('payment_restatements')); // Existing POP storage only.
        // MySQL JSON may reorder object keys; explore every permutation of the four effect/identity keys.
        foreach ($this->permutations(['id', 'Amount', 'Method', 'Note']) as $keys) {
            DB::beginTransaction();
            try {
                $permuted = [];
                foreach ($keys as $key) $permuted[$key] = $beforePayment[$key];
                $permuted += $beforePayment;
                $changed = $snapshot; $changed['before_payment'] = $permuted;
                DB::table('pop_execution_records')->where('id', $record->id)->update(['snapshot' => json_encode($changed)]);
                $this->assertSame('rolled_back', $svc->rollback($record->id, $f['actor'])['state']);
                $this->assertSame($beforePayment, (array) DB::table('Payment')->where('id', $f['extra']->id)->first());
            } finally { DB::rollBack(); }
        }
        $this->assertSame('rolled_back', $svc->rollback($result['restatement_id'], $f['actor'])['state']);
        $this->assertSame($beforePayment, (array) DB::table('Payment')->where('id', $f['extra']->id)->first());
        $this->assertSame('already_rolled_back', $svc->rollback($result['restatement_id'], $f['actor'])['state']);
        $this->refused(fn () => $this->execute($svc, $plan, $f['actor'], 'SYNTHETIC-RESTATEMENT', $op), 'reapply_requires_new_decision');
        $this->assertSame(1, DB::table('pop_execution_records')->where('phase', 'reversal-proof')->where('snapshot->payment_id', $f['extra']->id)->count());
    }

    public function test_drift_ownership_ambiguous_history_and_later_work_refuse(): void
    {
        $f = $this->excessFixture(); $svc = app(PaymentReversalRestatement::class);
        $plan = $svc->plan($f['scope'], $f['actor']);
        $this->refused(fn () => $svc->plan($f['scope'], array_replace($f['actor'], ['campus_ids' => []])), 'forbidden');
        $f['invoice']->update(['TotalAmount' => 8001]);
        $this->refused(fn () => $this->execute($svc, $plan, $f['actor'], 'DRIFT', (string) Str::uuid()), 'state_changed');
        $f['invoice']->update(['TotalAmount' => 8000]);
        $f['pending']->update(['StudentID' => $f['student']->id + 100000]);
        $this->refused(fn () => $svc->plan($f['scope'], $f['actor']), 'report_owner_mismatch');
        $f['pending']->update(['StudentID' => $f['student']->id]);
        $void = Payment::where('InvoiceID', $f['invoice']->id)->where('Amount', -8800)->first(); $void->update(['Amount' => -8700]);
        $this->refused(fn () => $svc->plan($f['scope'], $f['actor']), 'ambiguous_void_history');
        $void->update(['Amount' => -8800]);
        $f['extra']->update(['receipt_url' => 'private-synthetic-receipt']);
        $this->refused(fn () => $svc->plan($f['scope'], $f['actor']), 'not_unlinked_reversal');
        $f['extra']->update(['receipt_url' => '0']);
        $this->refused(fn () => $svc->plan($f['scope'], $f['actor']), 'not_unlinked_reversal');
        $f['extra']->update(['receipt_url' => null]);
        $plan = $svc->plan($f['scope'], $f['actor']);
        $tampered = $plan; $tampered['before_payment']['Amount'] = -1;
        $this->refused(fn () => $this->execute($svc, $tampered, $f['actor'], 'TAMPER', (string) Str::uuid()), 'plan_mismatch');
        $this->assertSame(-8000, (int) $f['extra']->fresh()->Amount);
        $result = $this->execute($svc, $plan, $f['actor'], 'LATER-WORK', (string) Str::uuid());
        $f['pending']->update(['status' => 'rejected']);
        $this->refused(fn () => $svc->rollback($result['restatement_id'], $f['actor']), 'values_moved_on');
        $this->assertSame(0, (int) $f['extra']->fresh()->Amount);
        $proof = DB::table('pop_execution_records')->where('id', $result['restatement_id'])->first();
        $this->assertFalse(DB::table('pop_execution_records')->where('operation_id', $proof->operation_id)->where('phase', 'reversal-proof-rollback')->exists());
    }

    public function test_history_or_required_audit_failure_rolls_back_the_posting(): void
    {
        $svc = app(PaymentReversalRestatement::class);
        foreach (['pop_execution_records', 'security_audit_events'] as $table) {
            $f = $this->excessFixture(); $plan = $svc->plan($f['scope'], $f['actor']); $fault = (object) ['active' => true];
            DB::listen(function ($query) use ($table, $fault) {
                if ($fault->active && str_starts_with($query->sql, 'insert into `'.$table.'`')) throw new \RuntimeException('synthetic_persistence_failure:'.$table);
            });
            try { $this->refused(fn () => $this->execute($svc, $plan, $f['actor'], 'FAIL-CLOSED', (string) Str::uuid()), 'synthetic_persistence_failure:'.$table); }
            finally { $fault->active = false; }
            $this->assertSame($plan, $svc->plan($f['scope'], $f['actor']));
            $this->assertSame(0, DB::table('pop_execution_records')->where('phase', 'reversal-proof')->where('snapshot->payment_id', $f['extra']->id)->count());
        }
    }

    public function test_owned_replay_survives_later_invoice_but_rollback_and_foreign_owner_refuse(): void
    {
        $f = $this->excessFixture(); $svc = app(PaymentReversalRestatement::class);
        $plan = $svc->plan($f['scope'], $f['actor']); $op = (string) Str::uuid();
        $result = $this->execute($svc, $plan, $f['actor'], 'LATER-INVOICE', $op);
        $next = $f['invoice']->replicate(); $next->save();
        $this->assertSame('already_applied', $this->execute($svc, $plan, $f['actor'], 'LATER-INVOICE', $op)['state']);
        $this->refused(fn () => $svc->rollback($result['restatement_id'], $f['actor']), 'invoice_scope_mismatch');
        $this->assertSame(0, (int) $f['extra']->fresh()->Amount);
        $f['student']->update(['CampusID' => 2]);
        $this->refused(fn () => $this->execute($svc, $plan, $f['actor'], 'LATER-INVOICE', $op), 'owner_mismatch');
        $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $op)->where('phase', 'reversal-proof')->count());
    }
}
