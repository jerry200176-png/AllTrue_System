<?php

namespace App\Operations\Strategies;

use App\Models\Invoice;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Services\InvoiceAmountReconciliationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exact-case POP strategy: unpaid contracts closed as settled/completed go back to settled_pending
 * (待對帳). Only StudentClass.closed_reason changes; Invoice/Payment/Charge/Paid are never written.
 * A pending payment report on a manifest row blocks the plan: a partial confirmation would settle the course
 * again (#3536), so those rows wait for the report to be handled.
 */
final class UnpaidHiddenClosuresStrategy
{
    private const REF = 'repair-unpaid-hidden-closures-20261005';
    private const VISIBLE = ['settled_pending', 'contract_amended', 'waived'];

    public function plan(array $parameters): array
    {
        $paramErrors = ($parameters['decision_reference'] ?? null) === self::REF ? [] : ['case_parameters_mismatch'];
        $rows = $this->inspect(false);
        $errors = $this->beforeErrors($rows);
        $state = 'before';
        if ($errors !== [] && $this->afterErrors($rows) === []) {
            $errors = [];
            $state = 'after';
        }
        $errors = array_values(array_unique([...$paramErrors, ...$errors]));

        return ['ok' => $errors === [], 'errors' => $errors, 'state' => $state,
            'student_class_ids' => array_keys(UnpaidHiddenClosuresManifest::cases()),
            'snapshot' => $errors === [] ? $this->snapshot($rows) : []];
    }

    public function execute(array $plan, array $context): array
    {
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'before') {
            throw new RuntimeException('unpaid_hidden_plan_not_ready');
        }

        return DB::transaction(function () use ($context): array {
            $ids = array_keys(UnpaidHiddenClosuresManifest::cases());
            $rows = $this->inspect(true);
            $errors = $this->beforeErrors($rows);
            if ($errors !== []) {
                throw new RuntimeException('unpaid_hidden_drift:' . implode(',', $errors));
            }
            $n = DB::table('StudentClass')->whereIn('ID', $ids)->update(['closed_reason' => 'settled_pending']);
            if ($n !== count($ids)) {
                throw new RuntimeException("unpaid_hidden_updated_{$n}_of_" . count($ids));
            }
            $this->audit('pop.unpaid_hidden_closures', ['reason_code' => self::REF,
                'operation_id' => (string) ($context['operation_id'] ?? ''), 'course_count' => count($ids), 'outcome' => 'success']);

            return ['ok' => true, 'snapshot' => $this->snapshot($rows), 'updated' => $n];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $errors = array_values(array_unique([...$errors, ...$this->afterErrors($this->inspect(false))]));

        return ['ok' => $errors === [], 'errors' => $errors,
            'checks' => ['settled_pending_or_moved_on', 'no_unpaid_hidden_row']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $before = $snapshot['rows'] ?? [];
        if (!is_array($before) || array_keys($before) !== array_keys(UnpaidHiddenClosuresManifest::cases())) {
            throw new RuntimeException('unpaid_hidden_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($before): array {
            $now = $this->inspect(true);
            $restored = $skipped = 0;
            $skippedIds = [];
            foreach ($before as $id => $old) {
                $row = $now[$id] ?? null;
                if (!$row || $row['closed_reason'] !== 'settled_pending'
                    || $row['outstanding'] !== $old['outstanding'] || $row['payments'] !== $old['payments']
                    || $row['invoices'] !== $old['invoices']) {
                    $skippedIds[] = $id; // later financial activity or already moved on: leave it alone
                    continue;
                }
                $n = DB::table('StudentClass')->where('ID', $id)->where('closed_reason', 'settled_pending')
                    ->update(['closed_reason' => $old['closed_reason']]);
                if ($n !== 1) {
                    throw new RuntimeException("unpaid_hidden_rollback_row_{$id}");
                }
                $restored++;
            }
            $this->audit('pop.unpaid_hidden_closures.rollback', ['reason_code' => self::REF,
                'restored' => $restored, 'skipped' => count($skippedIds), 'outcome' => 'success']);

            // Any skipped row means the repair is only partly undone: surface it for operator resolution.
            return ['ok' => $skippedIds === [], 'partial' => $skippedIds !== [], 'restored' => $restored, 'skipped_ids' => $skippedIds];
        }, 3);
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. */
    private function audit(string $event, array $metadata): void
    {
        $correlationId = (string) \Illuminate\Support\Str::uuid();
        SecurityAuditEvent::append($event, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'student_class_batch',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('unpaid_hidden_audit_not_persisted');
        }
    }

    /** @return array<int,array<string,mixed>> manifest id => live state; locks courses and invoices when asked */
    private function inspect(bool $lock): array
    {
        $ids = array_keys(UnpaidHiddenClosuresManifest::cases());
        $q = StudentClass::query()->with('student:id,CampusID')->whereIn('ID', $ids)->orderBy('ID');
        $courses = ($lock ? $q->lockForUpdate() : $q)->get()->keyBy(fn ($c) => (int) $c->getAttribute('ID'));
        $iq = Invoice::query()->with('payments')->whereIn('StudentClassID', $ids)
            ->where(fn ($w) => $w->whereNull('Status')->orWhere('Status', '!=', 'void'))->orderBy('id');
        $invoices = ($lock ? $iq->lockForUpdate() : $iq)->get()->groupBy('StudentClassID');
        $amounts = app(InvoiceAmountReconciliationService::class);
        $pending = DB::table('payment_reports')->whereIn('StudentClassID', $ids)->where('status', 'pending')
            ->pluck('StudentClassID')->map(fn ($v) => (int) $v)->all();
        $out = [];
        foreach ($courses as $id => $c) {
            $owed = 0;
            $payments = 0;
            $invoiceIds = [];
            if (!$invoices->has($id)) {
                $owed = (int) $c->getAttribute('Charge') > 0 && !$c->isEffectivelyPaid() ? (int) $c->getAttribute('Charge') : 0;
            }
            foreach ($invoices->get($id, []) as $invoice) {
                $a = $amounts->resolve($invoice, $c);
                $rows = $invoice->getRelationValue('payments');
                $paid = $rows->isEmpty() ? max(0, (int) $invoice->getAttribute('PaidAmount')) : (int) $a['net_applied'];
                $owed += max(0, (int) $a['total_amount'] - $paid);
                $payments += $rows->count();
                $invoiceIds[] = (int) $invoice->getKey();
            }
            $out[$id] = [
                'closed_reason' => (string) $c->getAttribute('closed_reason'), 'outstanding' => $owed,
                'invoices' => $invoiceIds, 'payments' => $payments,
                'stop' => (int) $c->getAttribute('Stop'), 'paid' => (int) $c->getAttribute('Paid'),
                'effectively_paid' => $c->isEffectivelyPaid(),
                'tutoring' => strtolower(trim((string) $c->getAttribute('ClassType'))) === 'tutoring',
                'pending_report' => in_array($id, $pending, true),
                'campus_id' => (int) $c->student?->getAttribute('CampusID'),
            ];
        }

        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows @return list<string> */
    private function beforeErrors(array $rows): array
    {
        $errors = [];
        foreach (UnpaidHiddenClosuresManifest::cases() as $id => $case) {
            $r = $rows[$id] ?? null;
            if (!$r) { $errors[] = "missing_{$id}"; continue; }
            if ($r['campus_id'] !== $case['campus_id']) $errors[] = "campus_{$id}";
            if ($r['stop'] !== 1) $errors[] = "not_closed_{$id}";
            if ($r['closed_reason'] !== $case['closed_reason']) $errors[] = "reason_{$id}";
            if ($r['paid'] === 1 || $r['effectively_paid']) $errors[] = "paid_{$id}"; // incl. paid package
            if ($r['tutoring']) $errors[] = "tutoring_{$id}";
            if ($r['pending_report']) $errors[] = "pending_report_{$id}";
            if ($r['outstanding'] !== $case['outstanding']) $errors[] = "outstanding_{$id}";
        }

        return $errors;
    }

    /** Applied, or legitimately moved on (paid in full, amended, waived). Unpaid + hidden is a failure. @return list<string> */
    private function afterErrors(array $rows): array
    {
        $errors = [];
        foreach (array_keys(UnpaidHiddenClosuresManifest::cases()) as $id) {
            $r = $rows[$id] ?? null;
            if (!$r || ($r['outstanding'] > 0 && !in_array($r['closed_reason'], self::VISIBLE, true))) {
                $errors[] = "hidden_{$id}";
            }
        }

        return $errors;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function snapshot(array $rows): array
    {
        return ['rows' => array_map(fn ($r) => array_intersect_key($r,
            array_flip(['closed_reason', 'outstanding', 'invoices', 'payments'])), $rows)];
    }
}
