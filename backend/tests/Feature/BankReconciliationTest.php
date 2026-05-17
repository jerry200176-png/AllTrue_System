<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BankTransaction;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_csv_creates_transactions(): void
    {
        [$token, $campus] = $this->seedDirector();

        $r = $this->postJson('/api/v1/bank-reconciliation/import', [
            'campus_id' => $campus->id,
            'transactions' => [
                ['date' => '2026-05-01', 'amount' => 5000, 'reference' => '12345', 'bank_name' => '台新'],
                ['date' => '2026-05-02', 'amount' => 3000, 'reference' => '67890', 'bank_name' => '台新'],
            ],
        ], $this->bearer($token));

        $r->assertOk();
        $this->assertEquals(2, $r->json('imported'));
        $this->assertEquals(0, $r->json('skipped'));
        $this->assertDatabaseCount('bank_transactions', 2);
    }

    public function test_import_csv_deduplicates(): void
    {
        [$token, $campus] = $this->seedDirector();

        BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => '12345', 'status' => 'unmatched',
        ]);

        $r = $this->postJson('/api/v1/bank-reconciliation/import', [
            'campus_id' => $campus->id,
            'transactions' => [
                ['date' => '2026-05-01', 'amount' => 5000, 'reference' => '12345'],
            ],
        ], $this->bearer($token));

        $r->assertOk();
        $this->assertEquals(0, $r->json('imported'));
        $this->assertEquals(1, $r->json('skipped'));
    }

    public function test_index_returns_transactions_with_summary(): void
    {
        [$token, $campus] = $this->seedDirector();

        BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => 'A', 'status' => 'unmatched',
        ]);
        BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-02',
            'amount' => 3000, 'reference' => 'B', 'status' => 'reconciled',
            'reconciled_at' => now(), 'reconciled_by' => 1,
        ]);

        $r = $this->getJson('/api/v1/bank-reconciliation?branch_id=' . $campus->id, $this->bearer($token));
        $r->assertOk();
        $this->assertCount(2, $r->json('transactions'));
        $this->assertEquals(1, $r->json('summary.unmatched'));
        $this->assertEquals(1, $r->json('summary.reconciled'));
    }

    public function test_reconcile_marks_transaction(): void
    {
        [$token, $campus] = $this->seedDirector();

        $txn = BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => 'X', 'status' => 'unmatched',
        ]);

        $r = $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", [
            'payment_report_id' => 999,
        ], $this->bearer($token));

        $r->assertOk();
        $this->assertDatabaseHas('bank_transactions', [
            'id' => $txn->id,
            'status' => 'reconciled',
            'matched_payment_id' => 999,
        ]);
    }

    public function test_cannot_reconcile_twice(): void
    {
        [$token, $campus] = $this->seedDirector();

        $txn = BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => 'Y', 'status' => 'reconciled',
            'reconciled_at' => now(), 'reconciled_by' => 1, 'matched_payment_id' => 1,
        ]);

        $r = $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", [
            'payment_report_id' => 2,
        ], $this->bearer($token));

        $r->assertStatus(422);
    }

    private function seedDirector(): array
    {
        $campus = CampusFactory::new()->create();
        $user = User::create([
            'LoginName' => 'dir_bank_' . uniqid() . '@test.com',
            'Name' => 'Dir', 'PSW' => password_hash('s', PASSWORD_DEFAULT),
            'type' => 'A', 'phone' => '0900000000',
        ]);
        \App\Models\UserCampus::create([
            'UserID' => $user->id, 'CampusID' => $campus->id, 'Approved' => 1,
        ]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return [$tok, $campus];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
