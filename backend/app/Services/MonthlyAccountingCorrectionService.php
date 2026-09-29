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
            'expected_receipt_status' => 'sometimes|in:confirmed,voided', 'expected_void_payment_id' => 'required_if:expected_receipt_status,voided|integer|min:1',
            'expected_target' => 'required_with:split.target_course_id|array',
            'expected_target.start' => 'required_with:split.target_course_id|date_format:Y-m-d', 'expected_target.end' => 'required_with:split.target_course_id|date_format:Y-m-d',
            'expected_target.charge' => 'required_with:split.target_course_id|integer|min:0', 'expected_target.invoice_id' => 'required_with:split.target_course_id|integer|min:1',
            'expected_target.item_id' => 'nullable|integer|min:1',
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
        $voided = ($data['expected_receipt_status'] ?? 'confirmed') === 'voided';
        $this->require((int) $source->getAttribute('Paid') === ($voided ? 0 : 1) && (int) $source->getAttribute('Charge') === $data['expected_registered_amount'], '來源收款標記或應收已變動');
        $this->require($data['actual_received_amount'] !== $data['expected_registered_amount'], '沒有需更正的收款金額');
        $split = $data['split'];
        $this->require(!empty($split['payment_evidence_reference']), '須提供已核對付款依據');
        $target = empty($split['target_course_id']) ? null : $this->course((int) $split['target_course_id']);
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
        $graph = $this->split->snapshotGraph($source, $target, $lock);
        $sourceInvoices = array_values(array_filter($graph['invoices'], fn ($row) => (int) $row['StudentClassID'] === (int) $source->getKey()));
        $this->require(count($sourceInvoices) === 1 && count($graph['reports']) === 1, '非單筆來源帳單或回報，須另行核對');
        $invoice = $sourceInvoices[0];
        $this->require(!collect($graph['items'])->contains(fn ($row) => (int) $row['InvoiceID'] === (int) $invoice['id']), '來源已有帳單項目，須另行核對');
        $payment = collect($graph['payments'])->firstWhere('id', $data['payment_id']); $report = $graph['reports'][0];
        $this->require((int) $invoice['id'] === $data['invoice_id'] && (int) $report['id'] === $data['report_id'] && $payment !== null, '帳務識別已變動');
        $this->require($invoice['billing_period'] === $data['expected_billing_period'] && $invoice['Status'] === ($voided ? 'unpaid' : 'paid')
            && (int) $invoice['PaidAmount'] === ($voided ? 0 : $data['expected_registered_amount']) && (int) $invoice['TotalAmount'] === $data['expected_registered_amount'], '原帳單已變動');
        $this->require($report['status'] === ($voided ? 'voided' : 'confirmed') && (int) $report['InvoiceID'] === (int) $invoice['id']
            && (int) $report['StudentClassID'] === (int) $source->getKey() && (int) $report['StudentID'] === (int) $source->getAttribute('StudentID')
            && (int) $report['payment_id'] === (int) $payment['id'] && (int) $report['reported_amount'] === $data['expected_registered_amount'], '原回報關聯或金額不符');
        $this->require((int) $payment['InvoiceID'] === (int) $invoice['id'] && (int) $payment['Amount'] === $data['expected_registered_amount']
            && in_array($payment['Method'], ['cash', 'transfer'], true) && $payment['Method'] === $report['payment_method'] && !empty($report['payment_date'])
            && substr((string) $payment['PaidAt'], 0, 10) === substr((string) $report['payment_date'], 0, 10), '原收款關聯或方式不符');
        $this->require(count($graph['payments']) === ($voided ? 2 : 1), '已有其他付款，須另行核對');
        if ($voided) {
            $void = collect($graph['payments'])->firstWhere('id', $data['expected_void_payment_id']);
            $this->require($void && (int) $void['InvoiceID'] === (int) $invoice['id'] && $void['Method'] === 'void'
                && (int) $void['Amount'] === -$data['expected_registered_amount'] && (int) $void['payment_report_id'] === (int) $report['id']
                && !empty($report['voided_at']), '原沖銷關聯或金額不符');
        }
        $reviewedTarget = $target ? $this->reviewTarget($source, $target, $data, $graph) : null;
        $this->require(count($graph['invoices']) === ($target ? 2 : 1), '已有其他帳單，須另行核對');
        $reviewed = clone $source;
        $reviewed->forceFill(['StartDate' => $split['source_start'], 'EndDate' => $split['target_end']]);
        foreach (['source', 'target'] as $period) {
            $fees = $this->billing->summarizePeriod($reviewed, substr($split[$period.'_start'], 0, 7));
            $this->require($fees['source'] === 'billable_sessions' && $fees['period_sessions'] > 0 && $fees['charge'] === (int) $split[$period.'_charge'], '已上堂次費率與核准應收不符');
        }
        $projected = $graph;
        foreach ($projected['courses'] as &$row) {
            if ((int) $row['ID'] === (int) $source->getKey()) $row = array_merge($row, ['StartDate' => $split['source_start'], 'EndDate' => $split['target_end'], 'Paid' => 1, 'Charge' => $split['source_charge']]);
            elseif ($reviewedTarget) $row = array_merge($row, ['StartDate' => $split['target_start'], 'EndDate' => $split['target_end'], 'Charge' => $split['target_charge']]);
        }
        unset($row);
        foreach ($projected['invoices'] as &$row) {
            $isSource = (int) $row['id'] === (int) $invoice['id'];
            $row = array_merge($row, ['billing_period' => substr($split[$isSource ? 'source_start' : 'target_start'], 0, 7),
                'TotalAmount' => $split[$isSource ? 'source_charge' : 'target_charge'], 'PaidAmount' => $isSource ? $data['actual_received_amount'] : 0, 'Status' => $isSource ? 'paid' : 'unpaid']);
        }
        unset($row);
        foreach ($projected['items'] as &$row) $row = array_merge($row, ['StudentClassID' => $target->getKey(), 'Amount' => $split['target_charge'], 'PeriodStart' => $split['target_start'], 'PeriodEnd' => $split['target_end']]);
        unset($row);
        $projected['reports'][0]['status'] = 'voided';
        if (!$voided) $projected['payments'][] = array_merge($payment, ['id' => -1, 'Amount' => -$data['expected_registered_amount'], 'Method' => 'void']);
        $projected['payments'][] = array_merge($payment, ['id' => -2, 'Amount' => $data['actual_received_amount']]);
        $projected['items'][] = ['id' => -1, 'InvoiceID' => $invoice['id'], 'StudentClassID' => $source->getKey(), 'Amount' => $split['source_charge'], 'PeriodStart' => $split['source_start'], 'PeriodEnd' => $split['source_end']];
        $plan = $this->split->previewState($reviewed, $split, $projected, false, $reviewedTarget);
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
            StudentClass::query()->whereIn('ID', array_filter([(int) $source->getKey(), $input['split']['target_course_id'] ?? null]))->orderBy('ID')->lockForUpdate()->get();
            $source = $this->course((int) $source->getKey());
            $existing = SessionCorrection::query()->where('decision_reference', $reference)->orderBy('id')->first();
            if ($existing) {
                $result = PopOperationService::canonicalParameters($existing->snapshot_before);
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
            $source->forceFill(['StartDate' => $split['source_start'], 'EndDate' => $split['target_end'], 'Charge' => $split['source_charge'], 'Paid' => 1, 'PayDate' => $report->getAttribute('payment_date')->toDateString()])->save();
            $invoice->forceFill(['billing_period' => substr($split['source_start'], 0, 7), 'DueDate' => $split['source_end'],
                'TotalAmount' => $split['source_charge'], 'PaidAmount' => $data['actual_received_amount'], 'Status' => 'paid', 'reconciled_at' => now(), 'reconciled_by' => null])->save();
            $item = $this->item($invoice, $source, $split['source_start'], $split['source_end'], (int) $split['source_charge']);
            $targetInvoice = null;
            if (!empty($split['target_course_id'])) {
                $existingTarget = $this->course((int) $split['target_course_id']);
                $existingTarget->forceFill(['StartDate' => $split['target_start'], 'EndDate' => $split['target_end'], 'Charge' => $split['target_charge']])->save();
                $targetInvoice = Invoice::query()->findOrFail($data['expected_target']['invoice_id']);
                if (!$targetInvoice instanceof Invoice) throw new \RuntimeException('Target invoice unavailable');
                $targetInvoice->forceFill(['TotalAmount' => $split['target_charge'], 'DueDate' => $split['target_end'], 'billing_period' => substr($split['target_start'], 0, 7)])->save();
                $this->targetItem($targetInvoice, $existingTarget, $split);
            }
            $splitPlan = $this->split->preview($source, $split, true);
            $result = $this->split->execute($source, $split, $splitPlan['confirmation_token'], $reference);
            $target = $this->course($result['target_course_id']);
            if (!$targetInvoice) {
                $targetInvoice = new Invoice(['StudentID' => $source->getAttribute('StudentID'), 'StudentClassID' => $target->getKey(),
                    'IssueDate' => today()->toDateString(), 'DueDate' => $split['target_end'], 'billing_period' => substr($split['target_start'], 0, 7),
                    'ScheduleModeAtIssue' => 'date', 'TotalAmount' => $split['target_charge'], 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => $reference]);
                $targetInvoice->save();
                $this->targetItem($targetInvoice, $target, $split);
            }
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
            $result = PopOperationService::canonicalParameters(json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
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
            if ($result['new_target']) {
                $target->forceFill(['Stop' => 1, 'closed_reason' => 'repair_rolled_back', 'Charge' => 0, 'TotalHours' => 0,
                    'SessionCount' => 0, 'UsedSessions' => 0, 'RemainingSessions' => 0, 'monthly_sessions' => 0])->save();
            } else {
                $original = collect($result['accounting_before']['courses'])->firstWhere('ID', $result['target_course_id']);
                foreach (['StartDate', 'EndDate', 'Charge', 'Stop', 'closed_reason', 'SessionCount', 'UsedSessions', 'RemainingSessions', 'TotalHours', 'monthly_sessions'] as $field) $target->setAttribute($field, $original[$field]);
                $target->save();
            }
            return ['ok' => true, 'scope' => 'contract_split_only', 'verified_cash_correction_preserved' => true];
        });
    }

    private function replaceReceipt(PaymentReport $old, Invoice $invoice, int $amount, string $reason): PaymentReport
    {
        if ($old->getAttribute('status') !== 'voided') {
        Payment::query()->create(['InvoiceID' => $invoice->getAttribute('id'), 'Amount' => -(int) $old->getAttribute('reported_amount'), 'PaidAt' => today()->toDateString(), 'Method' => 'void', 'Note' => $reason, 'payment_report_id' => $old->getAttribute('id')]);
        $old->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => null, 'void_reason' => $reason])->save();
        }
        $new = $old->replicate(['payment_id', 'confirmed_by', 'confirmed_at', 'voided_by', 'voided_at', 'void_reason', 'rejection_note', 'report_token_hash', 'token_expires_at']);
        $new->forceFill(['reported_amount' => $amount, 'status' => 'confirmed', 'confirmed_by' => null, 'confirmed_at' => now(),
            'note' => $reason, 'backfill_note' => 'Correction of report #'.$old->getAttribute('id'), 'report_token_hash' => hash('sha256', Str::random(64)), 'token_expires_at' => now()])->save();
        $payment = new Payment(['InvoiceID' => $invoice->getAttribute('id'), 'Amount' => $amount, 'PaidAt' => $old->getAttribute('payment_date')->toDateString(), 'Method' => $old->getAttribute('payment_method'), 'Note' => $reason, 'payment_report_id' => $new->getAttribute('id')]);
        $payment->save();
        $new->forceFill(['payment_id' => $payment->getAttribute('id')])->save();
        return $new;
    }

    private function reviewTarget(StudentClass $source, StudentClass $target, array &$data, array $graph): StudentClass
    {
        $expected = $data['expected_target']; $split = $data['split'];
        $this->require((int) $source->getKey() !== (int) $target->getKey(), '來源與目標不可相同');
        $this->require(substr((string) $target->getAttribute('StartDate'), 0, 10) === $expected['start']
            && substr((string) $target->getAttribute('EndDate'), 0, 10) === $expected['end'] && (int) $target->getAttribute('Charge') === $expected['charge'], '目標合約已變動');
        $invoice = collect($graph['invoices'])->firstWhere('id', $expected['invoice_id']);
        $this->require($invoice && (int) $invoice['StudentClassID'] === (int) $target->getKey() && $invoice['Status'] === 'unpaid'
            && (int) $invoice['PaidAmount'] === 0 && (int) $invoice['TotalAmount'] === $expected['charge'] && $invoice['billing_period'] === substr($split['target_start'], 0, 7), '目標帳單已變動或有收款');
        $items = array_values(array_filter($graph['items'], fn ($row) => (int) $row['InvoiceID'] === (int) $invoice['id']));
        if (!array_key_exists('item_id', $expected)) {
            $expected['item_id'] = $items[0]['id'] ?? null;
            $data['expected_target']['item_id'] = $expected['item_id']; // The signed manifest binds the uniquely owned parent item.
        }
        $this->require(count($items) <= 1 && (int) ($items[0]['id'] ?? 0) === (int) ($expected['item_id'] ?? 0), '目標帳單項目不符');
        if ($items) $this->require(empty($items[0]['StudentClassID']) || (int) $items[0]['StudentClassID'] === (int) $target->getKey(), '目標帳單項目屬於其他合約');
        foreach ($graph['sessions'] as $row) {
            if ((int) $row['StudentClassID'] !== (int) $target->getKey()) continue;
            $this->require(!in_array($row['Status'], ['attended', 'completed', 'late'], true), '目標已有實際上課，须另行核對');
            if (!in_array($row['Status'], ['cancelled', 'voided', 'leave', 'rescheduled'], true)) $this->require($row['SessionDate'] >= $split['target_start'] && $row['SessionDate'] <= $split['target_end'], '目標有其他期間有效堂次');
        }
        $reviewed = clone $target;
        $reviewed->forceFill(['StartDate' => $split['target_start'], 'EndDate' => $split['target_end'], 'Charge' => $split['target_charge']]);
        return $reviewed;
    }

    private function targetItem(Invoice $invoice, StudentClass $target, array $split): void
    {
        $item = InvoiceItem::query()->where('InvoiceID', $invoice->getKey())->first();
        if ($item instanceof InvoiceItem) $item->forceFill(['StudentClassID' => $target->getKey(), 'Amount' => $split['target_charge'],
            'PeriodStart' => $split['target_start'], 'PeriodEnd' => $split['target_end']])->save();
        else $this->item($invoice, $target, $split['target_start'], $split['target_end'], (int) $split['target_charge']);
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
