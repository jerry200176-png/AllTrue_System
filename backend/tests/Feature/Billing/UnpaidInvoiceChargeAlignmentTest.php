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
        try { $call(); $this->fail('Expected refusal '.$code); }
        catch (\RuntimeException $e) { $this->assertStringContainsString($code, $e->getMessage()); }
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
}
