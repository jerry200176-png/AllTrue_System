<?php

namespace Tests\Feature;

use App\Models\{AuthToken, Campus, Invoice, InvoiceItem, Payment, Student, StudentClass, User, UserCampus};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** In-app #535 / #5197: same-unit credit eligibility is a read, never a transfer. */
class SubjectCreditPreviewTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\SubjectCreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preview_preserves_both_contracts_history_cash_and_destination_debt(): void
    {
        [$token, $source, $target] = $this->fixture();
        $before = $this->businessSnapshot();
        $this->preview($token, $source, $target)->assertOk()
            ->assertJsonPath('preview', true)->assertJsonPath('execution_available', false)
            ->assertJsonPath('plan.remaining_sessions', 6)->assertJsonPath('plan.reserved_sessions', 0)
            ->assertJsonPath('plan.available_sessions', 6)->assertJsonPath('plan.moved_sessions', 2)
            ->assertJsonPath('plan.amount', 3000)->assertJsonPath('plan.source_keeps_amount', 9000)
            ->assertJsonPath('plan.destination_existing_outstanding', 12000);
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_reservations_are_retained_and_cannot_be_transferred(): void
    {
        [$token, $source, $target] = $this->fixture();
        $this->appendClassSession($source, '2026-10-07', 'scheduled');
        $this->appendClassSession($source, '2026-10-14', 'rescheduled');
        $this->preview($token, $source, $target, 4)->assertOk()
            ->assertJsonPath('plan.reserved_sessions', 2)->assertJsonPath('plan.available_sessions', 4);
        $this->preview($token, $source, $target, 5)->assertStatus(422)->assertJsonPath('code', 'subject_credit_unavailable');
        $this->assertSame(2, DB::table('ClassSession')->where('StudentClassID', $source->ID)->whereIn('Status', ['scheduled', 'rescheduled'])->count());
    }

    public function test_historical_destination_debt_can_be_previewed_without_reopening_or_paying_it(): void
    {
        [$token, $source, $target] = $this->fixture();
        $target->forceFill(['Stop' => 1, 'closed_reason' => 'completed', 'UsedSessions' => 8, 'RemainingSessions' => 0])->save();
        $before = $this->businessSnapshot();
        $this->preview($token, $source, $target)->assertOk()->assertJsonPath('plan.destination_existing_outstanding', 12000);
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_pending_leave_and_other_future_reservations_do_not_release_credit(): void
    {
        [$token, $source, $target] = $this->fixture();
        foreach (['leave_requested', 'suspended'] as $status) {
            $this->appendClassSession($source, '2026-10-14', $status);
        }
        $this->appendClassSession($source, '2026-10-21', 'cancelled');
        $this->preview($token, $source, $target, 4)->assertOk()
            ->assertJsonPath('plan.reserved_sessions', 2)->assertJsonPath('plan.available_sessions', 4);
        $this->preview($token, $source, $target, 5)->assertStatus(422);
    }

    public function test_future_attended_like_rows_cannot_be_counted_as_transferable_unused_credit(): void
    {
        foreach (['attended', 'late', 'completed'] as $status) {
            [$token, $source, $target] = $this->fixture(used: 0);
            $this->appendClassSession($source, '2026-10-14', $status);
            $before = $this->businessSnapshot();
            $this->preview($token, $source, $target, 8)->assertStatus(422)->assertJsonPath('code', 'subject_credit_future_usage');
            $this->assertSame($before, $this->businessSnapshot());
        }
    }

    public function test_counter_drift_is_reported_and_never_repaired_or_spent(): void
    {
        [$token, $source, $target] = $this->fixture();
        $source->forceFill(['UsedSessions' => 0, 'RemainingSessions' => 8])->save();
        $this->preview($token, $source, $target)->assertOk()
            ->assertJsonPath('plan.available_sessions', 6)->assertJsonPath('plan.stored_remaining_sessions', 8)
            ->assertJsonPath('plan.counters_require_review', true);
        $this->assertSame(8, (int) $source->fresh()->RemainingSessions);
        $this->preview($token, $source, $target, 7)->assertStatus(422);
    }

    public function test_past_and_same_day_ended_unmarked_lessons_block_preview(): void
    {
        foreach (['2026-09-29', '2026-09-30'] as $date) {
            [$token, $source, $target] = $this->fixture();
            $this->appendClassSession($source, $date, 'scheduled'); // 08:00–09:00; clock is 10:00
            $this->preview($token, $source, $target)->assertStatus(422)->assertJsonPath('code', 'subject_credit_unmarked');
        }
    }

    public function test_agreed_units_are_exact_and_no_catalogue_or_default_is_inferred(): void
    {
        foreach ([['Rate' => 1501], ['SessionDuration' => 60], ['Rate' => 0], ['SessionDuration' => 0],
            ['rate_unit' => 'hour'], ['deduction_basis' => 'actual_duration'], ['Disconunt' => 100], ['duration1' => 60]] as $change) {
            [$token, $source, $target] = $this->fixture();
            $target->forceFill($change)->save();
            $this->preview($token, $source, $target)->assertStatus(422);
        }
        [$token, $source, $target] = $this->fixture();
        $source->forceFill(['Rate' => 1499.99])->save();
        $target->forceFill(['Rate' => 1499.99])->save();
        $this->preview($token, $source, $target)->assertStatus(422)->assertJsonPath('code', 'subject_credit_price_unproven');
    }

    public function test_exact_decimal_price_requires_an_integer_transfer_amount_without_rounding(): void
    {
        [$token, $source, $target] = $this->fixture(rate: 1500.25, charge: 12002, used: 0);
        $this->preview($token, $source, $target, 4)->assertOk()->assertJsonPath('plan.amount', 6001);
        $this->preview($token, $source, $target, 1)->assertStatus(422)->assertJsonPath('code', 'subject_credit_fractional_amount');
    }

    public function test_paid_flag_alone_refunds_transfers_and_shared_invoices_are_not_spendable_credit(): void
    {
        foreach (['no_payment', 'refund', 'transfer', 'extra_item', 'pending', 'void', 'invoice_student', 'invoice_charge'] as $case) {
            [$token, $source, $target, $invoice] = $this->fixture();
            match ($case) {
                'no_payment' => Payment::where('InvoiceID', $invoice->id)->delete(),
                'refund' => Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -100, 'Method' => 'refund', 'PaidAt' => '2026-09-01']),
                'transfer' => Payment::where('InvoiceID', $invoice->id)->update(['Method' => 'transfer_in']),
                'extra_item' => InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $target->ID, 'Description' => 'other', 'Amount' => 0]),
                'pending' => DB::table('payment_reports')->insert(['StudentID' => $source->StudentID, 'StudentClassID' => $source->ID,
                    'reported_amount' => 12000, 'reported_by_name' => 'isolated reporter', 'payment_date' => '2026-09-01',
                    'report_token_hash' => hash('sha256', 'isolated fixture'), 'token_expires_at' => now()->addDay(),
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]),
                'void' => $invoice->forceFill(['Status' => 'void'])->save(),
                'invoice_student' => $invoice->forceFill(['StudentID' => 999999])->save(),
                'invoice_charge' => $invoice->forceFill(['TotalAmount' => 11999])->save(),
            };
            $this->preview($token, $source, $target)->assertStatus(422);
        }
    }

    public function test_uncapped_usage_and_invalid_quantities_cannot_create_availability(): void
    {
        [$token, $source, $target] = $this->fixture(used: 9);
        $this->preview($token, $source, $target)->assertStatus(422)->assertJsonPath('code', 'subject_credit_usage_unclear');
        [$token, $source, $target] = $this->fixture();
        foreach ([0, -1, 1.5, 'all', 10001] as $n) {
            $this->preview($token, $source, $target, $n)->assertStatus(422);
        }
    }

    public function test_target_is_same_student_same_campus_and_different_subject_even_for_admin(): void
    {
        foreach (['student', 'subject', 'campus'] as $case) {
            [$token, $source, $target] = $this->fixture();
            if ($case === 'student' || $case === 'campus') {
                $other = Student::create(['name' => 'isolated-other', 'CampusID' => $case === 'campus' ? Campus::factory()->create()->id : Student::find($source->StudentID)->CampusID, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
                $target->forceFill(['StudentID' => $other->id])->save();
            } else {
                $target->forceFill(['SubjectID' => $source->SubjectID])->save();
            }
            $this->preview($token, $source, $target)->assertStatus(422)->assertJsonPath('code', 'subject_credit_bad_target');
        }
    }

    public function test_role_and_campus_are_enforced_by_the_real_route(): void
    {
        [$token, $source, $target] = $this->fixture();
        $campus = (int) Student::find($source->StudentID)->CampusID;
        $this->preview('', $source, $target)->assertStatus(401);
        $this->preview($this->token($campus, false), $source, $target)->assertStatus(403);
        $this->preview($this->token((int) Campus::factory()->create()->id), $source, $target)->assertStatus(403);
        $this->preview($this->token(null), $source, $target)->assertStatus(403);
        $this->preview($token, $source, $target)->assertOk();
        $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer", ['target_course_id' => $target->ID, 'sessions' => 2])->assertNotFound();
    }

    private function preview(string $token, StudentClass $source, StudentClass $target, mixed $sessions = 2)
    {
        return $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", ['target_course_id' => $target->ID, 'sessions' => $sessions]);
    }

}
