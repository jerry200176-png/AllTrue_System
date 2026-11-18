<?php

namespace App\Operations\Strategies;

use App\Http\Controllers\BillingController;
use App\Models\Invoice;
use App\Models\StudentClass;
use App\Services\InvoiceAmountReconciliationService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** GH5413: one stale header after a fully reversed receipt and a new full agreed-fee receipt; never move cash. */
final class PaidInvoiceChargeAlignmentStrategy extends UnpaidInvoiceChargeAlignmentStrategy
{
    public const STRATEGY = self::class;

    protected function capture(array $p, bool $lock): array
    {
        $graph = parent::capture($p, $lock);
        $id = $p['course_id'];
        // Every competing money writer owns this course mutex; retain complete bounded history in the state hash.
        foreach (['invoice_corrections', 'session_entitlement_transfers', 'contract_subject_credit_transfers'] as $table) {
            $query = DB::table($table);
            if ($table === 'invoice_corrections') $query->where(fn ($q) => $q->where('student_class_id', $id)->orWhere('original_invoice_id', $p['invoice_id'])->orWhere('replacement_invoice_id', $p['invoice_id']));
            else $query->where(fn ($q) => $q->where('source_student_class_id', $id)->orWhere('target_student_class_id', $id));
            $rows = $query->when($lock, fn ($q) => $q->lockForUpdate())->orderBy('id')->limit(101)->get();
            if ($rows->count() > 100) $this->fail('row_budget');
            $graph[$table] = $rows->map(fn ($row) => (array) $row)->all();
        }
        return $graph;
    }

    protected function eligible(array $g, array $p): void
    {
        $c = $g['course']; $i = $g['invoice'];
        if ($g['items'] !== [] || $g['amendments'] !== [] || $g['invoice_corrections'] !== []
            || $g['session_entitlement_transfers'] !== [] || $g['contract_subject_credit_transfers'] !== []
            || $c['ScheduleMode'] !== 'count' || (int) ($c['PackageID'] ?? 0) > 0 || (int) $c['Stop'] !== 0
            || !empty($c['closed_reason']) || strtolower(trim((string) $c['ClassType'])) === 'tutoring'
            || (int) $c['Charge'] !== $p['expected_charge'] || (int) $c['Paid'] !== 0 || (int) $c['Pay'] !== 0 || !empty($c['PayDate'])
            || $i['Status'] !== 'partial' || (int) $i['TotalAmount'] !== $p['expected_invoice_total']
            || $p['expected_invoice_total'] <= $p['expected_charge'] || (int) $i['PaidAmount'] !== $p['expected_charge']
            || !empty($i['zero_amount_reason']) || !empty($i['billing_period'])
            || (($i['ScheduleModeAtIssue'] ?? null) !== null && $i['ScheduleModeAtIssue'] !== 'count')
            || count($g['reports']) !== 2 || count($g['payments']) !== 3) $this->fail('unsupported_paid_state');

        $payments = collect($g['payments']); $seen = []; $valid = null; $void = null;
        foreach ($g['reports'] as $report) {
            if (!in_array($report['status'], ['confirmed', 'voided'], true)) $this->fail('money_provenance');
            $rows = $payments->where('payment_report_id', $report['id']);
            $receipt = $rows->firstWhere('id', $report['payment_id']);
            $expected = $report['status'] === 'confirmed' ? $p['expected_charge'] : $p['expected_invoice_total'];
            try { $reported = BigDecimal::of((string) $report['reported_amount'])->toInt(); }
            catch (\Throwable) { $this->fail('money_provenance'); }
            if (!$receipt || $reported !== $expected || (int) $receipt['Amount'] !== $expected
                || !in_array($receipt['Method'], ['cash', 'transfer'], true) || isset($seen[$receipt['id']])) $this->fail('money_provenance');
            $seen[$receipt['id']] = true;
            if ($report['status'] === 'confirmed') {
                if ($valid || $rows->count() !== 1) $this->fail('money_provenance');
                $valid = $receipt;
            } else {
                $reversals = $rows->filter(fn ($row) => $row['Method'] === 'void' && (int) $row['Amount'] === -$expected);
                if ($void || $rows->count() !== 2 || $reversals->count() !== 1) $this->fail('money_provenance');
                $void = $reversals->first(); $seen[$void['id']] = true;
                if ((int) $void['id'] <= (int) $receipt['id']) $this->fail('money_provenance');
            }
        }
        if (!$valid || !$void || count($seen) !== 3 || (int) $valid['id'] <= (int) $void['id']
            || $payments->contains(fn ($row) => (string) $row['PaidAt'] > (string) $valid['PaidAt'])) $this->fail('money_provenance');
        $projection = app(InvoiceAmountReconciliationService::class)->resolve(Invoice::findOrFail($p['invoice_id']));
        if ($projection['net_applied'] !== $p['expected_charge'] || $projection['total_amount'] !== $p['expected_invoice_total']) $this->fail('money_projection');
    }

    protected function writeCorrection(array $p): void
    {
        parent::writeCorrection($p);
        $invoice = Invoice::findOrFail($p['invoice_id']);
        $projection = app(InvoiceAmountReconciliationService::class)->resolve($invoice);
        if ($projection['outstanding_amount'] !== 0 || $projection['overpaid_amount'] !== 0) $this->fail('money_projection');
        $invoice->Status = Invoice::statusFromProjection($projection); $invoice->save();
        BillingController::syncStudentClassPaidFromInvoice($invoice);
        if ((int) StudentClass::findOrFail($p['course_id'])->Paid !== 1) $this->fail('settlement_projection');
    }

    protected function restoreCorrection(array $snapshot): void
    {
        parent::restoreCorrection($snapshot);
        $p = $snapshot['parameters']; $old = $snapshot['before'];
        DB::table('Invoice')->where('id', $p['invoice_id'])->update(['Status' => $old['invoice']['Status']]);
        DB::table('StudentClass')->where('ID', $p['course_id'])->update(['Paid' => $old['course']['Paid'], 'PayDate' => $old['course']['PayDate']]);
    }

    protected function assertReplayCourse(array $snapshot, array $course): void
    {
        if (!isset($snapshot['after_course']) || !hash_equals($this->hash($snapshot['after_course']), $this->hash($course))) $this->fail('target_moved_on');
    }

    protected function verificationChecks(): array { return ['invoice_total_and_settlement_aligned', 'cash_reports_receipts_lessons_unchanged']; }
}
