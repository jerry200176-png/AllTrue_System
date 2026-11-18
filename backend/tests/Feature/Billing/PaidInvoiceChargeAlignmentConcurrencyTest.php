<?php

namespace Tests\Feature\Billing;

use App\Operations\Strategies\PaidInvoiceChargeAlignmentStrategy as Alignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\{CleansCommittedRows, PaidInvoiceAlignmentFixture};
use Tests\TestCase;

/** GH5413: committed synthetic fixtures and separate InnoDB sessions, using the existing alignment race harness. */
class PaidInvoiceChargeAlignmentConcurrencyTest extends TestCase
{
    use CleansCommittedRows, PaidInvoiceAlignmentFixture, RefreshDatabase;
    protected $connectionsToTransact = [];

    private function contender(): string
    {
        $primary = DB::getDefaultConnection();
        config(['database.connections.paid_alignment_b' => config('database.connections.'.$primary)]);
        DB::purge('paid_alignment_b'); $b = DB::connection('paid_alignment_b');
        $this->assertNotSame(DB::connection()->getPdo(), $b->getPdo());
        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->beforeApplicationDestroyed(static fn () => DB::disconnect('paid_alignment_b'));
        return $primary;
    }

    private function cleanup(array $ids): void
    {
        DB::table('pop_execution_records')->whereIn('operation_id', $ids)->delete();
        DB::table('pop_operation_requests')->whereIn('id', $ids)->delete();
    }

    public function test_same_or_distinct_requests_serialize_and_cannot_change_cash_twice(): void
    {
        $primary = $this->contender(); $svc = app(Alignment::class);
        foreach ([true, false] as $same) {
            [$f, $p, $ctx] = $this->candidate(); $ids = [$ctx['operation_id']];
            $other = $ctx;
            if (!$same) {
                $row = (array) DB::table('pop_operation_requests')->where('id', $ctx['operation_id'])->first();
                $row['id'] = (string) Str::uuid(); $row['idempotency_key'] = (string) Str::uuid();
                DB::table('pop_operation_requests')->insert($row); $ids[] = $row['id']; $other['operation_id'] = $row['id'];
            }
            $plan = $svc->plan($p); $money = DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson();
            $barrier = (object) ['armed' => true, 'blocked' => false];
            DB::listen(function ($query) use ($barrier, $primary, $svc, $plan, $other): void {
                if (!$barrier->armed || !str_starts_with($query->sql, 'update `Invoice`')) return;
                $barrier->armed = false; DB::setDefaultConnection('paid_alignment_b');
                try { $svc->execute($plan, $other); $this->fail('competing repair did not wait'); }
                catch (QueryException $e) { $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0)); $barrier->blocked = true; }
                finally { DB::setDefaultConnection($primary); }
            });
            try {
                $result = $svc->execute($plan, $ctx); $this->assertTrue($barrier->blocked);
                if ($same) $this->assertTrue($svc->execute($plan, $other)['already_applied']);
                else {
                    try { $svc->execute($plan, $other); $this->fail('stale distinct repair was applied'); }
                    catch (\RuntimeException $e) { $this->assertStringContainsString('unsupported_paid_state', $e->getMessage()); }
                }
                $this->assertSame(1, DB::table('pop_execution_records')->whereIn('operation_id', $ids)->count());
                $this->assertSame($money, DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson());
                $this->assertTrue($svc->verify($plan, $result)['ok']); $this->assertTrue($svc->rollback($result['snapshot'], $ctx)['ok']);
            } finally { $barrier->armed = false; DB::setDefaultConnection($primary); $this->cleanup($ids); }
        }
    }

    public function test_actual_receipt_void_waits_for_repair_then_prevents_rollback(): void
    {
        $primary = $this->contender(); [$f, $p, $ctx, , $valid] = $this->candidate(); $svc = app(Alignment::class);
        $plan = $svc->plan($p); $barrier = (object) ['armed' => true, 'blocked' => false];
        $headers = ['Authorization' => 'Bearer '.$f['token']]; $url = '/api/v1/payment-reports/'.$valid->id.'/void';
        $this->withoutExceptionHandling();
        DB::listen(function ($query) use ($barrier, $primary, $headers, $url): void {
            if (!$barrier->armed || !str_starts_with($query->sql, 'update `Invoice`')) return;
            $barrier->armed = false; DB::setDefaultConnection('paid_alignment_b');
            try { $this->withHeaders($headers)->putJson($url, ['void_reason' => 'Synthetic competing correction']); $this->fail('receipt void did not wait'); }
            catch (QueryException $e) { $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0)); $barrier->blocked = true; }
            finally { DB::setDefaultConnection($primary); }
        });
        try {
            $result = $svc->execute($plan, $ctx); $this->assertTrue($barrier->blocked);
            DB::setDefaultConnection('paid_alignment_b');
            try { $this->withHeaders($headers)->putJson($url, ['void_reason' => 'Synthetic subsequent correction'])->assertOk(); }
            finally { DB::setDefaultConnection($primary); }
            $this->assertSame(0, (int) DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->sum('Amount'));
            $this->assertSame('voided', $valid->fresh()->status);
            try { $svc->rollback($result['snapshot'], $ctx); $this->fail('later receipt void was overwritten'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('values_moved_on', $e->getMessage()); }
            $this->assertFalse($svc->verify($plan, $result)['ok']);
        } finally { $barrier->armed = false; DB::setDefaultConnection($primary); $this->cleanup([$ctx['operation_id']]); }
    }

    public function test_receipt_void_that_wins_first_invalidates_the_old_repair(): void
    {
        [$f, $p, $ctx, , $valid] = $this->candidate(); $svc = app(Alignment::class); $plan = $svc->plan($p);
        try {
            $this->withHeaders(['Authorization' => 'Bearer '.$f['token']])->putJson('/api/v1/payment-reports/'.$valid->id.'/void', ['void_reason' => 'Synthetic earlier correction'])->assertOk();
            $money = DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson();
            try { $svc->execute($plan, $ctx); $this->fail('repair overwrote the winning void'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('unsupported_paid_state', $e->getMessage()); }
            $this->assertSame($money, DB::table('Payment')->where('InvoiceID', $f['invoice']->id)->orderBy('id')->get()->toJson());
            $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $ctx['operation_id'])->count());
        } finally { $this->cleanup([$ctx['operation_id']]); }
    }
}
