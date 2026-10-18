<?php

namespace Tests\Feature\Billing;

use App\Operations\Strategies\UnpaidInvoiceChargeAlignmentStrategy as Alignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AgreedFeeFixture;
use Tests\TestCase;

class UnpaidInvoiceChargeAlignmentConcurrencyTest extends TestCase
{
    use AgreedFeeFixture, RefreshDatabase;

    protected $connectionsToTransact = [];

    public function test_same_request_and_distinct_request_cannot_write_while_first_writer_holds_graph(): void
    {
        $primary = DB::getDefaultConnection();
        config(['database.connections.alignment_race_b' => config('database.connections.'.$primary)]);
        DB::purge('alignment_race_b');
        $b = DB::connection('alignment_race_b');
        $this->beforeApplicationDestroyed(static fn () => DB::disconnect('alignment_race_b'));
        $this->assertNotSame(DB::connection()->getPdo(), $b->getPdo());
        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $iso = $b->selectOne('select @@transaction_isolation as i')->i;
        } catch (QueryException) {
            $iso = $b->selectOne('select @@tx_isolation as i')->i;
        }
        $this->assertSame('REPEATABLE-READ', $iso);
        $svc = app(Alignment::class);
        foreach ([true, false] as $sameRequest) {
            $f = $this->fixture(); $requests = []; $barrier = (object) ['enabled' => false, 'attempted' => false, 'blocked' => false];
            try {
                $f['course']->update(['Charge' => 500]); $f['invoice']->update(['TotalAmount' => 8000]);
                DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->delete();
                $p = ['student_id' => (int) $f['student']->id, 'course_id' => (int) $f['course']->ID, 'campus_id' => 1,
                    'invoice_id' => (int) $f['invoice']->id, 'expected_invoice_total' => 8000, 'expected_charge' => 500,
                    'decision_reference' => 'SYNTHETIC-ALIGNMENT-RACE'];
                $p['expected_state_hash'] = $svc->preview($p)['state_hash'];
                foreach (range(1, $sameRequest ? 1 : 2) as $_) {
                    $id = (string) Str::uuid(); $requests[] = $id;
                    DB::table('pop_operation_requests')->insert(['id' => $id, 'operation_id' => 'synthetic-unregistered-alignment',
                        'strategy_id' => Alignment::STRATEGY, 'catalog_version' => 1, 'parameters' => json_encode($p),
                        'parameters_hash' => str_repeat('a', 64), 'idempotency_key' => $id, 'status' => 'approved',
                        'actor' => 'synthetic-pop', 'created_at' => now(), 'updated_at' => now()]);
                }
                $plan = $svc->plan($p); $aCtx = ['actor' => 'synthetic-pop', 'operation_id' => $requests[0]];
                $bCtx = ['actor' => 'synthetic-pop', 'operation_id' => $requests[count($requests) - 1]];
                $barrier->enabled = true;
                DB::listen(function ($q) use ($barrier, $primary, $svc, $plan, $bCtx): void {
                    if (!$barrier->enabled || !str_starts_with($q->sql, 'update `Invoice`')) return;
                    $barrier->enabled = false; $barrier->attempted = true;
                    DB::setDefaultConnection('alignment_race_b');
                    try {
                        $svc->execute($plan, $bCtx);
                        $this->fail('Competing writer must wait for the first transaction');
                    } catch (QueryException $e) {
                        $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0)); $barrier->blocked = true;
                    } finally { DB::setDefaultConnection($primary); }
                });
                $result = $svc->execute($plan, $aCtx);
                $this->assertTrue($barrier->attempted); $this->assertTrue($barrier->blocked);
                $this->assertTrue($svc->verify($plan, $result)['ok']);
                $this->assertSame(500, (int) $f['invoice']->fresh()->TotalAmount);
                if ($sameRequest) {
                    $this->assertTrue($svc->execute($plan, $bCtx)['already_applied']);
                } else {
                    try { $svc->execute($plan, $bCtx); $this->fail('Stale distinct request must refuse'); }
                    catch (\RuntimeException $e) { $this->assertStringContainsString('unsupported_state', $e->getMessage()); }
                }
                $this->assertSame(1, DB::table('pop_execution_records')->whereIn('operation_id', $requests)->count());
                $this->assertSame(0, DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->count());
                $this->assertSame(1, $svc->rollback($result['snapshot'], $aCtx)['restored']);
            } finally {
                $barrier->enabled = false; DB::setDefaultConnection($primary);
                DB::table('pop_execution_records')->whereIn('operation_id', $requests)->delete();
                DB::table('pop_operation_requests')->whereIn('id', $requests)->delete();
                DB::table('security_audit_events')->where('subject_ref', \App\Models\SecurityAuditEvent::ref('invoice', $f['invoice']->id))
                    ->where('event_type', 'like', 'billing.invoice_charge_alignment%')->delete();
                DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->delete(); $f['invoice']->delete();
                $f['course']->delete(); $f['student']->delete();
                DB::table('auth_tokens')->where('user_id', $f['user']->id)->delete();
                DB::table('UserCampus')->where('UserID', $f['user']->id)->delete(); $f['user']->delete();
            }
        }
        DB::disconnect('alignment_race_b');
    }
}
