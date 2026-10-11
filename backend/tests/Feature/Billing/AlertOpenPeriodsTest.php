<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\ContractMoneyVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADMIN-OPS billing: the queue row lists every bill that still owes, so the student list and the student panel add up the same money. */
class AlertOpenPeriodsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMoneyFixtures;

    private function row(int $courseId): ?array
    {
        $user = User::create(['LoginName' => 'op@example.com', 'Name' => 'd', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $rows = $this->withHeaders(['Authorization' => "Bearer $token", 'Accept' => 'application/json'])->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json();

        return collect($rows)->firstWhere('id', $courseId);
    }

    public function test_two_open_bills_are_both_listed_and_add_up_to_the_verdict(): void
    {
        $course = $this->course([['2026-07', 3000, 'unpaid', 0, []], ['2026-08', 5000, 'partial', 1000, [[1000, 'cash']]]]);
        $row = $this->row($course->ID);

        $this->assertSame([['billing_period' => '2026-07', 'outstanding' => 3000, 'due_date' => '2026-07-15'],
            ['billing_period' => '2026-08', 'outstanding' => 4000, 'due_date' => '2026-08-15']], $row['open_periods']);
        $this->assertSame(app(ContractMoneyVerdict::class)->forCourse($course->fresh())->outstanding, array_sum(array_column($row['open_periods'], 'outstanding')));
    }

    public function test_a_settled_bill_is_not_listed_and_a_paid_contract_has_none(): void
    {
        $course = $this->course([['2026-07', 3000, 'paid', 3000, [[3000, 'cash']]], ['2026-08', 5000, 'unpaid', 0, []]]);
        $this->assertSame([['billing_period' => '2026-08', 'outstanding' => 5000, 'due_date' => '2026-08-15']], $this->row($course->ID)['open_periods']);
    }
}
