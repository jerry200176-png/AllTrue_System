<?php

namespace App\Services\Billing;

use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Internal preparation only; exact POP adapter/registration and protected approval remain required. */
final class PaymentReversalRestatement
{
    public const OPERATION = 'reviewed-legacy-reversal-restatement';

    public function plan(array $scope, array $actor): array
    {
        $graph = $this->capture($scope, $actor, false);
        $target = $this->proveExcess($graph, $scope);
        return ['scope' => $scope, 'state_hash' => $this->hash($graph),
            'actor_hash' => $this->hash($actor), 'before_payment' => $target,
            'raw_net_before' => (int) $target['Amount'], 'raw_net_after' => 0];
    }

    public function requestParameters(array $plan, string $decision): array
    {
        return ['scope' => $plan['scope'], 'actor_hash' => $plan['actor_hash'],
            'plan_hash' => $this->hash($plan), 'decision_reference' => $decision];
    }

    public function execute(array $plan, array $actor, string $decision, string $operationId): array
    {
        if (!preg_match('/\A[A-Za-z0-9:_-]{3,80}\z/', $decision) || !Str::isUuid($operationId)
            || !preg_match('/\A[a-f0-9]{64}\z/', $plan['state_hash'] ?? '')
            || ($plan['actor_hash'] ?? '') !== $this->hash($actor)) $this->fail('invalid_manifest');
        return DB::transaction(function () use ($plan, $actor, $decision, $operationId) {
            $this->request($operationId, $this->requestParameters($plan, $decision));
            $scope = $plan['scope']; $this->authorize($scope, $actor, true);
            $prior = $this->proof($operationId, 'reversal-proof');
            if ($this->proof($operationId, 'reversal-proof-rollback')) $this->fail('reapply_requires_new_decision');
            if ($prior) return ['state' => 'already_applied', 'restatement_id' => $prior->id];
            $graph = $this->capture($scope, $actor, true);
            if (!hash_equals($plan['state_hash'], $this->hash($graph))) $this->fail('state_changed');
            $before = $this->proveExcess($graph, $scope);
            if ($this->hash($plan['before_payment']) !== $this->hash($before) || $plan['raw_net_before'] !== (int) $before['Amount']
                || $plan['raw_net_after'] !== 0) $this->fail('plan_mismatch');
            DB::table('Payment')->where('id', $scope['payment_id'])->update([
                'Amount' => 0, 'Method' => 'other',
                'Note' => mb_substr('歷史沖銷更正 '.$decision.'；原沖銷 '.$before['Amount'].'，更正為非收款零額；'.$before['Note'], 0, 255),
                'updated_at' => now(),
            ]);
            $afterGraph = $this->capture($scope, $actor, true);
            $snapshot = ['payment_id' => $scope['payment_id'], 'scope' => $scope, 'decision_reference' => $decision,
                'request_parameters' => $this->requestParameters($plan, $decision), 'before_payment' => $before,
                'after_payment' => $this->payment($afterGraph, $scope['payment_id']),
                'before_state_hash' => $plan['state_hash'], 'after_state_hash' => $this->hash($afterGraph)];
            $id = $this->persistProof($operationId, 'reversal-proof', $actor, $snapshot);
            $this->audit($scope, $actor, $operationId, 'billing.legacy_reversal_restated', $decision);
            return ['state' => 'applied', 'restatement_id' => $id];
        }, 3);
    }

    public function rollback(string $id, array $actor): array
    {
        return DB::transaction(function () use ($id, $actor) {
            $record = DB::table('pop_execution_records')->where('id', $id)->where('phase', 'reversal-proof')->first();
            if (!$record) $this->fail('history_missing');
            $s = json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $this->request($record->operation_id, $s['request_parameters']);
            $scope = $s['scope']; $this->authorize($scope, $actor, true);
            if ($this->proof($record->operation_id, 'reversal-proof-rollback')) return ['state' => 'already_rolled_back'];
            $graph = $this->capture($scope, $actor, true);
            if (!hash_equals($s['after_state_hash'], $this->hash($graph))) $this->fail('values_moved_on');
            $before = $s['before_payment']; $restored = $graph;
            foreach ($restored['payments'] as &$row) if ((int) $row['id'] === (int) $scope['payment_id']) $row = $before;
            unset($row);
            if (!hash_equals($s['before_state_hash'], $this->hash($restored))) $this->fail('history_mismatch');
            $this->proveExcess($restored, $scope);
            DB::table('Payment')->where('id', $scope['payment_id'])->update(array_intersect_key($before,
                array_flip(['Amount', 'Method', 'Note', 'updated_at'])));
            $this->persistProof($record->operation_id, 'reversal-proof-rollback', $actor, ['original_proof_id' => $id]);
            $this->audit($scope, $actor, $record->operation_id, 'billing.legacy_reversal_rollback', $s['decision_reference']);
            return ['state' => 'rolled_back'];
        }, 3);
    }

    private function request(string $id, array $parameters): void
    {
        $r = DB::table('pop_operation_requests')->where('id', $id)->lockForUpdate()->first();
        if (!$r || $r->operation_id !== self::OPERATION
            || !hash_equals($this->hash(json_decode($r->parameters, true, 512, JSON_THROW_ON_ERROR)), $this->hash($parameters))) $this->fail('request_mismatch');
        // The future registered POP adapter owns approval validation; this primitive grants none.
    }

    private function proof(string $id, string $phase): ?object
    {
        return DB::table('pop_execution_records')->where('attempt_key', $id.':'.$phase)->first();
    }

    private function persistProof(string $operationId, string $phase, array $actor, array $snapshot): string
    {
        $id = (string) Str::uuid();
        DB::table('pop_execution_records')->insert(['id' => $id, 'execution_id' => $id, 'operation_id' => $operationId,
            'phase' => $phase, 'result' => 'succeeded', 'idempotency_key' => $operationId.':'.$phase,
            'attempt_key' => $operationId.':'.$phase, 'correlation_id' => $operationId,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'payload' => json_encode(['kind' => self::OPERATION]),
            'actor' => 'user:'.$actor['id'], 'created_at' => now()]);
        return $id;
    }

    private function authorize(array $s, array $actor, bool $lock): array
    {
        if (count($s) !== 5) $this->fail('invalid_scope');
        foreach (['student_id', 'course_id', 'campus_id', 'invoice_id', 'payment_id'] as $key) {
            if (!isset($s[$key]) || !is_int($s[$key]) || $s[$key] <= 0) $this->fail('invalid_scope');
        }
        if (!isset($actor['id']) || !is_int($actor['id']) || $actor['id'] <= 0 || ($actor['student_id'] ?? 0) !== $s['student_id']
            || !in_array($actor['role'] ?? '', ['director', 'super_admin'], true)
            || ($actor['role'] !== 'super_admin' && !in_array($s['campus_id'], $actor['campus_ids'] ?? [], true))) $this->fail('forbidden');
        $course = DB::table('StudentClass')->where('ID', $s['course_id'])->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $student = DB::table('Student')->where('id', $s['student_id'])->when($lock, fn ($q) => $q->lockForUpdate())->first(['id', 'CampusID']);
        if (!$student || (int) $student->CampusID !== $s['campus_id'] || !$course || (int) $course->StudentID !== $s['student_id']) $this->fail('owner_mismatch');
        return (array) $course;
    }

    private function capture(array $s, array $actor, bool $lock): array
    {
        $course = $this->authorize($s, $actor, $lock);
        $rows = function ($query) use ($lock): array {
            $rows = $query->when($lock, fn ($q) => $q->lockForUpdate())->limit(101)->get();
            if ($rows->count() > 100) $this->fail('row_budget');
            return $rows->map(fn ($r) => (array) $r)->all();
        };
        $invoices = $rows(DB::table('Invoice')->where('StudentClassID', $s['course_id'])->orderBy('id'));
        if (count($invoices) !== 1 || (int) $invoices[0]['id'] !== $s['invoice_id']
            || (int) $invoices[0]['StudentID'] !== $s['student_id']) $this->fail('invoice_scope_mismatch');
        $items = $rows(DB::table('InvoiceItem')->where(fn ($q) => $q->where('StudentClassID', $s['course_id'])->orWhere('InvoiceID', $s['invoice_id']))->orderBy('id'));
        if ($items !== []) $this->fail('unsupported_items');
        $payments = $rows(DB::table('Payment')->where('InvoiceID', $s['invoice_id'])->orderBy('id'));
        $reports = $rows(DB::table('payment_reports')->where(fn ($q) => $q->where('StudentClassID', $s['course_id'])->orWhere('InvoiceID', $s['invoice_id']))->orderBy('id'));
        foreach ($reports as $r) if ((int) $r['StudentID'] !== $s['student_id'] || (int) $r['StudentClassID'] !== $s['course_id'] || (int) $r['InvoiceID'] !== $s['invoice_id']) $this->fail('report_owner_mismatch');
        return ['course' => (array) $course, 'invoices' => $invoices, 'payments' => $payments, 'reports' => $reports];
    }

    private function proveExcess(array $g, array $s): array
    {
        $invoice = $g['invoices'][0]; $course = $g['course'];
        if ($invoice['Status'] !== 'unpaid' || (int) $invoice['PaidAmount'] !== 0 || (int) $invoice['TotalAmount'] <= 0
            || (int) $course['Paid'] !== 0 || (int) $course['Pay'] !== 0 || !empty($course['PayDate'])) $this->fail('money_state_mismatch');
        $target = $this->payment($g, $s['payment_id']);
        if (!$target || (int) $target['Amount'] >= 0 || $target['Method'] !== 'void'
            || $target['payment_report_id'] !== null || ($target['receipt_url'] !== null && trim((string) $target['receipt_url']) !== '')) $this->fail('not_unlinked_reversal');
        $used = []; $sameAmountReceipts = 0;
        foreach ($g['reports'] as $r) {
            if (in_array($r['status'], ['pending', 'rejected'], true) && $r['payment_id'] === null) continue;
            if ($r['status'] !== 'voided') $this->fail('standing_report');
            $linked = array_values(array_filter($g['payments'], fn ($p) => (int) $p['payment_report_id'] === (int) $r['id']));
            $receipt = $this->payment($g, (int) $r['payment_id']);
            $voids = array_values(array_filter($linked, fn ($p) => (int) $p['Amount'] < 0 || $p['Method'] === 'void'));
            if (count($linked) !== 2 || count($voids) !== 1 || !$receipt || (int) $receipt['Amount'] <= 0 || $receipt['Method'] === 'void'
                || (int) $receipt['payment_report_id'] !== (int) $r['id'] || $voids[0]['Method'] !== 'void'
                || (int) $voids[0]['Amount'] !== -(int) $receipt['Amount']
                || !preg_match('/\A([1-9][0-9]*)(?:\.0+)?\z/', (string) $r['reported_amount'], $m)
                || (int) $m[1] !== (int) $receipt['Amount']) $this->fail('ambiguous_void_history');
            foreach ($linked as $p) $used[(int) $p['id']] = true;
            if ((int) $receipt['Amount'] === abs((int) $target['Amount']) && (int) $receipt['id'] < (int) $target['id']) ++$sameAmountReceipts;
        }
        if ($sameAmountReceipts !== 1 || count($used) !== count($g['payments']) - 1 || isset($used[$s['payment_id']])) $this->fail('excess_not_unique');
        return $target;
    }

    private function payment(array $g, int $id): ?array
    {
        foreach ($g['payments'] as $p) if ((int) $p['id'] === $id) return $p;
        return null;
    }

    private function audit(array $scope, array $actor, string $uuid, string $event, string $decision): void
    {
        SecurityAuditEvent::append($event, 'success', ['campus_id' => $scope['campus_id'], 'actor_type' => 'user',
            'actor_id' => $actor['id'], 'subject_type' => 'payment', 'subject_id' => $scope['payment_id'], 'correlation_id' => $uuid],
            ['reason_code' => $decision, 'row_count' => 1], true);
    }

    private function hash(array $value): string
    {
        // Match the existing monthly-correction convention: JSON object order is not identity.
        $canonical = function (array $data) use (&$canonical): array {
            if (!array_is_list($data)) ksort($data);
            foreach ($data as &$item) if (is_array($item)) $item = $canonical($item);
            return $data;
        };
        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }
    private function fail(string $code): never { throw new RuntimeException('payment_restatement:'.$code); }
}
