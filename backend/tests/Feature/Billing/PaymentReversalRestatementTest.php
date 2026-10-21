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
    private function plannedCatalog(): void
    {
        $dir = sys_get_temp_dir().'/reversal-planned-'.Str::uuid(); mkdir($dir, 0700); mkdir($dir.'/policies', 0700);
        file_put_contents($dir.'/policies/default.yaml', "version: 1\n");
        file_put_contents($dir.'/catalog.yaml', "version: 1\noperations:\n  ".PaymentReversalRestatement::OPERATION.":\n    lifecycle: planned\n    strategy_class: App\\Operations\\Strategies\\PaymentReversalRestatementStrategy\n    parameter_keys: [campus_id, plan, decision_reference]\n    approval_required: true\n    approver_roles: [super_admin]\n");
        $this->app->instance(\App\Operations\PopOperationCatalog::class, new \App\Operations\PopOperationCatalog($dir.'/catalog.yaml'));
        $this->beforeApplicationDestroyed(static function () use ($dir): void { unlink($dir.'/catalog.yaml'); unlink($dir.'/policies/default.yaml'); rmdir($dir.'/policies'); rmdir($dir); });
    }
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
        try { $action(); } catch (\RuntimeException $e) { $this->assertStringContainsString($code, $e->getMessage()); return; }
        $this->fail('Expected refusal: '.$code);
    }
    private function execute(PaymentReversalRestatement $svc, array $plan, array $actor, string $decision, string $id): array
    {
        $this->prepareRequest($svc, $plan, $actor, $decision, $id);
        return $svc->execute($plan, $actor, $decision, $id);
    }
    private function prepareRequest(PaymentReversalRestatement $svc, array $plan, array $actor, string $decision, string $id): void
    {
        if (!DB::table('pop_operation_requests')->where('id', $id)->exists()) {
            $p = $svc->requestParameters($plan, $decision);
            DB::table('pop_operation_requests')->insert(['id' => $id, 'operation_id' => PaymentReversalRestatement::OPERATION,
                'strategy_id' => 'synthetic-internal-service-test', 'catalog_version' => 1, 'parameters' => json_encode($p),
                'parameters_hash' => hash('sha256', json_encode($p)), 'idempotency_key' => $id,
                'status' => 'approved', 'actor' => 'user:'.$actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        }
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
        $f['extra']->update(['Note' => str_repeat('Synthetic retained reason ', 30)]);
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
        $this->assertStringContainsString('SYNTHETIC-RESTATEMENT', $f['extra']->fresh()->Note); $this->assertStringEndsWith($beforePayment['Note'], $f['extra']->fresh()->Note);
        $ledger = $this->getJson('/api/v1/accounting/ledger?student_class_id='.$f['course']->ID, ['Authorization' => 'Bearer '.$f['token']])->assertOk()->json();
        $row = collect($ledger['invoices'][0]['payments'])->firstWhere('id', $f['extra']->id);
        $this->assertSame('voided', $row['application_status']); $this->assertTrue($row['is_void']);
        $this->assertFalse(collect($ledger['anomalies'])->contains(fn ($a) => $a['code'] === 'payment_without_receipt' && $a['payment_id'] === $f['extra']->id));
        $ordinary = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => 0, 'Method' => 'other', 'PaidAt' => '2026-10-02']);
        $otherLedger = $this->getJson('/api/v1/accounting/ledger?student_class_id='.$f['course']->ID, ['Authorization' => 'Bearer '.$f['token']])->assertOk()->json();
        $this->assertTrue(collect($otherLedger['anomalies'])->contains(fn ($a) => $a['code'] === 'payment_without_receipt' && $a['payment_id'] === $ordinary->id));
        $ordinary->delete();
        $studentRows = $this->getJson('/api/v1/student-classes/'.$f['course']->ID.'/invoices', ['Authorization' => 'Bearer '.$f['token']])->assertOk()->json('invoices.0.payments');
        $this->assertTrue(collect($studentRows)->firstWhere('id', $f['extra']->id)['is_void']);
        DB::table('Payment')->where('id', $f['extra']->id)->update(['receipt_url' => '0']);
        $changedLedger = $this->getJson('/api/v1/accounting/ledger?student_class_id='.$f['course']->ID, ['Authorization' => 'Bearer '.$f['token']])->assertOk()->json();
        $this->assertFalse(collect($changedLedger['invoices'][0]['payments'])->firstWhere('id', $f['extra']->id)['is_void']);
        DB::table('Payment')->where('id', $f['extra']->id)->update(['receipt_url' => null]);
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
        $next = $f['invoice']->replicate(); $next->save(); try { $this->assertSame('already_rolled_back', $svc->rollback($result['restatement_id'], $f['actor'])['state']); } finally { $next->delete(); }
        foreach (['edit', 'delete'] as $change) { DB::beginTransaction(); try { $q = DB::table('Payment')->where('id', $f['extra']->id); $change === 'edit' ? $q->update(['Note' => 'restored target drift']) : $q->delete(); $this->refused(fn () => $svc->rollback($result['restatement_id'], $f['actor']), 'target_moved_on'); } finally { DB::rollBack(); } }
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
        $this->assertSame('already_applied', $this->execute($svc, $plan, $f['actor'], 'LATER-INVOICE', $op)['state']); $this->assertTrue($svc->verifyProof($result['restatement_id'], $f['actor'])['ok']);
        foreach ([['Note' => 'synthetic drift'], null] as $change) {
            DB::beginTransaction();
            try {
                $target = DB::table('Payment')->where('id', $f['extra']->id);
                $change === null ? $target->delete() : $target->update($change);
                $this->refused(fn () => $this->execute($svc, $plan, $f['actor'], 'LATER-INVOICE', $op), 'target_moved_on');
                $this->refused(fn () => $svc->prepared($svc->requestParameters($plan, 'LATER-INVOICE'), $f['actor']), 'target_moved_on');
            } finally { DB::rollBack(); }
        }
        $this->refused(fn () => $svc->rollback($result['restatement_id'], $f['actor']), 'invoice_scope_mismatch');
        $this->assertSame(0, (int) $f['extra']->fresh()->Amount);
        $f['student']->update(['CampusID' => 2]);
        $this->refused(fn () => $this->execute($svc, $plan, $f['actor'], 'LATER-INVOICE', $op), 'owner_mismatch');
        $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $op)->where('phase', 'reversal-proof')->count());
    }

    public function test_pre_rollback_request_cannot_reapply_even_when_original_money_graph_is_restored(): void
    {
        $f = $this->excessFixture(); $svc = app(PaymentReversalRestatement::class);
        $plan = $svc->plan($f['scope'], $f['actor']); $a = (string) Str::uuid(); $b = (string) Str::uuid();
        $this->prepareRequest($svc, $plan, $f['actor'], 'BEFORE-ROLLBACK-B', $b);
        $applied = $this->execute($svc, $plan, $f['actor'], 'BEFORE-ROLLBACK-A', $a);
        $svc->rollback($applied['restatement_id'], $f['actor']);
        $this->refused(fn () => $this->execute($svc, $plan, $f['actor'], 'BEFORE-ROLLBACK-B', $b), 'stale_rollback_epoch');
        $this->assertSame(-8000, (int) $f['extra']->fresh()->Amount);
        $fresh = $svc->plan($f['scope'], $f['actor']); $this->assertNotSame($plan['rollback_epoch'], $fresh['rollback_epoch']);
        $this->assertSame('applied', $this->execute($svc, $fresh, $f['actor'], 'AFTER-ROLLBACK-NEW', (string) Str::uuid())['state']);
    }

    public function test_real_pop_adapter_binds_creator_recovers_proof_verifies_and_rolls_back(): void
    {
        $f = $this->excessFixture(); $svc = app(PaymentReversalRestatement::class);
        $actor = $svc->actorContext($f['user']->id, $f['student']->id);
        $p = $svc->requestParameters($svc->plan($f['scope'], $actor), 'SYNTHETIC-POP-REVERSAL');
        $dir = sys_get_temp_dir().'/reversal-pop-'.Str::uuid(); mkdir($dir, 0700); mkdir($dir.'/policies', 0700);
        try {
            file_put_contents($dir.'/policies/default.yaml', "version: 1\n");
            file_put_contents($dir.'/catalog.yaml', "version: 1\noperations:\n  ".PaymentReversalRestatement::OPERATION.":\n    lifecycle: active\n    strategy_class: App\\Operations\\Strategies\\PaymentReversalRestatementStrategy\n    parameter_keys: [campus_id, plan, decision_reference]\n    approval_required: true\n    approval_policy: critical-dual-approval\n    approver_roles: [director, super_admin]\n");
            $pop = new \App\Operations\PopOperationService(new \App\Operations\PopOperationCatalog($dir.'/catalog.yaml'));
            $r = $pop->createDraft(PaymentReversalRestatement::OPERATION, $p, (string) Str::uuid(), 'user:'.$f['user']->id,
                'director', [1], $f['user']->id);
            $this->assertTrue($pop->runDryRun($r['id'], 'user:'.$f['user']->id, $f['user']->id, 'director', [1])['ok']);
            $sha = str_repeat('b', 40);
            $this->assertFalse($pop->approve($r['id'], 'SYNTHETIC-DIRECTOR', 'reviewer-director', 'director', $sha, 900, [1])['ready']);
            $approved = $pop->approve($r['id'], 'SYNTHETIC-FOUNDER', 'reviewer-founder', 'super_admin', $sha, 901);
            $this->assertTrue($approved['ready']);
            $direct = $svc->execute($p['plan'], $actor, $p['decision_reference'], $r['id']); // Interrupted before outer receipt.
            $this->assertSame('applied', $direct['state']);
            $f['user']->update(['status' => 'suspended']); $this->assertFalse(app(\App\Operations\Strategies\PaymentReversalRestatementStrategy::class)->plan($p)['ok']);
            $executed = $pop->run($r['id'], 'execute', $approved['token'], $sha, 'pop-runner');
            $this->assertTrue($executed['ok']); $this->assertTrue($executed['already_applied']);
            $other = $pop->createDraft(PaymentReversalRestatement::OPERATION, $p, (string) Str::uuid(), 'user:'.$f['user']->id, 'director', [1], $f['user']->id);
            $this->assertFalse($pop->runDryRun($other['id'], 'user:'.$f['user']->id, $f['user']->id, 'director', [1])['ok']);
            $this->assertTrue($pop->run($r['id'], 'verify', $approved['token'], $sha, 'pop-runner')['ok']);
            $this->assertSame(0, (int) $f['extra']->fresh()->Amount);
            $this->assertSame('pending', $f['pending']->fresh()->status);
            $this->assertTrue($pop->run($r['id'], 'rollback', $approved['token'], $sha, 'pop-runner')['ok']);
            $this->assertSame(-8000, (int) $f['extra']->fresh()->Amount);
            $this->assertSame(2, DB::table('pop_execution_records')->where('operation_id', $r['id'])->whereIn('phase', ['reversal-proof', 'reversal-proof-rollback'])->count());
        } finally {
            unlink($dir.'/catalog.yaml'); unlink($dir.'/policies/default.yaml'); rmdir($dir.'/policies'); rmdir($dir);
        }
    }

    public function test_pop_adapter_refuses_scope_and_current_staff_permission_drift(): void
    {
        $f = $this->excessFixture(); $svc = app(PaymentReversalRestatement::class);
        $auth = \Mockery::mock(\App\Services\StaffCapabilityAuthorizer::class); $auth->shouldReceive('enabled')->andReturn(true);
        $auth->shouldReceive('resolve')->andReturn(['context_denied' => false, 'role' => 'director', 'campus_ids' => [2, 1, 2]], ['context_denied' => false, 'role' => 'director', 'campus_ids' => [1, 2]]); $this->app->instance(\App\Services\StaffCapabilityAuthorizer::class, $auth);
        try { $a = $svc->actorContext($f['user']->id, $f['student']->id); $b = $svc->actorContext($f['user']->id, $f['student']->id); $svc->prepared($svc->requestParameters($svc->plan($f['scope'], $a), 'SAME-CAMPUSES'), $b); $this->assertSame([1, 2], $a['campus_ids']); $this->assertSame($a, $b); } finally { $this->app->forgetInstance(\App\Services\StaffCapabilityAuthorizer::class); }
        $p = $svc->requestParameters($svc->plan($f['scope'], $svc->actorContext($f['user']->id, $f['student']->id)), 'SYNTHETIC-IDENTITY');
        $adapter = app(\App\Operations\Strategies\PaymentReversalRestatementStrategy::class);
        $this->assertTrue($adapter->plan($p)['ok']);
        $foreign = $p; $foreign['campus_id'] = 2; $this->assertFalse($adapter->plan($foreign)['ok']);
        $op = (string) Str::uuid(); $this->prepareRequest($svc, $p['plan'], $f['actor'], $p['decision_reference'], $op);
        DB::table('pop_operation_requests')->where('id', $op)->update(['actor' => 'user:999999']);
        $this->refused(fn () => $svc->execute($p['plan'], $f['actor'], $p['decision_reference'], $op), 'request_mismatch');
        $f['user']->update(['status' => 'suspended']); $this->assertFalse($adapter->plan($p)['ok']);
        $this->assertSame(-8000, (int) $f['extra']->fresh()->Amount);
    }

    public function test_committed_catalog_remains_planned_and_rejects_approval(): void
    {
        $f = $this->excessFixture(); $svc = app(PaymentReversalRestatement::class);
        $this->plannedCatalog(); $p = $svc->requestParameters($svc->plan($f['scope'], $f['actor']), 'SYNTHETIC-PLANNED');
        $catalog = app(\App\Operations\PopOperationCatalog::class); $this->assertSame('planned', $catalog->operation(PaymentReversalRestatement::OPERATION)['lifecycle']);
        $pop = app(\App\Operations\PopOperationService::class);
        $r = $pop->createDraft(PaymentReversalRestatement::OPERATION, $p, (string) Str::uuid(), 'user:'.$f['user']->id, 'director', [1], $f['user']->id);
        $this->assertTrue($pop->runDryRun($r['id'], 'user:'.$f['user']->id, $f['user']->id, 'director', [1])['ok']);
        $this->refused(fn () => $pop->approve($r['id'], 'founder-go-synthetic', 'reviewer-founder', 'super_admin', str_repeat('b', 40), 901), 'not active');
    }

    public function test_authenticated_http_preview_produces_fresh_confirmable_parameters_without_host_commands(): void
    {
        $this->plannedCatalog();
        $f = $this->excessFixture(); $headers = ['Authorization' => 'Bearer '.$f['token']];
        $p = ['campus_id' => 1, 'plan' => ['scope' => $f['scope']], 'decision_reference' => 'SYNTHETIC-HTTP-PREVIEW'];
        $draft = fn ($p) => $this->postJson('/api/v1/pop/operations/'.PaymentReversalRestatement::OPERATION.'/draft',
            ['parameters' => $p, 'idempotency_key' => (string) Str::uuid()], $headers)->assertCreated()->json('data.id');
        $id = $draft($p);
        $preview = $this->postJson('/api/v1/pop/operations/requests/'.$id.'/dry-run', [], $headers)->assertStatus(422)->json('data.plan');
        $this->assertFalse($preview['ok']); $this->assertSame('preview_confirmation_required', $preview['errors'][0]);
        $confirmed = $preview['preview_parameters']; $this->assertSame($f['user']->id, $confirmed['plan']['actor_user_id']);
        $other = $this->fixture(); $otherHeaders = ['Authorization' => 'Bearer '.$other['token']];
        $otherId = $this->postJson('/api/v1/pop/operations/'.PaymentReversalRestatement::OPERATION.'/draft', ['parameters' => $confirmed, 'idempotency_key' => (string) Str::uuid()], $otherHeaders)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/pop/operations/requests/'.$otherId.'/dry-run', [], $otherHeaders)->assertStatus(422);
        foreach (['INVALID REFERENCE', str_repeat('X', 81)] as $decision) {
            $bad = $confirmed; $bad['decision_reference'] = $decision;
            $this->assertFalse(app(\App\Operations\Strategies\PaymentReversalRestatementStrategy::class)->plan($bad)['ok']);
        }
        $id = $draft($confirmed); $this->postJson('/api/v1/pop/operations/requests/'.$id.'/dry-run', [], $headers)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(-8000, (int) $f['extra']->fresh()->Amount);
    }
}
