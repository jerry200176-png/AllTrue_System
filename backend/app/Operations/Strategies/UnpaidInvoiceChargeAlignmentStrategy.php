<?php

namespace App\Operations\Strategies;

use App\Models\SecurityAuditEvent;
use App\Operations\PopOperationService;
use App\Services\Billing\ContractMoneyState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Preparation only: deliberately absent from the POP catalog and public routes. */
final class UnpaidInvoiceChargeAlignmentStrategy
{
    public const STRATEGY = self::class;

    /** Operator preview only; the resulting hash must be bound into the POP request before approval. */
    public function preview(array $parameters): array
    {
        $graph = $this->capture($parameters, false);
        $this->eligible($graph, $parameters);
        return ['state_hash' => $this->hash($graph), 'old_total' => (int) $graph['invoice']['TotalAmount'],
            'new_total' => (int) $graph['course']['Charge']];
    }

    public function plan(array $parameters, array $context = []): array
    {
        try {
            // A process may stop after our atomic commit but before POP records phase=execute.
            // Keep replay reachable through POP's planning gate using the retained proof.
            $proof = DB::table('pop_execution_records')->where('id', $this->proofId($parameters, 'repair-proof'))
                ->where('phase', 'repair-proof')->first();
            if ($proof) {
                $snapshot = json_decode($proof->snapshot, true, 512, JSON_THROW_ON_ERROR);
                if ($this->proof($proof->operation_id, 'repair-proof-rollback')
                    || !hash_equals($this->hash($snapshot['parameters']), $this->hash($parameters)) || (isset($context['operation_id']) && $proof->operation_id !== $context['operation_id'])) $this->fail('history_mismatch');
                return ['ok' => true, 'parameters' => $parameters, 'digest' => $parameters['expected_state_hash'], 'recoverable_proof' => $proof->id];
            }
            $preview = $this->preview($parameters);
            if (($parameters['expected_state_hash'] ?? '') === '') {
                $parameters['expected_state_hash'] = $preview['state_hash'];
                return ['ok' => false, 'errors' => ['preview_confirmation_required'], 'preview_parameters' => $parameters];
            }
            if (!hash_equals($preview['state_hash'], (string) ($parameters['expected_state_hash'] ?? ''))) $this->fail('state_changed');
            return ['ok' => true, 'parameters' => $parameters, 'digest' => $preview['state_hash']];
        } catch (RuntimeException) {
            return ['ok' => false, 'errors' => ['invoice_alignment_precondition_failed']];
        }
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) !== true) $this->fail('plan_not_ready');
        return DB::transaction(function () use ($plan, $context): array {
            $p = $plan['parameters'];
            $request = $this->request($context, $p);
            $prior = $this->proof($request->id, 'repair-proof');
            if ($prior) {
                if ($this->proof($request->id, 'repair-proof-rollback')) $this->fail('reapply_requires_new_request');
                $snapshot = json_decode($prior->snapshot, true, 512, JSON_THROW_ON_ERROR);
                $course = DB::table('StudentClass')->where('ID', $p['course_id'])->lockForUpdate()->first();
                $student = DB::table('Student')->where('id', $p['student_id'])->lockForUpdate()->first();
                $invoice = DB::table('Invoice')->where('id', $p['invoice_id'])->lockForUpdate()->first();
                if ((int) ($course->StudentID ?? 0) !== $p['student_id'] || (int) ($student->CampusID ?? 0) !== $p['campus_id']
                    || (int) ($invoice->StudentID ?? 0) !== $p['student_id'] || (int) ($invoice->StudentClassID ?? 0) !== $p['course_id']
                    || !isset($snapshot['after_invoice']) || !hash_equals($this->hash($snapshot['after_invoice']), $this->hash((array) $invoice))) $this->fail('target_moved_on');
                return ['ok' => true, 'already_applied' => true, 'updated' => 0, 'snapshot' => $snapshot];
            }
            $before = $this->capture($p, true);
            $this->eligible($before, $p);
            if (!hash_equals($this->hash($before), $p['expected_state_hash'])
                || !hash_equals($plan['digest'], $p['expected_state_hash'])) $this->fail('state_changed');
            $updated = DB::table('Invoice')->where('id', $p['invoice_id'])->where('TotalAmount', $p['expected_invoice_total'])
                ->update(['TotalAmount' => $p['expected_charge'], 'updated_at' => now()]);
            if ($updated !== 1) $this->fail('update_failed');
            $after = $this->capture($p, true);
            $snapshot = ['parameters' => $p, 'before' => $before, 'after_hash' => $this->hash($after), 'after_invoice' => $after['invoice']];
            $this->persistProof($request, $context, 'repair-proof', $snapshot);
            $this->audit($p, $request->id, 'billing.invoice_charge_alignment');
            return ['ok' => true, 'updated' => 1, 'snapshot' => $snapshot];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $s = $result['snapshot'] ?? [];
        $ok = ($result['ok'] ?? false) && isset($s['parameters'], $s['after_hash'])
            && hash_equals($s['after_hash'], $this->hash($this->capture($s['parameters'], false)));
        return ['ok' => (bool) $ok, 'checks' => ['invoice_total_aligned', 'course_payments_reports_unchanged']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        return DB::transaction(function () use ($snapshot, $context): array {
            $p = $snapshot['parameters'] ?? [];
            $request = $this->request($context, $p);
            $proof = $this->proof($request->id, 'repair-proof');
            if (!$proof || !hash_equals($this->hash(json_decode($proof->snapshot, true, 512, JSON_THROW_ON_ERROR)), $this->hash($snapshot))) $this->fail('history_mismatch');
            if ($this->proof($request->id, 'repair-proof-rollback')) return ['ok' => true, 'already_rolled_back' => true, 'restored' => 0];
            if (!hash_equals($snapshot['after_hash'], $this->hash($this->capture($p, true)))) $this->fail('values_moved_on');
            $old = $snapshot['before']['invoice'];
            DB::table('Invoice')->where('id', $p['invoice_id'])->update(['TotalAmount' => $old['TotalAmount'], 'updated_at' => $old['updated_at']]);
            if (!hash_equals($p['expected_state_hash'], $this->hash($this->capture($p, true)))) $this->fail('restore_mismatch');
            $this->persistProof($request, $context, 'repair-proof-rollback', $snapshot);
            $this->audit($p, $request->id, 'billing.invoice_charge_alignment.rollback');
            return ['ok' => true, 'restored' => 1];
        }, 3);
    }

    private function capture(array $p, bool $lock): array
    {
        foreach (['student_id', 'course_id', 'campus_id', 'invoice_id', 'expected_invoice_total', 'expected_charge'] as $key)
            if (!isset($p[$key]) || !is_int($p[$key]) || $p[$key] <= 0) $this->fail('invalid_parameters');
        if (!preg_match('/\A[A-Za-z0-9:_-]{3,80}\z/', $p['decision_reference'] ?? '')) $this->fail('invalid_decision');
        $one = fn ($q) => (array) $q->when($lock, fn ($q) => $q->lockForUpdate())->first();
        // Match the course-first serialization used by normal payment writers.
        $course = $one(DB::table('StudentClass')->where('ID', $p['course_id']));
        $student = $one(DB::table('Student')->where('id', $p['student_id'])->select('id', 'CampusID'));
        if ((int) ($course['StudentID'] ?? 0) !== $p['student_id'] || (int) ($student['CampusID'] ?? 0) !== $p['campus_id']) $this->fail('owner_mismatch');
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
        $amendments = $rows(DB::table('student_class_pricing_amendments')->where('student_class_id', $p['course_id'])->orderBy('id'));
        $epoch = DB::table('pop_execution_records')->where('phase', 'repair-proof-rollback')
            ->where('snapshot->parameters->invoice_id', $p['invoice_id'])->orderBy('id')->limit(101)->pluck('id')->all();
        if (count($epoch) > 100) $this->fail('history_budget');
        return ['student' => $student, 'course' => $course, 'invoice' => $invoices[0], 'items' => $items, 'payments' => $payments, 'reports' => $reports, 'amendments' => $amendments, 'rollback_epoch' => $epoch];
    }

    private function eligible(array $g, array $p): void
    {
        $c = $g['course']; $i = $g['invoice'];
        if ($g['items'] !== [] || $g['amendments'] !== [] || $i['Status'] !== 'unpaid' || (int) $i['PaidAmount'] !== 0
            || (int) $i['TotalAmount'] !== $p['expected_invoice_total'] || (int) $c['Charge'] !== $p['expected_charge']
            || $p['expected_charge'] === $p['expected_invoice_total'] || (int) $c['Paid'] !== 0 || (int) $c['Pay'] !== 0
            || !empty($c['PayDate']) || (int) $c['Stop'] !== 0 || $c['ScheduleMode'] !== 'count'
            || (int) ($c['PackageID'] ?? 0) > 0 || strtolower(trim((string) $c['ClassType'])) === 'tutoring'
            || strtolower(trim((string) ($c['closed_reason'] ?? ''))) === 'waived'
            || !empty($i['zero_amount_reason'])) $this->fail('unsupported_state');
        foreach ($g['reports'] as $r) if (!in_array($r['status'], ['voided', 'rejected'], true)) $this->fail('standing_report');
        if (array_sum(array_column($g['payments'], 'Amount')) !== 0 || ContractMoneyState::blocksUnpaidCorrection($p['course_id'], true)) $this->fail('money_evidence');
    }

    private function request(array $context, array $p): object
    {
        if (!Str::isUuid($context['operation_id'] ?? '') || !is_string($context['actor'] ?? null) || trim($context['actor']) === '') $this->fail('invalid_context');
        $r = DB::table('pop_operation_requests')->where('id', $context['operation_id'])->lockForUpdate()->first();
        if (!$r || $r->strategy_id !== self::STRATEGY || !hash_equals($this->hash(json_decode($r->parameters, true, 512, JSON_THROW_ON_ERROR)), $this->hash($p))) $this->fail('request_mismatch');
        return $r; // POP owns approval validation; this unregistered strategy cannot grant approval.
    }

    private function proof(string $id, string $phase): ?object
    {
        return DB::table('pop_execution_records')->where('attempt_key', $id.':'.$phase)->first();
    }

    private function persistProof(object $request, array $context, string $phase, array $snapshot): void
    {
        $id = $this->proofId($snapshot['parameters'], $phase);
        DB::table('pop_execution_records')->insert(['id' => $id, 'execution_id' => $id, 'operation_id' => $request->id,
            'phase' => $phase, 'result' => 'succeeded', 'idempotency_key' => $request->id.':'.$phase,
            'attempt_key' => $request->id.':'.$phase, 'correlation_id' => (string) Str::uuid(),
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'payload' => json_encode(['kind' => self::STRATEGY], JSON_THROW_ON_ERROR),
            'actor' => substr($context['actor'], 0, 128), 'created_at' => now()]);
    }

    private function proofId(array $parameters, string $phase): string
    {
        // Existing UUID primary index makes recovery a point lookup; no new financial DDL.
        return \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_OID,
            'alltrue:invoice-alignment:'.$phase.':'.$this->hash($parameters))->toString();
    }

    private function audit(array $p, string $id, string $event): void
    {
        SecurityAuditEvent::append($event, 'success', ['actor_type' => 'pop-runner', 'campus_id' => $p['campus_id'],
            'subject_type' => 'invoice', 'subject_id' => $p['invoice_id'], 'correlation_id' => $id],
            ['operation_id' => $id, 'reason_code' => $p['decision_reference'], 'row_count' => 1], true);
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode(PopOperationService::canonicalParameters($data), JSON_THROW_ON_ERROR));
    }
    private function fail(string $code): never { throw new RuntimeException('invoice_alignment:'.$code); }
}
