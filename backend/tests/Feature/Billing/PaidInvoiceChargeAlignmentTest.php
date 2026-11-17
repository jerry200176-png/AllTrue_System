<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\PaidInvoiceChargeAlignmentStrategy as Alignment;
use App\Services\InvoiceAmountReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaidInvoiceAlignmentFixture;
use Tests\TestCase;

/** GH5413: synthetic equivalent of a reversed receipt followed by full payment of the agreed fee. */
class PaidInvoiceChargeAlignmentTest extends TestCase
{
    use PaidInvoiceAlignmentFixture, RefreshDatabase;

    private function graph(array $f): array
    {
        $rows = fn ($table, $key, $id) => DB::table($table)->where($key, $id)->orderBy($table === 'ClassSession' ? 'ID' : 'id')->get()->toJson();
        return ['invoice' => $f['invoice']->fresh()->getRawOriginal(), 'course' => $f['course']->fresh()->getRawOriginal(),
            'payments' => $rows('Payment', 'InvoiceID', $f['invoice']->id), 'reports' => $rows('payment_reports', 'InvoiceID', $f['invoice']->id),
            'sessions' => $rows('ClassSession', 'StudentClassID', $f['course']->ID)];
    }

    public function test_paid_bill_is_corrected_without_rewriting_cash_or_lessons_and_can_be_restored_exactly(): void
    {
        [$f, $p, $ctx, , $valid] = $this->candidate(); $svc = app(Alignment::class); $before = $this->graph($f);
        $headers = ['Authorization' => 'Bearer '.$f['token']];
        $receiptBefore = $this->withHeaders($headers)->getJson('/api/v1/payment-reports/'.$valid->id.'/receipt')->assertOk()->json();
        $plan = $svc->plan($p); $this->assertTrue($plan['ok']);
        $result = $svc->execute($plan, $ctx); $this->assertTrue($svc->verify($plan, $result)['ok']);
        $after = $this->graph($f);
        $this->assertSame([8000, 8000, 'paid'], [(int) $after['invoice']['TotalAmount'], (int) $after['invoice']['PaidAmount'], $after['invoice']['Status']]);
        $this->assertSame(1, (int) $after['course']['Paid']);
        $this->assertSame('2026-10-10', $after['course']['PayDate']);
        foreach (['payments', 'reports', 'sessions'] as $key) $this->assertSame($before[$key], $after[$key]);
        $this->assertSame(array_diff_key($before['course'], array_flip(['Paid', 'PayDate'])), array_diff_key($after['course'], array_flip(['Paid', 'PayDate'])));
        $projection = app(InvoiceAmountReconciliationService::class)->resolve($f['invoice']->fresh());
        $this->assertSame([8000, 8000, 0, 0], [$projection['total_amount'], $projection['net_applied'], $projection['outstanding_amount'], $projection['overpaid_amount']]);
        $this->withHeaders($headers)->getJson('/api/v1/invoices/'.$f['invoice']->id.'/slip-data')->assertOk()
            ->assertJsonPath('total_amount', 8000)->assertJsonPath('paid_amount', 8000)->assertJsonPath('remaining', 0);
        $this->withHeaders($headers)->getJson('/api/v1/accounting/ledger?student_class_id='.$f['course']->ID)->assertOk()
            ->assertJsonPath('invoices.0.total_amount', 8000)->assertJsonPath('invoices.0.outstanding_amount', 0)->assertJsonPath('summary.outstanding_total', 0);
        $this->assertSame($receiptBefore, $this->withHeaders($headers)->getJson('/api/v1/payment-reports/'.$valid->id.'/receipt')->assertOk()->json());
        $this->assertTrue($svc->execute($plan, $ctx)['already_applied']);
        $this->assertSame(1, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
        $this->assertTrue($svc->rollback($result['snapshot'], $ctx)['ok']);
        $this->assertSame($before, $this->graph($f));
        $this->assertTrue($svc->rollback($result['snapshot'], $ctx)['already_rolled_back']);
        $this->assertFalse($svc->plan($p, $ctx)['ok']);
    }

    public function test_ambiguous_money_or_owner_or_contract_history_refuses_without_a_write(): void
    {
        [$f, $p, $ctx, $old, $valid, $cash] = $this->candidate(); $svc = app(Alignment::class);
        $changes = [
            fn () => $f['student']->update(['CampusID' => 2]),
            fn () => $f['course']->update(['Charge' => 7900]),
            fn () => $f['course']->update(['ScheduleMode' => 'date']),
            fn () => $f['course']->update(['Stop' => 1]),
            fn () => $f['invoice']->update(['PaidAmount' => 7900]),
            fn () => $f['invoice']->update(['Status' => 'paid']),
            fn () => $valid->update(['reported_amount' => 7900]),
            fn () => $valid->update(['reported_amount' => '8000.50']),
            fn () => $valid->update(['status' => 'pending']),
            fn () => $valid->update(['payment_id' => null]),
            fn () => $cash->update(['payment_report_id' => null]),
            fn () => $cash->update(['Amount' => 7900]),
            fn () => $old->update(['status' => 'confirmed']),
            fn () => Payment::where('Method', 'void')->update(['Amount' => -8700]),
            fn () => Payment::where('Method', 'void')->update(['payment_report_id' => null]),
            fn () => Payment::where('Method', 'void')->update(['PaidAt' => '2026-10-11']),
            fn () => $this->reportFor($f),
        ];
        foreach ($changes as $change) {
            DB::beginTransaction();
            try {
                $change(); $before = $this->graph($f);
                $this->assertFalse($svc->plan($p, $ctx)['ok']);
                $this->assertSame($before, $this->graph($f));
                $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
            } finally { DB::rollBack(); }
        }
        $this->assertTrue($svc->plan($p, $ctx)['ok']);
        $this->assertFalse(app(\App\Operations\Strategies\UnpaidInvoiceChargeAlignmentStrategy::class)->plan($p)['ok']);
    }

    public function test_drift_after_preview_or_after_apply_blocks_execute_and_rollback(): void
    {
        [$f, $p, $ctx] = $this->candidate(); $svc = app(Alignment::class); $plan = $svc->plan($p);
        $f['course']->update(['RemainingSessions' => 4]); $before = $this->graph($f);
        try { $svc->execute($plan, $ctx); $this->fail('stale preview was applied'); } catch (\RuntimeException $e) { $this->assertStringContainsString('state_changed', $e->getMessage()); }
        $this->assertSame($before, $this->graph($f));
        $f['course']->update(['RemainingSessions' => 5]); $result = $svc->execute($plan, $ctx);
        $this->reportFor($f); $before = $this->graph($f);
        try { $svc->rollback($result['snapshot'], $ctx); $this->fail('new downstream work was overwritten'); } catch (\RuntimeException $e) { $this->assertStringContainsString('values_moved_on', $e->getMessage()); }
        $this->assertSame($before, $this->graph($f));
    }

    public function test_proof_and_required_audit_failures_restore_both_invoice_and_paid_sync(): void
    {
        [$f, $p, $ctx] = $this->candidate(); $svc = app(Alignment::class); $before = $this->graph($f);
        $fault = (object) ['table' => null];
        DB::listen(function ($query) use ($fault): void {
            if ($fault->table && str_starts_with($query->sql, 'insert into `'.$fault->table.'`')) throw new \RuntimeException('synthetic_atomic_fault');
        });
        foreach (['pop_execution_records', 'security_audit_events'] as $table) {
            $fault->table = $table;
            try { $svc->execute($svc->plan($p), $ctx); $this->fail('fault did not fire'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('synthetic_atomic_fault', $e->getMessage()); }
            finally { $fault->table = null; }
            $this->assertSame($before, $this->graph($f));
            $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
        }
    }

    public function test_native_pop_requires_human_exact_tuple_and_co_commits_the_outer_receipt(): void
    {
        [$f, $p] = $this->candidate(); $operation = 'reviewed-paid-invoice-charge-alignment';
        $f['user']->update(['type' => 'S']); $actor = 'user:'.$f['user']->id; $key = (string) Str::uuid();
        $dir = sys_get_temp_dir().'/paid-pop-'.Str::uuid(); mkdir($dir, 0700);
        $authorization = $dir.'/case.json'; $sha = str_repeat('a', 40);
        file_put_contents($dir.'/deployment.json', json_encode(['backend_sha' => $sha]));
        $pop = new PopOperationService(app(PopOperationCatalog::class), null, $dir.'/deployment.json', $authorization);
        try {
            foreach ([['api_client:synthetic', 'pop_machine', 1, 'human'], [$actor, 'super_admin', $f['user']->id, 'exact active Founder authorization']] as [$requester, $role, $id, $error]) {
                try { $pop->createDraft($operation, $p, $key, $requester, $role, [1], $id); $this->fail('unauthorized draft accepted'); }
                catch (\RuntimeException $e) { $this->assertStringContainsString($error, $e->getMessage()); }
            }
            $hash = hash('sha256', json_encode(PopOperationService::canonicalParameters($p), JSON_THROW_ON_ERROR));
            $reference = 'founder-go-'.$operation.'-'.substr($hash, 0, 16);
            $tuple = ['version' => 1, 'eligible_cases' => [['operation_id' => $operation, 'parameters_sha256' => $hash,
                'idempotency_sha256' => hash('sha256', $key), 'requester_sha256' => hash('sha256', $actor),
                'approver_sha256' => hash('sha256', $actor), 'approval_reference' => $reference, 'valid_until' => now()->addHour()->utc()->format('Y-m-d\\TH:i:s\\Z')]]];
            file_put_contents($authorization, json_encode($tuple));
            $r = $pop->createDraft($operation, $p, $key, $actor, 'super_admin', [1], $f['user']->id);
            $this->assertTrue($pop->runDryRun($r['id'], $actor, $f['user']->id, 'super_admin', [1])['ok']);
            $approval = $pop->approve($r['id'], $reference, $actor, 'super_admin', $sha, $f['user']->id);
            $this->assertTrue($approval['ready']); $before = $this->graph($f);
            $fault = (object) ['armed' => true];
            DB::listen(function ($query) use ($fault): void {
                if ($fault->armed && str_starts_with($query->sql, 'insert ignore into `pop_execution_records`') && in_array('execute', $query->bindings, true)) {
                    $fault->armed = false; throw new \RuntimeException('synthetic_outer_receipt_fault');
                }
            });
            $this->assertFalse($pop->run($r['id'], 'execute', $approval['token'], $sha, 'pop-runner')['ok']);
            $this->assertFalse($fault->armed); $this->assertSame($before, $this->graph($f));
            $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $r['id'])->where('phase', 'repair-proof')->count());
            $this->assertSame('idle', $pop->runApprovedLocally()['status'], 'a failed repair must not retry automatically');
            $this->assertTrue($pop->run($r['id'], 'execute', $approval['token'], $sha, 'pop-runner')['ok']);
            $this->assertTrue($pop->run($r['id'], 'verify', $approval['token'], $sha, 'pop-runner')['ok']);
            $this->assertTrue($pop->run($r['id'], 'rollback', $approval['token'], $sha, 'pop-runner')['ok']);
            $this->assertSame($before, $this->graph($f));
        } finally { @unlink($authorization); unlink($dir.'/deployment.json'); rmdir($dir); }
    }

    public function test_authenticated_preview_is_read_only_scoped_and_cannot_authorize_a_draft(): void
    {
        [$f, $p] = $this->candidate(); $before = $this->graph($f);
        $counts = [DB::table('pop_operation_requests')->count(), DB::table('pop_execution_records')->count(), DB::table('pop_approval_events')->count()];
        $headers = ['Authorization' => 'Bearer '.$f['token']];
        $url = '/api/v1/pop/operations/reviewed-paid-invoice-charge-alignment/preview';
        foreach ([null, ''] as $initialHash) {
            $bootstrap = $p; $bootstrap['expected_state_hash'] = $initialHash;
            $response = $this->withHeaders($headers)->postJson($url, ['parameters' => $bootstrap])->assertOk();
            $this->assertEqualsCanonicalizing(['state_hash', 'old_total', 'new_total'], array_keys($response->json('data')));
            $this->assertSame($p['expected_state_hash'], $response->json('data.state_hash'));
            $this->assertSame([8800, 8000], [$response->json('data.old_total'), $response->json('data.new_total')]);
        }
        $this->assertSame($before, $this->graph($f));
        $this->assertSame($counts, [DB::table('pop_operation_requests')->count(), DB::table('pop_execution_records')->count(), DB::table('pop_approval_events')->count()]);
        $this->withHeaders($headers)->postJson('/api/v1/pop/operations/reviewed-paid-invoice-charge-alignment/draft', ['parameters' => $p, 'idempotency_key' => (string) Str::uuid()])->assertStatus(422);
        $this->withHeaders(['Authorization' => ''])->postJson($url, ['parameters' => $p])->assertUnauthorized();
        $f['user']->update(['type' => 'T']);
        $this->withHeaders($headers)->postJson($url, ['parameters' => $p])->assertForbidden();
        $f['user']->update(['type' => 'A']);
        $foreign = $p; $foreign['campus_id'] = 2;
        $this->withHeaders($headers)->postJson($url, ['parameters' => $foreign])->assertForbidden();
        $f['student']->update(['CampusID' => 2]);
        $this->withHeaders($headers)->postJson($url, ['parameters' => $p])->assertStatus(422);
        $f['student']->update(['CampusID' => 1]);
        DB::table('UserCampus')->where('UserID', $f['user']->id)->delete();
        $this->withHeaders($headers)->postJson($url, ['parameters' => $p])->assertForbidden();
        $this->assertSame($counts, [DB::table('pop_operation_requests')->count(), DB::table('pop_execution_records')->count(), DB::table('pop_approval_events')->count()]);
    }

    public function test_a_valid_machine_key_cannot_use_human_preview_or_financial_draft(): void
    {
        [$f, $p] = $this->candidate(); $before = $this->graph($f);
        $key = 'synthetic-paid-preview-key';
        \App\Models\ApiClient::create(['Name' => 'synthetic-paid-preview', 'ApiKeyHash' => hash('sha256', $key),
            'CampusID' => 1, 'Active' => 1, 'Purpose' => 'pop_control_plane', 'Scopes' => ['pop:draft', 'pop:dry-run']]);
        $headers = ['X-POP-MACHINE-KEY' => $key];
        $response = $this->withHeaders($headers)->postJson('/api/v1/pop/operations/reviewed-paid-invoice-charge-alignment/preview', ['parameters' => $p]);
        $this->assertContains($response->status(), [401, 403]); $this->assertNull($response->json('data'));
        $this->withHeaders($headers)->postJson('/api/v1/pop/machine/operations/reviewed-paid-invoice-charge-alignment/draft', ['parameters' => $p, 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('reason_code', 'Reviewed financial repair requires an authenticated human requester.');
        $this->assertSame($before, $this->graph($f));
        $this->assertSame(0, DB::table('pop_approval_events')->count());
    }
}
