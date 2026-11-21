<?php

namespace App\Operations\Strategies;

use App\Models\Payment;
use App\Models\SecurityAuditEvent;
use App\Operations\PopOperationService;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stop an exhausted historical package whose sole member has already been settled. */
final class SettledPackageRetirementStrategy
{
    public const OPERATION = 'reviewed-settled-package-retirement';
    public const KEYS = ['campus_id', 'package_id', 'course_id', 'invoice_id', 'decision_reference', 'expected_state_hash'];

    private const CASE_IDS = ['campus_id' => 9, 'package_id' => 61, 'course_id' => 1383, 'invoice_id' => 746];

    public function preview(): array
    {
        $parameters = self::CASE_IDS + ['decision_reference' => self::OPERATION, 'expected_state_hash' => str_repeat('0', 64)];
        try {
            $state = $this->readState($parameters, false);
            if ((int) $state['package']['stop'] !== 0) {
                throw new DomainException('package_not_active');
            }
            $parameters['expected_state_hash'] = $this->hash($state);

            return ['ok' => true, 'parameters' => $parameters];
        } catch (DomainException $error) {
            return ['ok' => false, 'errors' => [$error->getMessage()]];
        }
    }

    public function plan(array $parameters, array $context = []): array
    {
        try {
            $state = $this->readState($parameters, false);
            $hash = $this->hash($state);
            if ((int) $state['package']['stop'] !== 0 || !hash_equals($parameters['expected_state_hash'], $hash)) {
                throw new DomainException('package_state_changed');
            }

            return ['ok' => true, 'parameters' => $parameters, 'digest' => $hash];
        } catch (DomainException $error) {
            return ['ok' => false, 'errors' => [$error->getMessage()]];
        }
    }

    public function execute(array $plan, array $context): array
    {
        $operation = $this->nativeOperationId($context);
        if (($plan['ok'] ?? false) !== true) {
            throw new DomainException('plan_not_ready');
        }
        $parameters = $plan['parameters'];
        $before = $this->readState($parameters, true);
        if ((int) $before['package']['stop'] !== 0
            || !hash_equals($parameters['expected_state_hash'], $this->hash($before))
            || !hash_equals($parameters['expected_state_hash'], $plan['digest'])) {
            throw new DomainException('package_state_changed');
        }

        $this->setStop(0, 1);
        $expected = $before;
        $expected['package']['stop'] = 1;
        $afterHash = $this->hash($this->readState($parameters, true));
        if (!hash_equals($this->hash($expected), $afterHash)) {
            throw new DomainException('unexpected_package_accounting_change');
        }
        $this->audit($operation, false);

        return ['ok' => true, 'updated' => 1, 'snapshot' => [
            'parameters' => $parameters, 'after_hash' => $afterHash,
        ]];
    }

    public function verify(array $plan, array $result): array
    {
        try {
            $snapshot = $result['snapshot'] ?? [];
            if (!isset($snapshot['parameters'], $snapshot['after_hash'])) {
                throw new DomainException('retirement_snapshot_missing');
            }
            $state = $this->readState($snapshot['parameters'], true);
            if ((int) $state['package']['stop'] !== 1 || !hash_equals($snapshot['after_hash'], $this->hash($state))) {
                throw new DomainException('retirement_state_not_verified');
            }

            return ['ok' => true, 'checks' => ['one_package_stopped', 'member_lessons_and_money_unchanged']];
        } catch (DomainException $error) {
            return ['ok' => false, 'errors' => [$error->getMessage()]];
        }
    }

    public function rollback(array $snapshot, array $context): array
    {
        $operation = $this->nativeOperationId($context);
        $parameters = $snapshot['parameters'];
        $state = $this->readState($parameters, true);
        if ((int) $state['package']['stop'] !== 1 || !hash_equals($snapshot['after_hash'], $this->hash($state))) {
            throw new DomainException('package_state_changed_after_retirement');
        }

        $this->setStop(1, 0);
        if (!hash_equals($parameters['expected_state_hash'], $this->hash($this->readState($parameters, true)))) {
            throw new DomainException('retirement_restore_mismatch');
        }
        $this->audit($operation, true);

        return ['ok' => true, 'restored' => 1];
    }

    private function readState(array $parameters, bool $lock): array
    {
        if (array_diff(self::KEYS, array_keys($parameters)) || array_diff(array_keys($parameters), self::KEYS)
            || $parameters['decision_reference'] !== self::OPERATION
            || !is_string($parameters['expected_state_hash']) || !preg_match('/\A[0-9a-f]{64}\z/', $parameters['expected_state_hash'])) {
            throw new DomainException('invalid_retirement_parameters');
        }
        foreach (self::CASE_IDS as $key => $id) {
            if ($parameters[$key] !== $id) {
                throw new DomainException('retirement_target_not_reviewed');
            }
        }

        $rows = fn (Builder $query, int $limit) => $query->when($lock, fn (Builder $q) => $q->lockForUpdate())->limit($limit)->get();
        $package = $rows(DB::table('course_packages')->where('id', $parameters['package_id'])->where('campus_id', $parameters['campus_id'])
            ->select('id', 'campus_id', 'student_id', 'billing_mode', 'stop', 'closed_reason', 'paid', 'paid_at',
                'total_sessions', 'used_sessions', 'remaining_sessions', 'updated_at'), 1)->first();
        if (!$package || $package->billing_mode !== 'count' || (int) $package->paid !== 1 || $package->remaining_sessions === null || (int) $package->remaining_sessions !== 0) {
            throw new DomainException('package_not_exhausted_and_paid');
        }

        $members = $rows(DB::table('StudentClass')->where('PackageID', $package->id)->orderBy('ID')
            ->select('ID', 'StudentID', 'PackageID', 'Stop', 'closed_reason', 'Charge', 'Paid', 'PayDate', 'RemainingSessions', 'MDate'), 2);
        $member = $members->first();
        if ($members->count() !== 1 || (int) $member->ID !== $parameters['course_id'] || (int) $member->StudentID !== (int) $package->student_id
            || (int) $member->Stop !== 1 || $member->closed_reason !== 'settled') {
            throw new DomainException('sole_member_not_settled');
        }
        $student = $rows(DB::table('Student')->where('id', $package->student_id)->where('CampusID', $package->campus_id)->select('id', 'CampusID'), 1)->first();
        if (!$student) {
            throw new DomainException('package_student_campus_mismatch');
        }

        $ledger = $rows(DB::table('package_session_ledger')->where('package_id', $package->id)->orderBy('id')
            ->select('id', 'package_id', 'student_class_id', 'class_session_id', 'delta', 'updated_at'), 100);
        // The ledger is authoritative. Require exact exhaustion, including agreement with the stored counter.
        $total = (int) $package->total_sessions;
        if ($total <= 0 || $ledger->isEmpty() || $ledger->count() >= 100
            || $ledger->contains(fn (object $entry) => (int) $entry->student_class_id !== (int) $member->ID)
            || (int) $ledger->sum('delta') !== -$total || (int) $package->used_sessions !== $total) {
            throw new DomainException('package_ledger_not_exactly_exhausted');
        }

        $sessions = $rows(DB::table('ClassSession')->where('StudentClassID', $member->ID)->orderBy('id')
            ->select('id', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'updated_at'), 100);
        if ($sessions->isEmpty() || $sessions->count() >= 100
            || $sessions->contains(fn (object $session) => !in_array($session->Status, ['attended', 'completed', 'late', 'cancelled'], true))) {
            throw new DomainException('package_has_open_lessons');
        }

        $invoices = $rows(DB::table('Invoice')->where('StudentClassID', $member->ID)->orderBy('id')
            ->select('id', 'StudentID', 'StudentClassID', 'TotalAmount', 'PaidAmount', 'Status', 'IssueDate', 'DueDate', 'billing_period', 'updated_at'), 2);
        $invoice = $invoices->first();
        if ($invoices->count() !== 1 || (int) $invoice->id !== $parameters['invoice_id']
            || (int) $invoice->StudentID !== (int) $student->id || $invoice->Status !== 'paid'
            || (int) $invoice->TotalAmount <= 0 || (int) $invoice->PaidAmount !== (int) $invoice->TotalAmount) {
            throw new DomainException('package_invoice_not_paid');
        }

        $items = $rows(DB::table('InvoiceItem')->where(fn (Builder $q) => $q->where('InvoiceID', $invoice->id)->orWhere('StudentClassID', $member->ID))
            ->orderBy('id')->select('id', 'InvoiceID', 'StudentClassID', 'Amount', 'updated_at'), 100);
        if ($items->count() >= 100 || $items->contains(fn (object $item) => (int) $item->InvoiceID !== (int) $invoice->id
            || $item->StudentClassID !== null && (int) $item->StudentClassID !== (int) $member->ID)) {
            throw new DomainException('package_invoice_has_other_coverage');
        }

        $reports = $rows(DB::table('payment_reports')->where(fn (Builder $q) => $q->where('InvoiceID', $invoice->id)->orWhere('StudentClassID', $member->ID))
            ->orderBy('id')->select('id', 'StudentID', 'StudentClassID', 'InvoiceID', 'status', 'reported_amount', 'payment_id', 'payment_date', 'voided_at', 'updated_at'), 2);
        $report = $reports->first();
        if ($reports->count() !== 1 || $report->status !== 'confirmed' || $report->voided_at !== null
            || (int) $report->StudentID !== (int) $student->id || (int) $report->StudentClassID !== (int) $member->ID
            || (int) $report->InvoiceID !== (int) $invoice->id || (float) $report->reported_amount !== (float) $invoice->TotalAmount) {
            throw new DomainException('package_receipt_not_confirmed');
        }
        $payments = $rows(DB::table('Payment')->where(fn (Builder $q) => $q->where('InvoiceID', $invoice->id)->orWhere('payment_report_id', $report->id))
            ->orderBy('id')->select('id', 'InvoiceID', 'Amount', 'Method', 'PaidAt', 'payment_report_id', 'updated_at'), 2);
        $payment = $payments->first();
        if ($payments->count() !== 1 || (int) $payment->InvoiceID !== (int) $invoice->id
            || !Payment::isReceiptOf($payment, (int) $report->id, (int) $report->payment_id)
            || !in_array($payment->Method, ['cash', 'transfer', 'bank_transfer', 'card', 'line_pay', 'other'], true)
            || (int) $payment->Amount !== (int) $invoice->TotalAmount) {
            throw new DomainException('package_receipt_ledger_not_settled');
        }

        return ['student' => (array) $student, 'package' => [...(array) $package, 'stop' => (int) $package->stop],
            'member' => (array) $member, 'ledger' => $ledger->map(fn (object $row) => (array) $row)->all(),
            'sessions' => $sessions->map(fn (object $row) => (array) $row)->all(),
            'invoice' => (array) $invoice, 'items' => $items->map(fn (object $row) => (array) $row)->all(),
            'report' => (array) $report, 'payment' => (array) $payment];
    }

    private function setStop(int $expected, int $desired): void
    {
        $updated = DB::table('course_packages')->where('id', self::CASE_IDS['package_id'])->where('campus_id', self::CASE_IDS['campus_id'])
            ->where('stop', $expected)->update(['stop' => $desired]);
        if ($updated !== 1) {
            throw new DomainException('package_stop_update_conflict');
        }
    }

    private function nativeOperationId(array $context): string
    {
        $operation = $context['operation_id'] ?? '';
        if (($context['actor'] ?? '') !== 'pop-pi-local' || (!is_string($operation) || !Str::isUuid($operation)) || DB::transactionLevel() < 1) {
            throw new DomainException('native_atomic_operation_required');
        }

        return $operation;
    }

    private function hash(array $state): string
    {
        return hash('sha256', json_encode(PopOperationService::canonicalParameters($state), JSON_THROW_ON_ERROR));
    }

    private function audit(string $operation, bool $rollback): void
    {
        SecurityAuditEvent::append('course_package.settled_retirement'.($rollback ? '.rollback' : ''), 'success',
            ['actor_type' => 'pop-runner', 'campus_id' => self::CASE_IDS['campus_id'], 'subject_type' => 'course_package',
                'subject_id' => self::CASE_IDS['package_id'], 'correlation_id' => $operation],
            ['reason_code' => self::OPERATION, 'row_count' => 1], true);
    }
}
