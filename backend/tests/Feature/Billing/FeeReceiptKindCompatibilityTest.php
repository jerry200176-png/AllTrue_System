<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AgreedFeeFixture;
use Tests\TestCase;

/** Local #4132 integration: fee corrections never become receipt/report submissions. */
class FeeReceiptKindCompatibilityTest extends TestCase
{
    use RefreshDatabase, AgreedFeeFixture;

    /** @dataProvider scopes */
    public function test_4132_receipt_collision_cannot_consume_a_fee_only_correction(string $scope): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]);
        $this->assertFalse(config('billing.receipt_correction_reissue_enabled'));
        $f = $this->fixture();
        $body = $this->feePayload($f['invoice']);
        if ($scope === 'unbilled') {
            DB::table('InvoiceItem')->delete(); $f['invoice']->delete();
            $body = ['scope' => 'unbilled', 'new_charge' => 500, 'reason' => 'Synthetic fee correction', 'idempotency_key' => (string) Str::uuid()];
        }
        $path = '/api/v1/student-classes/'.$f['course']->ID.'/agreed-fee-correction';
        $preview = $this->withToken($f['token'])->postJson($path.'/preview', $body)->assertOk()->json('preview_token');
        $fee = $this->withToken($f['token'])->postJson($path.'/apply', $body + ['preview_token' => $preview])->assertOk()->json();
        $this->assertSame(0, DB::table('Payment')->count());
        $this->assertSame(0, DB::table('payment_reports')->count());
        $this->assertSame($scope === 'billed' ? 2 : 0, Invoice::count());
        $this->assertNull(DB::table('invoice_corrections')->where('id', $fee['correction_id'])->value('payment_report_id'));
        if ($scope === 'unbilled') {
            // A later voided bill must not permit another correction kind to reuse the permanent course/key.
            $old = $f['invoice']->replicate(); $old->Status = 'void'; $old->save();
            $oldId = $old->id;
        } else {
            $oldId = $f['invoice']->id;
        }
        $before = DB::table('Invoice')->orderBy('id')->get()->toArray();
        $auditCount = DB::table('security_audit_events')->count();
        config(['billing.receipt_correction_reissue_enabled' => true]); // Explicit synthetic future-activation collision.
        $this->withToken($f['token'])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $f['course']->ID, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'amount' => 500, 'replaces_invoice_id' => $oldId, 'correction_reason' => $body['reason'],
            'idempotency_key' => $body['idempotency_key'],
        ])->assertStatus(409);
        $this->assertEquals($before, DB::table('Invoice')->orderBy('id')->get()->toArray());
        $this->assertSame(0, DB::table('Payment')->count());
        $this->assertSame(0, DB::table('payment_reports')->count());
        $this->assertSame($auditCount, DB::table('security_audit_events')->count());
        $this->assertSame(1, DB::table('invoice_corrections')->count());
        $this->assertSame(500, (int) $f['course']->fresh()->Charge);
    }

    public static function scopes(): array { return [['billed'], ['unbilled']]; }
}
