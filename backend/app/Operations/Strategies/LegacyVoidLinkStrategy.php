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
                'unchanged' => ['amount' => $live[$id]['amount'], 'invoice_status' => $live[$id]['invoice_status'], 'net_paid' => $live[$id]['net_paid']]];
        }
        $errors = array_values(array_unique($errors));

        return ['ok' => $errors === [], 'errors' => $errors, 'state' => in_array('before', $states, true) ? 'before' : 'after',
            'states' => $states, 'counts' => array_count_values($states),
            'digest' => hash('sha256', json_encode([$states, $live], JSON_THROW_ON_ERROR)), 'manifest' => $manifest, 'snapshot' => []];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            $this->audit('pop.legacy_void_link', ['reason_code' => self::REF, 'operation_id' => (string) ($context['operation_id'] ?? ''),
                'row_count' => 0, 'outcome' => 'already_applied']);

            return ['ok' => true, 'already_applied' => true, 'updated' => 0, 'snapshot' => ['rows' => []]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'before') {
            throw new RuntimeException('legacy_void_link_plan_not_ready');
        }

        return DB::transaction(function () use ($context): array {
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
            $snapshot = [];
            foreach ($todo as $id => $case) {
                $n = DB::table('Payment')->where('id', $id)->whereNull('payment_report_id')->update(['payment_report_id' => $case['report_id'], 'updated_at' => now()]);
                if ($n !== 1) {
                    throw new RuntimeException("legacy_void_link_updated_{$id}_{$n}");
                }
                $snapshot[$id] = ['payment_report_id' => null];
            }
            $this->audit('pop.legacy_void_link', ['reason_code' => self::REF, 'operation_id' => (string) ($context['operation_id'] ?? ''),
                'row_count' => count($todo), 'outcome' => 'success']);

            return ['ok' => true, 'snapshot' => ['rows' => $snapshot], 'updated' => count($todo)];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $live = $this->inspect(false);
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

        return DB::transaction(function () use ($rows): array {
            $restored = 0;
            $skipped = [];
            foreach (LegacyVoidLinkManifest::cases() as $id => $case) {
                if (!isset($rows[$id])) {
                    continue;
                }
                $n = DB::table('Payment')->where('id', $id)->where('payment_report_id', $case['report_id'])->update(['payment_report_id' => null, 'updated_at' => now()]);
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
            $pay = $q(DB::table('Payment')->where('id', $id))->first();
            $inv = $q(DB::table('Invoice')->where('id', $case['invoice_id']))->first();
            $rep = $q(DB::table('payment_reports')->where('id', $case['report_id']))->first();
            $out[$id] = [
                'present' => $pay !== null && $inv !== null && $rep !== null,
                'pay_invoice_id' => (int) ($pay->InvoiceID ?? 0), 'amount' => (int) ($pay->Amount ?? 0), 'method' => (string) ($pay->Method ?? ''),
                'report_link' => isset($pay->payment_report_id) ? (int) $pay->payment_report_id : null,
                'invoice_status' => (string) ($inv->Status ?? ''),
                'report_invoice_id' => (int) ($rep->InvoiceID ?? 0), 'report_payment_id' => (int) ($rep->payment_id ?? 0), 'report_amount' => (int) ($rep->reported_amount ?? 0),
                'other_linked_reversals' => $q(DB::table('Payment')->where('payment_report_id', $case['report_id'])->where('id', '!=', $id)
                    ->where(fn ($w) => $w->where('Amount', '<', 0)->orWhere('Method', 'void')))->count(),
                'net_paid' => (int) (DB::selectOne('SELECT ' . Invoice::netPaidSql() . ' AS n FROM Invoice WHERE id = ?', [$case['invoice_id']])->n ?? -1),
            ];
        }

        return $out;
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
        if ($l['amount'] !== $c['amount'] || $l['report_amount'] !== abs($c['amount'])) $e[] = "void_{$id}_amount";
        if ($l['report_payment_id'] !== $c['payment_id']) $e[] = "void_{$id}_receipt_payment";
        if ($l['invoice_status'] === 'void') $e[] = "void_{$id}_invoice_void";
        if ($l['other_linked_reversals'] !== 0) $e[] = "void_{$id}_receipt_has_other_reversal";
        if ($e !== []) return ['drift', $e];
        if ($l['report_link'] === null) return ['before', []];
        if ($l['report_link'] === $c['report_id']) return ['after', []];

        return ['drift', ["void_{$id}_linked_elsewhere"]];
    }
}
