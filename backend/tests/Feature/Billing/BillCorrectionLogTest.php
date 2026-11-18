<?php

namespace Tests\Feature\Billing;

use App\Models\{AuthToken, Campus, User, UserCampus};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Step 2 PR 3: GET accounting/bill-corrections, the HQ 更正紀錄 (super_admin only, read only, newest first). */
class BillCorrectionLogTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $type, ?int $campus = null): string
    {
        $user = User::create(['LoginName' => 'bcl-' . strtolower($type) . '-' . uniqid() . '@test.com', 'Name' => '紀錄測試帳號', 'PSW' => 'secret', 'type' => $type, 'phone' => '0912345678']);
        if ($campus) {
            UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function fetchLog(string $token, string $query = '')
    {
        return $this->getJson('/api/v1/accounting/bill-corrections' . $query, ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function row(int $n, int $campus, string $at, string $kind = 'bill_correction', ?int $operator = null): void
    {
        DB::table('invoice_corrections')->insert(['kind' => $kind, 'campus_id' => $campus, 'student_class_id' => 1000 + $n, 'original_invoice_id' => 100 + $n, 'replacement_invoice_id' => 200 + $n,
            'amount' => 0, 'old_charge' => 10000, 'new_charge' => 8000, 'original_invoice_total' => 10000, 'reason' => "原因{$n}", 'idempotency_key' => sprintf('%036d', $n),
            'operator_user_id' => $operator, 'request_hash' => str_repeat('a', 64), 'state_hash' => str_repeat('b', 64), 'created_at' => $at]);
    }

    public function test_only_super_admin_reads_the_log(): void
    {
        $campus = (int) Campus::factory()->create()->id;
        $this->fetchLog($this->token('A', $campus))->assertStatus(403);
        $this->fetchLog($this->token('T', $campus))->assertStatus(403);
        $this->fetchLog($this->token('S'))->assertOk()->assertJsonPath('total', 0);
    }

    public function test_lists_bill_corrections_newest_first_with_campus_and_operator_names(): void
    {
        $campus = Campus::factory()->create();
        $op = User::create(['LoginName' => 'op-' . uniqid() . '@test.com', 'Name' => '操作人', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678']);
        $this->row(1, (int) $campus->id, '2026-11-10 09:00:00', 'bill_correction', (int) $op->id);
        $this->row(2, (int) $campus->id, '2026-11-12 09:00:00');
        $this->row(3, (int) $campus->id, '2026-11-13 09:00:00', 'agreed_fee_correction'); // another kind: not listed

        $res = $this->fetchLog($this->token('S'))->assertOk()->assertJsonPath('total', 2);
        $this->assertSame([202, 201], array_column($res->json('data'), 'replacement_invoice_id'));
        $first = $res->json('data.1');
        $this->assertSame([101, 10000, 8000, '原因1', '操作人', $campus->name], [$first['original_invoice_id'], $first['old_total'], $first['new_total'], $first['reason'], $first['operator_name'], $first['campus_name']]);
        $this->assertNull($res->json('data.0.operator_name'));
    }

    public function test_filters_by_campus_and_month_and_paginates(): void
    {
        [$a, $b] = [(int) Campus::factory()->create()->id, (int) Campus::factory()->create()->id];
        $this->row(1, $a, '2026-10-31 23:00:00');
        $this->row(2, $a, '2026-11-02 09:00:00');
        $this->row(3, $b, '2026-11-03 09:00:00');
        $this->row(4, $a, '2026-12-01 00:00:00');
        $tok = $this->token('S');

        $this->assertSame([203, 202], array_column($this->fetchLog($tok, '?month=2026-11')->assertOk()->json('data'), 'replacement_invoice_id'));
        $this->assertSame([202], array_column($this->fetchLog($tok, "?month=2026-11&campus_id={$a}")->json('data'), 'replacement_invoice_id'));
        $page = $this->fetchLog($tok, '?per_page=2&page=2')->assertOk();
        $page->assertJsonPath('total', 4)->assertJsonPath('last_page', 2);
        $this->assertSame([202, 201], array_column($page->json('data'), 'replacement_invoice_id'));
        $this->fetchLog($tok, '?month=2026-13')->assertStatus(422);
    }
}
