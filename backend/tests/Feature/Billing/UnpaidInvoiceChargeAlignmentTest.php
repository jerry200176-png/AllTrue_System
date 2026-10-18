<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Operations\PopOperationService;
use App\Operations\Strategies\UnpaidInvoiceChargeAlignmentStrategy as Alignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AgreedFeeFixture;
use Tests\TestCase;

class UnpaidInvoiceChargeAlignmentTest extends TestCase
{
    use AgreedFeeFixture, RefreshDatabase;

    private function candidate(int $old, int $new, bool $voidHistory = true): array
    {
        $f = $this->fixture();
        $f['course']->update(['Charge' => $new]); $f['invoice']->update(['TotalAmount' => $old]);
        DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->delete();
        if ($voidHistory) {
            $report = $this->reportFor($f, 'voided'); $report->update(['reported_amount' => $old]);
            $payment = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => $old, 'Method' => 'cash',
                'PaidAt' => '2026-10-01', 'payment_report_id' => $report->id]);
            $report->update(['payment_id' => $payment->id]);
            Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => -$old, 'Method' => 'void',
                'PaidAt' => '2026-10-01', 'payment_report_id' => $report->id]);
        }
        $p = ['student_id' => (int) $f['student']->id, 'course_id' => (int) $f['course']->ID, 'campus_id' => 1,
            'invoice_id' => (int) $f['invoice']->id, 'expected_invoice_total' => $old, 'expected_charge' => $new,
            'decision_reference' => 'SYNTHETIC-ALIGNMENT'];
        $svc = app(Alignment::class); $p['expected_state_hash'] = $svc->preview($p)['state_hash'];
        // Use the real draft writer: strategy_id is a PHP class name, not a made-up version ID.
        $catalogPath = tempnam(sys_get_temp_dir(), 'alignment-catalog-');
        try {
            file_put_contents($catalogPath, "version: 1\noperations:\n  synthetic-alignment:\n    lifecycle: planned\n    strategy_class: ".Alignment::class."\n    parameter_keys: [".implode(', ', array_keys($p))."]\n    approval_required: true\n    approver_roles: [super_admin]\n");
            $pop = new PopOperationService(new \App\Operations\PopOperationCatalog($catalogPath));
            $request = $pop->createDraft('synthetic-alignment', $p, (string) Str::uuid(), 'synthetic-pop', 'director', [1]);
            $id = $request['id'];
            // Strategy-level tests only; this is not a claim that protected POP approval passed.
            DB::table('pop_operation_requests')->where('id', $id)->update(['status' => 'approved']);
        } finally { unlink($catalogPath); }
        return [$f, $p, ['operation_id' => $id, 'actor' => 'synthetic-pop']];
    }

    private function refuse(callable $call, string $code): void
    {
        try { $call(); } catch (\RuntimeException $e) { $this->assertStringContainsString($code, $e->getMessage()); return; }
        $this->fail('Expected refusal '.$code);
    }

    public function test_three_target_shapes_align_only_invoice_and_keep_atomic_recovery_proof(): void
    {
        $svc = app(Alignment::class);
        foreach ([[8000, 500], [8800, 7200], [8000, 8800]] as [$old, $new]) {
            [$f, $p, $ctx] = $this->candidate($old, $new);
            $course = $f['course']->fresh()->getRawOriginal(); $invoice = $f['invoice']->fresh()->getRawOriginal();
            $payments = DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson();
            $reports = DB::table('payment_reports')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson();
            $plan = $svc->plan($p); $this->assertTrue($plan['ok']);
            $result = $svc->execute($plan, $ctx); $this->assertSame(1, $result['updated']);
            $this->assertTrue($svc->verify($plan, $result)['ok']);
            $this->assertSame($new, (int) $f['invoice']->fresh()->TotalAmount);
            $this->assertSame(0, (int) $f['invoice']->fresh()->PaidAmount);
            $this->assertSame('unpaid', $f['invoice']->fresh()->Status);
            $this->assertSame($course, $f['course']->fresh()->getRawOriginal());
            $this->assertSame($payments, DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson());
            $this->assertSame($reports, DB::table('payment_reports')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson());
            $this->assertTrue($svc->execute($plan, $ctx)['already_applied']);
            $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
            $this->assertSame(1, $svc->rollback($result['snapshot'], $ctx)['restored']);
            $this->assertSame($invoice, $f['invoice']->fresh()->getRawOriginal());
            $this->assertTrue($svc->rollback($result['snapshot'], $ctx)['already_rolled_back']);
            DB::beginTransaction(); try { DB::table('Invoice')->where('id', $p['invoice_id'])->update(['TotalAmount' => $old + 1]); $this->refuse(fn () => $svc->rollback($result['snapshot'], $ctx), 'values_moved_on'); } finally { DB::rollBack(); }
            $this->assertSame(2, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
            $this->refuse(fn () => $svc->execute($plan, $ctx), 'reapply_requires_new_request');
        }
    }

    public function test_foreign_owner_pending_unmatched_reversal_drift_and_request_tampering_refuse(): void
    {
        $svc = app(Alignment::class); [$f, $p, $ctx] = $this->candidate(8000, 8800);
        $foreign = $p; $foreign['campus_id'] = 2; $this->assertFalse($svc->plan($foreign)['ok']);
        $pending = $this->reportFor($f); $this->assertFalse($svc->plan($p)['ok']); $pending->delete();
        $extra = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => -8000, 'Method' => 'void', 'PaidAt' => '2026-10-01']);
        $this->assertFalse($svc->plan($p)['ok']); $extra->delete();
        $plan = $svc->plan($p); $this->assertTrue($plan['ok']);
        $f['course']->update(['RemainingSessions' => 4]);
        $this->refuse(fn () => $svc->execute($plan, $ctx), 'state_changed');
        $f['course']->update(['RemainingSessions' => 5]);
        $tampered = $plan; $tampered['parameters']['expected_charge'] = 9999;
        $this->refuse(fn () => $svc->execute($tampered, $ctx), 'request_mismatch');
        $this->assertSame(8000, (int) $f['invoice']->fresh()->TotalAmount);
    }

    public function test_later_work_refuses_rollback_but_retains_replay_and_original_history(): void
    {
        $svc = app(Alignment::class); [$f, $p, $ctx] = $this->candidate(8000, 500, false);
        $plan = $svc->plan($p); $result = $svc->execute($plan, $ctx);
        $pending = $this->reportFor($f);
        $this->assertFalse($svc->verify($plan, $result)['ok']);
        $this->refuse(fn () => $svc->rollback($result['snapshot'], $ctx), 'values_moved_on');
        $this->assertTrue($svc->execute($plan, $ctx)['already_applied']);
        $this->assertSame(500, (int) $f['invoice']->fresh()->TotalAmount);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
    }

    public function test_replay_refuses_changed_or_deleted_target_invoice(): void
    {
        $svc = app(Alignment::class); [$f, $p, $ctx] = $this->candidate(8000, 500);
        $plan = $svc->plan($p); $svc->execute($plan, $ctx);
        foreach (['edit', 'delete'] as $change) {
            DB::beginTransaction();
            try {
                $target = DB::table('Invoice')->where('id', $p['invoice_id']);
                if ($change === 'edit') $target->update(['TotalAmount' => 777]);
                else $target->delete();
                $this->refuse(fn () => $svc->execute($plan, $ctx), 'target_moved_on');
                $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
            } finally { DB::rollBack(); }
        }
        $this->assertTrue($svc->execute($plan, $ctx)['already_applied']);
    }

    public function test_proof_or_required_audit_failure_rolls_back_invoice_change(): void
    {
        $svc = app(Alignment::class);
        foreach (['pop_execution_records', 'security_audit_events'] as $table) {
            [$f, $p, $ctx] = $this->candidate(8000, 500); $plan = $svc->plan($p); $fault = (object) ['active' => true];
            DB::listen(function ($q) use ($table, $fault) {
                if ($fault->active && str_starts_with($q->sql, 'insert into `'.$table.'`')) throw new \RuntimeException('synthetic_write_failure:'.$table);
            });
            try { $this->refuse(fn () => $svc->execute($plan, $ctx), 'synthetic_write_failure:'.$table); }
            finally { $fault->active = false; }
            $this->assertSame(8000, (int) $f['invoice']->fresh()->TotalAmount);
            $this->assertTrue($svc->plan($p)['ok']);
            $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
        }
    }

    public function test_proof_is_retained_when_rollback_evidence_cannot_be_written(): void
    {
        $svc = app(Alignment::class); [$f, $p, $ctx] = $this->candidate(8000, 500);
        $result = $svc->execute($svc->plan($p), $ctx); $active = true;
        DB::listen(function ($q) use (&$active) {
            if ($active && str_starts_with($q->sql, 'insert into `pop_execution_records`')) throw new \RuntimeException('synthetic_rollback_proof_failure');
        });
        try { $this->refuse(fn () => $svc->rollback($result['snapshot'], $ctx), 'synthetic_rollback_proof_failure'); }
        finally { $active = false; }
        $this->assertSame(500, (int) $f['invoice']->fresh()->TotalAmount);
        $this->assertTrue($svc->verify([], $result)['ok']);
        $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
        $tampered = $result['snapshot']; $tampered['before']['invoice']['TotalAmount'] = 99999;
        $this->refuse(fn () => $svc->rollback($tampered, $ctx), 'history_mismatch');
        $this->assertSame(1, $svc->rollback($result['snapshot'], $ctx)['restored']);
        $this->assertSame(8000, (int) $f['invoice']->fresh()->TotalAmount);
    }

    public function test_pop_recovers_after_strategy_commit_without_outer_execute_record(): void
    {
        $svc = app(Alignment::class); [$f, $p, $ctx] = $this->candidate(8000, 500);
        $dir = sys_get_temp_dir().'/alignment-pop-'.Str::uuid(); mkdir($dir, 0700); mkdir($dir.'/policies', 0700);
        try {
            file_put_contents($dir.'/policies/default.yaml', "version: 1\n");
            file_put_contents($dir.'/catalog.yaml', "version: 1\noperations:\n  synthetic-alignment:\n    lifecycle: active\n    strategy_class: ".Alignment::class."\n    parameter_keys: [".implode(', ', array_keys($p))."]\n    approval_required: true\n    approval_policy: critical-dual-approval\n    approver_roles: [director, super_admin]\n");
            $pop = new PopOperationService(new \App\Operations\PopOperationCatalog($dir.'/catalog.yaml'));
            $dry = $pop->runDryRun($ctx['operation_id'], 'synthetic-pop', null, 'director', [1]);
            $this->assertTrue($dry['ok']); $sha = str_repeat('a', 40);
            $first = $pop->approve($ctx['operation_id'], 'SYNTHETIC-DIRECTOR', 'reviewer-director', 'director', $sha, 900, [1]);
            $this->assertFalse($first['ready']);
            $approved = $pop->approve($ctx['operation_id'], 'SYNTHETIC-FOUNDER', 'reviewer-founder', 'super_admin', $sha, 901);
            $this->assertTrue($approved['ready']);
            $direct = $svc->execute($svc->plan($p), $ctx); // Process exits here, before outer phase=execute recording.
            $this->assertSame(500, (int) $f['invoice']->fresh()->TotalAmount);
            $this->assertFalse(DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->where('phase', 'execute')->exists());
            $this->assertFalse($svc->plan($p, ['operation_id' => (string) Str::uuid()])['ok'], 'A different request cannot inherit the retained proof');
            $recovered = $pop->run($ctx['operation_id'], 'execute', $approved['token'], $sha, 'synthetic-pop');
            $this->assertTrue($recovered['ok']); $this->assertTrue($recovered['already_applied']);
            $this->assertSame('succeeded', $recovered['result']);
            $this->assertEquals($direct['snapshot'], $recovered['snapshot']); // MySQL JSON normalizes numeric/object representation.
            $verified = $pop->run($ctx['operation_id'], 'verify', $approved['token'], $sha, 'synthetic-pop');
            $this->assertTrue($verified['ok']);
            $rollback = $pop->run($ctx['operation_id'], 'rollback', $approved['token'], $sha, 'synthetic-pop');
            $this->assertTrue($rollback['ok']); $this->assertSame('rolled_back', $rollback['result']);
            $this->assertSame(8000, (int) $f['invoice']->fresh()->TotalAmount);
            $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->where('phase', 'repair-proof')->count());

            // A failed outer receipt must roll back the money AND the internal proof on a normal POP run.
            [$secondFixture, $secondParameters, $secondContext] = $this->candidate(8000, 500);
            $id = $secondContext['operation_id'];
            $this->assertTrue($pop->runDryRun($id, 'synthetic-pop', null, 'director', [1])['ok']);
            $pop->approve($id, 'SYNTHETIC-DIRECTOR', 'reviewer-director', 'director', $sha, 900, [1]);
            $secondApproval = $pop->approve($id, 'SYNTHETIC-FOUNDER', 'reviewer-founder', 'super_admin', $sha, 901);
            $fault = (object) ['armed' => true];
            DB::listen(function ($q) use ($fault): void {
                if ($fault->armed && str_starts_with($q->sql, 'insert ignore into `pop_execution_records`')
                    && in_array('execute', $q->bindings, true)) {
                    $fault->armed = false;
                    throw new \RuntimeException('synthetic_outer_execution_receipt_failure');
                }
            });
            $failed = $pop->run($id, 'execute', $secondApproval['token'], $sha, 'synthetic-pop');
            $this->assertFalse($fault->armed, 'The fault must reach the outer execution writer');
            $this->assertFalse($failed['ok']);
            $this->assertSame(8000, (int) $secondFixture['invoice']->fresh()->TotalAmount);
            $this->assertTrue($svc->plan($secondParameters)['ok']);
            $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'repair-proof')->count());
            $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->where('result', 'failed')->count());
            $retriedExecute = $pop->run($id, 'execute', $secondApproval['token'], $sha, 'synthetic-pop');
            $this->assertTrue($retriedExecute['ok']); $this->assertSame(2, $retriedExecute['attempt_no']);
            $this->assertSame(500, (int) $secondFixture['invoice']->fresh()->TotalAmount);
            $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->where('result', 'failed')->count());

            [$thirdFixture, $thirdParameters, $thirdContext] = $this->candidate(8000, 500);
            $id = $thirdContext['operation_id'];
            $this->assertTrue($pop->runDryRun($id, 'synthetic-pop', null, 'director', [1])['ok']);
            $pop->approve($id, 'SYNTHETIC-DIRECTOR', 'reviewer-director', 'director', $sha, 900, [1]);
            $thirdApproval = $pop->approve($id, 'SYNTHETIC-FOUNDER', 'reviewer-founder', 'super_admin', $sha, 901);
            $this->assertTrue($pop->run($id, 'execute', $thirdApproval['token'], $sha, 'synthetic-pop')['ok']);
            $rollbackFault = (object) ['armed' => true];
            DB::listen(function ($q) use ($rollbackFault): void {
                if ($rollbackFault->armed && str_starts_with($q->sql, 'insert ignore into `pop_execution_records`')
                    && in_array('rollback', $q->bindings, true)) {
                    $rollbackFault->armed = false;
                    throw new \RuntimeException('synthetic_outer_rollback_receipt_failure');
                }
            });
            $failedRollback = $pop->run($id, 'rollback', $thirdApproval['token'], $sha, 'synthetic-pop');
            $this->assertFalse($rollbackFault->armed);
            $this->assertFalse($failedRollback['ok']);
            $this->assertSame(500, (int) $thirdFixture['invoice']->fresh()->TotalAmount, 'Failed outer rollback receipt must undo the attempted restore');
            $this->assertTrue($svc->plan($thirdParameters)['ok']);
            $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'repair-proof-rollback')->count());
            $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'rollback')->where('result', 'failed')->count());
            // #4271: a transient outer receipt failure must not permanently strand rollback.
            $oldApprovals = DB::table('pop_approval_events')->where('operation_id', $id)->orderBy('id')->get()->toJson();
            $oldToken = $thirdApproval['token']; $this->travel(61)->minutes();
            $this->refuse(fn () => $pop->run($id, 'rollback', $oldToken, $sha, 'synthetic-pop'), 'expired');
            $this->assertFalse($pop->approve($id, 'SYNTHETIC-RENEW-DIRECTOR', 'reviewer-director', 'director', $sha, 900, [1])['ready']);
            $thirdApproval = $pop->approve($id, 'SYNTHETIC-RENEW-FOUNDER', 'reviewer-founder', 'super_admin', $sha, 901);
            $this->assertTrue($thirdApproval['ready']);
            $this->assertSame($oldApprovals, DB::table('pop_approval_events')->where('operation_id', $id)->orderBy('id')->limit(2)->get()->toJson());
            $this->assertSame('failed', DB::table('pop_operation_requests')->where('id', $id)->value('status'), 'Rollback approval must not enqueue a new execute');
            $this->refuse(fn () => $pop->run($id, 'execute', $thirdApproval['token'], $sha, 'synthetic-pop'), 'bound');
            $this->refuse(fn () => $pop->run($id, 'rollback', 'invalid-token', $sha, 'synthetic-pop'), 'approval token');
            $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'rollback')->count());
            $retriedRollback = $pop->run($id, 'rollback', $thirdApproval['token'], $sha, 'synthetic-pop');
            $this->assertTrue($retriedRollback['ok']);
            $this->assertSame('rolled_back', $retriedRollback['result']);
            $this->assertSame(2, $retriedRollback['attempt_no']);
            $this->assertSame(8000, (int) $thirdFixture['invoice']->fresh()->TotalAmount);
            $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'rollback')->where('result', 'failed')->count());
            $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'repair-proof-rollback')->count());
            $this->assertSame($retriedRollback, $pop->run($id, 'rollback', $thirdApproval['token'], $sha, 'synthetic-pop'));
            $this->assertSame(2, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'rollback')->count());
            $this->refuse(fn () => $pop->run($id, 'verify', $thirdApproval['token'], $sha, 'synthetic-pop'), 'rollback'); $this->assertSame('rolled_back', DB::table('pop_operation_requests')->where('id', $id)->value('status'));
        } finally {
            $this->travelBack();
            unlink($dir.'/catalog.yaml'); unlink($dir.'/policies/default.yaml'); rmdir($dir.'/policies'); rmdir($dir);
        }
    }

    public function test_pre_rollback_request_cannot_reapply_after_invoice_values_are_restored(): void
    {
        $svc = app(Alignment::class); [$f, $p, $a] = $this->candidate(8000, 500);
        $old = $p; $old['decision_reference'] = 'OLD-SECOND-ALIGNMENT';
        $b = (string) Str::uuid(); $r = (array) DB::table('pop_operation_requests')->where('id', $a['operation_id'])->first();
        $r['id'] = $b; $r['idempotency_key'] = $b; $r['parameters'] = json_encode($old);
        $r['parameters_hash'] = hash('sha256', json_encode(PopOperationService::canonicalParameters($old)));
        DB::table('pop_operation_requests')->insert($r);
        $beforePlan = $svc->plan($old); $result = $svc->execute($svc->plan($p), $a);
        $svc->rollback($result['snapshot'], $a);
        $this->assertFalse($svc->plan($old)['ok']);
        $this->refuse(fn () => $svc->execute($beforePlan, ['actor' => 'synthetic-pop', 'operation_id' => $b]), 'state_changed');
        $this->assertSame(8000, (int) $f['invoice']->fresh()->TotalAmount);
        $this->assertNotSame($p['expected_state_hash'], $svc->preview($p)['state_hash']);
    }

    public function test_initial_dry_run_returns_confirmation_parameters_and_cannot_execute(): void
    {
        [$f, $p, $ctx] = $this->candidate(8000, 500); $svc = app(Alignment::class);
        $p['expected_state_hash'] = ''; $preview = $svc->plan($p);
        $this->assertFalse($preview['ok']); $this->assertSame('preview_confirmation_required', $preview['errors'][0]);
        $this->assertTrue($svc->plan($preview['preview_parameters'])['ok']);
        $this->refuse(fn () => $svc->execute($preview, $ctx), 'plan_not_ready');
        $this->assertSame(8000, (int) $f['invoice']->fresh()->TotalAmount);
    }

}
