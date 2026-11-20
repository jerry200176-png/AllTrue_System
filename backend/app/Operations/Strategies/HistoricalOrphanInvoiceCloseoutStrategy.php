<?php

namespace App\Operations\Strategies;

use App\Models\Payment;
use App\Models\SecurityAuditEvent;
use App\Operations\PopOperationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use DomainException;

final class HistoricalOrphanInvoiceCloseoutStrategy
{
    public const OPERATION = 'reviewed-historical-orphan-invoice-closeout';
    public const KEYS = ['campus_id', 'student_id', 'course_id', 'invoice_id', 'report_id', 'expected_issue_date', 'expected_invoice_total', 'decision_reference', 'expected_state_hash'];
    private const CUTOFF = '2026-09-30';

    public function plan(array $parameters, array $context = []): array
    {
        try {
            $state = $this->readState($parameters, false);
            $hash = $this->hash($state);
            if ($state['invoice']['Status'] !== 'unpaid' || !hash_equals($hash, $parameters['expected_state_hash'])) {
                throw new DomainException('state_changed');
            }

            return ['ok' => true, 'parameters' => $parameters, 'digest' => $hash];
        } catch (DomainException $error) {
            return ['ok' => false, 'errors' => [$error->getMessage()]];
        }
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) !== true) {
            throw new DomainException('plan_not_ready');
        }
        $p = $plan['parameters'];
        $this->assertNativeContext($context);
        $before = $this->readState($p, true);
        if ($before['invoice']['Status'] !== 'unpaid'
            || !hash_equals($p['expected_state_hash'], $this->hash($before))
            || !hash_equals($p['expected_state_hash'], $plan['digest'])) {
            throw new DomainException('state_changed');
        }

        // Preserve every ledger row and the original invoice amount and dates.
        $updated = DB::table('Invoice')->where('id', $p['invoice_id'])->where('Status', 'unpaid')
            ->update(['Status' => 'void', 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new DomainException('invoice_not_updated');
        }
        $after = $this->readState($p, true);
        $expected = $before;
        $expected['invoice']['Status'] = 'void';
        $expected['invoice']['updated_at'] = $after['invoice']['updated_at'];
        if (!hash_equals($this->hash($expected), $this->hash($after))) {
            throw new DomainException('unexpected_accounting_change');
        }
        $this->audit($p, $context['operation_id'], false);

        return ['ok' => true, 'updated' => 1, 'snapshot' => [
            'parameters' => $p,
            'original_updated_at' => $before['invoice']['updated_at'],
            'after_hash' => $this->hash($after),
        ]];
    }

    public function verify(array $plan, array $result): array
    {
        $snapshot = $result['snapshot'] ?? [];
        if (!isset($snapshot['parameters'], $snapshot['after_hash'])) {
            return ['ok' => false, 'errors' => ['execution_snapshot_missing']];
        }
        $state = $this->readState($snapshot['parameters'], true);

        return ['ok' => $state['invoice']['Status'] === 'void'
            && hash_equals($snapshot['after_hash'], $this->hash($state)),
            'checks' => ['one_orphan_invoice_void', 'receipt_and_reversal_unchanged']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $this->assertNativeContext($context);
        $p = $snapshot['parameters'];
        $state = $this->readState($p, true);
        if ($state['invoice']['Status'] !== 'void' || !hash_equals($snapshot['after_hash'], $this->hash($state))) {
            throw new DomainException('state_changed_after_closeout');
        }
        DB::table('Invoice')->where('id', $p['invoice_id'])->update([
            'Status' => 'unpaid', 'updated_at' => $snapshot['original_updated_at'],
        ]);
        if (!hash_equals($p['expected_state_hash'], $this->hash($this->readState($p, true)))) {
            throw new DomainException('restore_mismatch');
        }
        $this->audit($p, $context['operation_id'], true);

        return ['ok' => true, 'restored' => 1];
    }

    private function readState(array $p, bool $lock): array
    {
        $keys = array_keys($p);
        if (array_diff(self::KEYS, $keys) || array_diff($keys, self::KEYS)
            || $p['campus_id'] !== 11 || $p['decision_reference'] !== self::OPERATION
            || !is_string($p['expected_issue_date']) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $p['expected_issue_date'])
            || $p['expected_issue_date'] > self::CUTOFF || !is_string($p['expected_state_hash'])
            || !preg_match('/\A[0-9a-f]{64}\z/', $p['expected_state_hash'])) {
            throw new DomainException('invalid_case_parameters');
        }
        foreach (['student_id', 'course_id', 'invoice_id', 'report_id', 'expected_invoice_total'] as $key) {
            if (!is_int($p[$key]) || $p[$key] <= 0) {
                throw new DomainException('invalid_case_parameters');
            }
        }

        $rows = fn (Builder $query, int $limit) => $query->when($lock, fn (Builder $q) => $q->lockForUpdate())->limit($limit)->get();
        $student = $rows(DB::table('Student')->select('id', 'CampusID')->where('id', $p['student_id']), 1)->first();
        if ((int) ($student->CampusID ?? 0) !== $p['campus_id']
            || $rows(DB::table('StudentClass')->select('ID')->where('ID', $p['course_id']), 1)->isNotEmpty()
            || $rows(DB::table('ClassSession')->select('id')->where('StudentClassID', $p['course_id']), 1)->isNotEmpty()) {
            throw new DomainException('student_scope_or_course_exists');
        }

        $bills = $rows(DB::table('Invoice')
            ->select('id', 'StudentID', 'StudentClassID', 'IssueDate', 'DueDate', 'TotalAmount', 'PaidAmount', 'Status', 'billing_period', 'updated_at')
            ->where('StudentClassID', $p['course_id'])->orderBy('id'), 2);
        $bill = $bills->first();
        if ($bills->count() !== 1 || (int) $bill->id !== $p['invoice_id'] || (int) $bill->StudentID !== $p['student_id']
            || $bill->IssueDate !== $p['expected_issue_date'] || $bill->DueDate !== null || $bill->billing_period !== null
            || (int) $bill->TotalAmount !== $p['expected_invoice_total'] || (int) $bill->PaidAmount !== 0
            || !in_array($bill->Status, ['unpaid', 'void'], true)) {
            throw new DomainException('invoice_state_or_scope_changed');
        }

        if ($rows(DB::table('InvoiceItem')->select('id')->where('InvoiceID', $p['invoice_id'])->orWhere('StudentClassID', $p['course_id']), 1)->isNotEmpty()
            || $rows(DB::table('invoice_corrections')->select('id')->where('original_invoice_id', $p['invoice_id'])->orWhere('replacement_invoice_id', $p['invoice_id']), 1)->isNotEmpty()) {
            throw new DomainException('invoice_has_other_allocations');
        }

        $reports = $rows(DB::table('payment_reports')
            ->select('id', 'StudentID', 'StudentClassID', 'InvoiceID', 'status', 'reported_amount', 'payment_id', 'payment_date', 'voided_at', 'updated_at')
            ->where('InvoiceID', $p['invoice_id'])->orWhere('StudentClassID', $p['course_id'])->orderBy('id'), 2);
        $report = $reports->first();
        if ($reports->count() !== 1 || (int) $report->id !== $p['report_id'] || (int) $report->StudentID !== $p['student_id']
            || (int) $report->StudentClassID !== $p['course_id'] || (int) $report->InvoiceID !== $p['invoice_id']
            || $report->status !== 'voided' || $report->voided_at === null
            || (string) $report->reported_amount !== number_format($p['expected_invoice_total'], 2, '.', '')) {
            throw new DomainException('receipt_not_fully_reversed');
        }
        $payments = $rows(DB::table('Payment')->select('id', 'InvoiceID', 'Amount', 'Method', 'PaidAt', 'payment_report_id', 'updated_at')
            ->where('InvoiceID', $p['invoice_id'])->orWhere('payment_report_id', $p['report_id'])->orderBy('id'), 3);
        $receipts = $payments->filter(fn (object $row) => Payment::isReceiptOf($row, $p['report_id'], (int) $report->payment_id));
        $reversals = $payments->filter(fn (object $row) => Payment::reversesReport($row, $p['report_id']));
        if ($payments->count() !== 2 || $receipts->count() !== 1 || $reversals->count() !== 1
            || $payments->contains(fn (object $row) => (int) $row->InvoiceID !== $p['invoice_id'])
            || $receipts->first()->Method !== 'cash'
            || (int) $receipts->first()->Amount !== $p['expected_invoice_total']
            || (int) $reversals->first()->Amount !== -$p['expected_invoice_total']
            || $reversals->first()->Method !== 'void') {
            throw new DomainException('receipt_not_fully_reversed');
        }

        return ['student' => (array) $student, 'invoice' => (array) $bill, 'report' => (array) $report,
            'payments' => $payments->map(fn (object $row) => (array) $row)->all()];
    }

    private function assertNativeContext(array $context): void
    {
        if (($context['actor'] ?? '') !== 'pop-pi-local' || !Str::isUuid($context['operation_id'] ?? '') || DB::transactionLevel() < 1) {
            throw new DomainException('native_atomic_operation_required');
        }
    }

    private function hash(array $state): string
    {
        return hash('sha256', json_encode(PopOperationService::canonicalParameters($state), JSON_THROW_ON_ERROR));
    }

    private function audit(array $p, string $request, bool $rollback): void
    {
        SecurityAuditEvent::append('billing.historical_orphan_closeout'.($rollback ? '.rollback' : ''), 'success',
            ['actor_type' => 'pop-runner', 'campus_id' => $p['campus_id'], 'subject_type' => 'invoice',
                'subject_id' => $p['invoice_id'], 'correlation_id' => $request],
            ['reason_code' => $p['decision_reference'], 'row_count' => 1], true);
    }
}
