<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\FeeCharge;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** In-app #439: pass-through fees (代收) and payouts (代付) live in their own ledger, campus-scoped, never tuition. */
class FeeLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    private int $campus;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00'));
        $this->campus = (int) Campus::factory()->create()->id;
        $this->headers = $this->director($this->campus);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_class_is_charged_paid_and_summarised_without_touching_tuition(): void
    {
        [$a, $b] = [$this->student($this->campus), $this->student($this->campus)];
        $tuition = fn () => [DB::table('Invoice')->count(), DB::table('Payment')->count(), DB::table('StudentClass')->count()];
        $before = $tuition();

        $created = $this->postJson('/api/v1/fee-charges', ['campus_id' => $this->campus, 'student_ids' => [$a, $b],
            'category' => 'books', 'title' => '國二數學講義', 'amount' => 350], $this->headers)->assertCreated()->json('charges');
        $this->assertCount(2, $created);
        $this->postJson("/api/v1/fee-charges/{$created[0]['id']}/pay", ['paid_on' => '2026-10-28', 'method' => 'cash'], $this->headers)
            ->assertOk()->assertJsonPath('charge.status', 'paid');
        $this->postJson('/api/v1/fee-payouts', ['campus_id' => $this->campus, 'category' => 'books', 'title' => '書商',
            'amount' => 600, 'paid_on' => '2026-10-27'], $this->headers)->assertCreated();

        $ledger = $this->getJson("/api/v1/fee-ledger?campus_id={$this->campus}&month=2026-10", $this->headers)->assertOk();
        $ledger->assertJsonPath('summary.rows.0', ['category' => 'books', 'label' => '書籍費', 'charged' => 700,
            'collected' => 350, 'outstanding' => 350, 'paid_out' => 600, 'balance' => -250]);
        $this->assertCount(2, $ledger->json('charges'));
        $this->assertSame($before, $tuition(), 'pass-through fees never create tuition invoices, payments or courses');
    }

    public function test_a_charge_only_moves_forward(): void
    {
        $id = $this->charge();
        $pay = fn () => $this->postJson("/api/v1/fee-charges/{$id}/pay", ['paid_on' => '2026-10-28', 'method' => 'transfer'], $this->headers);

        $pay()->assertOk();
        $pay()->assertStatus(409)->assertJsonPath('code', 'fee_charge_already_paid');
        $this->postJson("/api/v1/fee-charges/{$id}/void", ['reason' => '收錯學生'], $this->headers)->assertOk()->assertJsonPath('charge.status', 'voided');
        $pay()->assertStatus(409)->assertJsonPath('code', 'fee_charge_voided');
        $this->postJson("/api/v1/fee-charges/{$id}/void", ['reason' => 'again'], $this->headers)->assertStatus(409);

        $row = FeeCharge::find($id);
        $this->assertSame('2026-10-28', $row->paid_on->toDateString(), 'voiding keeps when it was paid');
        $this->getJson("/api/v1/fee-ledger?campus_id={$this->campus}&month=2026-10", $this->headers)
            ->assertJsonPath('summary.total.charged', 0);
    }

    public function test_another_campus_is_refused_everywhere(): void
    {
        $other = (int) Campus::factory()->create()->id;
        $foreignStudent = $this->student($other);
        $foreignCharge = $this->charge($this->director($other), $other);

        $this->postJson('/api/v1/fee-charges', ['campus_id' => $this->campus, 'student_ids' => [$this->student($this->campus), $foreignStudent],
            'category' => 'books', 'title' => 'x', 'amount' => 100], $this->headers)->assertStatus(422)->assertJsonPath('code', 'student_not_in_campus');
        $this->assertSame(1, FeeCharge::count());
        $this->getJson("/api/v1/fee-ledger?campus_id={$other}&month=2026-10", $this->headers)->assertForbidden();
        $this->postJson("/api/v1/fee-charges/{$foreignCharge}/pay", ['paid_on' => '2026-10-28', 'method' => 'cash'], $this->headers)->assertForbidden();
        $this->postJson('/api/v1/fee-payouts', ['campus_id' => $other, 'category' => 'books', 'title' => 'x', 'amount' => 1, 'paid_on' => '2026-10-28'], $this->headers)->assertForbidden();
        $this->assertNull(FeeCharge::find($foreignCharge)->paid_on);
    }

    public function test_unpaid_scope_lists_every_month_and_inputs_are_checked(): void
    {
        $september = $this->charge(null, null, ['charged_on' => '2026-09-15']);
        $this->getJson("/api/v1/fee-ledger?campus_id={$this->campus}&scope=unpaid", $this->headers)
            ->assertOk()->assertJsonPath('charges.0.id', $september);
        $this->getJson("/api/v1/fee-ledger?campus_id={$this->campus}", $this->headers)->assertStatus(422);
        $this->postJson('/api/v1/fee-charges', ['campus_id' => $this->campus, 'student_ids' => [$this->student($this->campus)],
            'category' => 'books', 'title' => 'x', 'amount' => 0], $this->headers)->assertStatus(422);
        $this->postJson("/api/v1/fee-charges/{$september}/pay", ['paid_on' => '2026-10-29', 'method' => 'cash'], $this->headers)->assertStatus(422);
    }

    private function charge(?array $headers = null, ?int $campus = null, array $extra = []): int
    {
        $campus ??= $this->campus;

        return (int) $this->postJson('/api/v1/fee-charges', ['campus_id' => $campus, 'student_ids' => [$this->student($campus)],
            'category' => 'sundry', 'title' => '活動費', 'amount' => 200] + $extra, $headers ?? $this->headers)->assertCreated()->json('charges.0.id');
    }

    private function student(int $campus): int
    {
        return (int) Student::create(['name' => '代收測試學生', 'CampusID' => $campus, 'ClassID' => 1, 'SchoolName' => 'Test',
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => ''])->id;
    }

    private function director(int $campus): array
    {
        $user = User::create(['LoginName' => 'fee-' . uniqid() . '@example.com', 'Name' => '代收測試主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0900000000']);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
