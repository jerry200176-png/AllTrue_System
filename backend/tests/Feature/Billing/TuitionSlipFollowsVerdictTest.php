<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** In-app #548: expose the planning boundary; never trim or mutate excess bookings on a read. */
    public function test_count_slip_exposes_quota_without_changing_sessions_or_money(): void
    {
        $course = $this->course([], ['SessionCount' => 8, 'RemainingSessions' => 4, 'UsedSessions' => 4]);
        foreach (range(1, 12) as $i) {
            ClassSession::create([
                'StudentClassID' => $course->ID, 'SessionDate' => sprintf('2026-08-%02d', $i),
                'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => $i <= 4 ? 'attended' : 'scheduled',
            ]);
        }
        $before = DB::table('ClassSession')->where('StudentClassID', $course->ID)->orderBy('id')->get()->toJson();
        $moneyBefore = $course->fresh()->getAttributes();

        $this->slip($course->ID)->assertOk()
            ->assertJsonPath('sessions_purchased', 8)
            ->assertJsonPath('deduction_basis', 'fixed_session')
            ->assertJsonPath('scheduling_policy', 'auto_recurrence')
            ->assertJsonPath('package_id', null)
            ->assertJsonCount(12, 'sessions')
            ->assertJsonPath('estimated_amount', 10000);

        $this->assertSame($before, DB::table('ClassSession')->where('StudentClassID', $course->ID)->orderBy('id')->get()->toJson());
        $this->assertSame($moneyBefore, $course->fresh()->getAttributes());
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
        $waived = $this->course([], ['Stop' => 1, 'closed_reason' => 'waived', 'Paid' => 0]);
        $missing = $this->slip(99999999, 2);
        $missing->assertStatus(403);

        foreach ([$settled, $owing, $waived] as $course) {
            $res = $this->slip($course->ID, 2);
            $res->assertStatus(403);
            $this->assertSame($missing->json('message'), $res->json('message'), 'no money, status or name in the message');
        }
    }

    /** In-app #551: a terminal waiver is not an unpaid obligation, even if the old Charge remains. */
    public function test_waived_course_refuses_a_notice_without_touching_history_or_another_courses_receipt(): void
    {
        $course = $this->course([], ['Paid' => 0,
            'Charge' => 4800, 'Rate' => 0, 'SessionCount' => 1, 'UsedSessions' => 1, 'RemainingSessions' => 0]);
        $this->course([['2026-08', 6400, 'paid', 6400, [[6400, 'cash']]]], [], [], $course->student);
        foreach (['attended', 'scheduled'] as $i => $status) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-08-0' . ($i + 1),
                'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => $status]);
        }
        $course->update(['Stop' => 1, 'closed_reason' => 'waived']);
        $beforeCourse = $course->fresh()->getAttributes();
        $beforeRows = [];
        foreach (['ClassSession', 'Invoice', 'Payment'] as $table) {
            $beforeRows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        $this->slip($course->ID)->assertStatus(422)
            ->assertJson(['message' => '此課程已確認不收，不需要繳費單']);

        $this->assertSame($beforeCourse, $course->fresh()->getAttributes());
        foreach ($beforeRows as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_zero_unit_rate_does_not_reinterpret_an_active_positive_contract_as_free(): void
    {
        $course = $this->course([], ['Charge' => 4800, 'Rate' => 0]);
        $this->slip($course->ID)->assertOk()->assertJsonPath('charge', 4800);
        $this->assertSame(4800, (int) $course->fresh()->Charge);
    }
}
