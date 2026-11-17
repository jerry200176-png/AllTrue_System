<?php

namespace Tests\Support;

use App\Models\Payment;
use App\Operations\{PopOperationCatalog, PopOperationService};
use App\Operations\Strategies\PaidInvoiceChargeAlignmentStrategy as Alignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic linked-void plus agreed-fee receipt; shared by strategy and actual two-PDO race tests. */
trait PaidInvoiceAlignmentFixture
{
    use AgreedFeeFixture;

    private function candidate(): array
    {
        $f = $this->fixture();
        $f['invoice']->update(['TotalAmount' => 8800, 'PaidAmount' => 8000, 'Status' => 'partial']);
        DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->delete();
        $old = $this->reportFor($f, 'voided'); $old->update(['reported_amount' => 8800]);
        $receipt = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => 8800, 'Method' => 'transfer', 'PaidAt' => '2026-10-01', 'payment_report_id' => $old->id]);
        $old->update(['payment_id' => $receipt->id]);
        Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => -8800, 'Method' => 'void', 'PaidAt' => '2026-10-01', 'payment_report_id' => $old->id]);
        $valid = $this->reportFor($f, 'confirmed'); $valid->update(['reported_amount' => 8000]);
        $cash = Payment::create(['InvoiceID' => $f['invoice']->id, 'Amount' => 8000, 'Method' => 'transfer', 'PaidAt' => '2026-10-10', 'payment_report_id' => $valid->id]);
        $valid->update(['payment_id' => $cash->id]);
        $p = ['student_id' => (int) $f['student']->id, 'course_id' => (int) $f['course']->ID, 'campus_id' => 1,
            'invoice_id' => (int) $f['invoice']->id, 'expected_invoice_total' => 8800, 'expected_charge' => 8000, 'decision_reference' => 'SYNTHETIC-PAID-ALIGNMENT'];
        $svc = app(Alignment::class); $p['expected_state_hash'] = $svc->preview($p)['state_hash'];
        $catalogPath = tempnam(sys_get_temp_dir(), 'paid-alignment-');
        try {
            file_put_contents($catalogPath, "version: 1\noperations:\n  synthetic-alignment:\n    lifecycle: planned\n    strategy_class: ".Alignment::class."\n    parameter_keys: [".implode(', ', array_keys($p))."]\n    approval_required: true\n    approver_roles: [super_admin]\n");
            $pop = new PopOperationService(new PopOperationCatalog($catalogPath));
            $request = $pop->createDraft('synthetic-alignment', $p, (string) Str::uuid(), 'synthetic-pop', 'director', [1]);
            // Strategy tests only. This is not production approval or proof that the protected POP gate passed.
            DB::table('pop_operation_requests')->where('id', $request['id'])->update(['status' => 'approved']);
        } finally { unlink($catalogPath); }
        return [$f, $p, ['operation_id' => $request['id'], 'actor' => 'synthetic-pop'], $old, $valid, $cash];
    }

}
