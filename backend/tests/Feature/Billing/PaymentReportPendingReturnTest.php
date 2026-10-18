<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Operations\Strategies\PaymentReportPendingReturnStrategy as ReturnReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AgreedFeeFixture;
use Tests\TestCase;

class PaymentReportPendingReturnTest extends TestCase
{
    use AgreedFeeFixture, RefreshDatabase;
    private function candidate(): array
    {
        $f = $this->fixture(); $f['course']->update(['Charge' => 8800, 'Rate' => 1100]); $f['invoice']->update(['TotalAmount' => 8000]);
        DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->delete();
        foreach ([8000, 8800] as $amount) {
            $r = $this->reportFor($f, 'voided'); $r->update(['reported_amount' => $amount]);
            $receipt = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => $amount, 'Method' => 'transfer',
                'PaidAt' => '2026-10-01', 'payment_report_id' => $r->id]); $r->update(['payment_id' => $receipt->id]);
            Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => -$amount, 'Method' => 'void', 'PaidAt' => '2026-10-01', 'payment_report_id' => $r->id]);
        }
        // The separate historical-restatement stage has already neutralized the extra negative.
        Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => 0, 'Method' => 'other', 'PaidAt' => '2026-10-01']);
        $f['pending'] = $this->reportFor($f); $f['pending']->update(['reported_amount' => 8000]);
        $p = ['campus_id' => 1, 'student_id' => (int) $f['student']->id, 'course_id' => (int) $f['course']->ID,
            'invoice_id' => (int) $f['invoice']->id, 'report_id' => (int) $f['pending']->id, 'expected_reported_amount' => 8000,
            'expected_charge' => 8800, 'expected_invoice_total' => 8000, 'decision_reference' => 'SYNTHETIC-PENDING-RETURN',
            'reason' => '回報金額與應收不符，請核對實收後重新登記；退回不代表退款。'];
        $p['expected_state_hash'] = app(ReturnReport::class)->preview($p);
        return [$f, $p];
    }
    private function context(array $f, array $p): array
    {
        $id = (string) Str::uuid();
        DB::table('pop_operation_requests')->insert(['id' => $id, 'operation_id' => ReturnReport::OPERATION,
            'strategy_id' => ReturnReport::class, 'catalog_version' => 1, 'parameters' => json_encode($p),
            'parameters_hash' => str_repeat('c', 64), 'idempotency_key' => $id, 'status' => 'approved',
            'actor' => 'user:'.$f['user']->id, 'created_at' => now(), 'updated_at' => now()]);
        return ['actor' => 'pop-runner', 'operation_id' => $id];
    }
    private function refuse(callable $call, string $code): void
    {
        try { $call(); } catch (\RuntimeException $e) { $this->assertStringContainsString($code, $e->getMessage()); return; }
        $this->fail('Expected refusal '.$code);
    }

    public function test_return_retains_reported_amount_and_money_history_with_replay_and_exact_rollback(): void
    {
        [$f, $p] = $this->candidate(); $svc = app(ReturnReport::class); $ctx = $this->context($f, $p);
        $course = $f['course']->fresh()->getRawOriginal(); $invoice = $f['invoice']->fresh()->getRawOriginal();
        $before = $f['pending']->fresh()->getRawOriginal();
        $payments = DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson();
        $result = $svc->execute($svc->plan($p), $ctx); $report = $f['pending']->fresh();
        $this->assertSame('rejected', $report->status); $this->assertSame(8000, (int) $report->reported_amount);
        $this->assertNull($report->payment_id); $this->assertSame($f['user']->id, (int) $report->confirmed_by);
        $this->assertSame($p['reason'], $report->rejection_note); $this->assertTrue($svc->verify([], $result)['ok']);
        $this->assertSame($course, $f['course']->fresh()->getRawOriginal()); $this->assertSame($invoice, $f['invoice']->fresh()->getRawOriginal());
        $this->assertSame($payments, DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson());
        $this->assertFalse($svc->plan($p, ['operation_id' => (string) Str::uuid()])['ok']);
        $this->assertTrue($svc->execute($svc->plan($p, $ctx), $ctx)['already_applied']);
        DB::beginTransaction();
        try {
            DB::table('payment_reports')->where('id', $p['report_id'])->update(['rejection_note' => 'synthetic target drift']);
            $this->assertFalse($svc->plan($p, $ctx)['ok']);
            $this->refuse(fn () => $svc->execute(['ok' => true, 'parameters' => $p], $ctx), 'target_moved_on');
        } finally { DB::rollBack(); }
        $this->assertTrue($svc->rollback($result['snapshot'], $ctx)['ok']);
        $this->assertSame($before, $f['pending']->fresh()->getRawOriginal());
        $this->assertTrue($svc->rollback($result['snapshot'], $ctx)['already_rolled_back']);
        DB::beginTransaction(); try { DB::table('payment_reports')->where('id', $p['report_id'])->update(['rejection_note' => 'restored target drift']); $this->refuse(fn () => $svc->rollback($result['snapshot'], $ctx), 'values_moved_on'); } finally { DB::rollBack(); }
        $this->assertFalse($svc->plan($p)['ok']);
        $this->assertSame(2, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
    }

    public function test_pending_foreign_amount_unmatched_money_and_later_work_refuse(): void
    {
        [$f, $p] = $this->candidate(); $svc = app(ReturnReport::class); $ctx = $this->context($f, $p); $plan = $svc->plan($p);
        $wrong = $p; $wrong['campus_id'] = 2; $this->assertFalse($svc->plan($wrong)['ok']);
        $f['pending']->update(['reported_amount' => 8800]); $this->assertFalse($svc->plan($p)['ok']);
        $f['pending']->update(['reported_amount' => 8000]);
        $extra = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => -8000, 'Method' => 'void', 'PaidAt' => '2026-10-01']);
        $this->assertFalse($svc->plan($p)['ok']); $extra->delete();
        $f['invoice']->update(['TotalAmount' => 8800]); $this->refuse(fn () => $svc->execute($plan, $ctx), 'unsupported_state');
        $f['invoice']->update(['TotalAmount' => 8000]);
        $p['expected_state_hash'] = $svc->preview($p); $ctx = $this->context($f, $p);
        $result = $svc->execute($svc->plan($p), $ctx); $f['course']->update(['RemainingSessions' => 4]);
        $this->refuse(fn () => $svc->rollback($result['snapshot'], $ctx), 'values_moved_on');
        $this->assertSame('rejected', $f['pending']->fresh()->status);
    }

    public function test_proof_and_required_audit_failure_leave_pending_and_money_unchanged(): void
    {
        foreach (['pop_execution_records', 'security_audit_events'] as $table) {
            [$f, $p] = $this->candidate(); $svc = app(ReturnReport::class); $ctx = $this->context($f, $p); $fault = (object) ['active' => true];
            DB::listen(function ($q) use ($table, $fault): void {
                if ($fault->active && str_starts_with($q->sql, 'insert into `'.$table.'`')) throw new \RuntimeException('synthetic_failure:'.$table);
            });
            try { $this->refuse(fn () => $svc->execute($svc->plan($p), $ctx), 'synthetic_failure:'.$table); }
            finally { $fault->active = false; }
            $this->assertSame('pending', $f['pending']->fresh()->status); $this->assertTrue($svc->plan($p)['ok']);
            $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
        }
    }

    public function test_old_request_refuses_after_rollback_and_fresh_preview_is_required(): void
    {
        [$f, $p] = $this->candidate(); $svc = app(ReturnReport::class); $a = $this->context($f, $p);
        $old = $p; $old['decision_reference'] = 'OLD-SECOND-REQUEST'; $b = $this->context($f, $old);
        $result = $svc->execute($svc->plan($p), $a); $svc->rollback($result['snapshot'], $a);
        $this->assertFalse($svc->plan($old)['ok']);
        $this->refuse(fn () => $svc->execute(['ok' => true, 'parameters' => $old], $b), 'state_changed');
        $this->assertSame('pending', $f['pending']->fresh()->status);
        $fresh = $old; $fresh['expected_state_hash'] = $svc->preview($fresh);
        $this->assertNotSame($old['expected_state_hash'], $fresh['expected_state_hash']);
        $this->assertTrue($svc->execute($svc->plan($fresh), $this->context($f, $fresh))['ok']);
    }

    public function test_real_pop_draft_dual_approval_execute_verify_and_rollback(): void
    {
        [$f, $p] = $this->candidate(); $dir = sys_get_temp_dir().'/pending-pop-'.Str::uuid();
        mkdir($dir, 0700); mkdir($dir.'/policies', 0700);
        try {
            file_put_contents($dir.'/policies/default.yaml', "version: 1\n");
            file_put_contents($dir.'/catalog.yaml', "version: 1\noperations:\n  ".ReturnReport::OPERATION.":\n    lifecycle: active\n    strategy_class: ".ReturnReport::class."\n    parameter_keys: [".implode(', ', array_keys($p))."]\n    approval_required: true\n    approval_policy: critical-dual-approval\n    approver_roles: [director, super_admin]\n");
            $sha = str_repeat('c', 40); file_put_contents($dir.'/deployment.json', json_encode(['backend_sha' => $sha]));
            $pop = new \App\Operations\PopOperationService(new \App\Operations\PopOperationCatalog($dir.'/catalog.yaml'), null, $dir.'/deployment.json');
            $r = $pop->createDraft(ReturnReport::OPERATION, $p, (string) Str::uuid(), 'user:'.$f['user']->id, 'director', [1], $f['user']->id);
            $this->assertTrue($pop->runDryRun($r['id'], 'user:'.$f['user']->id, $f['user']->id, 'director', [1])['ok']);
            $this->assertFalse($pop->approve($r['id'], 'SYNTHETIC-DIRECTOR', 'reviewer-director', 'director', $sha, $f['user']->id + 100000, [1])['ready']);
            $approval = $pop->approve($r['id'], 'SYNTHETIC-FOUNDER', 'reviewer-founder', 'super_admin', $sha, $f['user']->id + 100001);
            $fault = (object) ['armed' => true, 'phase' => 'execute'];
            DB::listen(function ($q) use ($fault): void {
                if ($fault->armed && str_starts_with($q->sql, 'insert ignore into `pop_execution_records`') && in_array($fault->phase, $q->bindings, true)) {
                    $fault->armed = false; throw new \RuntimeException('synthetic_outer_receipt_failure');
                }
            });
            $this->assertFalse($pop->run($r['id'], 'execute', $approval['token'], $sha, 'pop-runner')['ok']);
            $this->assertFalse($fault->armed); $this->assertSame('pending', $f['pending']->fresh()->status);
            $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $r['id'])->where('phase', 'pending-return-proof')->count());
            $this->assertSame('idle', $pop->runApprovedLocally()['status'], 'Failed attempts require an explicitly selected retry');
            $this->refuse(fn () => $pop->runApprovedLocally(null, 'rollback'), 'explicit'); $this->refuse(fn () => $pop->runApprovedLocally($r['id'], 'invalid'), 'valid phase');
            $this->assertSame('skipped', $pop->runApprovedLocally($r['id'], 'verify')['status']);
            DB::beginTransaction();
            try { $this->assertFalse($pop->run($r['id'], 'rollback', $approval['token'], $sha, 'pop-runner')['ok']); $this->assertSame('skipped', $pop->runApprovedLocally($r['id'])['status'], 'Rollback after failed execute must prevent reclaim'); } finally { DB::rollBack(); }
            $oldApprovals = DB::table('pop_approval_events')->where('operation_id', $r['id'])->orderBy('id')->get()->toJson();
            $this->travel(61)->minutes();
            $this->refuse(fn () => $pop->runApprovedLocally($r['id']), 'expired');
            $this->assertFalse($pop->approve($r['id'], 'RETRY-DIRECTOR', 'reviewer-director', 'director', $sha, $f['user']->id + 100000, [1])['ready']);
            $approval = $pop->approve($r['id'], 'RETRY-FOUNDER', 'reviewer-founder', 'super_admin', $sha, $f['user']->id + 100001);
            $this->assertTrue($approval['ready']); $this->assertSame('failed', $approval['status']);
            $this->assertSame('execute-recovery', DB::table('pop_approval_events')->where('operation_id', $r['id'])->latest('id')->value('phase'));
            $this->assertSame($oldApprovals, DB::table('pop_approval_events')->where('operation_id', $r['id'])->orderBy('id')->limit(2)->get()->toJson());
            $this->assertSame('idle', $pop->runApprovedLocally()['status'], 'Renewal must not schedule execute');
            $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $r['id'])->where('phase', 'execute')->where('result', 'failed')->count());
            file_put_contents($dir.'/deployment.json', json_encode(['backend_sha' => str_repeat('d', 40)]));
            $this->refuse(fn () => $pop->runApprovedLocally($r['id']), 'match');
            file_put_contents($dir.'/deployment.json', json_encode(['backend_sha' => $sha]));
            config(['pop_inapp.standing_policy_enabled' => false]); $this->app->instance(\App\Operations\PopOperationService::class, $pop);
            $fault->phase = 'verify'; $fault->armed = true;
            $this->artisan('pop:execute-approved', ['--request' => $r['id']])->assertExitCode(1); $this->assertFalse($fault->armed);
            $this->assertSame('verification_failed', DB::table('pop_operation_requests')->where('id', $r['id'])->value('status'));
            $this->artisan('pop:execute-approved', ['--request' => $r['id'], '--phase' => 'verify'])->assertExitCode(0);
            $this->artisan('pop:execute-approved', ['--request' => $r['id']])->assertExitCode(0);
            $this->assertSame('succeeded', DB::table('pop_operation_requests')->where('id', $r['id'])->value('status'));
            $this->assertSame('skipped', $pop->runApprovedLocally($r['id'])['status']);
            $executed = $pop->run($r['id'], 'execute', $approval['token'], $sha, 'pop-runner');
            $this->assertTrue($executed['ok']); $this->assertSame('rejected', $f['pending']->fresh()->status);
            $other = $pop->createDraft(ReturnReport::OPERATION, $p, (string) Str::uuid(), 'user:'.$f['user']->id, 'director', [1], $f['user']->id);
            $this->assertFalse($pop->runDryRun($other['id'], 'user:'.$f['user']->id, $f['user']->id, 'director', [1])['ok']);
            $this->assertTrue($pop->run($r['id'], 'verify', $approval['token'], $sha, 'pop-runner')['ok']);
            $f['user']->update(['status' => 'suspended']);
            $fault->phase = 'rollback'; $fault->armed = true;
            $this->assertFalse($pop->run($r['id'], 'rollback', $approval['token'], $sha, 'pop-runner')['ok']);
            $this->assertFalse($fault->armed); $this->assertSame('rejected', $f['pending']->fresh()->status);
            $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $r['id'])->where('phase', 'pending-return-rollback')->count());
            $this->assertSame('skipped', $pop->runApprovedLocally($r['id'])['status'], 'A failed rollback must never reclaim execute');
            $this->refuse(fn () => $pop->run($r['id'], 'verify', $approval['token'], $sha, 'pop-runner'), 'rollback'); $this->assertSame('failed', DB::table('pop_operation_requests')->where('id', $r['id'])->value('status'));
            $this->assertSame('skipped', $pop->runApprovedLocally($r['id'], 'verify')['status']);
            $this->artisan('pop:execute-approved', ['--request' => $r['id'], '--phase' => 'rollback'])->assertExitCode(0);
            $this->assertTrue($pop->run($r['id'], 'rollback', $approval['token'], $sha, 'pop-runner')['ok']);
            $this->assertSame('pending', $f['pending']->fresh()->status);
            $this->refuse(fn () => $pop->run($r['id'], 'verify', $approval['token'], $sha, 'pop-runner'), 'rollback'); $this->assertSame('rolled_back', DB::table('pop_operation_requests')->where('id', $r['id'])->value('status'));
        } finally {
            $this->travelBack();
            unlink($dir.'/deployment.json'); unlink($dir.'/catalog.yaml'); unlink($dir.'/policies/default.yaml'); rmdir($dir.'/policies'); rmdir($dir);
        }
    }

    public function test_committed_catalog_keeps_all_three_prepared_operations_planned(): void
    {
        [$f, $p] = $this->candidate(); $catalog = app(\App\Operations\PopOperationCatalog::class);
        foreach ([ReturnReport::OPERATION, 'reviewed-unpaid-invoice-charge-alignment', 'reviewed-legacy-reversal-restatement'] as $operation) {
            $entry = $catalog->operation($operation); $this->assertSame('planned', $entry['lifecycle']);
            $this->assertTrue($entry['founder_approval_required']); $this->assertSame(['super_admin'], $entry['approver_roles']);
            $this->assertSame('founder-exact-reviewed-financial', $entry['approval_policy']); $policy = file_get_contents(dirname(base_path()).'/operations/policies/default.yaml');
            $this->assertSame(1, preg_match('/- id: founder-exact-reviewed-financial(.*?)(?=\n  - id:|\z)/s', $policy, $m)); $this->assertStringContainsString($operation, $m[1]); $this->assertStringContainsString($entry['blast_radius'], $m[1]);
        }
        $pop = app(\App\Operations\PopOperationService::class);
        $r = $pop->createDraft(ReturnReport::OPERATION, $p, (string) Str::uuid(), 'user:'.$f['user']->id, 'director', [1], $f['user']->id);
        $this->assertTrue($pop->runDryRun($r['id'], 'user:'.$f['user']->id, $f['user']->id, 'director', [1])['ok']);
        $this->refuse(fn () => $pop->approve($r['id'], 'founder-go-synthetic', 'reviewer-founder', 'super_admin', str_repeat('c', 40), $f['user']->id + 100001), 'not active');
    }

    public function test_exact_founder_shape_allows_same_human_requester_only_after_explicit_activation(): void
    {
        [$f, $p] = $this->candidate(); $f['user']->update(['type' => 'S']);
        $dir = sys_get_temp_dir().'/pending-founder-'.Str::uuid(); mkdir($dir, 0700); mkdir($dir.'/policies', 0700);
        try {
            $yaml = file_get_contents(dirname(base_path()).'/operations/catalog.yaml');
            $yaml = str_replace(ReturnReport::OPERATION.":\n    lifecycle: planned", ReturnReport::OPERATION.":\n    lifecycle: active", $yaml);
            file_put_contents($dir.'/catalog.yaml', $yaml); file_put_contents($dir.'/policies/default.yaml', "version: 1\n");
            $catalog = new \App\Operations\PopOperationCatalog($dir.'/catalog.yaml');
            $pop = new \App\Operations\PopOperationService($catalog); $actor = 'user:'.$f['user']->id;
            $r = $pop->createDraft(ReturnReport::OPERATION, $p, (string) Str::uuid(), $actor, 'super_admin', [], $f['user']->id);
            $this->assertTrue($pop->runDryRun($r['id'], $actor, $f['user']->id, 'super_admin', [])['ok']);
            $approval = $pop->approve($r['id'], 'founder-go-synthetic-case', $actor, 'super_admin', str_repeat('c', 40), $f['user']->id);
            $this->assertTrue($approval['ready']); $this->assertSame(['super_admin'], $approval['required_roles']);
            $this->assertTrue($pop->run($r['id'], 'execute', $approval['token'], str_repeat('c', 40), 'pop-runner')['ok']);
            $method = new \ReflectionMethod($pop, 'approvalRoles'); $entry = $catalog->operation(ReturnReport::OPERATION);
            foreach (['strategy_class', 'parameter_keys', 'blast_radius', 'risk', 'founder_approval_required', 'snapshot_required', 'transaction_required', 'rollback_supported', 'verification_required', 'approval_required', 'approval_policy', 'execution_authority', 'approver_roles'] as $key) {
                $bad = $entry; $bad[$key] = false;
                $this->refuse(fn () => $method->invoke($pop, $bad), 'approval');
            }
        } finally {
            unlink($dir.'/catalog.yaml'); unlink($dir.'/policies/default.yaml'); rmdir($dir.'/policies'); rmdir($dir);
        }
    }

    public function test_initial_authenticated_http_preview_is_read_only_and_requires_new_confirmed_draft(): void
    {
        [$f, $p] = $this->candidate(); $this->refuse(fn () => app(\App\Operations\PopOperationService::class)->createDraft(ReturnReport::OPERATION, $p, (string) Str::uuid(), 'api_client:synthetic', 'pop_machine', [1], 1), 'human'); $p['expected_state_hash'] = ''; $headers = ['Authorization' => 'Bearer '.$f['token']];
        $draft = fn ($p) => $this->postJson('/api/v1/pop/operations/'.ReturnReport::OPERATION.'/draft',
            ['parameters' => $p, 'idempotency_key' => (string) Str::uuid()], $headers)->assertCreated()->json('data.id');
        $id = $draft($p); $preview = $this->postJson('/api/v1/pop/operations/requests/'.$id.'/dry-run', [], $headers)->assertStatus(422)->json('data.plan');
        $this->assertFalse($preview['ok']); $this->assertSame('preview_confirmation_required', $preview['errors'][0]);
        $id = $draft($preview['preview_parameters']);
        $this->postJson('/api/v1/pop/operations/requests/'.$id.'/dry-run', [], $headers)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('pending', $f['pending']->fresh()->status);
    }

    public function test_read_only_status_is_campus_scoped_and_never_returns_private_proof_or_token(): void
    {
        [$f, $p] = $this->candidate(); $ctx = $this->context($f, $p); $url = '/api/v1/pop/operations/requests/'.$ctx['operation_id'].'/status';
        $headers = ['Authorization' => 'Bearer '.$f['token']];
        $data = $this->getJson($url, $headers)->assertOk()->json('data');
        $this->assertSame('approved', $data['status']);
        $this->assertSame(['request_id', 'operation_id', 'status', 'phase', 'result', 'execution_id', 'commit_sha'], array_keys($data));
        foreach (['dry-run' => 2, 'execute' => 1] as $phase => $attempt) {
            $executionId = (string) Str::uuid();
            DB::table('pop_execution_records')->insert(['id' => $executionId, 'execution_id' => $executionId, 'operation_id' => $ctx['operation_id'],
                'phase' => $phase, 'attempt_no' => $attempt, 'result' => 'failed', 'idempotency_key' => $executionId, 'attempt_key' => $executionId,
                'correlation_id' => $executionId, 'payload' => '{}', 'actor' => 'synthetic-pop', 'created_at' => now()->startOfSecond()]);
        }
        DB::table('pop_operation_requests')->where('id', $ctx['operation_id'])->update(['status' => 'failed']);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.phase', 'execute')->assertJsonPath('data.execution_id', $executionId);
        DB::table('UserCampus')->where('UserID', $f['user']->id)->update(['CampusID' => 2]);
        $this->getJson($url, $headers)->assertForbidden();
        DB::table('UserCampus')->where('UserID', $f['user']->id)->delete(); $this->getJson($url, $headers)->assertForbidden();
        $f['user']->update(['type' => 'S']); $this->getJson($url, $headers)->assertOk();
        $this->getJson($url, ['Authorization' => 'Bearer invalid-fixture-token'])->assertUnauthorized();
    }
}
