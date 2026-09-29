<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\SessionCorrection;
use App\Models\StudentClass;
use App\Operations\PopOperationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Catalog-only historical correction; no browser write route or external cash movement. */
final class MonthlyAccountingCorrectionService
{
    public function __construct(private MonthlyContractCorrectionService $split, private MonthlyBillingService $billing) {}

    public function preview(StudentClass $source, array $input, bool $lock = false): array
    {
        $source->refresh();
        $data = Validator::make($input, [
            'campus_id' => 'required|integer|min:1', 'invoice_id' => 'required|integer|min:1',
            'report_id' => 'required|integer|min:1', 'payment_id' => 'required|integer|min:1',
            'expected_start' => 'required|date_format:Y-m-d', 'expected_end' => 'required|date_format:Y-m-d',
            'expected_billing_period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'expected_registered_amount' => 'required|integer|min:1|max:999999',
            'actual_received_amount' => 'required|integer|min:1|max:999999',
            'expected_target_session_ids' => 'required|array|min:1', 'expected_target_session_ids.*' => 'required|integer|min:1|distinct',
            'split' => 'required|array', 'split.source_start' => 'required|date_format:Y-m-d',
            'split.source_end' => 'required|date_format:Y-m-d|after_or_equal:split.source_start',
            'split.target_start' => 'required|date_format:Y-m-d|after:split.source_end',
            'split.target_end' => 'required|date_format:Y-m-d|after_or_equal:split.target_start',
            'split.source_charge' => 'required|integer|min:1', 'split.target_charge' => 'required|integer|min:1',
            'split.target_course_id' => 'nullable|integer|min:1',
            'split.payment_evidence_reference' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.:#-]{3,128}$/'],
        ])->validate();
        $this->require((string) $source->getAttribute('ClassType') !== 'tutoring' && !(int) $source->getAttribute('Stop'), '僅適用進行中的收費月結課程');
        $this->require((int) $source->student?->CampusID === $data['campus_id'], '來源分校不符');
        $this->require(substr((string) $source->getAttribute('StartDate'), 0, 10) === $data['expected_start'] && substr((string) $source->getAttribute('EndDate'), 0, 10) === $data['expected_end'], '原合約日期已變動');
        $this->require((int) $source->getAttribute('Paid') === 1 && (int) $source->getAttribute('Charge') === $data['expected_registered_amount'], '來源收款標記或應收已變動');
        $this->require($data['actual_received_amount'] !== $data['expected_registered_amount'], '沒有需更正的收款金額');
        $split = $data['split'];
        $this->require(empty($split['target_course_id']) && !empty($split['payment_evidence_reference']), '須建立新期並提供已核對付款依據');
        $this->require(isset($split['source_start'], $split['source_end'], $split['target_start'], $split['target_end'], $split['source_charge'], $split['target_charge']), '分期資料不完整');
        $this->require(substr($split['source_start'], 0, 7) === substr($split['source_end'], 0, 7)
            && substr($split['target_start'], 0, 7) === substr($split['target_end'], 0, 7), '此更正僅支援兩個完整日曆月');
        foreach (['source', 'target'] as $period) {
            $month = Carbon::parse($split[$period.'_start']);
            $this->require($split[$period.'_start'] === $month->copy()->startOfMonth()->toDateString()
                && $split[$period.'_end'] === $month->copy()->endOfMonth()->toDateString(), '須依月底結算核對完整月份');
        }
        $this->require((int) $source->getAttribute('settlement_day') === 31, '非月底結算須另行核對');
        $this->require($data['actual_received_amount'] === (int) $split['source_charge'], '此更正僅支援舊期已全額收款');
        $graph = $this->split->snapshotGraph($source, null, $lock);
        $this->require(count($graph['invoices']) === 1 && count($graph['payments']) === 1 && count($graph['reports']) === 1 && $graph['items'] === [], '非單筆收款或已有帳單項目，須另行核對');
        $invoice = $graph['invoices'][0]; $payment = $graph['payments'][0]; $report = $graph['reports'][0];
        $this->require((int) $invoice['id'] === $data['invoice_id'] && (int) $report['id'] === $data['report_id'] && (int) $payment['id'] === $data['payment_id'], '帳務識別已變動');
        $this->require($invoice['billing_period'] === $data['expected_billing_period'] && $invoice['Status'] === 'paid'
            && (int) $invoice['PaidAmount'] === $data['expected_registered_amount'] && (int) $invoice['TotalAmount'] === $data['expected_registered_amount'], '原帳單已變動');
        $this->require($report['status'] === 'confirmed' && (int) $report['InvoiceID'] === (int) $invoice['id']
            && (int) $report['StudentClassID'] === (int) $source->getAttribute('ID') && (int) $report['StudentID'] === (int) $source->getAttribute('StudentID')
            && (int) $report['payment_id'] === (int) $payment['id'] && (int) $report['reported_amount'] === $data['expected_registered_amount'], '原回報關聯或金額不符');
        $this->require((int) $payment['InvoiceID'] === (int) $invoice['id'] && (int) $payment['Amount'] === $data['expected_registered_amount']
            && in_array($payment['Method'], ['cash', 'transfer'], true) && $payment['Method'] === $report['payment_method'] && !empty($report['payment_date'])
            && substr((string) $payment['PaidAt'], 0, 10) === substr((string) $report['payment_date'], 0, 10), '原收款關聯或方式不符');
        $reviewed = clone $source;
        $reviewed->forceFill(['StartDate' => $split['source_start'], 'EndDate' => $split['target_end']]);
        foreach (['source', 'target'] as $period) {
            $fees = $this->billing->summarizePeriod($reviewed, substr($split[$period.'_start'], 0, 7));
            $this->require($fees['source'] === 'billable_sessions' && $fees['period_sessions'] > 0 && $fees['charge'] === (int) $split[$period.'_charge'], '已上堂次費率與核准應收不符');
        }
        $projected = $graph;
        $projected['courses'][0]['StartDate'] = $split['source_start'];
        $projected['courses'][0]['EndDate'] = $split['target_end'];
        $projected['invoices'][0] = array_merge($invoice, ['billing_period' => substr($split['source_start'], 0, 7), 'TotalAmount' => $split['source_charge'], 'PaidAmount' => $data['actual_received_amount']]);
        $projected['reports'][0]['status'] = 'voided';
        $projected['payments'][] = array_merge($payment, ['id' => -1, 'Amount' => -$data['expected_registered_amount'], 'Method' => 'void']);
        $projected['payments'][] = array_merge($payment, ['id' => -2, 'Amount' => $data['actual_received_amount']]);
        $projected['items'][] = ['id' => -1, 'InvoiceID' => $invoice['id'], 'StudentClassID' => $source->getAttribute('ID'), 'Amount' => $split['source_charge'], 'PeriodStart' => $split['source_start'], 'PeriodEnd' => $split['source_end']];
        $plan = $this->split->previewState($reviewed, $split, $projected);
        $expected = $data['expected_target_session_ids']; sort($expected); $actual = $plan['session_ids']; sort($actual);
        $this->require($expected === $actual, '目標堂次清單已變動');
        $snapshot = ['input' => $data, 'graph' => $graph];
        return ['source_course_id' => (int) $source->getAttribute('ID'), 'session_ids' => $actual, 'input' => $data,
            'registered_before' => $data['expected_registered_amount'], 'received_after' => $data['actual_received_amount'],
            'target_charge' => (int) $split['target_charge'], 'target_payment_status' => 'unpaid',
            'snapshot' => $snapshot, 'confirmation_token' => $this->digest($snapshot)];
    }

    public function execute(StudentClass $source, array $input, string $token, string $reference, string $actor): array
    {
        $this->require((bool) preg_match('/^[A-Za-z0-9_.:#-]{3,128}$/', $reference), '更正識別無效');
        $this->require((bool) preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $actor), '操作身分無效');
        return DB::transaction(function () use ($source, $input, $token, $reference, $actor) {
            $source = $this->course((int) $source->getKey(), true);
            $existing = SessionCorrection::query()->where('decision_reference', $reference)->orderBy('id')->first();
            if ($existing) {
                $result = $existing->snapshot_before;
                $this->require(!$existing->rolled_back_at && ($result['source_course_id'] ?? null) === (int) $source->getAttribute('ID') && ($result['accounting_token'] ?? null) === $token
                    && $this->digest($result['accounting_input']) === $this->digest($input), '更正識別已使用或回復');
                return $result;
            }
            $plan = $this->preview($source, $input, true);
            $this->require(hash_equals($plan['confirmation_token'], $token), '快照已變動，須重新核准');
            $data = $plan['input']; $split = $data['split'];
            $invoice = Invoice::query()->findOrFail($data['invoice_id']);
            $report = PaymentReport::query()->findOrFail($data['report_id']);
            if (!$invoice instanceof Invoice || !$report instanceof PaymentReport) throw new \RuntimeException('Receipt model unavailable');
            $reason = '登錄金額更正 '.$reference.'；依據 '.$split['payment_evidence_reference'].'；actor '.$actor;
            $replacement = $this->replaceReceipt($report, $invoice, $data['actual_received_amount'], $reason);
            $source->forceFill(['StartDate' => $split['source_start'], 'EndDate' => $split['target_end'], 'Charge' => $split['source_charge']])->save();
            $invoice->forceFill(['billing_period' => substr($split['source_start'], 0, 7), 'DueDate' => $split['source_end'],
                'TotalAmount' => $split['source_charge'], 'PaidAmount' => $data['actual_received_amount'], 'Status' => 'paid', 'reconciled_at' => now(), 'reconciled_by' => null])->save();
            $item = $this->item($invoice, $source, $split['source_start'], $split['source_end'], (int) $split['source_charge']);
            $splitPlan = $this->split->preview($source, $split, true);
            $result = $this->split->execute($source, $split, $splitPlan['confirmation_token'], $reference);
            $target = $this->course($result['target_course_id']);
            $targetInvoice = new Invoice(['StudentID' => $source->getAttribute('StudentID'), 'StudentClassID' => $target->getAttribute('ID'),
                'IssueDate' => today()->toDateString(), 'DueDate' => $split['target_end'], 'billing_period' => substr($split['target_start'], 0, 7),
                'ScheduleModeAtIssue' => 'date', 'TotalAmount' => $split['target_charge'], 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => $reference]);
            $targetInvoice->save();
            $this->item($targetInvoice, $target, $split['target_start'], $split['target_end'], (int) $split['target_charge']);
            foreach ([$invoice->fresh(), $targetInvoice] as $bill) {
                $course = (int) $bill->getAttribute('StudentClassID') === (int) $source->getAttribute('ID') ? $source->fresh() : $target;
                $summary = $this->billing->summarizePeriod($course, $bill->getAttribute('billing_period'));
                $bill->forceFill(['billing_snapshot' => array_merge($summary, ['sessions' => $this->billing->billableSessionDetailsForPeriod($course, $bill->getAttribute('billing_period'))])])->save();
            }
            $result['accounting_token'] = $token; $result['accounting_input'] = $input;
            $result['accounting_before'] = $plan['snapshot']['graph'];
            $result['replacement_report_id'] = (int) $replacement->getAttribute('id');
            $result['source_item_id'] = (int) $item->getAttribute('id'); $result['target_invoice_id'] = (int) $targetInvoice->getKey();
            $source->fresh()->forceFill(['Stop' => 1, 'closed_reason' => 'settled'])->save();
            $result['after_digest'] = $this->digest($this->split->snapshotGraph($source->fresh(), $target->fresh()));
            $result = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            SessionCorrection::query()->where('decision_reference', $reference)->update(['snapshot_before' => json_encode($result, JSON_THROW_ON_ERROR), 'decided_by_actor' => $actor, 'correction_reason' => 'monthly_accounting_correction']);
            return $result;
        });
    }

    public function verify(array $result): array
    {
        $source = StudentClass::query()->find($result['source_course_id']); $target = StudentClass::query()->find($result['target_course_id']);
        $ok = $source instanceof StudentClass && $target instanceof StudentClass && hash_equals($result['after_digest'], $this->digest($this->split->snapshotGraph($source, $target)));
        return ['ok' => (bool) $ok, 'errors' => $ok ? [] : ['post_correction_data_drifted']];
    }

    /** Roll back contract ownership; verified cash correction remains append-only. */
    public function rollback(array $result): array
    {
        return DB::transaction(function () use ($result) {
            StudentClass::query()->whereIn('ID', [$result['source_course_id'], $result['target_course_id']])->orderBy('ID')->lockForUpdate()->get();
            $source = $this->course($result['source_course_id']);
            $target = $this->course($result['target_course_id']);
            $this->split->snapshotGraph($source, $target, true);
            $this->require($this->verify($result)['ok'], '更正後資料已變動，不可回復拆約');
            $contractOnly = $result;
            $contractOnly['new_target'] = false; // Keep the void invoice's historical owner.
            $this->split->rollback($contractOnly);
            Invoice::query()->where('id', $result['target_invoice_id'])->update(['Status' => 'void']);
            $source->fresh()->forceFill(['StartDate' => $result['accounting_input']['expected_start'], 'EndDate' => $result['accounting_input']['expected_end']])->save();
            $target->forceFill(['Stop' => 1, 'closed_reason' => 'repair_rolled_back', 'Charge' => 0, 'TotalHours' => 0,
                'SessionCount' => 0, 'UsedSessions' => 0, 'RemainingSessions' => 0, 'monthly_sessions' => 0])->save();
            return ['ok' => true, 'scope' => 'contract_split_only', 'verified_cash_correction_preserved' => true];
        });
    }

    private function replaceReceipt(PaymentReport $old, Invoice $invoice, int $amount, string $reason): PaymentReport
    {
        Payment::query()->create(['InvoiceID' => $invoice->getAttribute('id'), 'Amount' => -(int) $old->getAttribute('reported_amount'), 'PaidAt' => today()->toDateString(), 'Method' => 'void', 'Note' => $reason, 'payment_report_id' => $old->getAttribute('id')]);
        $old->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => null, 'void_reason' => $reason])->save();
        $new = $old->replicate(['payment_id', 'confirmed_by', 'confirmed_at', 'voided_by', 'voided_at', 'void_reason', 'rejection_note', 'report_token_hash', 'token_expires_at']);
        $new->forceFill(['reported_amount' => $amount, 'status' => 'confirmed', 'confirmed_by' => null, 'confirmed_at' => now(),
            'note' => $reason, 'backfill_note' => 'Correction of report #'.$old->getAttribute('id'), 'report_token_hash' => hash('sha256', Str::random(64)), 'token_expires_at' => now()])->save();
        $payment = new Payment(['InvoiceID' => $invoice->getAttribute('id'), 'Amount' => $amount, 'PaidAt' => $old->getAttribute('payment_date')->toDateString(), 'Method' => $old->getAttribute('payment_method'), 'Note' => $reason, 'payment_report_id' => $new->getAttribute('id')]);
        $payment->save();
        $new->forceFill(['payment_id' => $payment->getAttribute('id')])->save();
        return $new;
    }

    private function item(Invoice $invoice, StudentClass $course, string $start, string $end, int $amount): InvoiceItem
    {
        $item = new InvoiceItem(['InvoiceID' => $invoice->getAttribute('id'), 'StudentClassID' => $course->getAttribute('ID'),
            'Description' => '月結期間 '.$start.' ～ '.$end, 'Amount' => $amount, 'PeriodStart' => $start, 'PeriodEnd' => $end]);
        $item->save();
        return $item;
    }

    private function course(int $id, bool $lock = false): StudentClass
    {
        $query = StudentClass::query()->where('ID', $id);
        if ($lock) $query->lockForUpdate();
        $course = $query->firstOrFail();
        if (!$course instanceof StudentClass) throw new \RuntimeException('Course model unavailable');
        return $course;
    }

    private function digest(array $data): string
    {
        $this->require((string) config('app.key') !== '', '簽章設定缺失');
        return hash_hmac('sha256', json_encode(PopOperationService::canonicalParameters($data), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function require(bool $condition, string $message): void
    {
        if (!$condition) throw ValidationException::withMessages(['monthly_accounting' => [$message]]);
    }
}
