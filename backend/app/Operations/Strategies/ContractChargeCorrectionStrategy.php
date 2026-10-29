<?php

namespace App\Operations\Strategies;

use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Exact-case POP strategy (GitHub #3868, Founder 2026-10-29): writes StudentClass.Charge and nothing else, for the courses of one
 * committed manifest chosen by decision_reference. The reverse manifest (rollback) runs through the same operation. A course must be
 * an unbilled, unpaid, count-mode, non-package contract whose Charge is the manifest's `from` (or `to`, when already applied) and
 * whose Rate x quantity is the manifest's third number, so the G-009 preservedDelta cannot re-apply the old difference on a later edit.
 * Any invoice, payment, report, active pricing amendment or per-session charge fails the whole plan. Output carries ids and counts.
 */
final class ContractChargeCorrectionStrategy
{
    /** @var array<string,array<int,array{int,int,int}>> decision_reference => course id => [Charge from, Charge to, Rate x quantity] */
    public const MANIFESTS = [
        'repair-contract-charge-3868-20261029' => [2569 => [8000, 4000, 4000], 3288 => [8800, 1100, 1100]],
        'rollback-contract-charge-3868-20261029' => [2569 => [4000, 8000, 4000], 3288 => [1100, 8800, 1100]],
    ];

    public function plan(array $parameters): array
    {
        $manifest = self::MANIFESTS[(string) ($parameters['decision_reference'] ?? '')] ?? null;
        if ($manifest === null) {
            return ['ok' => false, 'errors' => ['case_parameters_mismatch'], 'state' => 'unknown', 'courses' => [], 'snapshot' => []];
        }
        $live = $this->inspect($manifest);
        $errors = array_merge(...array_column($live, 'errors'));
        $states = array_values(array_unique(array_column($live, 'state')));
        if (count($states) !== 1) {
            $errors[] = 'mixed_state';
        }
        $state = count($states) === 1 ? $states[0] : 'mixed';
        $courses = array_map(fn (array $l): array => array_diff_key($l, ['errors' => 1]), $live);

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'state' => $state,
            'counts' => ['courses' => count($manifest), 'to_change' => $state === 'before' ? count($manifest) : 0],
            'courses' => $courses, 'digest' => hash('sha256', json_encode([$state, $courses], JSON_THROW_ON_ERROR)),
            'snapshot' => $errors === [] ? ['reference' => (string) $parameters['decision_reference'], 'manifest' => $manifest] : []];
    }

    public function execute(array $plan, array $context): array
    {
        if (!($plan['ok'] ?? false)) {
            throw new RuntimeException('contract_charge_plan_not_ready');
        }
        $snapshot = (array) $plan['snapshot'];
        $manifest = (array) $snapshot['manifest'];
        if ($plan['state'] === 'after') {
            return ['ok' => true, 'already_applied' => true, 'updated' => 0, 'snapshot' => $snapshot];
        }
        $ids = array_keys($manifest);
        sort($ids);

        return DB::transaction(function () use ($manifest, $snapshot, $ids, $context): array {
            DB::table('StudentClass')->whereIn('ID', $ids)->orderBy('ID')->lockForUpdate()->get(['ID']); // first statement: locks
            $live = $this->inspect($manifest);
            $errors = array_merge(...array_column($live, 'errors'));
            if ($errors !== [] || array_values(array_unique(array_column($live, 'state'))) !== ['before']) {
                throw new RuntimeException('contract_charge_drift:' . implode(',', array_slice($errors ?: ['not_before'], 0, 20)));
            }
            foreach ($manifest as $id => [$from, $to, $_]) {
                if (DB::table('StudentClass')->where('ID', $id)->where('Charge', $from)->update(['Charge' => $to]) !== 1) {
                    throw new RuntimeException("contract_charge_update_{$id}");
                }
                $this->audit((int) $id, $from, $to, (string) $snapshot['reference'], (string) ($context['operation_id'] ?? ''));
            }

            return ['ok' => true, 'updated' => count($manifest), 'snapshot' => $snapshot];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $manifest = (array) ($result['snapshot']['manifest'] ?? $plan['snapshot']['manifest'] ?? []);
        $live = $manifest === [] ? [] : $this->inspect($manifest);
        if ($manifest === []) $errors[] = 'manifest_missing';
        foreach ($live as $id => $l) {
            if ($l['state'] !== 'after') $errors[] = "course_{$id}_charge_not_applied";
            array_push($errors, ...$l['errors']);
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)),
            'checks' => ['charge_applied', 'no_invoice_payment_report', 'rate_times_quantity_unchanged']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $manifest = (array) ($snapshot['manifest'] ?? []);
        if ($manifest === [] || !in_array($manifest, self::MANIFESTS, true)) {
            throw new RuntimeException('contract_charge_rollback_snapshot_invalid');
        }
        $reverse = array_map(fn (array $m): array => [$m[1], $m[0], $m[2]], $manifest);
        $reference = (string) ($snapshot['reference'] ?? '');

        return DB::transaction(function () use ($manifest, $reverse, $reference, $context): array {
            $ids = array_keys($manifest);
            sort($ids);
            DB::table('StudentClass')->whereIn('ID', $ids)->orderBy('ID')->lockForUpdate()->get(['ID']);
            $live = $this->inspect($manifest);
            if (array_values(array_unique(array_column($live, 'state'))) !== ['after']
                || array_merge(...array_column($live, 'errors')) !== []) {
                return ['ok' => false, 'restored' => 0, 'skipped' => 'values_moved_on'];
            }
            foreach ($reverse as $id => [$from, $to, $_]) {
                DB::table('StudentClass')->where('ID', $id)->where('Charge', $from)->update(['Charge' => $to]);
                $this->audit((int) $id, $from, $to, $reference, (string) ($context['operation_id'] ?? ''), '.rollback');
            }

            return ['ok' => true, 'restored' => count($reverse)];
        }, 3);
    }

    /** @param array<int,array{int,int,int}> $manifest @return array<int,array<string,mixed>> per course: facts, state and error codes */
    private function inspect(array $manifest): array
    {
        $out = [];
        foreach ($manifest as $id => [$from, $to, $expectedBase]) {
            $c = DB::table('StudentClass')->where('ID', $id)->first();
            $errors = [];
            if (!$c) {
                $out[$id] = ['state' => 'missing', 'errors' => ["course_{$id}_missing"]];
                continue;
            }
            $base = (int) round((float) ($c->Rate ?? 0) * ((string) ($c->rate_unit ?? '') === 'hour' ? (int) $c->TotalHours : (int) ($c->SessionCount ?? 0)));
            $charge = (int) ($c->Charge ?? -1);
            // Invoices of the contract by anchor or by item link (a null-anchor invoice can carry course items).
            $invoiceIds = DB::table('Invoice')->where('StudentClassID', $id)
                ->orWhereIn('id', DB::table('InvoiceItem')->where('StudentClassID', $id)->select('InvoiceID'))->pluck('id')->all();
            $invoices = DB::table('Invoice')->whereIn('id', $invoiceIds)->where('Status', '!=', 'void')->count();
            $voided = DB::table('Invoice')->whereIn('id', $invoiceIds)->where('Status', 'void')->count();
            $payments = DB::table('payment_reports')->where(fn ($q) => $q->where('StudentClassID', $id)->orWhereIn('InvoiceID', $invoiceIds))->count()
                + DB::table('Invoice')->whereIn('id', $invoiceIds)->where('PaidAmount', '>', 0)->count()
                + DB::table('Payment')->whereIn('InvoiceID', $invoiceIds)->where(fn ($q) => $q->whereNull('Method')->orWhere('Method', '!=', 'void'))->count();
            $amendments = DB::table('student_class_pricing_amendments')->where('student_class_id', $id)->whereNull('voided_at')->count();
            $sessionCharges = DB::table('ClassSession')->where('StudentClassID', $id)->whereNotNull('session_charge')->count();
            $paidFlag = (int) $c->Paid !== 0 || (int) $c->Pay !== 0 || !empty($c->PayDate);
            if ((string) $c->ScheduleMode !== 'count') $errors[] = "course_{$id}_schedule_mode";
            if ((int) ($c->PackageID ?? 0) > 0) $errors[] = "course_{$id}_package";
            if (!empty($c->settlement_locked_at)) $errors[] = "course_{$id}_settlement_locked";
            if ($charge !== $from && $charge !== $to) $errors[] = "course_{$id}_charge_drifted";
            if ($base !== $expectedBase) $errors[] = "course_{$id}_rate_quantity_drifted";
            if ($invoices > 0) $errors[] = "course_{$id}_invoice_present";
            if ($payments > 0) $errors[] = "course_{$id}_payment_evidence";
            if ($paidFlag) $errors[] = "course_{$id}_paid_flag";
            if ($amendments > 0) $errors[] = "course_{$id}_pricing_amendment_active";
            if ($sessionCharges > 0) $errors[] = "course_{$id}_session_charge_adjustments";
            $out[$id] = ['state' => $charge === $from ? 'before' : ($charge === $to ? 'after' : 'drift'),
                'charge' => $charge, 'charge_target' => [$from, $to], 'rate_times_quantity' => $base,
                'preserved_delta_if_applied' => $to - $base, 'schedule_mode' => (string) $c->ScheduleMode,
                'package' => (int) ($c->PackageID ?? 0) > 0, 'stop' => (int) ($c->Stop ?? 0), 'paid_flag' => $paidFlag,
                'invoices' => $invoices, 'void_invoices' => $voided, 'payment_evidence' => $payments,
                'pricing_amendments' => $amendments, 'session_charges' => $sessionCharges, 'errors' => $errors];
        }

        return $out;
    }

    private function audit(int $id, int $from, int $to, string $reference, string $operationId, string $suffix = ''): void
    {
        $correlationId = (string) Str::uuid();
        $campusId = DB::table('Student')->where('id', DB::table('StudentClass')->where('ID', $id)->value('StudentID'))->value('CampusID');
        SecurityAuditEvent::append('pop.contract_charge_correction' . $suffix, 'success', ['campus_id' => $campusId === null ? null : (int) $campusId,
            'actor_type' => 'pop-runner', 'subject_type' => 'student_class', 'subject_id' => $id, 'correlation_id' => $correlationId],
            ['reason_code' => $reference, 'old_charge' => $from, 'new_charge' => $to, 'operation_id' => $operationId,
                'outcome' => 'success'], true);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('contract_charge_audit_not_persisted');
        }
    }
}
