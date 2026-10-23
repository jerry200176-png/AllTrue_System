<?php

namespace App\Operations\Strategies;

use App\Models\CoursePackage;
use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Exact-case POP strategy (GitHub #4455, in-app #419) for shared package ledger rows left wrong by the old row-based
 * idempotency (net-based since #4452). Insert-only: class A (charged on the course, never debited on the pool) gets one -1
 * row, class B (debited as `attended` + `attendance`) one +1 row, reason `repair_4455` (one per session under the unique
 * key; marks the row done). Any other mismatch fails the plan closed. Counters are recomputed from the ledger; rollback
 * deletes exactly the inserted rows. Output carries ids and counts only.
 */
final class PackageLedgerNetRepairStrategy
{
    public const REASON = 'repair_4455';
    private const REF = 'repair-package-ledger-net-4455';
    private const GROUP_TYPES = ['one_on_two', 'one_on_three', 'one_on_many', 'tutoring']; // PackageDeductionService::groupSlot()
    private const TAUGHT = ['attended', 'completed', 'late'];

    public function plan(array $parameters): array
    {
        $live = $this->inspect();
        $rowErrors = $this->rowErrors($live);
        $errors = ($parameters['decision_reference'] ?? null) === self::REF ? [] : ['case_parameters_mismatch'];
        $errors = [...$errors, ...array_merge(...array_values($rowErrors))];
        $states = $delta = [];
        foreach ($live['rows'] as $key => $r) {
            $states[$key] = $rowErrors[$key] !== [] ? 'blocked' : ($r['repair_id'] !== null ? 'done' : 'insert');
            $delta[$r['package_id']] = ($delta[$r['package_id']] ?? 0) + ($states[$key] === 'insert' ? $r['delta'] : 0);
        }
        $packages = [];
        foreach ($live['packages'] as $id => $p) {
            // The ledger net too: a pool already used up shows remaining 0 before and after (counters clamp at 0).
            $packages[$id] = $p === null ? null : ['remaining_before' => $p['remaining'], 'remaining_after' => max(0, $p['total'] + $p['net'] + $delta[$id]),
                'ledger_net_before' => $p['net'], 'ledger_net_after' => $p['net'] + $delta[$id]];
        }
        $n = array_count_values($states) + ['insert' => 0, 'done' => 0, 'blocked' => 0];
        $out = ['rows' => $states, 'packages' => $packages];

        return ['ok' => $errors === [], 'errors' => $errors, 'state' => $errors === [] && $n['insert'] === 0 ? 'after' : 'before',
            'counts' => ['rows' => count($states), 'to_insert' => $n['insert'], 'done' => $n['done'], 'blocked' => $n['blocked'],
                'packages' => count($packages)],
            'digest' => hash('sha256', json_encode($out, JSON_THROW_ON_ERROR))] + $out;
    }

    public function execute(array $plan, array $context): array
    {
        $requestId = (string) ($context['operation_id'] ?? '');
        if (!($plan['ok'] ?? false) || $requestId === '' || strlen($requestId) > 64) {
            throw new RuntimeException('package_ledger_plan_not_ready');
        }

        return DB::transaction(function () use ($requestId): array {
            $this->lockPackages();
            $live = $this->inspect(true);
            $errors = array_merge(...array_values($this->rowErrors($live)));
            if ($errors !== []) {
                throw new RuntimeException('package_ledger_drift:' . implode(',', array_slice($errors, 0, 20)));
            }
            // Counters as found before this execute (evidence for the rollback receipt).
            $prior = array_map(fn (?array $p): ?array => $p === null ? null : ['remaining' => $p['remaining'], 'used' => $p['used']],
                $live['packages']);
            $owned = $written = [];
            foreach ($live['rows'] as $key => $r) {
                if ($r['repair_id'] !== null) {
                    if ($r['repair_request_id'] === $requestId) {
                        $owned[$key] = $r['repair_id']; // a retry of this request: its rows stay in the rollback snapshot
                    }
                    continue;
                }
                $owned[$key] = (int) DB::table('package_session_ledger')->insertGetId([
                    'package_id' => $r['package_id'], 'student_class_id' => $r['student_class_id'],
                    'class_session_id' => $r['class_session_id'], 'delta' => $r['delta'], 'reason' => self::REASON,
                    'recorded_by' => null, 'note' => '#4455 class ' . $r['class'], 'request_id' => $requestId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $written[$r['package_id']] = ($written[$r['package_id']] ?? 0) + 1;
            }
            $this->recompute(array_keys($written));
            if ($written !== []) {
                $this->audit('pop.package_ledger_net_repair', array_sum($written));
            }

            return ['ok' => true, 'inserted' => array_sum($written), 'already_applied' => $written === [],
                'snapshot' => ['rows' => $owned, 'packages' => $prior]];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $live = $this->inspect();
        foreach ($live['rows'] as $r) {
            if ($r['repair_id'] === null) $errors[] = "not_repaired_{$r['class_session_id']}";
            if ($r['net'] !== -1) $errors[] = "net_{$r['class_session_id']}"; // A: slot or lesson net; B: lesson net
        }
        foreach ($live['packages'] as $id => $p) {
            $remaining = $p === null ? -1 : max(0, $p['total'] + $p['net']);
            if ($p === null || $p['remaining'] !== $remaining || $p['used'] !== max(0, $p['total'] - $remaining)) {
                $errors[] = "counters_{$id}";
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'checks' => ['target_net', 'repair_row_present', 'counters_match_ledger']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $ids = $snapshot['rows'] ?? null;
        $keys = array_map(fn (array $m): string => "{$m['class']}:{$m['package_id']}:{$m['class_session_id']}",
            PackageLedgerNetRepairManifest::rows());
        if (!is_array($ids) || array_diff(array_keys($ids), $keys) !== []
            || array_filter($ids, fn ($id): bool => !is_int($id) || $id <= 0) !== []) {
            throw new RuntimeException('package_ledger_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($ids): array {
            $this->lockPackages();
            $live = $this->inspect(true);
            $skipped = $touched = [];
            foreach ($ids as $key => $id) {
                $r = $live['rows'][$key];
                if ($r['repair_id'] !== $id || $r['net'] !== -1) {
                    $skipped[] = $key; // moved on since the repair: deleting would leave a wrong net, so leave it alone
                    continue;
                }
                if (DB::table('package_session_ledger')->where('id', $id)->where('reason', self::REASON)
                    ->where('package_id', $r['package_id'])->where('class_session_id', $r['class_session_id'])->delete() !== 1) {
                    throw new RuntimeException("package_ledger_rollback_row_{$id}");
                }
                $touched[$r['package_id']] = ($touched[$r['package_id']] ?? 0) + 1;
            }
            $this->recompute(array_keys($touched));
            $this->audit('pop.package_ledger_net_repair.rollback', array_sum($touched));

            // Any skipped row means the repair is only partly undone: surface it for operator resolution.
            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'deleted' => array_sum($touched), 'skipped' => $skipped];
        }, 3);
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. */
    private function audit(string $event, int $rows): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append($event, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'package_ledger_batch',
            'correlation_id' => $correlationId], ['reason_code' => self::REF, 'row_count' => $rows, 'outcome' => 'success']);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('package_ledger_audit_not_persisted');
        }
    }

    /**
     * Serialises with the deduction path, which locks the course (StudentClass) and then its package: lock every member
     * course of the manifest packages, then the packages, in id order, so no attendance write on them runs meanwhile.
     */
    private function lockPackages(): void
    {
        $ids = array_values(array_unique(array_column(PackageLedgerNetRepairManifest::rows(), 'package_id')));
        // Lock by primary key in id order (a PackageID range lock would follow index order and take gap locks).
        $members = DB::table('StudentClass')->whereIn('PackageID', $ids)->pluck('ID')->all();
        DB::table('StudentClass')->whereIn('ID', $members)->orderBy('ID')->lockForUpdate()->pluck('ID');
        DB::table('course_packages')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
    }

    /** @param array<int, int|string> $packageIds */
    private function recompute(array $packageIds): void
    {
        // Under the package locks, from a current ledger read: a plain sum would reuse the snapshot taken before the locks.
        foreach ($packageIds as $id) {
            /** @var CoursePackage $package */
            $package = CoursePackage::query()->whereKey((int) $id)->lockForUpdate()->firstOrFail();
            $package->recomputeCounters(true);
        }
    }

    /**
     * Live state per manifest row (`A|B:package:session`) and package. `net` is the ledger net the repair targets: a group
     * package's class A slot (PackageDeductionService::slotNet()), else the session. `$current` (execute, rollback): the
     * ledgers are read with shared locks, so a snapshot taken by the POP transaction before the row locks cannot hide a
     * write committed meanwhile (#4454); ClassSession rows are never locked.
     * @return array{rows: array<string, array<string, mixed>>, packages: array<int, array{group: bool, total: int, remaining: int, used: int, net: int}|null>}
     */
    private function inspect(bool $current = false): array
    {
        $read = fn ($query) => $current ? $query->sharedLock() : $query;
        $manifest = PackageLedgerNetRepairManifest::rows();
        $packages = [];
        foreach (array_unique(array_column($manifest, 'package_id')) as $id) {
            $p = DB::table('course_packages')->where('id', $id)->first(['class_type', 'total_sessions', 'remaining_sessions', 'used_sessions']);
            $packages[(int) $id] = $p === null ? null : [
                'group' => in_array((string) $p->class_type, self::GROUP_TYPES, true),
                'total' => (int) $p->total_sessions, 'remaining' => (int) $p->remaining_sessions, 'used' => (int) $p->used_sessions,
                'net' => (int) $read(DB::table('package_session_ledger')->where('package_id', $id))->sum('delta'),
            ];
        }
        $rows = [];
        foreach ($manifest as $m) {
            [$pkg, $cs] = [(int) $m['package_id'], (int) $m['class_session_id']];
            $ledger = $read(DB::table('package_session_ledger')->where('package_id', $pkg)->where('class_session_id', $cs))
                ->orderBy('id')->get(['id', 'student_class_id', 'reason', 'delta', 'request_id']);
            $repair = $ledger->firstWhere('reason', self::REASON);
            $r = ['class' => $m['class'], 'package_id' => $pkg, 'class_session_id' => $cs, 'expected_scope' => $m['scope'],
                'delta' => $m['class'] === 'A' ? -1 : 1, 'repair_id' => $repair ? (int) $repair->id : null,
                'repair_request_id' => $repair ? (string) $repair->request_id : null, 'net' => (int) $ledger->sum('delta'), 'scope' => 'session',
                'session_net' => (int) $ledger->sum('delta')];
            if ($m['class'] === 'B') {
                // The ClassSession row may be gone; the two ledger rows themselves are the precondition.
                $r['others'] = $ledger->where('reason', '!=', self::REASON)
                    ->map(fn ($l): string => $l->reason . ':' . (int) $l->delta)->sort()->values()->all();
                $r['student_class_id'] = (int) ($ledger->firstWhere('reason', 'attendance')->student_class_id ?? 0);
            } else {
                $s = DB::table('ClassSession')->where('id', $cs)->first(['StudentClassID', 'SessionDate', 'StartTime', 'Status']);
                $sc = $s ? (int) $s->StudentClassID : 0;
                $count = fn (string $type): int => $read(DB::table('session_deduction_ledger')->where('student_class_id', $sc)
                    ->where('class_session_id', $cs)->where('event_type', $type))->count();
                $r += ['session' => $s !== null, 'student_class_id' => $sc, 'status' => $s ? strtolower(trim((string) $s->Status)) : null,
                    'course_package_id' => (int) DB::table('StudentClass')->where('ID', $sc)->value('PackageID'),
                    'course_net' => $s ? $count('deduct') - $count('reverse') : 0, 'slot' => null];
                if ($s && ($packages[$pkg]['group'] ?? false) && !empty($s->SessionDate) && !empty($s->StartTime)) {
                    $r['scope'] = 'slot';
                    $r['slot'] = "{$pkg}|{$s->SessionDate} {$s->StartTime}";
                    $slotSessions = DB::table('ClassSession')->where('SessionDate', $s->SessionDate)->where('StartTime', $s->StartTime)->pluck('id');
                    $r['net'] = (int) $read(DB::table('package_session_ledger')->where('package_id', $pkg)
                        ->whereIn('class_session_id', $slotSessions))->sum('delta');
                }
            }
            $rows["{$m['class']}:{$pkg}:{$cs}"] = $r;
        }

        return ['rows' => $rows, 'packages' => $packages];
    }

    /** @return array<string, list<string>> row key => failed preconditions (a row already repaired by this operation has none) */
    private function rowErrors(array $live): array
    {
        $out = $slots = [];
        foreach ($live['rows'] as $key => $r) {
            $cs = $r['class_session_id'];
            $e = [];
            if ($live['packages'][$r['package_id']] === null) {
                $e[] = "package_missing_{$cs}";
            } elseif ($r['repair_id'] !== null) {
                // done: reported and skipped
            } elseif ($r['class'] === 'B') {
                if ($r['net'] !== -2) $e[] = "b_net_{$cs}";
                if ($r['others'] !== ['attendance:-1', 'attended:-1']) $e[] = "b_rows_{$cs}";
            } elseif (!$r['session']) {
                $e[] = "a_session_missing_{$cs}";
            } else {
                if ($r['course_package_id'] !== $r['package_id']) $e[] = "a_package_{$cs}";
                if (!in_array($r['status'], self::TAUGHT, true)) $e[] = "a_status_{$cs}";
                if ($r['scope'] !== $r['expected_scope']) $e[] = "a_scope_{$cs}";
                if ($r['course_net'] <= 0) $e[] = "a_course_net_{$cs}";
                if ($r['net'] !== 0) $e[] = "a_package_net_{$cs}";
                if ($r['session_net'] !== 0) $e[] = "a_session_net_{$cs}"; // the slot could net 0 over a +1 on a sibling session
                if ($r['slot'] !== null && isset($slots[$r['slot']])) $e[] = "a_slot_twice_{$cs}"; // would debit one slot twice
                if ($r['slot'] !== null) $slots[$r['slot']] = true;
            }
            $out[$key] = $e;
        }

        return $out;
    }
}
