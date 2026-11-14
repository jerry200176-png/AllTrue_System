<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentReportReassignment;
use App\Services\Billing\ReceiptReassignService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CleansCommittedRows;
use Tests\Support\ReceiptReassignFixtures;
use Tests\TestCase;

/**
 * #5113 (in-app #522), design PR 3b: two applies of one receipt move on two real connections, committed rows.
 * Barrier: right before the request's first FOR UPDATE, connection A commits its own apply (or another change); the
 * request must then decide from the committed state under its locks (REPEATABLE READ would otherwise hide it).
 */
class ReceiptReassignApplyRaceTest extends TestCase
{
    use RefreshDatabase;
    use CleansCommittedRows;
    use ReceiptReassignFixtures;

    private const AMOUNT = 8000;

    protected function connectionsToTransact()
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00:00', 'Asia/Taipei'));
        config(['feature_flags.values' => ['FEATURE_RECEIPT_REASSIGN' => true]]);
        config(['billing.receipt_reassign_director_max_amount' => 0, 'billing.receipt_reassign_director_max_age_days' => 0]);
    }

    /** UserCampus has no auto-increment key, so CleansCommittedRows cannot see its rows: delete the ones of the users this test made. */
    protected function tearDown(): void
    {
        try {
            $userIds = DB::table('User')->where('LoginName', 'like', 'rra-%@test.com')->pluck('id')->all();
            DB::table('UserCampus')->whereIn('UserID', $userIds)->delete();
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_concurrent_double_apply_moves_the_money_once_and_the_loser_replays_the_winner(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $hash = (string) $this->preview($tok, $f)->assertOk()->json('state_hash');
        $payments = DB::table('Payment')->count();
        $twin = null;

        $res = $this->race(function () use ($f, $hash, &$twin) {
            $twin = app(ReceiptReassignService::class)->apply((int) $f['report']->id, (int) $f['rightInvoice']->id, null, true, '選錯學生', $hash, 1);
        }, $tok, $f, $hash);

        $this->assertSame(201, $twin['status'] ?? null, json_encode($twin));
        $this->assertSame(200, $res->status(), $res->getContent());
        $this->assertTrue($res->json('replayed'));
        $this->assertSame([$twin['body']['reassignment_id'], $twin['body']['new_report_id']], [$res->json('reassignment_id'), $res->json('new_report_id')]);
        $this->assertSame(1, PaymentReportReassignment::query()->where('from_report_id', $f['report']->id)->count());
        $this->assertSame($payments + 2, DB::table('Payment')->count()); // one reversal, one reapply: never twice
        $this->assertSame(1, Payment::query()->where('payment_report_id', $f['report']->id)->where('Method', 'void')->count());
        $this->assertSame(['unpaid', 'paid'], [$f['wrongInvoice']->fresh()->Status, $f['rightInvoice']->fresh()->Status]);
    }

    public function test_change_committed_while_waiting_for_the_locks_makes_the_hash_stale(): void
    {
        $f = $this->fixture();
        $f['rightInvoice']->update(['TotalAmount' => self::AMOUNT + 500]);
        $tok = $this->token('S', 1);
        $hash = (string) $this->preview($tok, $f)->assertOk()->json('state_hash');
        $payments = DB::table('Payment')->count();

        $res = $this->race(fn () => DB::connection('mysql_a')->table('Payment')->insert(
            ['InvoiceID' => $f['rightInvoice']->id, 'Amount' => 100, 'PaidAt' => '2026-11-12', 'Method' => 'cash']), $tok, $f, $hash);

        $this->assertSame(409, $res->status(), $res->getContent());
        $this->assertSame('reassign_state_changed', $res->json('code'));
        $this->assertSame($payments + 1, DB::table('Payment')->count()); // only the concurrent payment
        $this->assertSame(0, PaymentReportReassignment::query()->count());
        $this->assertSame('confirmed', $f['report']->fresh()->status);
    }

    public function test_a_deadlock_victim_is_run_again_and_moves_the_money_once(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $hash = (string) $this->preview($tok, $f)->assertOk()->json('state_hash');
        $payments = DB::table('Payment')->count();
        $deadlocks = 0;
        // InnoDB rolls the victim's whole transaction back; the first attempt dies on its first write.
        DB::connection()->beforeExecuting(function (string $sql) use (&$deadlocks) {
            if ($deadlocks === 0 && str_starts_with(strtolower($sql), 'insert into `payment_reports`')) {
                $deadlocks++;
                $pdo = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
                $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
                throw new QueryException('mysql', $sql, [], $pdo);
            }
        });

        $res = $this->postJson("/api/v1/payment-reports/{$f['report']->id}/reassign",
            ['target_invoice_id' => $f['rightInvoice']->id, 'state_hash' => $hash, 'reason' => '選錯學生'], $this->h($tok));

        $this->assertSame(1, $deadlocks);
        $this->assertSame(201, $res->status(), $res->getContent()); // without DeadlockRetry the director gets a 500
        $this->assertSame(1, PaymentReportReassignment::query()->where('from_report_id', $f['report']->id)->count());
        $this->assertSame($payments + 2, DB::table('Payment')->count());
    }

    /** Runs $concurrent on connection A (as the default connection, so services use it) right before the request's first course lock. */
    private function race(callable $concurrent, string $tok, array $f, string $hash)
    {
        config(['database.connections.mysql_a' => config('database.connections.' . config('database.default'))]);
        $main = config('database.default');
        $fired = false;
        DB::connection()->beforeExecuting(function ($q) use (&$fired, $concurrent, $main) {
            if (!$fired && stripos($q, 'for update') !== false && stripos($q, '`StudentClass`') !== false) {
                $fired = true;
                DB::setDefaultConnection('mysql_a');
                try {
                    $concurrent();
                } finally {
                    DB::setDefaultConnection($main);
                }
            }
        });
        $res = $this->postJson("/api/v1/payment-reports/{$f['report']->id}/reassign",
            ['target_invoice_id' => $f['rightInvoice']->id, 'state_hash' => $hash, 'reason' => '選錯學生'], $this->h($tok));
        $this->assertTrue($fired, 'course lock query never ran');

        return $res;
    }
}
