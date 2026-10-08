<?php

namespace App\Operations\Strategies;

use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exact-case POP (in-app #369, GitHub #3356): align InvoiceItem 101, Invoice 1997 billing_snapshot and StudentClass 4099 Charge
 * with the 5 attended lessons (7500). Invoice total/paid/status, Payments and StudentClass.Paid are never written; afterwards
 * Charge = Rate x SessionCount, so the G-009 preservedDelta is 0.
 */
final class Invoice1997StaleValuesStrategy
{
    private const REF = 'repair-invoice-1997-stale-values-20261008';

    /** @var array<string,mixed>|null test-only override */
    private static ?array $override = null;

    public static function useCaseForTesting(?array $case): void
    {
        self::$override = $case;
    }

    /** @return array<string,mixed> */
    public static function expected(): array
    {
        return self::$override ?? [
            'invoice_id' => 1997, 'item_id' => 101, 'course_id' => 4099, 'student_id' => 172,
            'rate' => 1500, 'session_count' => 5, 'old_amount' => 6000, 'new_amount' => 7500,
            'total' => 7500, 'payment_ids' => [2068],
            'period' => ['2026-09-01', '2026-09-30'],
            'session_ids' => [33562, 31548, 33563, 32430, 42099],
            'old_snapshot_sessions' => [33562, 31548, 33563, 32430],
            'added_session' => ['class_session_id' => 42099, 'date' => '2026-09-30', 'start_time' => '18:00',
                'end_time' => '20:00', 'subject' => '數學', 'lesson' => 5, 'status' => 'attended'],
        ];
    }

    public function plan(array $parameters): array
    {
        $paramErrors = ($parameters['decision_reference'] ?? null) === self::REF ? [] : ['case_parameters_mismatch'];
        $live = $this->inspect(false);
        $errors = $this->beforeErrors($live);
        $state = 'before';
        if ($errors !== [] && $this->afterErrors($live) === []) {
            $errors = [];
            $state = 'after';
        }
        $errors = array_values(array_unique([...$paramErrors, ...$errors]));
        $e = self::expected();

        return ['ok' => $errors === [], 'errors' => $errors, 'state' => $state,
            'digest' => hash('sha256', json_encode([$state, $this->snapshot($live)], JSON_THROW_ON_ERROR)),
            'manifest' => ['invoice_id' => $e['invoice_id'], 'invoice_item_id' => $e['item_id'], 'student_class_id' => $e['course_id'],
                'item_amount' => [$live['item_amount'], $e['new_amount']], 'course_charge' => [$live['charge'], $e['new_amount']],
                'snapshot_charge_sessions' => [[$live['snapshot']['charge'] ?? null, $live['snapshot']['period_sessions'] ?? null],
                    [$e['new_amount'], count($e['session_ids'])]],
                'unchanged' => ['invoice_total' => $live['total'], 'invoice_paid' => $live['paid'], 'invoice_status' => $live['status'], 'payments_sum' => $live['payments_sum']]],
            'snapshot' => $errors === [] ? $this->snapshot($live) : []];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            $snapshot = $plan['snapshot'] ?? [];
            $e = self::expected();
            $snapshot['item_amount'] = $e['old_amount'];
            $snapshot['charge'] = $e['old_amount'];
            $snapshot['snapshot'] = $this->oldSnapshotFrom((array) ($snapshot['snapshot'] ?? []));

            return ['ok' => true, 'already_applied' => true, 'updated' => 0, 'snapshot' => $snapshot];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'before') {
            throw new RuntimeException('invoice1997_plan_not_ready');
        }

        return DB::transaction(function () use ($context): array {
            $e = self::expected();
            $live = $this->inspect(true);
            $errors = $this->beforeErrors($live);
            if ($errors !== []) {
                throw new RuntimeException('invoice1997_drift:' . implode(',', $errors));
            }
            $now = now();
            $n = DB::table('InvoiceItem')->where('id', $e['item_id'])->where('Amount', $e['old_amount'])
                ->update(['Amount' => $e['new_amount'], 'updated_at' => $now]);
            $m = DB::table('Invoice')->where('id', $e['invoice_id'])
                ->update(['billing_snapshot' => json_encode($this->newSnapshot()), 'updated_at' => $now]);
            $k = DB::table('StudentClass')->where('ID', $e['course_id'])->where('Charge', $e['old_amount'])
                ->update(['Charge' => $e['new_amount']]);
            if ([$n, $m, $k] !== [1, 1, 1]) {
                throw new RuntimeException("invoice1997_updated_{$n}_{$m}_{$k}");
            }
            $this->audit('pop.invoice1997_stale_values', ['reason_code' => self::REF,
                'operation_id' => (string) ($context['operation_id'] ?? ''), 'outcome' => 'success']);

            return ['ok' => true, 'snapshot' => $this->snapshot($live), 'updated' => 3];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $live = $this->inspect(false);
        $e = self::expected();
        $errors = [...$errors, ...$this->afterErrors($live)];
        if ($live['charge'] - $live['rate'] * $live['session_count'] !== 0) {
            $errors[] = 'preserved_delta_nonzero';
        }
        if ($live['total'] !== $e['total'] || $live['paid'] !== $e['total'] || $live['payments_sum'] !== $e['total']) {
            $errors[] = 'money_changed';
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)),
            'checks' => ['item_snapshot_charge_updated', 'preserved_delta_zero', 'payments_and_total_unchanged']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $e = self::expected();
        if (($snapshot['item_amount'] ?? null) !== $e['old_amount'] || ($snapshot['charge'] ?? null) !== $e['old_amount']
            || !is_array($snapshot['snapshot'] ?? null)) {
            throw new RuntimeException('invoice1997_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($snapshot, $e): array {
            $live = $this->inspect(true);
            if ($this->afterErrors($live) !== [] || $live['payments_sum'] !== $live['total']) {
                return ['ok' => false, 'partial' => false, 'restored' => 0, 'skipped' => 'values_moved_on'];
            }
            DB::table('InvoiceItem')->where('id', $e['item_id'])->update(['Amount' => $e['old_amount'], 'updated_at' => now()]);
            DB::table('Invoice')->where('id', $e['invoice_id'])->update(['billing_snapshot' => json_encode($snapshot['snapshot']), 'updated_at' => now()]);
            DB::table('StudentClass')->where('ID', $e['course_id'])->update(['Charge' => $e['old_amount']]);
            $this->audit('pop.invoice1997_stale_values.rollback', ['reason_code' => self::REF, 'outcome' => 'success']);

            return ['ok' => true, 'partial' => false, 'restored' => 3];
        }, 3);
    }

    private function audit(string $event, array $metadata): void
    {
        $correlationId = (string) \Illuminate\Support\Str::uuid();
        SecurityAuditEvent::append($event, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'invoice',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('invoice1997_audit_not_persisted');
        }
    }

    /** @param array<string,mixed> $new @return array<string,mixed> the replaced snapshot rebuilt from the updated one */
    private function oldSnapshotFrom(array $new): array
    {
        $e = self::expected();
        $new['charge'] = $e['old_amount'];
        $new['period_sessions'] = count($e['old_snapshot_sessions']);
        $new['sessions'] = array_values(array_filter($new['sessions'] ?? [],
            fn ($s) => (int) ($s['class_session_id'] ?? 0) !== $e['added_session']['class_session_id']));

        return $new;
    }

    /** @return array<string,mixed> the stored snapshot with the fifth attended lesson appended and charge 7500 */
    private function newSnapshot(): array
    {
        $e = self::expected();
        $row = DB::table('Invoice')->where('id', $e['invoice_id'])->value('billing_snapshot');
        $snap = json_decode((string) $row, true, 512, JSON_THROW_ON_ERROR);
        $snap['charge'] = $e['new_amount'];
        $snap['period_sessions'] = count($e['session_ids']);
        $snap['sessions'][] = $e['added_session'];

        return $snap;
    }

    /** @return array<string,mixed> */
    private function inspect(bool $lock): array
    {
        $e = self::expected();
        $inv = DB::table('Invoice')->where('id', $e['invoice_id']);
        $inv = ($lock ? $inv->lockForUpdate() : $inv)->first();
        $item = DB::table('InvoiceItem')->where('id', $e['item_id']);
        $item = ($lock ? $item->lockForUpdate() : $item)->first();
        $course = DB::table('StudentClass')->where('ID', $e['course_id']);
        $course = ($lock ? $course->lockForUpdate() : $course)->first();
        $payments = DB::table('Payment')->where('InvoiceID', $e['invoice_id'])->orderBy('id')->get(['id', 'Amount', 'Method']);
        $attended = DB::table('ClassSession')->where('StudentClassID', $e['course_id'])
            ->whereBetween('SessionDate', $e['period'])->whereIn('Status', ['attended', 'completed', 'late'])
            ->orderBy('SessionDate')->orderBy('StartTime')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $snap = $inv ? json_decode((string) $inv->billing_snapshot, true) : null;
        $snapSessions = is_array($snap) ? array_map(fn ($s) => (int) ($s['class_session_id'] ?? 0), $snap['sessions'] ?? []) : [];

        return [
            'invoice_present' => $inv !== null && (int) $inv->StudentClassID === $e['course_id'],
            'total' => (int) ($inv->TotalAmount ?? -1), 'paid' => (int) ($inv->PaidAmount ?? -1), 'status' => (string) ($inv->Status ?? ''),
            'snapshot' => is_array($snap) ? $snap : [], 'snapshot_session_ids' => $snapSessions,
            'item_present' => $item !== null && (int) $item->InvoiceID === $e['invoice_id'],
            'item_amount' => (int) ($item->Amount ?? -1),
            'rate' => (int) ($course->Rate ?? 0), 'session_count' => (int) ($course->SessionCount ?? 0),
            'charge' => (int) ($course->Charge ?? -1), 'course_paid' => (int) ($course->Paid ?? -1),
            'payment_ids' => $payments->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'payments_sum' => (int) $payments->sum(fn ($p) => (string) $p->Method === 'void' ? 0 : (int) $p->Amount),
            'attended_ids' => $attended,
        ];
    }

    private function commonErrors(array $l): array
    {
        $e = self::expected();
        $errors = [];
        if (!$l['invoice_present']) $errors[] = 'invoice_missing';
        if (!$l['item_present']) $errors[] = 'item_missing';
        if ($l['total'] !== $e['total'] || $l['paid'] !== $e['total'] || $l['status'] !== 'paid') $errors[] = 'invoice_money_state';
        if ($l['payment_ids'] !== $e['payment_ids'] || $l['payments_sum'] !== $e['total']) $errors[] = 'payments';
        if ($l['rate'] !== $e['rate'] || $l['session_count'] !== $e['session_count']) $errors[] = 'course_rate_or_count';
        if ($l['course_paid'] !== 1) $errors[] = 'course_paid_flag';
        if ($l['attended_ids'] !== $e['session_ids']) $errors[] = 'attended_sessions';

        return $errors;
    }

    private function beforeErrors(array $l): array
    {
        $e = self::expected();
        $errors = $this->commonErrors($l);
        if ($l['item_amount'] !== $e['old_amount']) $errors[] = 'item_amount';
        if ($l['charge'] !== $e['old_amount']) $errors[] = 'course_charge';
        if (($l['snapshot']['charge'] ?? null) !== $e['old_amount'] || ($l['snapshot']['period_sessions'] ?? null) !== 4
            || $l['snapshot_session_ids'] !== $e['old_snapshot_sessions']) $errors[] = 'snapshot';

        return $errors;
    }

    private function afterErrors(array $l): array
    {
        $e = self::expected();
        $errors = $this->commonErrors($l);
        if ($l['item_amount'] !== $e['new_amount']) $errors[] = 'item_amount';
        if ($l['charge'] !== $e['new_amount']) $errors[] = 'course_charge';
        if (($l['snapshot']['charge'] ?? null) !== $e['new_amount'] || ($l['snapshot']['period_sessions'] ?? null) !== count($e['session_ids'])
            || $l['snapshot_session_ids'] !== $e['session_ids']) $errors[] = 'snapshot';

        return $errors;
    }

    private function snapshot(array $l): array
    {
        return array_intersect_key($l, array_flip(['item_amount', 'charge', 'snapshot', 'total', 'paid', 'status', 'payment_ids', 'payments_sum']));
    }
}
