<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentReportController;
use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\PaymentReportReassignment;
use App\Models\StudentClass;
use App\Services\Billing\ReceiptReassignService;
use App\Services\InvoiceAmountReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReceiptReassignFixtures;
use Tests\TestCase;

/**
 * #5113 (in-app #522), design PR 3b: applying a receipt move. Each refusal is checked to write nothing; must-do items 1-5
 * of the 2026-11-13 list each have their own test (6 and 7 are in the targets/preview tests). Limits are varied through
 * config, never asserted as literals.
 */
class ReceiptReassignApplyTest extends TestCase
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

    // ── happy path ──────────────────────────────────────────────────────

    public function test_move_voids_the_old_receipt_and_records_a_new_one_on_the_right_bill_in_one_step(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $actor = (int) AuthToken::query()->where('token', $tok)->value('user_id');
        $plan = $this->preview($tok, $f)->assertOk()->json();
        $netBefore = $this->net($f['wrongInvoice']) + $this->net($f['rightInvoice']);
        $audits = DB::table('security_audit_events')->count();

        $res = $this->apply($tok, $f['report'], $f['rightInvoice'], $plan['state_hash'])->assertStatus(201)->assertJsonPath('replayed', false);

        $link = PaymentReportReassignment::query()->sole();
        $old = $f['report']->fresh();
        $new = PaymentReport::query()->findOrFail($res->json('new_report_id'));
        $this->assertSame([(int) $link->id, (int) $old->id], [$res->json('reassignment_id'), $res->json('old_report_id')]);
        $this->assertSame(['voided', $actor], [$old->status, (int) $old->voided_by]);
        $this->assertStringStartsWith('更正移轉：選錯學生', (string) $old->void_reason);
        $this->assertSame(['confirmed', (int) $f['right']->id, (int) $f['rightInvoice']->id, (int) $f['rightCourse']->ID, $actor],
            [$new->status, (int) $new->StudentID, (int) $new->InvoiceID, (int) $new->StudentClassID, (int) $new->confirmed_by]);
        $this->assertSame([self::AMOUNT, '2026-11-10'], [(int) $new->reported_amount, $new->payment_date->toDateString()]);
        $this->assertSame((int) $f['rightInvoice']->id, (int) DB::table('Payment')->where('id', $new->payment_id)->value('InvoiceID'));
        $this->assertSame([(int) $f['wrongInvoice']->id, (int) $f['rightInvoice']->id, (int) $f['wrong']->id, (int) $f['right']->id, 1, self::AMOUNT, '選錯學生', $actor],
            [$link->from_invoice_id, $link->to_invoice_id, $link->from_student_id, $link->to_student_id, $link->campus_id, $link->amount, $link->reason, $link->actor_user_id]);

        // Money: unchanged in sum, off the wrong bill, on the right one; both stored projections follow the Payment rows.
        $this->assertSame($netBefore, $this->net($f['wrongInvoice']) + $this->net($f['rightInvoice']));
        $this->assertSame(['unpaid', 0], [$f['wrongInvoice']->fresh()->Status, (int) $f['wrongInvoice']->fresh()->PaidAmount]);
        $this->assertSame(['paid', self::AMOUNT], [$f['rightInvoice']->fresh()->Status, (int) $f['rightInvoice']->fresh()->PaidAmount]);
        $this->assertSame([0, 1], [(int) $f['wrongCourse']->fresh()->Paid, (int) $f['rightCourse']->fresh()->Paid]);
        $this->assertSame([$plan['from']['balance_after'], $plan['to']['balance_after']], [$res->json('from.balance_after'), $res->json('to.balance_after')]);

        $this->assertSame($audits + 1, DB::table('security_audit_events')->count());
        $audit = DB::table('security_audit_events')->orderByDesc('id')->first();
        $this->assertSame([ReceiptReassignService::AUDIT_EVENT, 'success', 1], [$audit->event_type, $audit->outcome, (int) $audit->campus_id]);
        $this->assertStringNotContainsString('選錯學生', (string) $audit->metadata); // reason stored only as a hash
    }

    // ── must-do 1: after-balances through the resolver ──────────────────

    public function test_after_balance_of_an_over_collected_source_keeps_its_surplus(): void
    {
        $f = $this->fixture();
        $this->payment($f['wrongInvoice'], 1000); // total 8000, net 9000, receipt 8000
        $tok = $this->token('S', 1);
        $plan = $this->preview($tok, $f)->assertOk()->assertJsonPath('from.balance_after', 7000)->json();
        $this->apply($tok, $f['report'], $f['rightInvoice'], $plan['state_hash'])->assertStatus(201)
            ->assertJsonPath('from.balance_after', 7000)->assertJsonPath('from.status', 'partial');
        $this->assertSame(['partial', 1000], [$f['wrongInvoice']->fresh()->Status, (int) $f['wrongInvoice']->fresh()->PaidAmount]);
    }

    public function test_after_balance_of_a_monthly_source_back_at_net_zero_is_repriced_by_its_sessions(): void
    {
        $wrong = $this->student(1);
        $src = $this->bill($wrong, self::AMOUNT, 'paid', self::AMOUNT, $this->monthly(), ['billing_period' => '2026-11']);
        $this->sessions($src['course'], ['2026-11-03', '2026-11-05', '2026-11-07']); // 3 × 2000 = 6000 < stored 8000
        $report = $this->confirmedReport($src);
        $right = $this->bill($this->student(1), self::AMOUNT);
        $tok = $this->token('S', 1);

        // The old preview added the receipt back to the clamped balance (8000); the resolver reprices the empty bill to 6000.
        $plan = $this->previewTo($tok, $report, $right['invoice'])->assertOk()->assertJsonPath('from.balance_before', 0)->assertJsonPath('from.balance_after', 6000)->json();
        $this->apply($tok, $report, $right['invoice'], $plan['state_hash'])->assertStatus(201)->assertJsonPath('from.balance_after', 6000);
        $this->assertSame(6000, $this->resolved($src['invoice'])['outstanding_amount']);
    }

    // ── must-do 2: a repriced monthly target keeps the total the plan used ──

    public function test_repriced_monthly_target_is_saved_at_its_session_total_before_the_money_lands(): void
    {
        $f = $this->fixture();
        $target = $this->bill($f['right'], 9000, 'unpaid', 0, $this->monthly(), ['billing_period' => '2026-11']);
        $this->sessions($target['course'], ['2026-11-03', '2026-11-05', '2026-11-07', '2026-11-09']); // 4 × 2000 = 8000, stored 9000
        $tok = $this->token('S', 1);

        $plan = $this->previewTo($tok, $f['report'], $target['invoice'])->assertOk()
            ->assertJsonPath('to.balance_before', self::AMOUNT)->assertJsonPath('to.balance_after', 0)->json();
        $this->apply($tok, $f['report'], $target['invoice'], $plan['state_hash'])->assertStatus(201)->assertJsonPath('to.balance_after', 0);

        $saved = $target['invoice']->fresh();
        $this->assertSame([self::AMOUNT, 'paid', self::AMOUNT], [(int) $saved->TotalAmount, $saved->Status, (int) $saved->PaidAmount]);
        $this->assertSame('monthly_billable_sessions', $saved->billing_snapshot['source'] ?? null);
    }

    public function test_monthly_target_without_a_period_is_frozen_at_the_month_the_preview_priced(): void
    {
        $f = $this->fixture();
        // No billing_period: the resolver prices the bill by its IssueDate month (October), not the receipt's payment month (November).
        $target = $this->bill($f['right'], 9000, 'unpaid', 0, ['EndDate' => '2026-11-30', 'StartDate' => '2026-10-01'] + $this->monthly(),
            ['billing_period' => null, 'IssueDate' => '2026-10-01']);
        $this->sessions($target['course'], ['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26', '2026-11-03', '2026-11-05']); // Oct 8000, Nov 4000
        $tok = $this->token('S', 1);

        $plan = $this->previewTo($tok, $f['report'], $target['invoice'])->assertOk()->assertJsonPath('to.balance_before', self::AMOUNT)->json();
        $this->apply($tok, $f['report'], $target['invoice'], $plan['state_hash'])->assertStatus(201)->assertJsonPath('to.balance_after', 0);
        $saved = $target['invoice']->fresh();
        $this->assertSame([self::AMOUNT, '2026-10', 'paid'], [(int) $saved->TotalAmount, $saved->billing_period, $saved->Status]);
    }

    public function test_new_receipt_keeps_the_note_the_old_receipt_showed(): void
    {
        $f = $this->fixture();
        DB::table('Payment')->where('id', $f['report']->payment_id)->update(['Note' => '家長分兩次轉帳']);
        $tok = $this->token('S', 1);
        $new = $this->apply($tok, $f['report'], $f['rightInvoice'], $this->preview($tok, $f)->json('state_hash'))->assertStatus(201)->json('new_report_id');
        $this->assertSame('家長分兩次轉帳', PaymentReport::query()->findOrFail($new)->displayNote()); // not the internal move tag
    }

    public function test_target_course_pointing_at_another_students_package_is_refused_and_that_package_is_untouched(): void
    {
        $f = $this->fixture();
        $foreign = $this->package($this->student(2)); // another student, another campus
        $f['rightCourse']->update(['PackageID' => $foreign->id]);
        $tok = $this->token('S', 1);
        $before = $this->snapshot();
        $this->preview($tok, $f)->assertStatus(422)->assertJsonPath('code', 'reassign_target_package_mismatch');
        $this->apply($tok, $f['report'], $f['rightInvoice'], ReceiptReassignService::STATE_HASH_VERSION . ':' . str_repeat('0', 64))
            ->assertStatus(422)->assertJsonPath('code', 'reassign_target_package_mismatch');
        $this->assertSame($before, $this->snapshot());
        $this->assertFalse((bool) $foreign->fresh()->paid);
    }

    // ── R3 review P2s on df30a41 ────────────────────────────────────────

    public function test_every_member_of_either_package_is_locked_with_the_courses_before_the_move(): void
    {
        $f = $this->fixture();
        $pkg = $this->package($f['wrong']);
        $f['wrongCourse']->update(['PackageID' => $pkg->id]);
        $sibling = $this->bill($f['wrong'], self::AMOUNT, 'unpaid', 0, ['PackageID' => $pkg->id]); // PackagePaidMarks::drop rewrites it
        $tok = $this->token('S', 1);
        $hash = $this->preview($tok, $f)->assertOk()->json('state_hash');
        $locked = null;
        DB::listen(function ($q) use (&$locked) {
            if ($locked === null && stripos($q->sql, 'for update') !== false && stripos($q->sql, '`StudentClass`') !== false) {
                $locked = array_map('intval', $q->bindings);
            }
        });
        $this->apply($tok, $f['report'], $f['rightInvoice'], $hash)->assertStatus(201);
        $this->assertContains((int) $sibling['course']->ID, (array) $locked, 'the first course lock must already hold the package sibling');
        $this->assertSame(array_values(array_unique($locked)), $locked);
        $sorted = $locked;
        sort($sorted);
        $this->assertSame($sorted, $locked, 'ascending, the order every other writer uses');
    }

    public function test_bill_auto_issued_by_the_moved_receipts_confirmation_is_voided_with_it_as_void_does(): void
    {
        $f = $this->fixture();
        $f['wrongInvoice']->update(['billing_snapshot' => ['version' => 1, 'source' => PaymentReportController::FIRST_BILL_SOURCE,
            'payment_report_id' => $f['report']->id, 'contract_charge' => self::AMOUNT]]);
        $tok = $this->token('S', 1);
        $plan = $this->preview($tok, $f)->assertOk()->assertJsonPath('from.balance_after', 0)->json();
        $this->assertContains('source_bill_voided', $plan['warnings']);
        $this->apply($tok, $f['report'], $f['rightInvoice'], $plan['state_hash'])->assertStatus(201)
            ->assertJsonPath('from.status', 'void')->assertJsonPath('from.balance_after', 0);
        $this->assertSame('void', $f['wrongInvoice']->fresh()->Status);
        $this->assertSame(0, (int) $f['wrongCourse']->fresh()->Paid);
        $this->assertSame(['paid', self::AMOUNT], [$f['rightInvoice']->fresh()->Status, $this->net($f['rightInvoice'])]);
    }

    public function test_pending_report_bound_to_the_target_bill_only_by_invoice_id_blocks_the_move_and_the_offer(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $hash = $this->preview($tok, $f)->assertOk()->json('state_hash');
        $elsewhere = $this->bill($this->student(1), self::AMOUNT); // the report names another course, but this bill
        $this->pendingReport($f['right'], $elsewhere['course'], $f['rightInvoice']);
        $before = $this->snapshot();
        $this->apply($tok, $f['report'], $f['rightInvoice'], $hash)->assertStatus(422)->assertJsonPath('code', 'reassign_pending_report');
        $this->assertSame($before, $this->snapshot());
        $ids = array_column($this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$f['right']->id}", $this->h($tok))->assertOk()->json('targets'), 'invoice_id');
        $this->assertNotContains((int) $f['rightInvoice']->id, $ids);
    }

    public function test_targets_never_offer_a_bill_whose_course_points_at_another_students_package(): void
    {
        $f = $this->fixture();
        $f['rightCourse']->update(['PackageID' => $this->package($this->student(2))->id]);
        $ok = $this->bill($f['right'], self::AMOUNT);
        $ids = array_column($this->getJson("/api/v1/payment-reports/{$f['report']->id}/reassign-targets?student_id={$f['right']->id}", $this->h($this->token('S', 1)))
            ->assertOk()->json('targets'), 'invoice_id');
        $this->assertSame([(int) $ok['invoice']->id], $ids); // the preview would refuse the other one with reassign_target_package_mismatch
    }

    public function test_source_course_pointing_at_its_students_package_in_another_campus_is_refused_and_the_mark_is_kept(): void
    {
        config(['billing.receipt_reassign_director_max_amount' => self::AMOUNT, 'billing.receipt_reassign_director_max_age_days' => 30]);
        $f = $this->fixture();
        $pkg = $this->package($f['wrong']);
        $pkg->forceFill(['campus_id' => 2, 'paid' => true, 'paid_at' => '2026-11-01'])->save(); // same student, recorded under another campus
        $f['wrongCourse']->update(['PackageID' => $pkg->id]);
        $dir = $this->token('A', 1);
        $before = $this->snapshot();
        $this->preview($dir, $f)->assertStatus(422)->assertJsonPath('code', 'reassign_source_package_mismatch');
        $this->apply($dir, $f['report'], $f['rightInvoice'], ReceiptReassignService::STATE_HASH_VERSION . ':' . str_repeat('0', 64))
            ->assertStatus(422)->assertJsonPath('code', 'reassign_source_package_mismatch');
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue((bool) $pkg->fresh()->paid);
    }

    public function test_target_course_joining_a_package_after_the_preview_makes_the_hash_stale(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $old = $this->preview($tok, $f)->assertOk()->json('state_hash');
        $f['rightCourse']->update(['PackageID' => $this->package($f['right'])->id]); // its own student's package, same campus: allowed, but a different plan
        $this->assertNotSame($old, $this->preview($tok, $f)->assertOk()->json('state_hash'));
        $before = $this->snapshot();
        $this->apply($tok, $f['report'], $f['rightInvoice'], $old)->assertStatus(409)->assertJsonPath('code', 'reassign_state_changed');
        $this->assertSame($before, $this->snapshot());
    }

    // ── must-do 3: pending reports across a shared package ──────────────

    public function test_pending_report_on_any_member_of_either_package_blocks_the_apply_and_writes_nothing(): void
    {
        foreach (['target side' => 'right', 'source side' => 'wrong'] as $label => $side) {
            $f = $this->fixture();
            $pkg = $this->package($f[$side]);
            $member = $side === 'right' ? $this->bill($f['right'], self::AMOUNT, 'unpaid', 0, ['PackageID' => $pkg->id]) : null;
            $target = $member['invoice'] ?? $f['rightInvoice'];
            if ($side === 'wrong') {
                $f['wrongCourse']->update(['PackageID' => $pkg->id]);
            }
            $other = $this->bill($f[$side], self::AMOUNT, 'unpaid', 0, ['PackageID' => $pkg->id]); // a non-header member
            $tok = $this->token('S', 1);
            $hash = $this->previewTo($tok, $f['report'], $target)->assertOk()->json('state_hash');
            $this->pendingReport($f[$side], $other['course'], $other['invoice']);
            $before = $this->snapshot();
            $this->apply($tok, $f['report'], $target, $hash)->assertStatus(422)->assertJsonPath('code', 'reassign_pending_report');
            $this->assertSame($before, $this->snapshot(), $label);
        }
    }

    // ── must-do 4: what the state_hash covers ───────────────────────────

    public function test_state_hash_covers_owner_course_and_closed_reason_of_both_sides_and_a_stale_one_is_refused(): void
    {
        $changes = [
            'target owner' => function (array $f) {
                $third = $this->student(1);
                $f['rightInvoice']->update(['StudentID' => $third->id]);
                $f['rightCourse']->update(['StudentID' => $third->id]);
            },
            'target course' => fn (array $f) => $f['rightInvoice']->update(['StudentClassID' => $this->bill($f['right'], 1)['course']->ID]),
            'target course closed_reason' => fn (array $f) => $f['rightCourse']->update(['closed_reason' => 'settled_pending']),
            'source course closed_reason' => fn (array $f) => $f['wrongCourse']->update(['closed_reason' => 'settled']),
        ];
        $tok = $this->token('S', 1);
        foreach ($changes as $label => $change) {
            $f = $this->fixture();
            $old = $this->preview($tok, $f)->assertOk()->json('state_hash');
            $this->assertStringStartsWith(ReceiptReassignService::STATE_HASH_VERSION . ':', $old);
            $change($f);
            $this->assertNotSame($old, $this->preview($tok, $f)->assertOk()->json('state_hash'), $label);
            $before = $this->snapshot();
            $this->apply($tok, $f['report'], $f['rightInvoice'], $old)->assertStatus(409)->assertJsonPath('code', 'reassign_state_changed');
            $this->assertSame($before, $this->snapshot(), $label);
        }
    }

    public function test_a_session_change_after_the_preview_that_moves_the_repriced_source_balance_makes_the_hash_stale(): void
    {
        $src = $this->bill($this->student(1), self::AMOUNT, 'paid', self::AMOUNT, $this->monthly(), ['billing_period' => '2026-11']);
        $this->sessions($src['course'], ['2026-11-03', '2026-11-05', '2026-11-07']);
        $report = $this->confirmedReport($src);
        $right = $this->bill($this->student(1), self::AMOUNT);
        $tok = $this->token('S', 1);
        $old = $this->previewTo($tok, $report, $right['invoice'])->assertJsonPath('from.balance_after', 6000)->json('state_hash');
        $this->sessions($src['course'], ['2026-11-09']); // no stored field changes; only the projected source balance does
        $this->previewTo($tok, $report, $right['invoice'])->assertJsonPath('from.balance_after', self::AMOUNT);
        $before = $this->snapshot();
        $this->apply($tok, $report, $right['invoice'], $old)->assertStatus(409)->assertJsonPath('code', 'reassign_state_changed');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_source_bill_pointing_at_another_students_course_is_refused_and_that_course_is_untouched(): void
    {
        $f = $this->fixture();
        $foreign = $this->bill($this->student(2), self::AMOUNT, 'paid', self::AMOUNT); // another student, another campus
        $f['wrongInvoice']->update(['StudentClassID' => $foreign['course']->ID]);
        $tok = $this->token('S', 1);
        $before = $this->snapshot();
        $this->preview($tok, $f)->assertStatus(422)->assertJsonPath('code', 'reassign_source_other_owner');
        $this->apply($tok, $f['report'], $f['rightInvoice'], ReceiptReassignService::STATE_HASH_VERSION . ':' . str_repeat('0', 64))
            ->assertStatus(422)->assertJsonPath('code', 'reassign_source_other_owner');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, (int) $foreign['course']->fresh()->Paid);
    }

    public function test_hash_without_the_version_prefix_is_refused(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $hash = $this->preview($tok, $f)->json('state_hash');
        $before = $this->snapshot();
        $this->apply($tok, $f['report'], $f['rightInvoice'], substr($hash, strlen(ReceiptReassignService::STATE_HASH_VERSION) + 1))
            ->assertStatus(409)->assertJsonPath('code', 'reassign_state_changed');
        $this->assertSame($before, $this->snapshot());
    }

    // ── must-do 5: reversals carry the move id; away and back works ─────

    public function test_move_away_and_back_tags_each_reversal_with_its_own_move_and_leaves_one_live_receipt(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $first = $this->apply($tok, $f['report'], $f['rightInvoice'], $this->preview($tok, $f)->json('state_hash'))->assertStatus(201)->json();
        $moved = PaymentReport::query()->findOrFail($first['new_report_id']);
        $back = $this->apply($tok, $moved, $f['wrongInvoice'], $this->previewTo($tok, $moved, $f['wrongInvoice'])->assertOk()->json('state_hash'))
            ->assertStatus(201)->json();

        $reversal = fn (int $reportId) => Payment::query()->where('payment_report_id', $reportId)->where('Method', 'void')->sole();
        $this->assertSame($first['reassignment_id'], ReceiptReassignService::reassignmentIdOf($reversal((int) $f['report']->id)->Note));
        $this->assertSame($back['reassignment_id'], ReceiptReassignService::reassignmentIdOf($reversal((int) $moved->id)->Note));
        $this->assertNotSame($first['reassignment_id'], $back['reassignment_id']);
        $this->assertSame($back['reassignment_id'], ReceiptReassignService::reassignmentIdOf(Payment::query()->where('payment_report_id', $back['new_report_id'])->sole()->Note));
        $this->assertSame([self::AMOUNT, 0], [$this->net($f['wrongInvoice']), $this->net($f['rightInvoice'])]);
        $this->assertSame(['voided', 'voided', 'confirmed'], [$f['report']->fresh()->status, $moved->fresh()->status, PaymentReport::query()->findOrFail($back['new_report_id'])->status]);

        // The receipt now on the original bill is an ordinary receipt: the unchanged 撤銷收據 reverses it once.
        $this->putJson("/api/v1/payment-reports/{$back['new_report_id']}/void", ['void_reason' => '測試撤銷'], $this->h($tok))->assertOk();
        $this->assertSame(0, $this->net($f['wrongInvoice']));
    }

    // ── limits: 0 = off, above the limit goes to HQ (super_admin) ───────

    public function test_director_is_refused_while_the_limit_is_zero_and_nothing_is_written(): void
    {
        $f = $this->fixture();
        $hash = $this->preview($this->token('S', 1), $f)->json('state_hash');
        $before = $this->snapshot();
        $this->apply($this->token('A', 1), $f['report'], $f['rightInvoice'], $hash)->assertStatus(422)
            ->assertJsonPath('code', 'reassign_needs_super_admin')->assertJsonPath('hq_reason', 'off');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_over_the_director_limit_goes_to_super_admin_and_within_it_the_director_may_move(): void
    {
        config(['billing.receipt_reassign_director_max_age_days' => 30, 'billing.receipt_reassign_director_max_amount' => self::AMOUNT - 1]);
        $f = $this->fixture();
        $hash = $this->preview($this->token('S', 1), $f)->json('state_hash');
        $before = $this->snapshot();
        $this->apply($this->token('A', 1), $f['report'], $f['rightInvoice'], $hash)->assertStatus(422)
            ->assertJsonPath('code', 'reassign_needs_super_admin')->assertJsonPath('hq_reason', 'amount');
        $this->assertSame($before, $this->snapshot());
        $this->apply($this->token('S', 1), $f['report'], $f['rightInvoice'], $hash)->assertStatus(201);

        config(['billing.receipt_reassign_director_max_amount' => self::AMOUNT]);
        $g = $this->fixture();
        $dir = $this->token('A', 1);
        $this->apply($dir, $g['report'], $g['rightInvoice'], $this->preview($dir, $g)->assertJsonPath('needs_role', 'director')->json('state_hash'))->assertStatus(201);
    }

    public function test_a_moved_receipt_keeps_the_age_of_the_money_for_the_director_limit(): void
    {
        $f = $this->fixture();
        $f['report']->update(['confirmed_at' => Carbon::today()->subDays(40)->setTime(9, 0)]);
        $tok = $this->token('S', 1);
        $moved = $this->apply($tok, $f['report'], $f['rightInvoice'], $this->preview($tok, $f)->json('state_hash'))->assertStatus(201)->json('new_report_id');
        config(['billing.receipt_reassign_director_max_amount' => self::AMOUNT, 'billing.receipt_reassign_director_max_age_days' => 30]);
        $third = $this->bill($this->student(1), self::AMOUNT);
        $this->previewTo($this->token('A', 1), PaymentReport::query()->findOrFail($moved), $third['invoice'])
            ->assertStatus(422)->assertJsonPath('code', 'reassign_needs_super_admin')->assertJsonPath('hq_reason', 'age');
    }

    // ── campus isolation and roles ──────────────────────────────────────

    public function test_cross_campus_apply_is_refused_and_writes_nothing(): void
    {
        $f = $this->fixture();
        $hash = $this->preview($this->token('S', 1), $f)->json('state_hash');
        $other = $this->bill($this->student(2), self::AMOUNT);
        $before = $this->snapshot();
        $this->apply($this->token('A', 2), $f['report'], $f['rightInvoice'], $hash)->assertStatus(403)->assertJsonMissingPath('code');
        $this->apply($this->token('A', 1), $f['report'], $other['invoice'], $hash)->assertStatus(403)->assertJsonPath('code', 'reassign_cross_campus');
        $this->apply($this->token('S', 1), $f['report'], $other['invoice'], $hash)->assertStatus(422)->assertJsonPath('code', 'reassign_cross_campus');
        $this->apply($this->token('T', 1), $f['report'], $f['rightInvoice'], $hash)->assertStatus(403);
        $this->assertSame($before, $this->snapshot());
    }

    // ── idempotency, staleness, reason ──────────────────────────────────

    public function test_same_request_twice_replays_the_first_result_and_moves_the_money_once(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $hash = $this->preview($tok, $f)->json('state_hash');
        $first = $this->apply($tok, $f['report'], $f['rightInvoice'], $hash)->assertStatus(201)->json();
        $after = $this->snapshot();

        $again = $this->apply($tok, $f['report'], $f['rightInvoice'], $hash)->assertOk()->assertJsonPath('replayed', true)->json();
        unset($first['replayed'], $again['replayed']);
        $this->assertEquals($first, $again);
        $this->assertSame($after, $this->snapshot());

        // Any other plan for the moved receipt is stale.
        $this->apply($tok, $f['report'], $f['rightInvoice'], ReceiptReassignService::STATE_HASH_VERSION . ':' . str_repeat('0', 64))
            ->assertStatus(409)->assertJsonPath('code', 'reassign_state_changed');
        $this->assertSame($after, $this->snapshot());
    }

    public function test_stale_state_hash_after_the_target_bill_changed_is_refused_and_writes_nothing(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $hash = $this->preview($tok, $f)->json('state_hash');
        $this->payment($f['rightInvoice'], 100);
        $before = $this->snapshot();
        $this->apply($tok, $f['report'], $f['rightInvoice'], $hash)->assertStatus(422)->assertJsonPath('code', 'reassign_target_overpay');
        DB::table('Payment')->where('InvoiceID', $f['rightInvoice']->id)->delete();
        $f['rightInvoice']->update(['TotalAmount' => self::AMOUNT + 100]);
        $before = $this->snapshot();
        $this->apply($tok, $f['report'], $f['rightInvoice'], $hash)->assertStatus(409)->assertJsonPath('code', 'reassign_state_changed');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_needs_a_reason_and_writes_nothing_without_one(): void
    {
        $f = $this->fixture();
        $tok = $this->token('S', 1);
        $hash = $this->preview($tok, $f)->json('state_hash');
        $before = $this->snapshot();
        foreach (['', '   '] as $blank) {
            $this->apply($tok, $f['report'], $f['rightInvoice'], $hash, $blank)->assertStatus(422)->assertJsonPath('code', 'reassign_reason_required');
        }
        $this->assertSame($before, $this->snapshot());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function apply(string $token, PaymentReport $report, Invoice $target, string $hash, string $reason = '選錯學生')
    {
        return $this->postJson("/api/v1/payment-reports/{$report->id}/reassign", ['target_invoice_id' => $target->id, 'state_hash' => $hash, 'reason' => $reason], $this->h($token));
    }

    private function net(Invoice $invoice): int
    {
        return (int) DB::table('Invoice')->where('id', $invoice->id)->selectRaw(Invoice::netPaidSql('Invoice') . ' AS n')->value('n');
    }

    /** @return array<string, mixed> */
    private function resolved(Invoice $invoice): array
    {
        $fresh = Invoice::query()->with(['payments', 'items'])->findOrFail($invoice->id);

        return app(InvoiceAmountReconciliationService::class)->resolve($fresh);
    }

    /** @return array<string, mixed> a single-month date course priced at 2000 per lesson */
    private function monthly(): array
    {
        return ['ScheduleMode' => 'date', 'Rate' => 2000, 'rate_unit' => 'session', 'StartDate' => '2026-11-01', 'EndDate' => '2026-11-30'];
    }

    /** @param list<string> $dates completed lessons */
    private function sessions(StudentClass $course, array $dates): void
    {
        foreach ($dates as $d) {
            DB::table('ClassSession')->insert(['StudentClassID' => $course->ID, 'SessionDate' => $d, 'StartTime' => '18:00:00', 'EndTime' => '20:00:00',
                'Status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param array{course:StudentClass, invoice:Invoice, payment:?Payment} $bill */
    private function confirmedReport(array $bill): PaymentReport
    {
        return PaymentReport::create([
            'StudentID' => $bill['invoice']->StudentID, 'StudentClassID' => $bill['course']->ID, 'InvoiceID' => $bill['invoice']->id, 'reported_by_name' => 'x',
            'payment_date' => '2026-11-10', 'payment_method' => 'transfer', 'reported_amount' => (int) $bill['payment']->Amount, 'status' => 'confirmed',
            'confirmed_by' => 1, 'confirmed_at' => Carbon::now(), 'payment_id' => $bill['payment']->id,
            'report_token_hash' => hash('sha256', uniqid('r', true)), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
    }

    private function package(\App\Models\Student $owner): CoursePackage
    {
        return CoursePackage::create(['student_id' => $owner->id, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => 'count', 'total_sessions' => 8, 'remaining_sessions' => 8,
            'used_sessions' => 0, 'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true]);
    }
}
