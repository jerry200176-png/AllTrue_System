<?php

namespace App\Operations\Strategies;

use App\Models\Invoice;
use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exact-case POP (GitHub #4850 / #4863): set Invoice.TotalAmount, InvoiceItem.Amount and InvoiceItem.StudentClassID of the
 * manifest invoices. PaidAmount, Status, Payment rows and StudentClass are never written; net paid, status and the
 * payments sum are reported as `unchanged` and re-checked on every plan/apply. An item that belongs to another invoice
 * fails the plan; nothing is guessed.
 */
final class InvoiceHeaderItemRepairStrategy
{
    private const REF = 'repair-invoice-header-item-20261104';

    public function plan(array $parameters): array
    {
        $errors = ($parameters['decision_reference'] ?? null) === self::REF ? [] : ['case_parameters_mismatch'];
        $states = [];
        $manifest = [];
        $live = $this->inspect(false);
        foreach (InvoiceHeaderItemRepairManifest::cases() as $id => $case) {
            [$state, $rowErrors] = $this->classify($id, $case, $live[$id]);
            $errors = [...$errors, ...$rowErrors];
            $states[$id] = $state;
            $manifest[$id] = [
                'item_id' => $case['item_id'], 'course_id' => $case['course_id'],
                'total' => [$live[$id]['total'], $case['after']['total']],
                'item_amount' => [$live[$id]['item_amount'], $case['after']['item_amount']],
                'item_student_class_id' => [$live[$id]['item_student_class_id'], $case['after']['item_student_class_id']],
                'unchanged' => ['net_paid' => $live[$id]['net_paid'], 'status' => $live[$id]['status'], 'payments_sum' => $live[$id]['payments_sum']],
            ];
        }
        $errors = array_values(array_unique($errors));
        $state = in_array('before', $states, true) ? 'before' : 'after';

        return ['ok' => $errors === [], 'errors' => $errors, 'state' => $state, 'states' => $states,
            'counts' => array_count_values($states),
            'digest' => hash('sha256', json_encode([$states, $live], JSON_THROW_ON_ERROR)),
            'manifest' => $manifest,
            'snapshot' => $errors === [] ? $this->snapshot() : []];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            return ['ok' => true, 'already_applied' => true, 'updated' => 0, 'snapshot' => $this->snapshot()];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'before') {
            throw new RuntimeException('invoice_header_item_plan_not_ready');
        }

        return DB::transaction(function () use ($context): array {
            $live = $this->inspect(true);
            $todo = [];
            $errors = [];
            foreach (InvoiceHeaderItemRepairManifest::cases() as $id => $case) {
                [$state, $rowErrors] = $this->classify($id, $case, $live[$id]);
                $errors = [...$errors, ...$rowErrors];
                if ($state === 'before') {
                    $todo[$id] = $case;
                }
            }
            if ($errors !== []) {
                throw new RuntimeException('invoice_header_item_drift:' . implode(',', array_unique($errors)));
            }
            $now = now();
            foreach ($todo as $id => $case) {
                $a = $case['after'];
                $n = DB::table('Invoice')->where('id', $id)->where('TotalAmount', $case['before']['total'])
                    ->update(['TotalAmount' => $a['total'], 'updated_at' => $now]);
                $m = DB::table('InvoiceItem')->where('id', $case['item_id'])->where('InvoiceID', $id)
                    ->update(['Amount' => $a['item_amount'], 'StudentClassID' => $a['item_student_class_id'], 'updated_at' => $now]);
                if ([$n, $m] !== [1, 1]) {
                    throw new RuntimeException("invoice_header_item_updated_{$id}_{$n}_{$m}");
                }
            }
            $this->audit('pop.invoice_header_item_repair', ['reason_code' => self::REF,
                'operation_id' => (string) ($context['operation_id'] ?? ''), 'invoice_count' => count($todo), 'outcome' => 'success']);

            return ['ok' => true, 'snapshot' => $this->snapshot(), 'updated' => count($todo)];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $live = $this->inspect(false);
        foreach (InvoiceHeaderItemRepairManifest::cases() as $id => $case) {
            [$state, $rowErrors] = $this->classify($id, $case, $live[$id]);
            $errors = [...$errors, ...$rowErrors, ...($state === 'after' ? [] : ["not_applied_{$id}"])];
        }

        return ['ok' => array_unique($errors) === [], 'errors' => array_values(array_unique($errors)),
            'checks' => ['header_and_item_match_after', 'net_paid_status_payments_unchanged']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $rows = $snapshot['rows'] ?? null;
        if (!is_array($rows) || array_keys($rows) !== array_keys(InvoiceHeaderItemRepairManifest::cases())) {
            throw new RuntimeException('invoice_header_item_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($rows): array {
            $live = $this->inspect(true);
            $restored = 0;
            $skipped = [];
            foreach (InvoiceHeaderItemRepairManifest::cases() as $id => $case) {
                [$state, $rowErrors] = $this->classify($id, $case, $live[$id]);
                if ($state !== 'after' || $rowErrors !== []) {
                    $skipped[] = $id; // not in the repaired state (or money moved): leave it alone
                    continue;
                }
                $old = $rows[$id];
                DB::table('Invoice')->where('id', $id)->update(['TotalAmount' => $old['total'], 'updated_at' => now()]);
                DB::table('InvoiceItem')->where('id', $case['item_id'])
                    ->update(['Amount' => $old['item_amount'], 'StudentClassID' => $old['item_student_class_id'], 'updated_at' => now()]);
                $restored++;
            }
            $this->audit('pop.invoice_header_item_repair.rollback', ['reason_code' => self::REF,
                'restored' => $restored, 'skipped' => count($skipped), 'outcome' => 'success']);

            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'restored' => $restored, 'skipped_ids' => $skipped];
        }, 3);
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. */
    private function audit(string $event, array $metadata): void
    {
        $correlationId = (string) \Illuminate\Support\Str::uuid();
        SecurityAuditEvent::append($event, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'invoice',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('invoice_header_item_audit_not_persisted');
        }
    }

    /** @return array{rows:array<int,array<string,mixed>>} manifest before values only, for rollback */
    private function snapshot(): array
    {
        return ['rows' => array_map(fn ($c) => [
            'total' => $c['before']['total'], 'item_amount' => $c['before']['item_amount'],
            'item_student_class_id' => $c['before']['item_student_class_id'],
        ], InvoiceHeaderItemRepairManifest::cases())];
    }

    /** @return array<int,array<string,mixed>> invoice id => live state; locks invoice, item and course rows when asked */
    private function inspect(bool $lock): array
    {
        $out = [];
        foreach (InvoiceHeaderItemRepairManifest::cases() as $id => $case) {
            $q = fn ($t) => $lock ? $t->lockForUpdate() : $t;
            $inv = $q(DB::table('Invoice')->where('id', $id))->first();
            $item = $q(DB::table('InvoiceItem')->where('id', $case['item_id']))->first();
            $itemIds = $q(DB::table('InvoiceItem')->where('InvoiceID', $id))->pluck('id')->map(fn ($v) => (int) $v)->all();
            $course = $q(DB::table('StudentClass')->where('ID', $case['course_id']))->first(['ID']);
            $out[$id] = [
                'invoice_present' => $inv !== null, 'item_present' => $item !== null, 'course_present' => $course !== null,
                'total' => (int) ($inv->TotalAmount ?? -1), 'status' => (string) ($inv->Status ?? ''),
                'invoice_student_class_id' => isset($inv->StudentClassID) ? (int) $inv->StudentClassID : null,
                'item_invoice_id' => (int) ($item->InvoiceID ?? 0), 'item_amount' => (int) ($item->Amount ?? -1),
                'item_student_class_id' => isset($item->StudentClassID) ? (int) $item->StudentClassID : null,
                'invoice_item_ids' => $itemIds,
                'net_paid' => (int) (DB::selectOne('SELECT ' . Invoice::netPaidSql() . ' AS n FROM Invoice WHERE id = ?', [$id])->n ?? -1),
                'payments_sum' => (int) DB::table('Payment')->where('InvoiceID', $id)->sum('Amount'),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $c manifest row
     * @param array<string,mixed> $l live row
     * @return array{0:string,1:list<string>} state (before|after|drift) and errors naming invoice and field
     */
    private function classify(int $id, array $c, array $l): array
    {
        $e = [];
        if (!$l['invoice_present']) return ['drift', ["invoice_{$id}_missing"]];
        if (!$l['item_present']) return ['drift', ["invoice_{$id}_item_missing"]];
        if ($l['item_invoice_id'] !== $id) $e[] = "invoice_{$id}_item_on_other_invoice";
        if ($l['invoice_item_ids'] !== [$c['item_id']]) $e[] = "invoice_{$id}_item_count";
        if (!$l['course_present']) $e[] = "invoice_{$id}_course_missing";
        if ($l['invoice_student_class_id'] !== null && $l['invoice_student_class_id'] !== $c['course_id']) $e[] = "invoice_{$id}_student_class_id";
        if ($l['net_paid'] !== $c['before']['net_paid']) $e[] = "invoice_{$id}_net_paid";
        if ($l['status'] !== $c['before']['status']) $e[] = "invoice_{$id}_status";
        if ($e !== []) return ['drift', $e];
        $live = ['total' => $l['total'], 'item_amount' => $l['item_amount'], 'item_student_class_id' => $l['item_student_class_id']];
        foreach (['before', 'after'] as $state) {
            if ($live === array_intersect_key($c[$state], $live)) return [$state, []];
        }
        foreach ($live as $field => $v) {
            if ($v !== $c['before'][$field] && $v !== $c['after'][$field]) $e[] = "invoice_{$id}_{$field}";
        }

        return ['drift', $e ?: ["invoice_{$id}_mixed_values"]];
    }
}
