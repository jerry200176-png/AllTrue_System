<?php

namespace App\Operations\Strategies;

use App\Models\CoursePackage;
use App\Models\SecurityAuditEvent;
use App\Services\SessionDeductionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Exact-case POP strategy (GitHub #4455, in-app #419) for shared package ledger rows left wrong by the old row-based
 * idempotency (net-based since #4452). Insert-only: class A (charged on the course, never debited on the pool) gets one -1
 * row, class B (debited as `attended` + `attendance`) one +1 row, reason `repair_4455` (one per session under the unique
 * key; marks the row done). Any other mismatch fails the plan closed. Counters are recomputed from the ledger; rollback
 * deletes exactly the inserted rows. Output carries ids and counts only.
 * Round 2 (`decision_reference` REF_R2, reason `repair_4455_r2`): class R brings a lesson's package net back to 0 with one
 * row of minus its net (+1 for a cancelled or deleted lesson still debited or a group slot's second debit, -1 for a stray
 * credit), only while the lesson is not course-charged and the rest of its slot (the lesson's own course on a per-lesson
 * package) then nets -1 if a lesson there is attended, late or course-charged, else 0 (Founder 2026-10-23 rule 1).
 * Round 4 (REF_R4, Founder GO 2026-10-29「AA」): class C reverses a cancelled lesson's course charge through the existing
 * SessionDeductionService::reverseForSession() (course `reverse` + its package +1 sync, source/reason `repair_4455_r4`).
 * Course counters are not recomputed: that recompute clears Stop on a date-mode course and ignores this source anyway.
 */
final class PackageLedgerNetRepairStrategy
{
    public const REASON = 'repair_4455';
    public const REASON_R2 = 'repair_4455_r2';
    public const REASON_R3 = 'repair_4455_r3';
    public const REASON_R4 = 'repair_4455_r4';
    private const REF = 'repair-package-ledger-net-4455';
    private const REF_R2 = 'repair-package-ledger-overdebit-4455';
    private const REF_R3 = 'repair-package-ledger-overdebit-r3-4455'; // round 3: class R as round 2, own reason
    private const REF_R4 = 'repair-course-charge-r4-4455'; // round 4: class C
    private const REFS = [1 => self::REF, 2 => self::REF_R2, 3 => self::REF_R3, 4 => self::REF_R4];
    private const REASONS = [1 => self::REASON, 2 => self::REASON_R2, 3 => self::REASON_R3, 4 => self::REASON_R4];
    private const GROUP_TYPES = ['one_on_two', 'one_on_three', 'one_on_many', 'tutoring']; // PackageDeductionService::groupSlot()
    private const TAUGHT = ['attended', 'completed', 'late'];

    public function plan(array $parameters): array
    {
        $round = array_search($parameters['decision_reference'] ?? null, self::REFS, true) ?: 1;
        $live = $this->inspect($round);
        $rowErrors = $this->rowErrors($live);
        $errors = in_array($parameters['decision_reference'] ?? null, self::REFS, true) ? [] : ['case_parameters_mismatch'];
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
            'digest' => hash('sha256', json_encode($out, JSON_THROW_ON_ERROR)), 'round' => $round] + $out;
    }

    public function execute(array $plan, array $context): array
    {
        $requestId = (string) ($context['operation_id'] ?? '');
        if (!($plan['ok'] ?? false) || $requestId === '' || strlen($requestId) > 64) {
            throw new RuntimeException('package_ledger_plan_not_ready');
        }

        $round = (int) ($plan['round'] ?? 1);
        $members = $this->memberCourseIds($round); // read before the transaction: its first statement must be a lock (REPEATABLE READ)
        return DB::transaction(function () use ($requestId, $members, $round): array {
            $this->lockPackages($members, $round);
            $live = $this->inspect($round, true);
            $errors = array_merge(...array_values($this->rowErrors($live)));
            if ($errors !== []) {
                throw new RuntimeException('package_ledger_drift:' . implode(',', array_slice($errors, 0, 20)));
            }
            // Counters as found before this execute (evidence for the rollback receipt).
            $prior = array_map(fn (?array $p): ?array => $p === null ? null : ['remaining' => $p['remaining'], 'used' => $p['used']],
                $live['packages']);
            $owned = $written = $course = [];
            foreach ($live['rows'] as $key => $r) {
                if ($r['repair_id'] !== null) {
                    if ($r['repair_request_id'] === $requestId) {
                        $owned[$key] = $r['repair_id']; // a retry of this request: its rows stay in the rollback snapshot
                        if ($r['class'] === 'C') $course[$key] = $r['course_repair_id'];
                    }
                    continue;
                }
                if ($r['class'] === 'C') {
                    // The course's own reverse writer: course `reverse`, then its package +1 (same reason) and package counters.
                    $cs = $r['class_session_id'];
                    SessionDeductionService::reverseForSession($r['student_class_id'], $cs, $live['reason'], null, '#4455 class C');
                    $owned[$key] = (int) DB::table('package_session_ledger')->where('package_id', $r['package_id'])
                        ->where('class_session_id', $cs)->where('reason', $live['reason'])->value('id');
                    $course[$key] = (int) DB::table('session_deduction_ledger')->where('class_session_id', $cs)
                        ->where('event_type', 'reverse')->where('source', $live['reason'])->value('id');
                    if ($owned[$key] === 0 || $course[$key] === 0) throw new RuntimeException("package_ledger_course_reverse_{$cs}");
                    DB::table('package_session_ledger')->where('id', $owned[$key])->update(['request_id' => $requestId]); // written just now
                    $written[$r['package_id']] = ($written[$r['package_id']] ?? 0) + 1;
                    continue;
                }
                $owned[$key] = (int) DB::table('package_session_ledger')->insertGetId([
                    'package_id' => $r['package_id'], 'student_class_id' => $r['student_class_id'],
                    'class_session_id' => $r['class_session_id'], 'delta' => $r['delta'], 'reason' => $live['reason'],
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
                'snapshot' => ['rows' => $owned, 'packages' => $prior, 'round' => $round] + ($course === [] ? [] : ['course_rows' => $course])];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $live = $this->inspect((int) ($plan['round'] ?? 1));
        foreach ($live['rows'] as $r) {
            if ($r['repair_id'] === null) $errors[] = "not_repaired_{$r['class_session_id']}";
            if ($r['net'] !== $r['target']) $errors[] = "net_{$r['class_session_id']}"; // A: slot or lesson net; B: lesson net; R, C: lesson net 0
            if ($r['class'] === 'C' && ($r['course_net'] !== 0 || $r['course_repair_id'] === null)) $errors[] = "course_net_{$r['class_session_id']}";
        }
        foreach ($live['packages'] as $id => $p) {
            $remaining = $p === null ? -1 : max(0, $p['total'] + $p['net']);
            if ($p === null || $p['remaining'] !== $remaining || $p['used'] !== max(0, $p['total'] - $remaining)) {
                $errors[] = "counters_{$id}";
            }
            if ($p !== null && $p['floor'] !== null && -$p['net'] < $p['floor']) $errors[] = "floor_{$id}";
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'checks' => ['target_net', 'repair_row_present', 'counters_match_ledger']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $ids = $snapshot['rows'] ?? null;
        $round = (int) ($snapshot['round'] ?? 1);
        $keys = array_map(fn (array $m): string => "{$m['class']}:{$m['package_id']}:{$m['class_session_id']}",
            PackageLedgerNetRepairManifest::rows($round));
        if (!is_array($ids) || array_diff(array_keys($ids), $keys) !== []
            || array_filter($ids, fn ($id): bool => !is_int($id) || $id <= 0) !== []) {
            throw new RuntimeException('package_ledger_rollback_snapshot_invalid');
        }

        $course = $snapshot['course_rows'] ?? [];
        $members = $this->memberCourseIds($round);
        return DB::transaction(function () use ($ids, $members, $round, $course): array {
            $this->lockPackages($members, $round);
            $live = $this->inspect($round, true);
            $skipped = $touched = [];
            foreach ($ids as $key => $id) {
                $r = $live['rows'][$key];
                $c = $r['class'] === 'C';
                if ($r['repair_id'] !== $id || $r['net'] !== $r['target']
                    || ($c && ($r['course_net'] !== 0 || $r['course_repair_id'] === null || $r['course_repair_id'] !== ($course[$key] ?? null)))) {
                    $skipped[] = $key; // moved on since the repair: deleting would leave a wrong net, so leave it alone
                    continue;
                }
                if ($c && DB::table('session_deduction_ledger')->where('id', $course[$key])->where('source', $live['reason'])
                    ->where('class_session_id', $r['class_session_id'])->delete() !== 1) {
                    throw new RuntimeException("package_ledger_rollback_course_row_{$course[$key]}");
                }
                if (DB::table('package_session_ledger')->where('id', $id)->where('reason', $live['reason'])
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
    /** @param list<int|string> $members read before the transaction; locked by primary key in id order (no gap locks) */
    private function lockPackages(array $members, int $round): void
    {
        DB::table('StudentClass')->whereIn('ID', $members)->orderBy('ID')->lockForUpdate()->pluck('ID');
        DB::table('course_packages')->whereIn('id', $this->packageIds($round))->orderBy('id')->lockForUpdate()->pluck('id');
    }

    private function memberCourseIds(int $round): array
    {
        return DB::table('StudentClass')->whereIn('PackageID', $this->packageIds($round))->pluck('ID')->all();
    }

    private function packageIds(int $round): array
    {
        return array_values(array_unique(array_column(PackageLedgerNetRepairManifest::rows($round), 'package_id')));
    }

    /** @param array<int, int|string> $packageIds */
    private function recompute(array $packageIds): void
    {
        // Under the package locks, from a current ledger read: a plain sum would reuse the snapshot taken before the locks.
        foreach ($packageIds as $id) {
            /** @var CoursePackage|null $package */
            $package = CoursePackage::query()->whereKey((int) $id)->lockForUpdate()->first();
            if ($package === null) {
                throw new RuntimeException("package_ledger_package_missing_{$id}");
            }
            $package->recomputeCounters(true);
        }
    }

    /**
     * Live state per manifest row (`A|B:package:session`) and package. `net` is the ledger net the repair targets: a group
     * package's class A slot (PackageDeductionService::slotNet()), else the session. `$current` (execute, rollback): the
     * ledgers are read with shared locks, so a snapshot taken by the POP transaction before the row locks cannot hide a
     * write committed meanwhile (#4454); ClassSession rows are locked only by round 3's floor read (shared).
     * Round 3 also reads each package's floor: taught slots plus absent slots of its member lessons, as the probe counts
     * them (Founder 2026-10-29: used never below taught + absent).
     * @return array{rows: array<string, array<string, mixed>>, packages: array<int, array{group: bool, total: int, remaining: int, used: int, net: int, floor: ?int}|null>, reason: string}
     */
    private function inspect(int $round, bool $current = false): array
    {
        $read = fn ($query) => $current ? $query->sharedLock() : $query;
        $reason = self::REASONS[$round];
        $manifest = PackageLedgerNetRepairManifest::rows($round);
        $courseNet = fn (int $cs): int => (int) $read(DB::table('session_deduction_ledger')->where('class_session_id', $cs))
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type = 'deduct' THEN 1 WHEN event_type = 'reverse' THEN -1 ELSE 0 END), 0) AS net")->value('net');
        $packages = [];
        foreach (array_unique(array_column($manifest, 'package_id')) as $id) {
            $p = DB::table('course_packages')->where('id', $id)->first(['class_type', 'total_sessions', 'remaining_sessions', 'used_sessions']);
            $packages[(int) $id] = $p === null ? null : [
                'group' => in_array((string) $p->class_type, self::GROUP_TYPES, true),
                'total' => (int) $p->total_sessions, 'remaining' => (int) $p->remaining_sessions, 'used' => (int) $p->used_sessions,
                'net' => (int) $read(DB::table('package_session_ledger')->where('package_id', $id))->sum('delta'),
                // Under execute/rollback a shared lock, so a member lesson's status write (scheduled -> absent locks only the
                // session) waits for the repair to commit instead of raising the floor after it was read (Codex on #4630).
                'floor' => $round < 3 ? null : (int) $read(DB::table('ClassSession as c')->join('StudentClass as sc', 'sc.ID', '=', 'c.StudentClassID'))
                    ->where('sc.PackageID', $id)->selectRaw("COUNT(DISTINCT CASE WHEN c.Status IN ('attended', 'completed', 'late') THEN CONCAT(c.SessionDate, ' ', c.StartTimeHM) END)"
                        . " + COUNT(DISTINCT CASE WHEN LOWER(c.Status) = 'absent' THEN CONCAT(c.SessionDate, ' ', c.StartTimeHM) END) AS n")->value('n'),
            ];
        }
        $rows = [];
        foreach ($manifest as $m) {
            [$pkg, $cs] = [(int) $m['package_id'], (int) $m['class_session_id']];
            $ledger = $read(DB::table('package_session_ledger')->where('package_id', $pkg)->where('class_session_id', $cs))
                ->orderBy('id')->get(['id', 'student_class_id', 'reason', 'delta', 'request_id']);
            $repair = $ledger->firstWhere('reason', $reason);
            $r = ['class' => $m['class'], 'package_id' => $pkg, 'class_session_id' => $cs, 'expected_scope' => $m['scope'],
                'delta' => match ($m['class']) { 'A' => -1, 'R' => -(int) $m['net'], default => 1 }, 'target' => in_array($m['class'], ['R', 'C'], true) ? 0 : -1,
                'repair_id' => $repair ? (int) $repair->id : null,
                'repair_request_id' => $repair ? (string) $repair->request_id : null, 'net' => (int) $ledger->sum('delta'), 'scope' => 'session',
                'session_net' => (int) $ledger->sum('delta')];
            if ($m['class'] === 'R') {
                // The lesson as found (or `deleted`), its course charge, and the rest of its slot: the package's member
                // lessons at the manifest slot (group types) or the same course's (per lesson), ledger nets read current.
                $s = DB::table('ClassSession')->where('id', $cs)->first(['SessionDate', 'StartTimeHM', 'Status']);
                $r['student_class_id'] = (int) ($ledger->where('reason', '!=', $reason)->last()->student_class_id ?? 0);
                [$date, $hm] = explode(' ', $m['slot']);
                $rest = DB::table('ClassSession as c')->join('StudentClass as sc', 'sc.ID', '=', 'c.StudentClassID')
                    ->where('sc.PackageID', $pkg)->where('c.SessionDate', $date)->where('c.StartTimeHM', $hm)->where('c.id', '!=', $cs)
                    ->when($m['scope'] === 'session', fn ($q) => $q->where('c.StudentClassID', $r['student_class_id']))
                    ->orderBy('c.id')->get(['c.id', 'c.Status'])
                    ->map(fn ($l): array => ['id' => (int) $l->id, 'charged' => in_array(strtolower(trim((string) $l->Status)), ['attended', 'late'], true)
                        || $courseNet((int) $l->id) > 0, 'net' => (int) $read(DB::table('package_session_ledger')->where('package_id', $pkg)
                        ->where('class_session_id', $l->id))->sum('delta')])->all();
                $r += ['status' => $s ? strtolower(trim((string) $s->Status)) : 'deleted', 'expected_status' => $m['status'], 'found_net' => (int) $m['net'],
                    'slot' => "{$pkg}|{$m['slot']}", 'slot_moved' => $s !== null && "{$s->SessionDate} {$s->StartTimeHM}" !== $m['slot'],
                    'course_net' => $courseNet($cs), 'rest' => $rest];
            } elseif ($m['class'] === 'C') {
                // The lesson as found, on the manifest course, its course charge and this round's course reverse, read current.
                $s = DB::table('ClassSession')->where('id', $cs)->first(['StudentClassID', 'SessionDate', 'StartTimeHM', 'Status']);
                $courseRepair = $read(DB::table('session_deduction_ledger')->where('class_session_id', $cs)->where('event_type', 'reverse')
                    ->where('source', $reason))->value('id');
                $r += ['student_class_id' => (int) $m['student_class_id'], 'status' => $s ? strtolower(trim((string) $s->Status)) : 'deleted',
                    'expected_status' => $m['status'], 'slot_moved' => $s === null || (int) $s->StudentClassID !== (int) $m['student_class_id']
                        || "{$s->SessionDate} {$s->StartTimeHM}" !== $m['slot'],
                    'course_net' => $courseNet($cs), 'course_repair_id' => $courseRepair ? (int) $courseRepair : null,
                    'course_package_id' => (int) DB::table('StudentClass')->where('ID', $m['student_class_id'])->value('PackageID')];
            } elseif ($m['class'] === 'B') {
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

        return ['rows' => $rows, 'packages' => $packages, 'reason' => $reason];
    }

    /** @return array<string, list<string>> row key => failed preconditions (a row already repaired by this operation has none) */
    private function rowErrors(array $live): array
    {
        $out = $slots = $reversed = $pending = [];
        foreach ($live['rows'] as $r) {
            if ($r['class'] === 'R') $reversed[$r['slot']][] = $r['class_session_id'];
            if (in_array($r['class'], ['R', 'C'], true) && $r['repair_id'] === null) $pending[$r['package_id']] = ($pending[$r['package_id']] ?? 0) + $r['delta'];
        }
        // Rounds 3-4: the package's pending reversals keep used at or above its taught + absent floor.
        $belowFloor = function (array $r) use ($live, $pending): bool {
            $p = $live['packages'][$r['package_id']];

            return $p['floor'] !== null && -($p['net'] + $pending[$r['package_id']]) < $p['floor'];
        };
        foreach ($live['rows'] as $key => $r) {
            $cs = $r['class_session_id'];
            $e = [];
            if ($live['packages'][$r['package_id']] === null) {
                $e[] = "package_missing_{$cs}";
            } elseif ($r['repair_id'] !== null) {
                // done: reported and skipped
            } elseif ($r['class'] === 'R') {
                if ($live['packages'][$r['package_id']]['group'] !== ($r['expected_scope'] === 'slot')) $e[] = "r_scope_{$cs}";
                if ($r['status'] !== $r['expected_status'] || $r['slot_moved']) $e[] = "r_status_{$cs}";
                if (!in_array($r['found_net'], [-1, 1], true) || $r['net'] !== $r['found_net']) $e[] = "r_net_{$cs}";
                if ($r['course_net'] > 0) $e[] = "r_course_net_{$cs}"; // still charged on its course: the package follows (rule 1)
                if ($belowFloor($r)) $e[] = "r_floor_{$cs}";
                // After every reversal in this slot, the slot keeps one debit if any lesson there, the reversed ones
                // included (an attended second debit), is attended, late or charged, else none.
                $rest = array_filter($r['rest'], fn (array $l): bool => !in_array($l['id'], $reversed[$r['slot']], true));
                $charged = in_array($r['status'], ['attended', 'late'], true) || array_filter($r['rest'], fn (array $l): bool => $l['charged']) !== [];
                if (array_sum(array_column($rest, 'net')) !== ($charged ? -1 : 0)) $e[] = "r_slot_net_{$cs}";
            } elseif ($r['class'] === 'C') {
                if ($r['status'] !== $r['expected_status'] || $r['slot_moved']) $e[] = "c_status_{$cs}"; // still cancelled, same course and slot
                if ($r['net'] !== -1) $e[] = "c_net_{$cs}"; // its package debit, which the course reverse credits back
                if ($r['course_net'] !== 1 || $r['course_repair_id'] !== null) $e[] = "c_course_net_{$cs}"; // charged exactly once
                if ($r['course_package_id'] !== $r['package_id']) $e[] = "c_package_{$cs}"; // the sync credits this package
                if ($belowFloor($r)) $e[] = "c_floor_{$cs}";
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
