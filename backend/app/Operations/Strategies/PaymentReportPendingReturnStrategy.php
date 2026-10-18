<?php

namespace App\Operations\Strategies;

use App\Models\SecurityAuditEvent;
use App\Operations\PopOperationService;
use App\Services\Billing\ContractMoneyState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Planned POP operation: return a wrong pending amount; no receipt, refund or invoice mutation. */
final class PaymentReportPendingReturnStrategy
{
    public const OPERATION = 'reviewed-mismatched-pending-return';

    public function preview(array $p): string
    {
        $g = $this->capture($p, false); $this->eligible($g, $p);
        return $this->hash($g);
    }

    public function plan(array $p): array
    {
        try {
            $proof = DB::table('pop_execution_records')->where('id', $this->proofId($p, 'pending-return-proof'))->first();
            if ($proof) {
                if ($this->proof($proof->operation_id, 'pending-return-rollback')) $this->fail('reapply_requires_new_preview');
            } else {
                $hash = $this->preview($p);
                if (($p['expected_state_hash'] ?? '') === '') {
                    $p['expected_state_hash'] = $hash;
                    return ['ok' => false, 'errors' => ['preview_confirmation_required'], 'preview_parameters' => $p];
                }
                if (!hash_equals($p['expected_state_hash'] ?? '', $hash)) $this->fail('state_changed');
            }
            return ['ok' => true, 'parameters' => $p];
        } catch (Throwable) { return ['ok' => false, 'errors' => ['pending_return_precondition_failed']]; }
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) !== true) $this->fail('plan_not_ready');
        return DB::transaction(function () use ($plan, $context): array {
            $p = $plan['parameters']; $r = $this->request($p, $context);
            if ($this->proof($r->id, 'pending-return-rollback')) $this->fail('reapply_requires_new_preview');
            if ($prior = $this->proof($r->id, 'pending-return-proof')) return ['ok' => true, 'already_applied' => true,
                'snapshot' => json_decode($prior->snapshot, true, 512, JSON_THROW_ON_ERROR)];
            $before = $this->capture($p, true); $this->eligible($before, $p);
            if (!hash_equals($p['expected_state_hash'], $this->hash($before))) $this->fail('state_changed');
            DB::table('payment_reports')->where('id', $p['report_id'])->update(['status' => 'rejected',
                'confirmed_by' => (int) substr($r->actor, 5), 'confirmed_at' => now(), 'updated_at' => now(), 'rejection_note' => $p['reason']]);
            $s = ['parameters' => $p, 'before' => $before, 'after_hash' => $this->hash($this->capture($p, true))];
            $this->persist($r->id, 'pending-return-proof', $context, $s); $this->audit($p, $r->id, false);
            return ['ok' => true, 'snapshot' => $s];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $s = $result['snapshot'];
        return ['ok' => hash_equals($s['after_hash'], $this->hash($this->capture($s['parameters'], false)))];
    }

    public function rollback(array $s, array $context): array
    {
        return DB::transaction(function () use ($s, $context): array {
            $p = $s['parameters']; $r = $this->request($p, $context); $proof = $this->proof($r->id, 'pending-return-proof');
            if (!$proof || !hash_equals($this->hash(json_decode($proof->snapshot, true, 512, JSON_THROW_ON_ERROR)), $this->hash($s))) $this->fail('history_mismatch');
            if ($this->proof($r->id, 'pending-return-rollback')) return ['ok' => true, 'already_rolled_back' => true];
            if (!hash_equals($s['after_hash'], $this->hash($this->capture($p, true)))) $this->fail('values_moved_on');
            $report = collect($s['before']['reports'])->firstWhere('id', $p['report_id']);
            DB::table('payment_reports')->where('id', $p['report_id'])->update(array_intersect_key($report,
                array_flip(['status', 'confirmed_by', 'confirmed_at', 'rejection_note', 'updated_at'])));
            if (!hash_equals($p['expected_state_hash'], $this->hash($this->capture($p, true)))) $this->fail('restore_mismatch');
            $this->persist($r->id, 'pending-return-rollback', $context, $s); $this->audit($p, $r->id, true);
            return ['ok' => true];
        }, 3);
    }

    private function capture(array $p, bool $lock): array
    {
        foreach (['campus_id', 'student_id', 'course_id', 'invoice_id', 'report_id', 'expected_reported_amount', 'expected_charge', 'expected_invoice_total'] as $k)
            if (!isset($p[$k]) || !is_int($p[$k]) || $p[$k] <= 0) $this->fail('invalid_parameters');
        if (!is_string($p['reason'] ?? null) || trim($p['reason']) === '' || mb_strlen($p['reason']) > 500
            || !preg_match('/\A[A-Za-z0-9:_-]{3,80}\z/', $p['decision_reference'] ?? '')) $this->fail('invalid_reason');
        $one = fn ($q) => (array) $q->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $c = $one(DB::table('StudentClass')->where('ID', $p['course_id']));
        $student = $one(DB::table('Student')->where('id', $p['student_id'])->select('id', 'CampusID'));
        if ((int) ($c['StudentID'] ?? 0) !== $p['student_id'] || (int) ($student['CampusID'] ?? 0) !== $p['campus_id']) $this->fail('owner_mismatch');
        $rows = function ($q) use ($lock): array {
            $r = $q->when($lock, fn ($q) => $q->lockForUpdate())->limit(101)->get();
            if ($r->count() > 100) $this->fail('row_budget');
            return $r->map(fn ($r) => (array) $r)->all();
        };
        $invoices = $rows(DB::table('Invoice')->where('StudentClassID', $p['course_id'])->orderBy('id'));
        if (count($invoices) !== 1 || (int) $invoices[0]['id'] !== $p['invoice_id'] || (int) $invoices[0]['StudentID'] !== $p['student_id']) $this->fail('invoice_scope');
        $items = $rows(DB::table('InvoiceItem')->where(fn ($q) => $q->where('InvoiceID', $p['invoice_id'])->orWhere('StudentClassID', $p['course_id']))->orderBy('id'));
        $payments = $rows(DB::table('Payment')->where('InvoiceID', $p['invoice_id'])->orderBy('id'));
        $reports = $rows(DB::table('payment_reports')->where(fn ($q) => $q->where('InvoiceID', $p['invoice_id'])->orWhere('StudentClassID', $p['course_id']))->orderBy('id'));
        foreach ($reports as $r) if ((int) $r['StudentID'] !== $p['student_id'] || (int) $r['StudentClassID'] !== $p['course_id'] || (int) $r['InvoiceID'] !== $p['invoice_id']) $this->fail('report_owner');
        $epoch = DB::table('pop_execution_records')->where('phase', 'pending-return-rollback')->where('snapshot->parameters->report_id', $p['report_id'])->orderBy('id')->limit(101)->pluck('id')->all();
        if (count($epoch) > 100) $this->fail('history_budget');
        return ['student' => $student, 'course' => $c, 'invoice' => $invoices[0], 'items' => $items, 'payments' => $payments, 'reports' => $reports, 'rollback_epoch' => $epoch];
    }

    private function eligible(array $g, array $p): void
    {
        $c = $g['course']; $i = $g['invoice']; $target = collect($g['reports'])->firstWhere('id', $p['report_id']);
        if ($g['items'] !== [] || !$target || $target['status'] !== 'pending' || $target['payment_id'] !== null
            || !preg_match('/\A([1-9][0-9]*)(?:\.0+)?\z/', (string) $target['reported_amount'], $m)
            || (int) $m[1] !== $p['expected_reported_amount'] || $p['expected_reported_amount'] === $p['expected_charge']
            || (int) $c['Charge'] !== $p['expected_charge'] || $c['ScheduleMode'] !== 'count' || (int) ($c['PackageID'] ?? 0) > 0
            || (int) $c['Paid'] !== 0 || (int) $c['Pay'] !== 0 || !empty($c['PayDate']) || (int) $c['Stop'] !== 0
            || $i['Status'] !== 'unpaid' || (int) $i['PaidAmount'] !== 0 || (int) $i['TotalAmount'] !== $p['expected_invoice_total']) $this->fail('unsupported_state');
        foreach ($g['reports'] as $r) if ((int) $r['id'] !== $p['report_id'] && !in_array($r['status'], ['voided', 'rejected'], true)) $this->fail('standing_report');
        if (array_sum(array_column($g['payments'], 'Amount')) !== 0 || ContractMoneyState::blocksUnpaidCorrection($p['course_id'], true)) $this->fail('money_evidence');
    }

    private function request(array $p, array $context): object
    {
        if (!Str::isUuid($context['operation_id'] ?? '') || !is_string($context['actor'] ?? null) || $context['actor'] === '') $this->fail('invalid_context');
        $r = DB::table('pop_operation_requests')->where('id', $context['operation_id'])->lockForUpdate()->first();
        if (!$r || $r->operation_id !== self::OPERATION || $r->strategy_id !== self::class || !preg_match('/\Auser:([1-9][0-9]*)\z/', $r->actor, $m)
            || !DB::table('User')->where('id', (int) $m[1])->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['inactive', 'suspended']))->exists()
            || !hash_equals($this->hash(json_decode($r->parameters, true, 512, JSON_THROW_ON_ERROR)), $this->hash($p))) $this->fail('request_mismatch');
        return $r;
    }

    private function proof(string $id, string $phase): ?object
    {
        return DB::table('pop_execution_records')->where('attempt_key', $id.':'.$phase)->first();
    }
    private function proofId(array $p, string $phase): string
    {
        return \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_OID, self::OPERATION.':'.$phase.':'.$this->hash($p))->toString();
    }
    private function persist(string $operationId, string $phase, array $context, array $s): void
    {
        $id = $this->proofId($s['parameters'], $phase);
        DB::table('pop_execution_records')->insert(['id' => $id, 'execution_id' => $id, 'operation_id' => $operationId, 'phase' => $phase,
            'result' => 'succeeded', 'idempotency_key' => $operationId.':'.$phase, 'attempt_key' => $operationId.':'.$phase,
            'correlation_id' => $operationId, 'snapshot' => json_encode($s, JSON_THROW_ON_ERROR),
            'payload' => json_encode(['kind' => self::OPERATION]), 'actor' => substr($context['actor'], 0, 128), 'created_at' => now()]);
    }
    private function audit(array $p, string $id, bool $rollback): void
    {
        SecurityAuditEvent::append('billing.mismatched_pending_return'.($rollback ? '.rollback' : ''), 'success',
            ['actor_type' => 'pop-runner', 'campus_id' => $p['campus_id'], 'subject_type' => 'payment_report', 'subject_id' => $p['report_id'], 'correlation_id' => $id],
            ['reason_code' => $p['decision_reference'], 'row_count' => 1], true);
    }
    private function hash(array $data): string { return hash('sha256', json_encode(PopOperationService::canonicalParameters($data), JSON_THROW_ON_ERROR)); }
    private function fail(string $code): never { throw new RuntimeException('pending_return:'.$code); }
}
