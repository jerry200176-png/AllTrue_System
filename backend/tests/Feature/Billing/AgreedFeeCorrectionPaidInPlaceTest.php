<?php

namespace Tests\Feature\Billing;

use App\Models\{Invoice, Payment, PaymentReport};
use App\Services\InvoiceAmountReconciliationService;
use Illuminate\Support\Facades\{DB, Http, Notification, Queue};
use Illuminate\Support\Str;
use Tests\Support\AgreedFeeFixture;
use Tests\Support\AgreedFeeRefreshDatabase;
use Tests\TestCase;

/**
 * Founder 2026-10-21: a count-mode bill whose agreed fee is wrong (rate × sessions < invoice total) is corrected in place
 * even after the family paid part or all of the correct fee. Synthetic amounts only: rate 900 × 8 = 7200, bill 8800.
 */
class AgreedFeeCorrectionPaidInPlaceTest extends TestCase
{
    use AgreedFeeRefreshDatabase, AgreedFeeFixture;

    private function path(array $f): string { return '/api/v1/student-classes/'.$f['course']->ID.'/agreed-fee-correction'; }

    private function overbilled(): array
    {
        config(['billing.agreed_fee_correction_enabled' => true]);
        $f = $this->fixture();
        $f['course']->update(['ClassType' => 'one_on_one', 'Rate' => 900, 'Charge' => 8800, 'SessionCount' => 8]);
        $f['invoice']->update(['TotalAmount' => 8800]);
        DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->update(['Amount' => 8800]);
        return $f;
    }

    /** Confirmed money written directly (same rows the confirm path writes) for the boundary cases. */
    private function confirmedPayment(array $f, int $amount): PaymentReport
    {
        $report = $this->reportFor($f, 'confirmed');
        $report->update(['reported_amount' => $amount]);
        $payment = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => $amount, 'PaidAt' => '2026-10-01', 'Method' => 'cash', 'payment_report_id' => $report->id]);
        $report->update(['payment_id' => $payment->id]);
        $f['invoice']->update(['PaidAmount' => $amount, 'Status' => $amount >= (int) $f['invoice']->fresh()->TotalAmount ? 'paid' : 'partial']);
        return $report;
    }

    private function body(array $f, int $newCharge = 7200): array
    {
        return array_replace($this->feePayload($f['invoice']), ['new_charge' => $newCharge]);
    }

    public function test_paid_7200_on_8800_bill_is_corrected_in_place_and_becomes_paid_without_touching_money(): void
    {
        Http::fake(); Notification::fake(); Queue::fake(); // keep the collection step offline too
        $f = $this->overbilled();
        // Real canonical collection path: record + confirm 7200 against the 8800 bill (stays partial, course Paid stays 0).
        $reportId = $this->withToken($f['token'])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $f['course']->ID, 'invoice_id' => $f['invoice']->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'amount' => 7200,
        ])->assertOk()->json('report_id');
        $this->withToken($f['token'])->putJson('/api/v1/payment-reports/'.$reportId.'/confirm')->assertOk();
        $this->assertSame('partial', $f['invoice']->fresh()->Status);
        $this->assertSame(0, (int) $f['course']->fresh()->Paid);
        Http::fake(); Notification::fake(); Queue::fake(); // only the correction's side effects are asserted below
        $payments = DB::table('Payment')->orderBy('id')->get()->toArray();
        $reports = DB::table('payment_reports')->orderBy('id')->get()->toArray();
        $beforeCourse = $f['course']->fresh()->getRawOriginal();
        $body = $this->body($f);

        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()
            ->assertJsonPath('mode', 'in_place')->assertJsonPath('old_charge', 8800)->assertJsonPath('new_charge', 7200)
            ->assertJsonPath('original_invoice_total', 8800)->assertJsonPath('paid_amount', 7200)->assertJsonPath('new_outstanding', 0)
            ->assertJsonPath('effects.payment', 0)->assertJsonPath('effects.receipt', 0)->assertJsonPath('effects.refund', 0)
            ->assertJsonPath('effects.notification', 0)
            ->assertJsonPath('message', '已收款項不變，原帳單金額改為新約定費用；收款等於新費用時帳單即為已繳清。')->json('preview_token');
        $result = $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk()
            ->assertJsonPath('mode', 'in_place')->assertJsonPath('replacement_invoice_id', null)
            ->assertJsonPath('original_invoice_id', (int) $f['invoice']->id)->json();

        $invoice = $f['invoice']->fresh();
        $this->assertSame(1, Invoice::count());
        $this->assertSame(7200, (int) $invoice->TotalAmount);
        $this->assertSame(7200, (int) $invoice->PaidAmount);
        $this->assertSame('paid', $invoice->Status);
        $this->assertSame(0, app(InvoiceAmountReconciliationService::class)->resolve($invoice)['outstanding_amount']);
        $this->assertEquals($payments, DB::table('Payment')->orderBy('id')->get()->toArray());
        $this->assertEquals($reports, DB::table('payment_reports')->orderBy('id')->get()->toArray());
        $afterCourse = $f['course']->fresh()->getRawOriginal();
        $this->assertSame(7200, (int) $afterCourse['Charge']);
        unset($beforeCourse['Charge'], $afterCourse['Charge']);
        $this->assertEquals($beforeCourse, $afterCourse, 'legacy Paid/Pay/PayDate, rate, sessions and dates stay untouched');

        $row = DB::table('invoice_corrections')->sole();
        $this->assertSame('agreed_fee_in_place', $row->kind);
        $this->assertSame((int) $f['invoice']->id, (int) $row->original_invoice_id);
        $this->assertNull($row->replacement_invoice_id);
        $this->assertNull($row->payment_report_id);
        $this->assertSame([7200, 8800, 7200, 8800], [(int) $row->amount, (int) $row->old_charge, (int) $row->new_charge, (int) $row->original_invoice_total]);
        $audit = DB::table('security_audit_events')->where('event_type', 'billing.agreed_fee_corrected')->sole();
        $meta = json_decode($audit->metadata, true);
        $this->assertSame([7200, 0, 'agreed_fee_in_place'], [$meta['paid_before'] ?? null, $meta['outstanding_after'] ?? null, $meta['reason_code'] ?? null]);
        Http::assertNothingSent(); Notification::assertNothingSent(); Queue::assertNothingPushed();

        // Idempotent replay: same result, no second write, even after the preview token is gone.
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => 'expired'])->assertOk()->assertExactJson($result);
        $this->assertSame(1, DB::table('invoice_corrections')->count());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'billing.agreed_fee_corrected')->count());
        // A different request against the same bill is a second correction: refused.
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', array_replace($body, ['idempotency_key' => (string) Str::uuid()]))
            ->assertStatus(409)->assertJsonPath('code', 'already_corrected');
    }

    public function test_partial_800_leaves_6400_outstanding_after_correcting_to_7200(): void
    {
        $f = $this->overbilled();
        $this->confirmedPayment($f, 800);
        $body = $this->body($f);
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()
            ->assertJsonPath('paid_amount', 800)->assertJsonPath('new_outstanding', 6400)->assertJsonPath('original_outstanding', 8000)
            ->json('preview_token');
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk();
        $invoice = $f['invoice']->fresh();
        $this->assertSame([7200, 800, 'partial'], [(int) $invoice->TotalAmount, (int) $invoice->PaidAmount, $invoice->Status]);
        $this->assertSame(6400, app(InvoiceAmountReconciliationService::class)->resolve($invoice)['outstanding_amount']);
        $this->assertSame(1, Payment::count());
        $this->assertSame(800, (int) Payment::sole()->Amount);
        $meta = json_decode(DB::table('security_audit_events')->where('event_type', 'billing.agreed_fee_corrected')->value('metadata'), true);
        $this->assertSame([800, 6400], [$meta['paid_before'] ?? null, $meta['outstanding_after'] ?? null]);
    }

    public function test_paid_above_new_fee_needs_a_refund_and_is_refused_without_writes(): void
    {
        $f = $this->overbilled();
        $this->confirmedPayment($f, 8000);
        $before = DB::table('Invoice')->get()->toArray();
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->body($f))
            ->assertStatus(422)->assertJsonPath('code', 'refund_required');
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $this->body($f) + ['preview_token' => 'unused'])->assertStatus(409);
        $this->assertEquals($before, DB::table('Invoice')->get()->toArray());
        $this->assertSame(8800, (int) $f['course']->fresh()->Charge);
        $this->assertSame(0, DB::table('invoice_corrections')->count());
    }

    public function test_pending_report_unmatched_reversal_and_stale_paid_amount_still_block(): void
    {
        $f = $this->overbilled();
        $this->confirmedPayment($f, 7200);
        $pending = $this->reportFor($f); // pending
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->body($f))
            ->assertStatus(422)->assertJsonPath('code', 'payment_evidence_blocks');
        $pending->update(['status' => 'rejected']);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->body($f))->assertOk();

        // Stored PaidAmount disagreeing with Payment rows fails closed.
        $f['invoice']->update(['PaidAmount' => 7000]);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->body($f))
            ->assertStatus(422)->assertJsonPath('code', 'payment_evidence_blocks');
        $f['invoice']->update(['PaidAmount' => 7200]);

        // A receipt/reversal pair with no voided-report provenance blocks even though the net is unchanged.
        foreach ([500, -500] as $amount) {
            Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => $amount, 'PaidAt' => '2026-10-02', 'Method' => $amount > 0 ? 'cash' : 'void']);
        }
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->body($f))
            ->assertStatus(422)->assertJsonPath('code', 'payment_evidence_blocks');
        $this->assertSame(0, DB::table('invoice_corrections')->count());
    }

    public function test_legacy_paid_flag_and_confirmed_report_on_another_bill_still_refuse(): void
    {
        $f = $this->overbilled();
        $this->confirmedPayment($f, 7200);
        $f['course']->update(['Paid' => 1]);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->body($f))
            ->assertStatus(422)->assertJsonPath('code', 'payment_evidence_blocks');
        $f['course']->update(['Paid' => 0]);
        $other = Invoice::create(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 500, 'PaidAmount' => 0, 'Status' => 'void']);
        PaymentReport::query()->update(['InvoiceID' => $other->id]);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->body($f))
            ->assertStatus(422)->assertJsonPath('code', 'payment_evidence_blocks');
    }

    public function test_unpaid_bill_keeps_void_and_reissue(): void
    {
        $f = $this->overbilled();
        $body = $this->body($f);
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()
            ->assertJsonPath('mode', 'reissue')->assertJsonPath('paid_amount', 0)->assertJsonPath('new_outstanding', 7200)->json('preview_token');
        $result = $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk()
            ->assertJsonPath('mode', 'reissue')->json();
        $this->assertSame('void', $f['invoice']->fresh()->Status);
        $this->assertSame(8800, (int) $f['invoice']->fresh()->TotalAmount);
        $this->assertSame(7200, (int) Invoice::findOrFail($result['replacement_invoice_id'])->TotalAmount);
        $this->assertSame('agreed_fee', DB::table('invoice_corrections')->sole()->kind);
    }
}
