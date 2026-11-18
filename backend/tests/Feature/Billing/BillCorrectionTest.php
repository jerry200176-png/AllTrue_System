<?php

namespace Tests\Feature\Billing;

use App\Models\{Campus, Invoice, InvoiceItem, Payment, StudentClass};
use App\Services\Billing\{BillCorrection, ContractMoneyVerdict};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReceiptReassignFixtures;
use Tests\TestCase;

/**
 * Step 2 更正帳單, PR 1 (backend, off by default): in-app #560 (count bill at the old price) and #569 (monthly bill that misses a lesson
 * added after it was issued). Every case checks the Contract Money Verdict, that cash is unchanged and that each transfer pair sums to 0.
 */
class BillCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use ReceiptReassignFixtures;

    private int $campus;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'Asia/Taipei'));
        $this->campus = (int) Campus::factory()->create()->id;
        config(['billing.bill_correction_campus_ids' => [$this->campus]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ledger_payload_carries_can_correct_only_for_live_bills_of_an_enabled_campus(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'unpaid', 0);
        $url = "/api/v1/accounting/ledger?student_class_id={$b['course']->ID}";
        $h = ['Authorization' => 'Bearer '.$this->token('A', $this->campus), 'Accept' => 'application/json'];
        $this->withHeaders($h)->getJson($url)->assertOk()->assertJsonPath('invoices.0.can_correct', true);
        $b['invoice']->update(['Status' => 'void']);
        $this->withHeaders($h)->getJson($url)->assertOk()->assertJsonPath('invoices.0.can_correct', false);
        config(['billing.bill_correction_campus_ids' => [$this->campus + 999]]);
        $this->withHeaders($h)->getJson($url)->assertOk()->assertJsonPath('invoices.0.can_correct', false);
        config(['billing.bill_correction_campus_ids' => []]);
        $this->assertArrayNotHasKey('can_correct', $this->withHeaders($h)->getJson($url)->assertOk()->json('invoices.0'));
    }

    // ── 560: count bill at the old price ────────────────────────────────

    public function test_560_fully_paid_old_bill_then_lower_price_keeps_cash_and_shows_the_overpay(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $tok = $this->token('S', $this->campus);
        $plan = $this->correct($tok, $b['invoice'], ['new_total' => 8000])->assertOk()->assertJsonPath('moved_amount', 10000)
            ->assertJsonPath('overpaid_after', 2000)->assertJsonPath('status_after', 'paid')->json();
        $audits = DB::table('security_audit_events')->count();

        $res = $this->correct($tok, $b['invoice'], ['new_total' => 8000, 'state_hash' => $plan['state_hash']], 'apply')->assertStatus(201)->assertJsonPath('replayed', false);

        $new = Invoice::findOrFail($res->json('replacement_invoice_id'));
        $this->assertSame(['void', 0], [$b['invoice']->fresh()->Status, (int) $b['invoice']->fresh()->PaidAmount]);
        $this->assertSame([8000, 10000, 'paid'], [(int) $new->TotalAmount, (int) $new->PaidAmount, $new->Status]);
        $this->assertSame([8000, 1], [(int) $b['course']->fresh()->Charge, (int) $b['course']->fresh()->Paid]);
        $this->assertCashAndPairs($b['course'], 10000);
        $v = app(ContractMoneyVerdict::class)->forCourse($b['course']->fresh());
        $this->assertSame(['paid', 8000, 8000, 0, 2000], [$v->status, $v->owed, $v->applied, $v->outstanding, $v->overpaid]);
        $row = DB::table('invoice_corrections')->sole();
        $this->assertSame([BillCorrection::KIND, $this->campus, 10000, 8000, 10000], [$row->kind, (int) $row->campus_id, (int) $row->original_invoice_total, (int) $row->new_charge, (int) $row->amount]);
        $this->assertSame($audits + 1, DB::table('security_audit_events')->count());
    }

    public function test_560_partly_paid_old_bill_moves_the_paid_part_and_still_owes_the_rest(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'unpaid', 4000);
        $res = $this->applyCorrection($this->token('A', $this->campus), $b['invoice'], ['new_total' => 8000]);

        $new = Invoice::findOrFail($res->json('replacement_invoice_id'));
        $this->assertSame([8000, 4000, 'partial'], [(int) $new->TotalAmount, (int) $new->PaidAmount, $new->Status]);
        $this->assertCashAndPairs($b['course'], 4000);
        $v = app(ContractMoneyVerdict::class)->forCourse($b['course']->fresh());
        $this->assertSame(['partial', 8000, 4000, 4000, 0], [$v->status, $v->owed, $v->applied, $v->outstanding, $v->overpaid]);
        $this->assertSame(0, (int) $b['course']->fresh()->Paid);
    }

    public function test_560_unpaid_old_bill_gets_a_lower_price_without_any_transfer(): void
    {
        $b = $this->bill($this->student($this->campus), 10000);
        $this->applyCorrection($this->token('A', $this->campus), $b['invoice'], ['new_total' => 8000]);

        $this->assertSame(0, Payment::count());
        $v = app(ContractMoneyVerdict::class)->forCourse($b['course']->fresh());
        $this->assertSame(['unpaid', 8000, 0, 8000], [$v->status, $v->owed, $v->applied, $v->outstanding]);
    }

    // ── 569: monthly bill that misses a lesson ──────────────────────────

    public function test_569_unpaid_monthly_bill_is_refused_because_it_reprices_itself(): void
    {
        [$m] = $this->monthlyBill(0);
        $tok = $this->token('A', $this->campus);
        $this->correct($tok, $m['invoice'])->assertStatus(422)->assertJsonPath('code', 'repriceable');
    }

    public function test_569_partly_paid_monthly_bill_gets_the_late_lesson_and_it_is_no_longer_no_invoice(): void
    {
        [$m, $lateId] = $this->monthlyBill(2000);
        $verdict = app(ContractMoneyVerdict::class);
        $this->assertSame('no_invoice', collect($verdict->lessons($m['course']->fresh()))->firstWhere('class_session_id', $lateId)['payment']);
        $tok = $this->token('A', $this->campus);
        $plan = $this->correct($tok, $m['invoice'])->assertOk()->assertJsonPath('shape', 'monthly')->assertJsonPath('old_total', 6000)
            ->assertJsonPath('new_total', 8000)->assertJsonPath('added_lessons', ['2026-10-31'])->json();

        $res = $this->correct($tok, $m['invoice'], ['state_hash' => $plan['state_hash']], 'apply')->assertStatus(201);

        $new = Invoice::findOrFail($res->json('replacement_invoice_id'));
        $this->assertSame([8000, 2000, 'partial'], [(int) $new->TotalAmount, (int) $new->PaidAmount, $new->Status]);
        $this->assertSame('2026-10-31', substr((string) $new->items()->sole()->PeriodEnd, 0, 10));
        $this->assertSame(6000, (int) $m['course']->fresh()->Charge); // monthly: the contract is not touched
        $this->assertCashAndPairs($m['course'], 2000);
        $this->assertNotSame('no_invoice', collect($verdict->lessons($m['course']->fresh()))->firstWhere('class_session_id', $lateId)['payment']);
        $v = $verdict->forCourse($m['course']->fresh());
        $this->assertSame(['partial', 2000, 6000], [$v->status, $v->applied, $v->outstanding]);
    }

    // ── refusals (each writes nothing) ──────────────────────────────────

    public function test_refusals_write_nothing(): void
    {
        $other = (int) Campus::factory()->create()->id;
        $b = $this->bill($this->student($this->campus), 10000, 'unpaid', 4000);
        $before = $this->snapshot();
        $own = $this->token('A', $this->campus);
        $this->correct($this->token('T', $this->campus), $b['invoice'], ['new_total' => 8000])->assertStatus(403);
        $this->correct($this->token('A', $other), $b['invoice'], ['new_total' => 8000])->assertStatus(403);
        $this->correct($own, $b['invoice'], ['new_total' => 8000, 'reason' => 'x', 'state_hash' => str_repeat('a', 64)], 'apply')->assertStatus(422); // reason too short (apply only)
        $this->postJson("/api/v1/invoices/{$b['invoice']->id}/correction/preview", ['new_total' => 8000], $this->h($own))->assertOk(); // preview needs no reason
        $this->correct($own, $b['invoice'], [])->assertStatus(422)->assertJsonPath('code', 'new_total_required');
        $this->correct($own, $b['invoice'], ['new_total' => 10000])->assertStatus(422)->assertJsonPath('code', 'unchanged_total');
        $this->correct($own, $b['invoice'], ['new_total' => 8000], 'apply')->assertStatus(422); // state_hash required
        config(['billing.bill_correction_campus_ids' => []]);
        $this->correct($own, $b['invoice'], ['new_total' => 8000])->assertStatus(404)->assertJsonPath('code', 'bill_correction_off');
        $this->correct($own, $b['invoice'], ['new_total' => 8000, 'state_hash' => str_repeat('a', 64)], 'apply')->assertStatus(404);
        $this->correct($this->token('S', $this->campus), $b['invoice'], ['new_total' => 8000, 'state_hash' => str_repeat('a', 64)], 'apply')->assertStatus(404)->assertJsonPath('code', 'bill_correction_off');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_refuses_pending_report_package_multi_item_and_a_bill_with_refunded_money(): void
    {
        $tok = $this->token('A', $this->campus);
        $a = $this->bill($this->student($this->campus), 10000);
        $this->pendingReport(\App\Models\Student::findOrFail($a['course']->StudentID), $a['course'], $a['invoice']);
        $this->correct($tok, $a['invoice'], ['new_total' => 8000])->assertStatus(422)->assertJsonPath('code', 'pending_report');

        $p = $this->bill($this->student($this->campus), 10000, 'unpaid', 0, ['PackageID' => 1]);
        $this->correct($tok, $p['invoice'], ['new_total' => 8000])->assertStatus(422)->assertJsonPath('code', 'unsupported_course');

        $m = $this->bill($this->student($this->campus), 10000);
        foreach ([5000, 5000] as $amount) {
            InvoiceItem::create(['InvoiceID' => $m['invoice']->id, 'StudentClassID' => $m['course']->ID, 'Description' => 'x', 'Amount' => $amount]);
        }
        $this->correct($tok, $m['invoice'], ['new_total' => 8000])->assertStatus(422)->assertJsonPath('code', 'unsupported_bill');

        $r = $this->bill($this->student($this->campus), 10000, 'unpaid', 6000);
        $this->payment($r['invoice'], -2000, 'refund');
        $this->correct($tok, $r['invoice'], ['new_total' => 8000])->assertStatus(422)->assertJsonPath('code', 'money_moved');
    }

    public function test_second_correction_of_the_same_bill_and_a_stale_preview_are_refused(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'unpaid', 4000);
        $tok = $this->token('A', $this->campus);
        $stale = $this->correct($tok, $b['invoice'], ['new_total' => 8000])->assertOk()->json('state_hash');
        $this->payment($b['invoice'], 1000, 'cash'); // the bill moved after the screen saw it
        $b['invoice']->update(['PaidAmount' => 5000]);
        $before = $this->snapshot();
        $this->correct($tok, $b['invoice'], ['new_total' => 8000, 'state_hash' => $stale], 'apply')->assertStatus(409)->assertJsonPath('code', 'state_changed');
        $this->assertSame($before, $this->snapshot());

        $this->applyCorrection($tok, $b['invoice'], ['new_total' => 8000]);
        $this->correct($tok, $b['invoice'], ['new_total' => 7000])->assertStatus(409)->assertJsonPath('code', 'already_corrected');
    }

    public function test_monthly_gap_already_covered_by_another_live_bill_is_refused(): void
    {
        [$m] = $this->monthlyBill(2000);
        $other = Invoice::create(['StudentID' => $m['course']->StudentID, 'StudentClassID' => $m['course']->ID, 'IssueDate' => '2026-11-01', 'TotalAmount' => 2000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-11']);
        InvoiceItem::create(['InvoiceID' => $other->id, 'StudentClassID' => $m['course']->ID, 'Description' => 'x', 'Amount' => 2000, 'PeriodStart' => '2026-10-31', 'PeriodEnd' => '2026-10-31']);
        $this->correct($this->token('A', $this->campus), $m['invoice'])->assertStatus(422)->assertJsonPath('code', 'no_gap');
    }

    // ── idempotent replay ───────────────────────────────────────────────

    public function test_the_same_apply_again_replays_and_writes_nothing_new(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $tok = $this->token('A', $this->campus);
        $hash = $this->correct($tok, $b['invoice'], ['new_total' => 8000])->json('state_hash');
        $first = $this->correct($tok, $b['invoice'], ['new_total' => 8000, 'state_hash' => $hash], 'apply')->assertStatus(201)->json();
        $before = $this->snapshot();

        $again = $this->correct($tok, $b['invoice'], ['new_total' => 8000, 'state_hash' => $hash], 'apply')->assertOk()->assertJsonPath('replayed', true)->json();
        $this->assertSame($first['correction_id'], $again['correction_id']);
        $this->assertSame($before, $this->snapshot());
    }

    // ── void of a receipt whose money a correction moved ────────────────

    public function test_void_after_one_correction_takes_the_money_back_from_the_new_bill(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $report = $this->confirmedReport($b);
        $tok = $this->token('S', $this->campus);
        $new = $this->applyCorrection($tok, $b['invoice'], ['new_total' => 8000])->json('replacement_invoice_id');

        $this->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '收錯了'], $this->h($tok))->assertOk();

        $this->assertSame('voided', $report->fresh()->status);
        $this->assertSame([0, 'void'], [$this->net((int) $b['invoice']->id), $b['invoice']->fresh()->Status]);
        $this->assertSame([0, 'unpaid', 0], [$this->net((int) $new), Invoice::find($new)->Status, (int) Invoice::find($new)->PaidAmount]);
        $this->assertCashAndPairs($b['course'], 0);
        $this->assertSame(0, (int) $b['course']->fresh()->Paid);
        $v = app(ContractMoneyVerdict::class)->forCourse($b['course']->fresh());
        $this->assertSame(['unpaid', 8000, 0], [$v->status, $v->owed, $v->applied]);
    }

    public function test_void_after_two_corrections_in_a_row_follows_the_chain_to_the_last_bill(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $report = $this->confirmedReport($b);
        $tok = $this->token('S', $this->campus);
        $second = (int) $this->applyCorrection($tok, $b['invoice'], ['new_total' => 8000])->json('replacement_invoice_id');
        $third = (int) $this->applyCorrection($tok, Invoice::findOrFail($second), ['new_total' => 9000])->json('replacement_invoice_id');
        $this->assertSame(10000, $this->net($third));

        $this->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '收錯了'], $this->h($tok))->assertOk();

        $this->assertSame([0, 0, 0], [$this->net((int) $b['invoice']->id), $this->net($second), $this->net($third)]);
        $this->assertSame(['void', 'void', 'unpaid'], [$b['invoice']->fresh()->Status, Invoice::find($second)->Status, Invoice::find($third)->Status]);
        $this->assertCashAndPairs($b['course'], 0);
        $this->assertSame(['unpaid', 9000, 0], (function () use ($b) {
            $v = app(ContractMoneyVerdict::class)->forCourse($b['course']->fresh());

            return [$v->status, $v->owed, $v->applied];
        })());
    }

    public function test_void_is_refused_when_the_money_was_refunded_or_is_no_longer_there(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $report = $this->confirmedReport($b);
        $tok = $this->token('S', $this->campus);
        $new = Invoice::findOrFail($this->applyCorrection($tok, $b['invoice'], ['new_total' => 8000])->json('replacement_invoice_id'));
        $before = $this->snapshot();

        $refund = $this->payment($new, -3000, 'refund');
        $this->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '收錯了'], $this->h($tok))->assertStatus(409)->assertJsonPath('code', 'void_refunded');
        $refund->delete();
        $this->payment($new, -5000, 'transfer_out'); // 5000 of it already moved on: 5000 left, 10000 to take back
        $this->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '收錯了'], $this->h($tok))->assertStatus(409)->assertJsonPath('code', 'void_transferred_credit');
        $this->assertSame('confirmed', $report->fresh()->status);
        $this->assertSame($before['payments'] + [], array_slice($this->snapshot()['payments'], 0, count($before['payments'])));
    }

    public function test_void_is_refused_when_the_live_bill_was_itself_voided(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $report = $this->confirmedReport($b);
        $tok = $this->token('S', $this->campus);
        $new = (int) $this->applyCorrection($tok, $b['invoice'], ['new_total' => 8000])->json('replacement_invoice_id');
        DB::table('Invoice')->where('id', $new)->update(['Status' => 'void']);
        $before = $this->snapshot();

        $this->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '收錯了'], $this->h($tok))->assertStatus(409)->assertJsonPath('code', 'void_transferred_credit');
        $this->assertSame($before, $this->snapshot());
    }

    // ── legacy / stored-only paid state, last paid date, month cap ──────

    public function test_a_paid_state_the_payment_rows_do_not_back_is_refused(): void
    {
        $tok = $this->token('A', $this->campus);
        $stored = $this->bill($this->student($this->campus), 10000);
        $stored['invoice']->update(['PaidAmount' => 5000, 'Status' => 'partial']);
        $this->correct($tok, $stored['invoice'], ['new_total' => 8000])->assertStatus(422)->assertJsonPath('code', 'paid_state_unverified');

        $flag = $this->bill($this->student($this->campus), 10000);
        $flag['course']->update(['Paid' => 1]);
        $this->correct($tok, $flag['invoice'], ['new_total' => 8000])->assertStatus(422)->assertJsonPath('code', 'paid_state_unverified');
    }

    public function test_last_paid_date_survives_a_correction(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000); // receipt dated 2026-11-10
        $id = (int) $b['course']->ID;
        $this->applyCorrection($this->token('A', $this->campus), $b['invoice'], ['new_total' => 8000]);

        $this->assertSame([$id => '2026-11-10'], app(\App\Services\BillingPayableResolver::class)->lastPaidAtByStudentClassIds([$id]));
    }

    public function test_last_paid_date_is_gone_once_the_moved_money_is_taken_back(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $report = $this->confirmedReport($b);
        $id = (int) $b['course']->ID;
        $tok = $this->token('S', $this->campus);
        $this->applyCorrection($tok, $b['invoice'], ['new_total' => 8000]);
        $this->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '收錯了'], $this->h($tok))->assertOk();

        $this->assertSame([], app(\App\Services\BillingPayableResolver::class)->lastPaidAtByStudentClassIds([$id]));
    }

    public function test_the_replacement_cannot_be_exception_voided_while_the_original_receipt_is_confirmed(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $report = $this->confirmedReport($b);
        $tok = $this->token('S', $this->campus);
        $new = (int) $this->applyCorrection($tok, $b['invoice'], ['new_total' => 8000])->json('replacement_invoice_id');
        $this->assertTrue(BillCorrection::chainHasConfirmedReceipt($new));
        $before = $this->snapshot();

        $this->postJson("/api/v1/invoices/{$new}/exception-void", ['reason' => '開錯了'], $this->h($tok))->assertStatus(422)->assertJsonPath('code', 'confirmed_receipt_on_invoice');
        $this->assertSame($before, $this->snapshot());
        $report->update(['status' => 'voided']);
        $this->assertFalse(BillCorrection::chainHasConfirmedReceipt($new));
    }

    public function test_lessons_outside_the_contract_dates_are_not_priced_in(): void
    {
        [$m] = $this->monthlyBill(2000);
        $m['course']->update(['StartDate' => '2026-10-10']);
        foreach (['2026-10-06', '2026-10-07'] as $d) { // inside the bill's range, before the contract began
            DB::table('ClassSession')->insert(['StudentClassID' => $m['course']->ID, 'SessionDate' => $d, 'StartTime' => '18:00:00', 'EndTime' => '20:00:00',
                'Status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->correct($this->token('A', $this->campus), $m['invoice'])->assertOk()->assertJsonPath('range_shortfall', 0)->assertJsonPath('new_total', 8000);
    }

    public function test_the_gap_stops_at_the_end_of_the_items_calendar_month(): void
    {
        [$m] = $this->monthlyBill(2000);
        $m['course']->update(['EndDate' => '2026-11-30']);
        DB::table('ClassSession')->insert(['StudentClassID' => $m['course']->ID, 'SessionDate' => '2026-11-03', 'StartTime' => '18:00:00', 'EndTime' => '20:00:00',
            'Status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

        $this->correct($this->token('A', $this->campus), $m['invoice'])->assertOk()->assertJsonPath('added_lessons', ['2026-10-31'])->assertJsonPath('new_total', 8000);
    }

    public function test_a_lesson_added_inside_the_original_range_is_priced_in_too(): void
    {
        [$m] = $this->monthlyBill(2000);
        DB::table('ClassSession')->insert(['StudentClassID' => $m['course']->ID, 'SessionDate' => '2026-10-22', 'StartTime' => '18:00:00', 'EndTime' => '20:00:00',
            'Status' => 'completed', 'created_at' => now(), 'updated_at' => now()]); // inside 10-01..10-27, held after the bill froze at 6000

        $this->correct($this->token('A', $this->campus), $m['invoice'])->assertOk()->assertJsonPath('range_shortfall', 2000)
            ->assertJsonPath('added_lessons', ['2026-10-31'])->assertJsonPath('new_total', 10000);
    }

    public function test_replaying_the_applied_hash_with_other_contents_conflicts(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $tok = $this->token('A', $this->campus);
        $hash = $this->correct($tok, $b['invoice'], ['new_total' => 8000])->json('state_hash');
        $this->correct($tok, $b['invoice'], ['new_total' => 8000, 'state_hash' => $hash], 'apply')->assertStatus(201);

        $this->correct($tok, $b['invoice'], ['new_total' => 7000, 'state_hash' => $hash], 'apply')->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->correct($tok, $b['invoice'], ['new_total' => 8000, 'reason' => '另一個原因', 'state_hash' => $hash], 'apply')->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_void_is_refused_while_the_original_bill_has_an_unlinked_reversal(): void
    {
        $b = $this->bill($this->student($this->campus), 10000, 'paid', 10000);
        $report = $this->confirmedReport($b);
        $this->payment($b['invoice'], -4000, 'void'); // a legacy reversal no receipt is linked to
        $b['invoice']->update(['PaidAmount' => 6000, 'Status' => 'partial']);
        $tok = $this->token('S', $this->campus);
        $this->applyCorrection($tok, $b['invoice'], ['new_total' => 8000]);
        $before = $this->snapshot();

        $this->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '收錯了'], $this->h($tok))->assertStatus(409)->assertJsonPath('code', 'legacy_reversal_unreconciled');
        $this->assertSame($before, $this->snapshot());
    }

    // ── pointer from the confirm refusals ───────────────────────────────

    public function test_pointer_only_when_the_campus_is_on_and_the_charge_differs_from_the_bill(): void
    {
        $b = $this->bill($this->student($this->campus), 10000);
        $this->assertNull(BillCorrection::correctableInvoiceId((int) $b['course']->ID));
        $b['course']->update(['Charge' => 8000]);
        $this->assertSame((int) $b['invoice']->id, BillCorrection::correctableInvoiceId((int) $b['course']->ID));
        config(['billing.bill_correction_campus_ids' => []]);
        $this->assertNull(BillCorrection::correctableInvoiceId((int) $b['course']->ID));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function correct(string $tok, Invoice $invoice, array $body = [], string $verb = 'preview')
    {
        return $this->postJson("/api/v1/invoices/{$invoice->id}/correction/{$verb}", $body + ['reason' => '更正帳單金額'], $this->h($tok));
    }

    private function applyCorrection(string $tok, Invoice $invoice, array $body = [])
    {
        $hash = $this->correct($tok, $invoice, $body)->assertOk()->json('state_hash');

        return $this->correct($tok, $invoice, $body + ['state_hash' => $hash], 'apply')->assertStatus(201);
    }

    /** @return array{0: array{course:StudentClass, invoice:Invoice, payment:?Payment}, 1:int} a monthly October bill (3 lessons, range to 10-27) and the id of the 10-31 lesson */
    private function monthlyBill(int $paid): array
    {
        $m = $this->bill($this->student($this->campus), 6000, 'unpaid', $paid, ['ScheduleMode' => 'date', 'Rate' => 2000, 'rate_unit' => 'session',
            'StartDate' => '2026-10-01', 'EndDate' => '2026-10-31', 'Charge' => 6000], ['billing_period' => '2026-10', 'IssueDate' => '2026-10-01']);
        InvoiceItem::create(['InvoiceID' => $m['invoice']->id, 'StudentClassID' => $m['course']->ID, 'Description' => '十月學費', 'Amount' => 6000,
            'PeriodStart' => '2026-10-01', 'PeriodEnd' => '2026-10-27']);
        $late = 0;
        foreach (['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-31'] as $d) {
            $id = DB::table('ClassSession')->insertGetId(['StudentClassID' => $m['course']->ID, 'SessionDate' => $d, 'StartTime' => '18:00:00', 'EndTime' => '20:00:00',
                'Status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
            $late = $d === '2026-10-31' ? $id : $late;
        }

        return [$m, $late];
    }

    private function net(int $invoiceId): int
    {
        return (int) DB::table('Invoice')->where('id', $invoiceId)->selectRaw(Invoice::netPaidSql('Invoice').' AS n')->value('n');
    }

    /** Cash (every non-transfer row) is what it was, and every correction/reversal transfer pair nets to 0. */
    private function assertCashAndPairs(StudentClass $course, int $cash): void
    {
        $rows = DB::table('Payment')->whereIn('InvoiceID', DB::table('Invoice')->where('StudentClassID', $course->ID)->pluck('id'))->get();
        $this->assertSame($cash, (int) $rows->reject(fn ($p) => Payment::isCreditMovement($p))->sum('Amount'));
        $this->assertSame(0, (int) $rows->filter(fn ($p) => Payment::isCreditMovement($p))->sum('Amount'));
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'payments' => DB::table('Payment')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'invoices' => DB::table('Invoice')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'items' => DB::table('InvoiceItem')->count(),
            'courses' => DB::table('StudentClass')->orderBy('ID')->get()->map(fn ($r) => (array) $r)->all(),
            'corrections' => DB::table('invoice_corrections')->count(),
            'audit' => DB::table('security_audit_events')->count(),
        ];
    }
}
