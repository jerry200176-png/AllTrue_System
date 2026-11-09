<?php

namespace App\Operations\Strategies;

use App\Models\Invoice;
use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exact-case POP (in-app #521): set Payment.payment_report_id of the manifest legacy void rows to the receipt each one reversed,
 * so every reversal points at its entry (void() then recognises it). Only that column is written; amounts, methods, invoices,
 * statuses, receipts and net paid are never changed and are re-checked on every plan/apply. Anything off the manifest fails the plan.
 */
final class LegacyVoidLinkStrategy
{
    private const REF = 'link-legacy-void-20261107';

    public function plan(array $parameters): array
    {
        $errors = ($parameters['decision_reference'] ?? null) === self::REF ? [] : ['case_parameters_mismatch'];
        $states = [];
        $manifest = [];
        $live = $this->inspect(false);
        foreach (LegacyVoidLinkManifest::cases() as $id => $case) {
            [$state, $rowErrors] = $this->classify($id, $case, $live[$id]);
            $errors = [...$errors, ...$rowErrors];
            $states[$id] = $state;
            $manifest[$id] = ['invoice_id' => $case['invoice_id'], 'payment_report_id' => [$live[$id]['report_link'], $case['report_id']],
                'unchanged' => ['amount' => $live[$id]['amount_raw'], 'receipt_amount' => $live[$id]['report_amount_raw'], 'invoice_status' => $live[$id]['invoice_status'], 'net_paid' => $live[$id]['net_paid']]];
        }
        $digest = $this->digest($live);
        $expectedDigest = (string) ($parameters['expected_digest'] ?? '');
        if ($expectedDigest !== '' && (!preg_match('/^[0-9a-f]{64}$/D', $expectedDigest) || !hash_equals($digest, $expectedDigest))) {
            $errors[] = 'preview_digest_mismatch';
        }
        $errors = array_values(array_unique($errors));

        return ['ok' => $errors === [], 'errors' => $errors, 'state' => in_array('before', $states, true) ? 'before' : 'after',
            'states' => $states, 'counts' => array_count_values($states),
            'digest' => $digest, 'expected_digest' => $expectedDigest, 'manifest' => $manifest, 'snapshot' => []];
    }

    public function execute(array $plan, array $context): array
    {
        if (!($plan['ok'] ?? false) || !in_array($plan['state'] ?? null, ['before', 'after'], true)
            || !preg_match('/^[0-9a-f]{64}$/D', (string) ($plan['expected_digest'] ?? ''))) {
            throw new RuntimeException('legacy_void_link_plan_not_ready');
        }

        return DB::transaction(function () use ($plan, $context): array {
            $live = $this->inspect(true);
            $todo = [];
            $errors = [];
            foreach (LegacyVoidLinkManifest::cases() as $id => $case) {
                [$state, $rowErrors] = $this->classify($id, $case, $live[$id]);
                $errors = [...$errors, ...$rowErrors];
                if ($state === 'before') {
                    $todo[$id] = $case;
                }
            }
            if ($errors !== []) {
                throw new RuntimeException('legacy_void_link_drift:' . implode(',', array_unique($errors)));
            }
            if (!hash_equals($plan['expected_digest'], $this->digest($live))) {
                throw new RuntimeException('legacy_void_link_preview_digest_drift');
            }
            $snapshot = [];
            foreach ($todo as $id => $case) {
                $n = DB::table('Payment')->where('id', $id)->whereNull('payment_report_id')->update(['payment_report_id' => $case['report_id']]);
                if ($n !== 1) {
                    throw new RuntimeException("legacy_void_link_updated_{$id}_{$n}");
                }
                $snapshot[$id] = ['payment_report_id' => null, 'updated_at' => $live[$id]['updated_at'],
                    'created_at' => $live[$id]['created_at'], 'paid_at' => $live[$id]['paid_at'], 'method' => $live[$id]['method']];
            }
            $this->audit('pop.legacy_void_link', ['reason_code' => self::REF, 'operation_id' => (string) ($context['operation_id'] ?? ''),
                'row_count' => count($todo), 'outcome' => $todo === [] ? 'already_applied' : 'success']);

            $afterDigest = $this->digest($this->inspect(true));
            return ['ok' => true, 'already_applied' => $todo === [], 'snapshot' => ['rows' => $snapshot, 'after_digest' => $afterDigest],
                'after_digest' => $afterDigest, 'updated' => count($todo)];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $live = $this->inspect(false);
        if (!hash_equals((string) ($result['after_digest'] ?? ''), $this->digest($live))) $errors[] = 'post_execution_state_drift';
        foreach (LegacyVoidLinkManifest::cases() as $id => $case) {
            [$state, $rowErrors] = $this->classify($id, $case, $live[$id]);
            $errors = [...$errors, ...$rowErrors, ...($state === 'after' ? [] : ["not_applied_{$id}"])];
        }

        return ['ok' => array_unique($errors) === [], 'errors' => array_values(array_unique($errors)),
            'checks' => ['void_row_linked_to_its_receipt', 'amount_status_net_paid_unchanged']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $rows = $snapshot['rows'] ?? null;
        if (!is_array($rows) || array_diff(array_keys($rows), array_keys(LegacyVoidLinkManifest::cases())) !== []) {
            throw new RuntimeException('legacy_void_link_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($rows, $snapshot): array {
            if (!preg_match('/^[0-9a-f]{64}$/D', (string) ($snapshot['after_digest'] ?? ''))
                || !hash_equals($snapshot['after_digest'], $this->digest($this->inspect(true)))) {
                throw new RuntimeException('legacy_void_link_rollback_state_drift');
            }
            $restored = 0;
            $skipped = [];
            foreach (LegacyVoidLinkManifest::cases() as $id => $case) {
                if (!isset($rows[$id])) {
                    continue;
                }
                if (array_keys($rows[$id]) !== ['payment_report_id', 'updated_at', 'created_at', 'paid_at', 'method']
                    || $rows[$id]['payment_report_id'] !== null) throw new RuntimeException('legacy_void_link_rollback_snapshot_invalid');
                $n = DB::table('Payment')->where('id', $id)->where('payment_report_id', $case['report_id'])
                    ->where('InvoiceID', $case['invoice_id'])->where('Amount', $case['amount'])
                    ->where('Method', $rows[$id]['method'])->where('PaidAt', $rows[$id]['paid_at'])
                    ->where('created_at', $rows[$id]['created_at'])->where('updated_at', $rows[$id]['updated_at'])
                    ->update(['payment_report_id' => null]);
                $n === 1 ? $restored++ : $skipped[] = $id; // not in the linked state: leave it alone
            }
            $this->audit('pop.legacy_void_link.rollback', ['reason_code' => self::REF, 'restored' => $restored, 'skipped' => count($skipped), 'outcome' => 'success']);

            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'restored' => $restored, 'skipped_ids' => $skipped];
        }, 3);
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. */
    private function audit(string $event, array $metadata): void
    {
        $correlationId = (string) \Illuminate\Support\Str::uuid();
        SecurityAuditEvent::append($event, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'payment', 'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('legacy_void_link_audit_not_persisted');
        }
    }

    /** @return array<int,array<string,mixed>> void payment id => live state; locks the rows when asked */
    private function inspect(bool $lock): array
    {
        $out = [];
        foreach (LegacyVoidLinkManifest::cases() as $id => $case) {
            $q = fn ($t) => $lock ? $t->lockForUpdate() : $t;
            $inv = $q(DB::table('Invoice')->where('id', $case['invoice_id']))->first();
            $payments = $q(DB::table('Payment')->where('InvoiceID', $case['invoice_id'])->orderBy('id'))
                ->get(['id', 'InvoiceID', 'Amount', 'Method', 'PaidAt', 'payment_report_id', 'created_at', 'updated_at']);
            $pay = $q(DB::table('Payment')->where('id', $id))->first();
            $rep = $q(DB::table('payment_reports')->where('id', $case['report_id']))->first();
            $out[$id] = [
                'present' => $pay !== null && $inv !== null && $rep !== null,
                'pay_invoice_id' => (int) ($pay->InvoiceID ?? 0), 'amount' => (int) ($pay->Amount ?? 0),
                'amount_raw' => (string) ($pay->Amount ?? ''), 'report_amount_raw' => (string) ($rep->reported_amount ?? ''), 'method' => (string) ($pay->Method ?? ''),
                'report_link' => isset($pay->payment_report_id) ? (int) $pay->payment_report_id : null,
                'updated_at' => $pay->updated_at ?? null, 'created_at' => $pay->created_at ?? null, 'paid_at' => $pay->PaidAt ?? null,
                'invoice_payments_digest' => hash('sha256', json_encode($payments->all(), JSON_THROW_ON_ERROR)),
                'invoice_state_digest' => hash('sha256', json_encode(array_intersect_key((array) $inv,
                    array_flip(['id', 'StudentID', 'StudentClassID', 'TotalAmount', 'PaidAmount', 'Status', 'billing_period', 'updated_at'])), JSON_THROW_ON_ERROR)),
                'receipt_state_digest' => hash('sha256', json_encode(array_intersect_key((array) $rep,
                    array_flip(['id', 'StudentID', 'StudentClassID', 'InvoiceID', 'payment_id', 'reported_amount', 'status', 'confirmed_at', 'voided_at', 'billing_period', 'updated_at'])), JSON_THROW_ON_ERROR)),
                'invoice_status' => (string) ($inv->Status ?? ''),
                'report_invoice_id' => (int) ($rep->InvoiceID ?? 0), 'report_payment_id' => (int) ($rep->payment_id ?? 0), 'report_amount' => (int) ($rep->reported_amount ?? 0),
                'other_linked_reversals' => $q(DB::table('Payment')->where('payment_report_id', $case['report_id'])->where('id', '!=', $id)
                    ->where(fn ($w) => $w->where('Amount', '<', 0)->orWhere('Method', 'void')))->count(),
                'net_paid' => (int) (DB::selectOne('SELECT ' . Invoice::netPaidSql() . ' AS n FROM Invoice WHERE id = ?', [$case['invoice_id']])->n ?? -1),
            ];
        }

        return $out;
    }

    private function digest(array $live): string
    {
        $states = [];
        foreach (LegacyVoidLinkManifest::cases() as $id => $case) $states[$id] = $this->classify($id, $case, $live[$id])[0];
        return hash('sha256', json_encode([$states, $live], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,int> $c manifest row
     * @param array<string,mixed> $l live row
     * @return array{0:string,1:list<string>} state (before|after|drift) and errors naming the void row
     */
    private function classify(int $id, array $c, array $l): array
    {
        if (!$l['present']) return ['drift', ["void_{$id}_missing"]];
        $e = [];
        if ($l['pay_invoice_id'] !== $c['invoice_id'] || $l['report_invoice_id'] !== $c['invoice_id']) $e[] = "void_{$id}_invoice";
        if (!preg_match('/^-?\d+(?:\.0+)?$/D', $l['amount_raw']) || !preg_match('/^\d+(?:\.0+)?$/D', $l['report_amount_raw'])
            || $l['amount'] !== $c['amount'] || $l['report_amount'] !== abs($c['amount'])) $e[] = "void_{$id}_amount";
        if ($l['report_payment_id'] !== $c['payment_id']) $e[] = "void_{$id}_receipt_payment";
        if ($l['invoice_status'] === 'void') $e[] = "void_{$id}_invoice_void";
        if (in_array($l['method'], ['transfer_out', 'refund'], true)) $e[] = "void_{$id}_method"; // not a legacy void row
        if ($l['other_linked_reversals'] !== 0) $e[] = "void_{$id}_receipt_has_other_reversal";
        if ($e !== []) return ['drift', $e];
        if ($l['report_link'] === null) return ['before', []];
        if ($l['report_link'] === $c['report_id']) return ['after', []];

        return ['drift', ["void_{$id}_linked_elsewhere"]];
    }
}
