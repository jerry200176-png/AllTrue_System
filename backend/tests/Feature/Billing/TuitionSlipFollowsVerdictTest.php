<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADMIN-OPS billing: the 繳費單 (alerts/tuition-slip) is offered while ContractMoneyVerdict says the contract owes, refused once it is settled or free. */
class TuitionSlipFollowsVerdictTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMoneyFixtures;

    private function slip(int $courseId, int $campusId = 1)
    {
        $user = User::create(['LoginName' => uniqid("slip") . "@example.com", 'Name' => 'd', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $this->withHeaders(['Authorization' => "Bearer $token", 'Accept' => 'application/json'])->getJson("/api/v1/alerts/tuition-slip/$courseId");
    }

    public function test_paid_flag_beside_an_unpaid_invoice_still_gets_a_slip_for_what_is_owed(): void
    {
        $course = $this->course([['2026-08', 10000, 'unpaid', 0, []]], ['Paid' => 1]);

        $res = $this->slip($course->ID)->assertOk();
        $this->assertSame(10000, (int) $res->json('payable_amount'));
        $this->assertSame('invoiced', $res->json('payable_status'));
    }

    public function test_payment_rows_that_settle_an_unflagged_contract_refuse_the_slip(): void
    {
        $course = $this->course([['2026-08', 10000, 'paid', 10000, [[10000, 'cash']]]]);

        $this->slip($course->ID)->assertStatus(422)->assertJson(['message' => '此課程已繳費，不需產生繳費單']);
    }

    public function test_a_partly_paid_contract_still_gets_its_slip(): void
    {
        $course = $this->course([['2026-08', 10000, 'partial', 4000, [[4000, 'cash']]]]);

        $this->assertSame('invoiced', $this->slip($course->ID)->assertOk()->json('payable_status'));
    }

    public function test_zero_dollar_trial_says_it_is_free(): void
    {
        $course = $this->course([], ['Charge' => 0, 'Rate' => 0]);

        $this->slip($course->ID)->assertStatus(422)->assertJson(['message' => '這門課是免費課程，不需要繳費單']);
    }

    public function test_legacy_paid_flag_without_any_invoice_stays_refused(): void
    {
        $course = $this->course([], ['Paid' => 1]);

        $this->slip($course->ID)->assertStatus(422)->assertJson(['message' => '此課程已繳費，不需產生繳費單']);
    }

    public function test_other_campus_director_gets_the_same_bare_403_as_for_a_missing_id(): void
    {
        $settled = $this->course([['2026-08', 10000, 'paid', 10000, [[10000, 'cash']]]]);
        $owing = $this->course([['2026-08', 10000, 'unpaid', 0, []]]);
        $missing = $this->slip(99999999, 2);
        $missing->assertStatus(403);

        foreach ([$settled, $owing] as $course) {
            $res = $this->slip($course->ID, 2);
            $res->assertStatus(403);
            $this->assertSame($missing->json('message'), $res->json('message'), 'no money, status or name in the message');
        }
    }
}
