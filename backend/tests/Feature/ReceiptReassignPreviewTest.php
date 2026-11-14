<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\PaymentReportReassignment;
use App\Services\Billing\ReceiptReassignService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReceiptReassignFixtures;
use Tests\TestCase;

/**
 * #5113 (in-app #522), design PR 3a: read-only preview of moving a confirmed receipt to the correct bill.
 * Every test also proves nothing is written. Limits are varied through config, never asserted as literals.
 */
class ReceiptReassignPreviewTest extends TestCase
{
    use RefreshDatabase;
    use ReceiptReassignFixtures;

    private const AMOUNT = 8000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00:00', 'Asia/Taipei'));
        config(['feature_flags.values' => ['FEATURE_RECEIPT_REASSIGN' => true]]);
        config(['billing.receipt_reassign_director_max_amount' => 0, 'billing.receipt_reassign_director_max_age_days' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── happy path and "nothing is written" ─────────────────────────────

    public function test_super_admin_preview_shows_both_bills_and_writes_nothing(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();

        $res = $this->preview($this->token('S', 1), $f)->assertOk();
        $res->assertJsonPath('preview', true)->assertJsonPath('amount', self::AMOUNT)->assertJsonPath('needs_role', 'super_admin')
            ->assertJsonPath('from.invoice_id', $f['wrongInvoice']->id)->assertJsonPath('from.balance_before', 0)->assertJsonPath('from.balance_after', self::AMOUNT)
            ->assertJsonPath('to.invoice_id', $f['rightInvoice']->id)->assertJsonPath('to.student_id', $f['right']->id)
            ->assertJsonPath('to.balance_before', self::AMOUNT)->assertJsonPath('to.balance_after', 0);
        $this->assertContains('source_bill_becomes_unpaid', $res->json('warnings'));
        $this->assertMatchesRegularExpression('/^' . ReceiptReassignService::STATE_HASH_VERSION . ':[0-9a-f]{64}$/', (string) $res->json('state_hash'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, PaymentReportReassignment::count());
    }

    public function test_money_across_the_two_bills_is_unchanged_by_the_plan(): void
    {
        $f = $this->fixture();
        $j = $this->preview($this->token('S', 1), $f)->assertOk()->json();
        $this->assertSame($j['from']['balance_before'] + $j['to']['balance_before'], $j['from']['balance_after'] + $j['to']['balance_after']);
    }

    public function test_state_hash_follows_the_money_on_the_target_bill(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $h1 = $this->preview($tok, $f)->json('state_hash');
        $this->assertSame($h1, $this->preview($tok, $f)->json('state_hash'));
        $this->payment($f['rightInvoice'], 1000);
        $h2 = $this->preview($tok, $f)->json('state_hash');
        $this->assertNotSame($h1, $h2);
    }

    public function test_apply_without_the_previews_state_hash_is_refused_and_writes_nothing(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $this->postJson("/api/v1/payment-reports/{$f['report']->id}/reassign",
            ['target_invoice_id' => $f['rightInvoice']->id, 'reason' => '選錯學生'], $this->h($this->token('S', 1)))
            ->assertStatus(422)->assertJsonPath('code', 'reassign_state_hash_required');
        $this->assertSame($before, $this->snapshot());
    }

    // ── director limit: config-driven, 0 = off, at-limit allowed ────────

    public function test_director_is_refused_while_limits_are_zero_but_super_admin_is_not(): void
    {
        $f = $this->fixture();
        $this->preview($this->token('A', 1), $f)->assertStatus(422)->assertJsonPath('code', 'reassign_needs_super_admin');
        $this->preview($this->token('S', 1), $f)->assertOk()->assertJsonPath('needs_role', 'super_admin');
    }

    public function test_director_amount_limit_follows_config_at_and_over_the_limit(): void
    {
        $f = $this->fixture();
        $tok = $this->token('A', 1);
        config(['billing.receipt_reassign_director_max_age_days' => 30]);
        foreach ([self::AMOUNT, self::AMOUNT + 500, 123456] as $limit) { // at-limit and above: allowed
            config(['billing.receipt_reassign_director_max_amount' => $limit]);
            $this->preview($tok, $f)->assertOk()->assertJsonPath('needs_role', 'director');
        }
        foreach ([self::AMOUNT - 1, 1, 0] as $limit) { // over the limit (or off): HQ path
            config(['billing.receipt_reassign_director_max_amount' => $limit]);
            $this->preview($tok, $f)->assertStatus(422)->assertJsonPath('code', 'reassign_needs_super_admin');
        }
    }

    public function test_director_age_limit_follows_config_at_and_over_the_limit(): void
    {
        $f = $this->fixture();
        $f['report']->update(['confirmed_at' => Carbon::today()->subDays(20)->setTime(9, 0)]);
        $tok = $this->token('A', 1);
        config(['billing.receipt_reassign_director_max_amount' => self::AMOUNT]);
        foreach ([20, 21, 90] as $maxAge) {
            config(['billing.receipt_reassign_director_max_age_days' => $maxAge]);
            $this->preview($tok, $f)->assertOk()->assertJsonPath('report_age_days', 20)->assertJsonPath('needs_role', 'director');
        }
        foreach ([19, 1, 0] as $maxAge) {
            config(['billing.receipt_reassign_director_max_age_days' => $maxAge]);
            $this->preview($tok, $f)->assertStatus(422)->assertJsonPath('code', 'reassign_needs_super_admin');
        }
        $this->assertSame('super_admin', app(ReceiptReassignService::class)->needsRole(1, 99));
    }

    public function test_needs_role_reads_config_on_every_call(): void
    {
        $svc = app(ReceiptReassignService::class);
        config(['billing.receipt_reassign_director_max_amount' => 42, 'billing.receipt_reassign_director_max_age_days' => 7]);
        $this->assertSame('director', $svc->needsRole(42, 7));
        $this->assertSame('super_admin', $svc->needsRole(43, 7));
        $this->assertSame('super_admin', $svc->needsRole(42, 8));
        config(['billing.receipt_reassign_director_max_amount' => 43]);
        $this->assertSame('director', $svc->needsRole(43, 7));
    }

    // ── authorization and campus isolation ──────────────────────────────

    public function test_teacher_is_forbidden(): void
    {
        $f = $this->fixture();
        $this->preview($this->token('T', 1), $f)->assertStatus(403);
        $this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets", $this->h($this->token('T', 1)))->assertStatus(403);
    }

    public function test_director_of_another_campus_gets_403_before_any_state_is_revealed(): void
    {
        $f = $this->fixture();
        $f['report']->update(['status' => 'voided']); // would be a 422 state refusal for an owner
        $this->preview($this->token('A', 2), $f)->assertStatus(403)->assertJsonMissingPath('code');
        $this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets", $this->h($this->token('A', 2)))->assertStatus(403);
    }

    public function test_target_bill_in_a_campus_the_director_does_not_own_is_403(): void
    {
        $f = $this->fixture();
        $other = $this->bill($this->student(2), 8000);
        $this->previewTo($this->token('A', 1), $f['report'], $other['invoice'])->assertStatus(403)->assertJsonPath('code', 'reassign_cross_campus');
    }

    public function test_target_in_another_campus_is_refused_even_for_super_admin_and_for_a_two_campus_director(): void
    {
        $f = $this->fixture();
        $other = $this->bill($this->student(2), 8000);
        $this->previewTo($this->token('S', 1), $f['report'], $other['invoice'])->assertStatus(422)->assertJsonPath('code', 'reassign_cross_campus');
        $this->previewTo($this->token('A', [1, 2]), $f['report'], $other['invoice'])->assertStatus(422)->assertJsonPath('code', 'reassign_cross_campus');
    }

    public function test_missing_target_bill_is_404(): void
    {
        $f = $this->fixture();
        $this->postJson("/api/v1/payment-reports/{$f['report']->id}/reassign", ['target_invoice_id' => 999999, 'preview' => true], $this->h($this->token('S', 1)))
            ->assertStatus(404)->assertJsonPath('code', 'reassign_target_not_found');
    }

    // ── feature flag ────────────────────────────────────────────────────

    public function test_flag_off_refuses_and_a_campus_override_wins(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        config(['feature_flags.values' => ['FEATURE_RECEIPT_REASSIGN' => false]]);
        $this->preview($tok, $f)->assertStatus(422)->assertJsonPath('code', 'reassign_disabled');
        $this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets", $this->h($tok))->assertStatus(422)->assertJsonPath('code', 'reassign_disabled');
        config(['feature_flags.values' => ['FEATURE_RECEIPT_REASSIGN' => false, 'FEATURE_RECEIPT_REASSIGN_CAMPUS_1' => true]]);
        $this->preview($tok, $f)->assertOk();
        config(['feature_flags.values' => ['FEATURE_RECEIPT_REASSIGN' => true, 'FEATURE_RECEIPT_REASSIGN_CAMPUS_1' => false]]);
        $this->preview($tok, $f)->assertStatus(422)->assertJsonPath('code', 'reassign_disabled');
    }

    // ── named refusals (each leaves the data untouched) ─────────────────

    public function test_each_refusal_has_its_code_and_writes_nothing(): void
    {
        $tok = $this->token('S', 1);
        $cases = [
            'reassign_not_confirmed' => fn (array $f) => $f['report']->update(['status' => 'voided']),
            'reassign_same_bill' => fn (array $f) => null,
            'reassign_target_voided' => fn (array $f) => $f['rightInvoice']->update(['Status' => 'void']),
            'reassign_target_overpay' => fn (array $f) => $this->payment($f['rightInvoice'], 1),
            'reassign_course_refunded' => fn (array $f) => $f['rightCourse']->update(['closed_reason' => 'refunded']),
            'reassign_refund_or_transfer_exists' => fn (array $f) => $this->payment($f['rightInvoice'], 10, 'transfer_in'),
            'reassign_pending_report' => fn (array $f) => $this->pendingReport($f['right'], $f['rightCourse'], $f['rightInvoice']),
            'reassign_amount_zero' => fn (array $f) => $f['report']->update(['reported_amount' => 0]),
            'reassign_source_unresolvable' => fn (array $f) => $f['report']->update(['payment_id' => null, 'InvoiceID' => 987654]),
        ];
        foreach ($cases as $code => $arrange) {
            $f = $this->fixture();
            $arrange($f);
            $before = $this->snapshot();
            $target = $code === 'reassign_same_bill' ? $f['wrongInvoice'] : $f['rightInvoice'];
            $res = $this->previewTo($tok, $f['report'], $target)->assertStatus(422);
            $this->assertSame($code, $res->json('code'), $code);
            $this->assertNotSame('', trim((string) $res->json('message')), $code);
            $this->assertSame($before, $this->snapshot(), $code);
        }
    }

    public function test_overpay_refusal_names_the_remaining_amount(): void
    {
        $f = $this->fixture();
        $this->payment($f['rightInvoice'], 3000);
        $res = $this->preview($this->token('S', 1), $f)->assertStatus(422)->assertJsonPath('code', 'reassign_target_overpay')->assertJsonPath('remaining', 5000);
        $this->assertStringContainsString('5000', $res->json('message'));
    }

    public function test_legacy_unlinked_reversal_on_the_source_bill_is_409(): void
    {
        $f = $this->fixture();
        Payment::create(['InvoiceID' => $f['wrongInvoice']->id, 'Amount' => -50, 'PaidAt' => '2026-11-01', 'Method' => 'void']);
        $this->preview($this->token('S', 1), $f)->assertStatus(409)->assertJsonPath('code', 'reassign_legacy_reversal_unreconciled');
    }

    public function test_receipt_already_reversed_is_409(): void
    {
        $f = $this->fixture();
        Payment::create(['InvoiceID' => $f['wrongInvoice']->id, 'Amount' => -self::AMOUNT, 'PaidAt' => '2026-11-01', 'Method' => 'void', 'payment_report_id' => $f['report']->id]);
        $this->preview($this->token('S', 1), $f)->assertStatus(409)->assertJsonPath('code', 'reassign_source_not_applied');
    }

    public function test_reason_longer_than_500_is_refused_and_a_missing_reason_is_fine_for_a_preview(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $this->postJson("/api/v1/payment-reports/{$f['report']->id}/reassign", ['target_invoice_id' => $f['rightInvoice']->id, 'preview' => true, 'reason' => str_repeat('字', 501)], $this->h($tok))
            ->assertStatus(422)->assertJsonPath('code', 'reassign_reason_too_long');
        $this->preview($tok, $f)->assertOk();
    }

    // ── reassign-targets ────────────────────────────────────────────────

    public function test_targets_lists_only_live_same_campus_bills_that_can_take_the_amount(): void
    {
        $f = $this->fixture();
        $voided = $this->bill($f['right'], 8000, 'void');
        $small = $this->bill($f['right'], self::AMOUNT - 1);
        $other = $this->bill($this->student(2), 8000);
        $res = $this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$f['right']->id}", $this->h($this->token('A', 1)))->assertOk();
        $ids = array_column($res->json('targets'), 'invoice_id');
        $this->assertSame([(int) $f['rightInvoice']->id], $ids);
        $this->assertNotContains((int) $voided['invoice']->id, $ids);
        $this->assertNotContains((int) $small['invoice']->id, $ids);
        $this->assertNotContains((int) $other['invoice']->id, $ids);
        $this->assertSame(self::AMOUNT, $res->json('targets.0.remaining'));
        $this->assertSame(self::AMOUNT, $res->json('amount'));
    }

    public function test_targets_default_to_the_receipts_own_student_and_never_offer_the_current_bill(): void
    {
        $f = $this->fixture();
        $res = $this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets", $this->h($this->token('A', 1)))->assertOk();
        $this->assertSame((int) $f['wrong']->id, $res->json('student_id'));
        $this->assertNotContains((int) $f['wrongInvoice']->id, array_column($res->json('targets'), 'invoice_id'));
    }

    public function test_targets_refuse_other_campus_students_and_bad_input(): void
    {
        $f = $this->fixture();
        $foreign = $this->student(2);
        $this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$foreign->id}", $this->h($this->token('A', 1)))->assertStatus(403);
        $this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$foreign->id}", $this->h($this->token('S', 1)))->assertStatus(422)->assertJsonPath('code', 'reassign_cross_campus');
        $this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id=abc", $this->h($this->token('A', 1)))->assertStatus(422)->assertJsonPath('code', 'reassign_bad_student');
    }

    public function test_targets_run_the_same_number_of_queries_for_two_or_eight_candidates(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $url = "/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$f['right']->id}";
        $pkg = CoursePackage::create(['student_id' => $f['right']->id, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => 'count', 'total_sessions' => 8, 'remaining_sessions' => 8,
            'used_sessions' => 0, 'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true]);
        $this->bill($f['right'], self::AMOUNT, 'unpaid', 0, ['PackageID' => $pkg->id]); // the package lookups run too
        $this->getJson($url, $this->h($tok))->assertOk(); // warm-up
        $count = function () use ($url, $tok): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url, $this->h($tok))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $two = $count();
        foreach (range(1, 6) as $i) {
            $this->bill($f['right'], self::AMOUNT, 'unpaid', 0, $i % 2 ? ['PackageID' => $pkg->id] : []);
        }
        $this->assertCount(8, $this->getJson($url, $this->h($tok))->json('targets'));
        $this->assertSame($two, $count());
    }

    // ── money proof on the source side (review P1-1) ────────────────────

    public function test_receipt_without_payment_id_is_accepted_when_its_payment_row_points_back_to_it(): void
    {
        $f = $this->fixture();
        DB::table('Payment')->where('id', $f['report']->payment_id)->update(['payment_report_id' => $f['report']->id]);
        $f['report']->update(['payment_id' => null]);
        $this->preview($this->token('S', 1), $f)->assertOk()->assertJsonPath('amount', self::AMOUNT);
    }

    public function test_receipt_whose_money_is_not_on_the_bill_is_refused_not_applied(): void
    {
        $tok = $this->token('S', 1);
        $arrange = [
            'no payment_id and an unlinked payment' => fn (array $f) => $f['report']->update(['payment_id' => null]),
            'bill has no payments' => function (array $f) {
                DB::table('Payment')->where('InvoiceID', $f['wrongInvoice']->id)->delete();
                $f['report']->update(['payment_id' => null]);
            },
            'payment amount differs from the report' => fn (array $f) => $f['report']->update(['reported_amount' => self::AMOUNT - 1000]),
            'bill net is below the receipt' => fn (array $f) => DB::table('Payment')->insert(['InvoiceID' => $f['wrongInvoice']->id, 'Amount' => -3000, 'PaidAt' => '2026-11-11', 'Method' => 'transfer_out']),
        ];
        foreach ($arrange as $label => $fn) {
            $f = $this->fixture();
            $fn($f);
            $before = $this->snapshot();
            $res = $this->preview($tok, $f);
            // #5113 must-do 7: one exact answer per shape (the source proof runs before any target or transfer check).
            $this->assertSame(409, $res->status(), $label);
            $this->assertSame('reassign_source_not_applied', $res->json('code'), $label);
            $this->assertSame($before, $this->snapshot(), $label);
        }
        $f = $this->fixture();
        $f['report']->update(['payment_id' => null]);
        $this->preview($tok, $f)->assertStatus(409)->assertJsonPath('code', 'reassign_source_not_applied');
    }

    public function test_negative_reported_amount_is_refused_instead_of_made_positive(): void
    {
        $f = $this->fixture();
        $f['report']->update(['reported_amount' => -self::AMOUNT]);
        $this->preview($this->token('S', 1), $f)->assertStatus(422)->assertJsonPath('code', 'reassign_amount_invalid');
    }

    public function test_two_receipts_on_one_bill_each_use_their_own_payment(): void
    {
        $f = $this->fixture();
        $pay2 = $this->payment($f['wrongInvoice'], 500);
        $report2 = PaymentReport::create([
            'StudentID' => $f['wrong']->id, 'StudentClassID' => $f['wrongCourse']->ID, 'InvoiceID' => $f['wrongInvoice']->id, 'reported_by_name' => 'x',
            'payment_date' => '2026-11-10', 'payment_method' => 'cash', 'reported_amount' => 500, 'status' => 'confirmed', 'confirmed_by' => 1,
            'confirmed_at' => Carbon::now(), 'payment_id' => $pay2->id, 'report_token_hash' => hash('sha256', uniqid('q', true)), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
        $tok = $this->token('S', 1);
        $this->preview($tok, $f)->assertOk()->assertJsonPath('amount', self::AMOUNT);
        $this->previewTo($tok, $report2, $f['rightInvoice'])->assertOk()->assertJsonPath('amount', 500);
    }

    public function test_source_bill_with_a_larger_total_shows_its_real_balance(): void
    {
        $f = $this->fixture();
        $f['wrongInvoice']->update(['TotalAmount' => 10000]);
        $j = $this->preview($this->token('S', 1), $f)->assertOk()->json();
        $this->assertSame(2000, $j['from']['balance_before']);
        $this->assertSame(10000, $j['from']['balance_after']);
    }

    public function test_over_collected_source_bill_keeps_its_surplus_after_the_move(): void
    {
        $f = $this->fixture();
        $this->payment($f['wrongInvoice'], 1000); // total 8000, net 9000, receipt 8000
        $j = $this->preview($this->token('S', 1), $f)->assertOk()->json();
        $this->assertSame(0, $j['from']['balance_before']);
        $this->assertSame(7000, $j['from']['balance_after']); // net 1000 left on an 8000 bill, not 8000
    }

    public function test_pending_report_on_another_member_of_a_count_package_blocks_the_move_and_the_offer(): void
    {
        $f = $this->fixture();
        $pkg = CoursePackage::create(['student_id' => $f['right']->id, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => 'count', 'total_sessions' => 8, 'remaining_sessions' => 8,
            'used_sessions' => 0, 'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true]);
        $target = $this->bill($f['right'], 8000, 'unpaid', 0, ['PackageID' => $pkg->id]);
        $other = $this->bill($f['right'], 8000, 'unpaid', 0, ['PackageID' => $pkg->id]); // non-header member
        $tok = $this->token('S', 1);
        $this->previewTo($tok, $f['report'], $target['invoice'])->assertOk();
        $this->pendingReport($f['right'], $other['course'], $other['invoice']);
        $this->previewTo($tok, $f['report'], $target['invoice'])->assertStatus(422)->assertJsonPath('code', 'reassign_pending_report');
        $ids = array_column($this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$f['right']->id}", $this->h($tok))->json('targets'), 'invoice_id');
        $this->assertNotContains((int) $target['invoice']->id, $ids);
    }

    public function test_state_hash_changes_when_the_source_side_changes(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $h1 = $this->preview($tok, $f)->json('state_hash');
        $this->payment($f['wrongInvoice'], 300);
        $this->assertNotSame($h1, $this->preview($tok, $f)->json('state_hash'));
    }

    public function test_from_student_is_the_owner_of_the_bill_the_money_sits_on(): void
    {
        $f = $this->fixture();
        $f['report']->update(['StudentID' => $f['right']->id]); // receipt filed under another student of the same campus
        $this->preview($this->token('S', 1), $f)->assertOk()->assertJsonPath('from.student_id', $f['wrong']->id);
    }

    // ── canonical payable of the target (review P1-2) ───────────────────

    public function test_monthly_target_repriced_by_held_sessions_is_judged_on_the_resolver_amount(): void
    {
        $f = $this->fixture();
        $month = $this->bill($f['right'], 8000, 'unpaid', 0, ['ScheduleMode' => 'date', 'StartDate' => '2026-11-01'], ['billing_period' => '2026-11', 'IssueDate' => '2026-11-01']);
        ClassSession::create(['StudentClassID' => $month['course']->ID, 'SessionDate' => '2026-11-05', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'cancelled', 'Note' => '']);
        $tok = $this->token('S', 1);
        // Stored 8000 would fit; every session of the period is held, so the bill is worth 0.
        $this->previewTo($tok, $f['report'], $month['invoice'])->assertStatus(422)->assertJsonPath('code', 'reassign_target_overpay');
        $ids = array_column($this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$f['right']->id}", $this->h($tok))->assertOk()->json('targets'), 'invoice_id');
        $this->assertNotContains((int) $month['invoice']->id, $ids);
        $this->assertContains((int) $f['rightInvoice']->id, $ids);
    }

    public function test_target_of_a_paid_package_is_refused_and_not_offered(): void
    {
        $f = $this->fixture();
        $pkg = CoursePackage::create(['student_id' => $f['right']->id, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => 'count', 'total_sessions' => 8, 'remaining_sessions' => 8,
            'used_sessions' => 0, 'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true, 'paid_at' => '2026-11-01']);
        $held = $this->bill($f['right'], 20000, 'unpaid', 0, ['PackageID' => $pkg->id], ['Status' => 'paid']);
        $tok = $this->token('S', 1);
        $this->previewTo($tok, $f['report'], $held['invoice'])->assertStatus(422)->assertJsonPath('code', 'reassign_target_package_paid');
        $ids = array_column($this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$f['right']->id}", $this->h($tok))->json('targets'), 'invoice_id');
        $this->assertNotContains((int) $held['invoice']->id, $ids);
    }

    // ── wording, age, other refusals ────────────────────────────────────

    public function test_hq_messages_name_the_actual_cause(): void
    {
        $f = $this->fixture();
        $tok = $this->token('A', 1);
        $msgs = [];
        $msgs['off'] = $this->preview($tok, $f)->assertJsonPath('hq_reason', 'off')->json('message');
        config(['billing.receipt_reassign_director_max_amount' => self::AMOUNT - 1, 'billing.receipt_reassign_director_max_age_days' => 30]);
        $msgs['amount'] = $this->preview($tok, $f)->assertJsonPath('hq_reason', 'amount')->json('message');
        config(['billing.receipt_reassign_director_max_amount' => self::AMOUNT]);
        $f['report']->update(['confirmed_at' => Carbon::today()->subDays(31)]);
        $msgs['age'] = $this->preview($tok, $f)->assertJsonPath('hq_reason', 'age')->json('message');
        $this->assertCount(3, array_unique($msgs));
    }

    public function test_unknown_report_age_is_null_and_goes_to_hq(): void
    {
        $f = $this->fixture();
        DB::table('payment_reports')->where('id', $f['report']->id)->update(['confirmed_at' => null, 'created_at' => null]);
        $this->preview($this->token('S', 1), $f)->assertOk()->assertJsonPath('report_age_days', null)->assertJsonPath('needs_role', 'super_admin');
        config(['billing.receipt_reassign_director_max_amount' => self::AMOUNT, 'billing.receipt_reassign_director_max_age_days' => 30]);
        $this->preview($this->token('A', 1), $f)->assertStatus(422)->assertJsonPath('hq_reason', 'age');
    }

    public function test_overpay_on_an_over_collected_bill_never_prints_a_negative_remaining(): void
    {
        $f = $this->fixture();
        $this->payment($f['rightInvoice'], self::AMOUNT + 500);
        $res = $this->preview($this->token('S', 1), $f)->assertStatus(422)->assertJsonPath('code', 'reassign_target_overpay')->assertJsonPath('remaining', 0);
        $this->assertStringNotContainsString('-', $res->json('message'));
    }

    public function test_target_owner_mismatch_report_404_and_source_bill_in_another_campus(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        DB::table('StudentClass')->where('ID', $f['rightCourse']->ID)->update(['StudentID' => $f['wrong']->id]);
        $this->preview($tok, $f)->assertStatus(422)->assertJsonPath('code', 'reassign_target_other_owner');
        $this->postJson('/api/v1/payment-reports/999999/reassign', ['target_invoice_id' => $f['rightInvoice']->id, 'preview' => true], $this->h($tok))
            ->assertStatus(404)->assertJsonPath('code', 'reassign_report_not_found');
        $g = $this->fixture();
        DB::table('Invoice')->where('id', $g['wrongInvoice']->id)->update(['StudentID' => $this->student(2)->id]);
        $this->preview($this->token('A', 1), $g)->assertStatus(403);
        $this->preview($tok, $g)->assertStatus(422)->assertJsonPath('code', 'reassign_cross_campus');
    }

    public function test_targets_never_offer_a_bill_the_preview_would_refuse(): void
    {
        $f = $this->fixture();
        $moved = $this->bill($f['right'], 8000);
        $this->payment($moved['invoice'], 10, 'refund');
        $pend = $this->bill($f['right'], 8000);
        $this->pendingReport($f['right'], $pend['course'], $pend['invoice']);
        $tok = $this->token('S', 1);
        $ids = array_column($this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$f['right']->id}", $this->h($tok))->json('targets'), 'invoice_id');
        $this->assertSame([(int) $f['rightInvoice']->id], $ids);
        foreach ([$moved, $pend] as $b) {
            $this->previewTo($tok, $f['report'], $b['invoice'])->assertStatus(422);
        }
    }
}
