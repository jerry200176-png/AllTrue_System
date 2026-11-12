<?php

namespace App\Operations\Strategies;

use App\Models\ClassSession;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Services\Scheduling\ClassSessionContractReassigner;
use App\Services\Scheduling\DeductionBasis;
use App\Services\SessionDeductionService;
use App\Support\LearningRecordMutableOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * GitHub #5124 / #5162 C1, design issuecomment-6813739987: a chain-aware contract repair (plan, execute, verify, restore).
 *
 * One student's count contracts of one subject form a chain. A reviewed, explicitly ordered list of moves (lesson -> from
 * contract -> to contract) is validated as ONE plan: scope (campus, student, subject, the whole chain), drift (owner, date,
 * time, status), the result (taught <= bought on every contract, total taught unchanged, reservations fit), money locks, the
 * reassigner's own refusals (slot taken, locked assessment) and the two Founder decisions, which default to "no":
 * `allow_active_contracts` and `allow_order_only_moves`. The oldest-first allocation is computed for comparison only.
 *
 * `plan()` only reads. `execute()` runs a plan that is pinned to its own digest: it locks the student, the chain and its
 * lessons in a fixed order, plans again under those locks and refuses on any difference, then makes every move through
 * ClassSessionContractReassigner (lesson + sign-in + ledger + learning record, counters recomputed, one reassignment row and
 * one audit event per move) in one transaction; a refusal or failure on any move undoes all of them. No lesson is cancelled
 * or created and no money row is written: the fingerprint of the chain's contracts, invoices, items, payments and payment
 * reports must be the same before and after. The snapshot records the prior owner of every moved lesson.
 *
 * Undo is the inverse moves in reverse order, through the same service, all or nothing. In production the one way to undo
 * is a new Founder-approved request with `restore_request_id`; `rollback()` implements the POP rollback phase, which the
 * Pi-local runner does not offer for this operation. Both refuse, with a per-lesson report, when a moved lesson's owner,
 * date, time, status or mirror rows changed after the execute, and with `restore_already_applied` when the receipts show
 * the cited request was undone before. Before an execute the stored counters must be the derived ones
 * (`counters_not_derived`), so the counters an undo recomputes are the ones stored before the execute.
 *
 * Failure: POP rolls back every data change of a failed execute, verify or rollback before it writes the failed receipt
 * (PopOperationService::run, PopPhaseAborted), also when a lock-wait timeout skipped the nested savepoint rollback, and
 * also when a deadlock made that rollback itself throw: the receipt is written for whatever escapes a phase that began.
 *
 * Output carries ids, dates, statuses and counts: no names, no amounts.
 */
final class CourseContractChainRebalanceStrategy
{
    public const OPERATION = 'course-contract-chain-rebalance';
    private const AUDIT = 'pop.course_contract_chain_rebalance';
    private const SNAPSHOT_VERSION = 1;
    /** Rows that follow a lesson: table, lesson column, contract column. */
    private const MIRRORS = [['StudentSingIn', 'ClassSessionID', 'StudentClassID'], ['LearningRecord', 'ClassSessionID', 'StudentClassID'],
        ['session_deduction_ledger', 'class_session_id', 'student_class_id']];
    /** StudentClass columns the reassigner's counter recompute may write; every other contract column must stay as it was. */
    private const COUNTER_COLUMNS = ['UsedSessions', 'RemainingSessions', 'PurchasedMinutes', 'RemainingMinutes'];

    /** Statuses that count as a taught lesson (the set SessionDeductionService counts on ClassSession). */
    private const TAUGHT = ['attended', 'completed', 'late'];
    private const MAX_MOVES = 200;
    private const MAX_CONTRACTS = 40;
    private const MAX_SESSIONS = 5000;

    /** @param array<string,mixed> $parameters @param array<string,mixed> $context @return array<string,mixed> */
    public function plan(array $parameters, array $context = []): array
    {
        [$manifest, $errors] = $this->manifest($parameters);
        if ($errors !== []) {
            return $this->rejected($errors, [], 'invalid', ($parameters['restore_request_id'] ?? '') === '' ? 'rebalance' : 'restore');
        }

        return $this->evaluate($manifest, (string) ($parameters['expected_digest'] ?? ''), null);
    }

    /**
     * The whole validation, read-only. `$source` is the snapshot of the execute being undone: given by `rollback()`, or
     * loaded here for a restore request. Undo is checked like any plan (scope, drift, mirrors, money locks, the reassigner's
     * refusals) except the rules about the result: it returns the chain to a state that was there before, so the capacity
     * rules and the two Founder decisions do not apply; instead every moved lesson must be as the execute left it.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed>|null $source
     * @return array<string,mixed>
     */
    private function evaluate(array $manifest, string $want, ?array $source): array
    {
        $mode = $source !== null || $manifest['restore_request_id'] !== '' ? 'restore' : 'rebalance';

        // Campus isolation first (acceptance 15): a student of another campus is refused before any contract or lesson is read.
        // No role is exempt: a super_admin may ask for any campus, but the manifest's campus must be the student's own.
        $studentCampus = DB::table('Student')->where('id', $manifest['student_id'])->value('CampusID');
        if ($studentCampus === null || (int) $studentCampus !== $manifest['campus_id']) {
            return $this->rejected(['student_campus_mismatch'], [], 'rejected', $mode);
        }
        if ($source === null && $manifest['restore_request_id'] !== '') {
            $source = $this->sourceSnapshot($manifest['restore_request_id'], $manifest);
            if ($source === null) {
                // One code for: unknown request, another operation, another campus / student / subject, not executed, itself a restore.
                return $this->rejected(['restore_source_not_found'], [], 'rejected', $mode);
            }
        }
        $restore = $source !== null;
        // #5236 review P2b: "already restored" is a fact of the records, not of the rows. After request R1, its restore and a
        // second request that moves the same lessons again, the rows look as R1 left them; a restore citing R1 must still
        // be refused. The receipt of the restore run (or of R1's rollback phase) commits with its moves and names R1.
        if ($source !== null && ($by = $this->restoredBy((string) $source['request_id'])) !== null) {
            return $this->rejected(['restore_already_applied'], [['code' => 'restore_already_applied', 'restored_by_request_id' => $by]], 'rejected', $mode);
        }

        $findings = [];
        $add = function (string $code, array $ids = []) use (&$findings): void {
            $findings[] = ['code' => $code] + $ids;
        };

        // ── Contracts: the listed ids must be exactly the student's non-package count contracts of this subject. ──
        $listed = $manifest['contract_ids'];
        $columns = ['ID', 'SubjectID', 'TeacherID', 'PackageID', 'ScheduleMode', 'SessionCount', 'SessionDuration',
            'standard_lesson_minutes', 'deduction_basis', 'Stop', 'closed_reason', 'settlement_locked_at', 'StartDate'];
        // Y10: only this student's contracts are read (the student is already bound to the campus). A listed id that is
        // missing or belongs to someone else gets one code, so the answer never tells whether another student's id exists.
        $rows = DB::table('StudentClass')->where('StudentID', $manifest['student_id'])
            ->where(fn ($q) => $q->whereIn('ID', $listed)->orWhere('SubjectID', $manifest['subject_id']))
            ->orderBy('ID')->get($columns)->keyBy('ID');
        foreach ($listed as $id) {
            $c = $rows->get($id);
            if (!$c) {
                $add('contract_not_owned_by_student', ['contract_id' => $id]);
            } elseif ((int) $c->SubjectID !== $manifest['subject_id']) {
                $add('contract_subject_mismatch', ['contract_id' => $id]);
            } elseif ((int) $c->PackageID > 0) {
                $add('package_contract_unsupported', ['contract_id' => $id]); // the reassigner does not move package_session_ledger
            } elseif (strtolower((string) $c->ScheduleMode) !== 'count') {
                $add('non_count_contract_unsupported', ['contract_id' => $id]);
            }
        }
        foreach ($rows as $c) {
            $inChain = (int) $c->SubjectID === $manifest['subject_id'] && (int) $c->PackageID <= 0 && strtolower((string) $c->ScheduleMode) === 'count';
            if ($inChain && !in_array((int) $c->ID, $listed, true)) {
                $add('contract_chain_incomplete', ['contract_id' => (int) $c->ID]); // no silent partial chain
            }
        }
        if ($findings !== []) {
            return $this->rejected($this->codes($findings), $findings, 'rejected', $mode);
        }

        /** @var array<int,\stdClass> $chain */
        $chain = [];
        foreach ($listed as $id) {
            $c = $rows->get($id);
            if ($c instanceof \stdClass) {
                $chain[$id] = $c;
            }
        }
        ksort($chain);
        $minutes = [];
        foreach ($chain as $id => $c) {
            if (in_array((string) $c->closed_reason, StudentClass::TERMINAL_MONEY_CLOSURES, true)) {
                $add('contract_terminal_money_closure', ['contract_id' => $id]);
            } elseif ($c->settlement_locked_at !== null || in_array((string) $c->closed_reason, StudentClass::USAGE_LOCK_CLOSURES, true)) {
                $add('contract_settlement_locked', ['contract_id' => $id]);
            }
            if ((string) $c->deduction_basis === DeductionBasis::ACTUAL_DURATION) {
                $add('actual_duration_contract_unsupported', ['contract_id' => $id]);
            }
            if ((int) $c->SessionCount <= 0) {
                $add('contract_session_count_invalid', ['contract_id' => $id]);
            }
            // A moved ledger row keeps its minutes (G-011): every contract of the chain must use one lesson length.
            $minutes[] = ((int) $c->SessionDuration >= 1 ? (int) $c->SessionDuration : StudentClass::DEFAULT_SESSION_MINUTES)
                . '/' . (int) $c->standard_lesson_minutes;
        }
        if (count(array_unique($minutes)) > 1) {
            $add('session_duration_differs');
        }

        // ── Lessons of the chain, and the moved lessons that are not on it (to say why). ──
        $chainIds = array_keys($chain);
        $sessions = DB::table('ClassSession')->whereIn('StudentClassID', $chainIds)->orderBy('id')->limit(self::MAX_SESSIONS + 1)
            ->get(['id', 'StudentClassID', 'SubjectID', 'SessionDate', 'StartTime', 'Status', 'session_charge'])->keyBy('id');
        if ($sessions->count() > self::MAX_SESSIONS) {
            return $this->rejected(['chain_too_large'], [], 'rejected', $mode);
        }
        $moveIds = array_values(array_unique(array_column($manifest['moves'], 'session_id')));
        $outside = array_values(array_diff($moveIds, $sessions->keys()->map(fn ($id) => (int) $id)->all()));
        $foreign = [];
        if ($outside !== []) {
            // Y10: looked up inside this campus only; an id that is unknown or in another campus gets the same code.
            $foreignRows = DB::table('ClassSession as cs')->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                ->join('Student as st', 'st.id', '=', 'sc.StudentID')->where('st.CampusID', $manifest['campus_id'])
                ->whereIn('cs.id', $outside)->get(['cs.id', 'sc.StudentID', 'sc.SubjectID']);
            foreach ($foreignRows as $f) {
                $foreign[(int) $f->id] = (int) $f->StudentID !== $manifest['student_id'] ? 'session_student_mismatch'
                    : ((int) $f->SubjectID !== $manifest['subject_id'] ? 'session_subject_mismatch' : 'session_not_in_chain');
            }
        }

        // ── Mirrors before: contract totals, then ownership of every row that follows a lesson. ──
        $today = now()->toDateString();
        $owner = [];
        $taught = array_fill_keys($chainIds, 0);
        foreach ($sessions as $s) {
            $owner[(int) $s->id] = (int) $s->StudentClassID;
            if ($this->isTaught($s, $today)) {
                $taught[(int) $s->StudentClassID]++;
            }
        }
        $diagnostics = SessionDeductionService::batchExpectedUsedSessionDiagnostics($chainIds);
        foreach ($chainIds as $id) {
            $d = $diagnostics[$id] ?? null;
            if ($d === null) {
                $add('mirror_inconsistent', ['contract_id' => $id]);
                continue;
            }
            if ($d['has_partial']) {
                $add('partial_minutes_unsupported', ['contract_id' => $id]);
            }
            // The plan counts taught lessons from ClassSession; that is only the app's used count when the ledger and the
            // observed usage (sign-ins, learning records) say the same number and no cancelled lesson still carries usage.
            if ($d['ledger_used'] !== $d['observed_used'] || $d['uncapped_used'] !== $taught[$id] || $d['cancelled_usage_artifacts'] > 0) {
                $add('mirror_inconsistent', ['contract_id' => $id]);
            }
        }
        // Usage bound to no lesson (a live sign-in, a learning record or a ledger row without a ClassSession) stays on its
        // contract when lessons move, and the app's used count is the maximum over its sources: the projected counts would
        // be wrong, so such a chain is refused (Codex P2 on #5222; same rule as ForwardOverdeductReassignStrategy).
        $unbound = array_merge(
            DB::table('StudentSingIn as si')->leftJoin('ClassSession as cs', 'cs.id', '=', 'si.ClassSessionID')
                ->whereIn('si.StudentClassID', $chainIds)->whereNull('si.VoidedAt')->whereNull('cs.id')->distinct()->pluck('si.StudentClassID')->all(),
            DB::table('LearningRecord')->whereIn('StudentClassID', $chainIds)->whereNull('VoidedAt')
                ->where(fn ($q) => $q->whereNull('ClassSessionID')->orWhere('ClassSessionID', '<=', 0))->distinct()->pluck('StudentClassID')->all(),
            DB::table('session_deduction_ledger as l')->leftJoin('ClassSession as cs', 'cs.id', '=', 'l.class_session_id')
                ->whereIn('l.student_class_id', $chainIds)->whereNull('cs.id')->distinct()->pluck('l.student_class_id')->all(),
        );
        $unbound = array_values(array_unique(array_map('intval', $unbound)));
        sort($unbound);
        foreach ($unbound as $id) {
            $add('unbound_usage', ['contract_id' => $id]);
        }
        $sessionIds = array_keys($owner);
        $ledgerNet = [];
        $mirrorState = [];
        $deductedSignIn = [];
        foreach (array_chunk($sessionIds, 500) as $chunk) {
            foreach (self::MIRRORS as [$table, $sessionColumn, $ownerColumn]) {
                $select = [$sessionColumn . ' as sid', $ownerColumn . ' as cid'];
                if ($table === 'session_deduction_ledger') {
                    $select[] = 'event_type';
                } elseif ($table === 'StudentSingIn') {
                    array_push($select, 'SessionDeducted', 'VoidedAt');
                }
                foreach (DB::table($table)->whereIn($sessionColumn, $chunk)->orderBy('id')->get($select) as $m) {
                    $sid = (int) $m->sid;
                    if ((int) $m->cid !== $owner[$sid]) {
                        $add('mirror_inconsistent', ['session_id' => $sid]);
                    }
                    $mirrorState[$sid][$table] = ($mirrorState[$sid][$table] ?? 0) + 1;
                    if ($table === 'session_deduction_ledger') {
                        $ledgerNet[$sid] = ($ledgerNet[$sid] ?? 0) + ((string) $m->event_type === 'deduct' ? 1 : -1);
                    } elseif ($table === 'StudentSingIn' && $m->VoidedAt === null && (int) $m->SessionDeducted === 1) {
                        $deductedSignIn[$sid] = true;
                    }
                }
            }
        }
        // Usage must sit on taught lessons only, one net deduction each (Codex P2 on #5222): a deduction or a deducted
        // sign-in on a lesson that is not taught counts toward its contract but is not in the plan's taught count, so the
        // projection after the moves would be wrong. With this, every usage source equals the taught lessons and follows them.
        foreach ($sessions as $s) {
            $sid = (int) $s->id;
            $isTaught = $this->isTaught($s, $today);
            if (($ledgerNet[$sid] ?? 0) !== ($isTaught ? 1 : 0) || (!$isTaught && isset($deductedSignIn[$sid]))) {
                $add('mirror_inconsistent', ['session_id' => $sid]);
            }
        }

        $sound = $findings === []; // contracts and mirrors agree: the derived counters below mean something

        // ── Undo only: the list must be the executed moves reversed, and every moved lesson must be as the execute left it. ──
        $restoreRows = [];
        if ($source !== null) {
            if ($manifest['moves'] !== $this->inverseMoves($source['rows'])) {
                $add('restore_moves_mismatch');
            }
            foreach ($source['rows'] as $r) {
                $sid = (int) $r['id'];
                $s = $sessions->get($sid);
                $state = 'restorable';
                if (!$s) {
                    $state = 'not_on_chain'; // deleted, or moved to a contract outside the chain
                } elseif ($owner[$sid] !== (int) $r['to_class_id']) {
                    $state = 'owner_changed';
                } elseif (substr((string) $s->SessionDate, 0, 10) !== $r['date'] || substr((string) $s->StartTime, 0, 5) !== $r['start_time']
                    || strtolower((string) $s->Status) !== $r['status']) {
                    $state = 'lesson_changed';
                } elseif (($mirrorState[$sid] ?? []) != $r['mirrors'] || ($ledgerNet[$sid] ?? 0) !== (int) $r['ledger_net']) {
                    $state = 'mirrors_changed';
                }
                $restoreRows[] = ['session_id' => $sid, 'state' => $state];
                if ($state !== 'restorable') {
                    $add('restore_row_changed', ['session_id' => $sid, 'state' => $state]);
                }
            }
        }

        // ── The moves, applied in order to a copy of the ownership map (what execute would do, one reassign per move). ──
        $reserved = array_fill_keys($chainIds, 0);
        foreach ($sessions as $s) {
            if ($this->isReserved($s, $today)) {
                $reserved[(int) $s->StudentClassID]++;
            }
        }
        $before = ['taught' => $taught, 'reserved' => $reserved];
        $now = $owner;
        $seen = [];
        $applied = [];
        $teacherMonths = [];
        $teacherChanges = 0;
        foreach ($manifest['moves'] as $index => $move) {
            $sid = $move['session_id'];
            $at = ['move' => $index + 1, 'session_id' => $sid];
            if (isset($seen[$sid])) {
                $add('session_moved_twice', $at);
                continue;
            }
            $seen[$sid] = true;
            if (!isset($chain[$move['from']]) || !isset($chain[$move['to']])) {
                $add('move_contract_not_in_chain', $at);
                continue;
            }
            if ($move['from'] === $move['to']) {
                $add('move_noop', $at);
                continue;
            }
            $s = $sessions->get($sid);
            if (!$s) {
                $add($foreign[$sid] ?? 'session_not_found_in_campus', $at);
                continue;
            }
            if ($s->SubjectID !== null && (int) $s->SubjectID !== $manifest['subject_id']) {
                $add('session_subject_mismatch', $at); // per-lesson subject override
                continue;
            }
            if ($now[$sid] !== $move['from']) {
                $add('move_from_mismatch', $at);
                continue;
            }
            $status = strtolower((string) $s->Status);
            if (substr((string) $s->SessionDate, 0, 10) !== $move['date'] || substr((string) $s->StartTime, 0, 5) !== $move['start_time']
                || $status !== $move['status']) {
                $add('session_drift', $at);
                continue;
            }
            if (!in_array($status, ClassSessionContractReassigner::STATUSES, true)) {
                $add('session_status_unsupported', $at);
                continue;
            }
            if ($s->session_charge !== null) {
                $add('session_charge_override_present', $at); // a per-lesson price would move money between contracts
            }
            $isTaught = $this->isTaught($s, $today);
            $from = $chain[$move['from']];
            $to = $chain[$move['to']];
            if (!$restore && (!$manifest['allow_active_contracts']) && ($this->isActive($from) || $this->isActive($to))) {
                $add('decision_required_active_contract', $at);
            }
            if ($status !== 'cancelled') {
                foreach ($sessions as $other) {
                    if ((int) $other->id !== $sid && $now[(int) $other->id] === $move['to'] && strtolower((string) $other->Status) !== 'cancelled'
                        && substr((string) $other->SessionDate, 0, 10) === $move['date'] && substr((string) $other->StartTime, 0, 5) === $move['start_time']) {
                        $add('target_slot_taken', $at);
                        break;
                    }
                }
            }
            // The undo moves the lesson back through the same guard, so a locked assessment must match both teachers.
            $locked = LearningRecordMutableOwnership::protectedRecordForSession($sid, false);
            if ($locked !== null && ((int) $locked->TeacherID !== (int) $to->TeacherID || (int) $locked->TeacherID !== (int) $from->TeacherID)) {
                $add('assessment_locked', $at);
            }
            $now[$sid] = $move['to'];
            $applied[] = $move + ['number' => $index + 1, 'taught' => $isTaught, 'reserved' => $this->isReserved($s, $today)];
            if ($isTaught) {
                $month = substr($move['date'], 0, 7);
                $teacherMonths[(int) $from->TeacherID . '|' . $month] = true;
                $teacherMonths[(int) $to->TeacherID . '|' . $month] = true;
                $teacherChanges += (int) $from->TeacherID !== (int) $to->TeacherID ? 1 : 0;
            }
        }

        // ── Stored counters must already be the derived ones (#5236 review P2c). Execute and restore recompute the counters
        // of the contracts they touch; the restore is only exact, column by column, when what was stored before the
        // execute is what a recompute writes. Session counters are checked on every contract of the chain, the minute
        // columns on the contracts a move touches (the only ones written). Not asked of an undo, which must stay possible. ──
        if (!$restore && $sound) {
            $touched = [];
            foreach ($applied as $move) {
                $touched[$move['from']] = $touched[$move['to']] = true;
            }
            foreach (StudentClass::query()->whereIn('ID', $chainIds)->orderBy('ID')->get() as $course) {
                $id = (int) $course->getAttribute('ID');
                $purchased = (int) $course->getAttribute('SessionCount');
                $used = min($purchased, $taught[$id]);
                $left = $purchased - $used;
                $derived = (int) $course->getAttribute('UsedSessions') === $used && (int) $course->getAttribute('RemainingSessions') === $left;
                if ($derived && isset($touched[$id])) {
                    $perLesson = SessionDeductionService::billingStandardMinutes($course);
                    $derived = $course->getAttribute('PurchasedMinutes') !== null && (int) $course->getAttribute('PurchasedMinutes') === $purchased * $perLesson
                        && $course->getAttribute('RemainingMinutes') !== null && (int) $course->getAttribute('RemainingMinutes') === $left * $perLesson;
                }
                if (!$derived) {
                    $add('counters_not_derived', ['contract_id' => $id]);
                }
            }
        }

        // ── Reservation policy (#5236 review P2a). `report` only lists a contract left with more taught + reserved lessons
        // than it bought. That is acceptable on a chain of ended contracts, where nobody books against what is left. With
        // an active contract on the chain, or the cascade decision given, the policy must be `block`. ──
        if (!$restore && $manifest['future_reservation_policy'] === 'report') {
            $activeIds = array_keys(array_filter($chain, fn (\stdClass $c): bool => $this->isActive($c)));
            foreach ($activeIds as $id) {
                $add('report_policy_requires_no_active_contract', ['contract_id' => $id]);
            }
            if ($activeIds === [] && $manifest['allow_active_contracts']) {
                $add('report_policy_requires_no_active_contract');
            }
        }

        // ── Result per contract. ──
        $after = ['taught' => array_fill_keys($chainIds, 0), 'reserved' => array_fill_keys($chainIds, 0)];
        foreach ($sessions as $s) {
            $cid = $now[(int) $s->id];
            $after['taught'][$cid] += $this->isTaught($s, $today) ? 1 : 0;
            $after['reserved'][$cid] += $this->isReserved($s, $today) ? 1 : 0;
        }
        if (array_sum($after['taught']) !== array_sum($before['taught'])) {
            $add('taught_total_not_conserved');
        }
        $overflows = [];
        $contracts = [];
        $stale = [];
        foreach ($sessions as $s) {
            if (strtolower((string) $s->Status) === 'scheduled' && substr((string) $s->SessionDate, 0, 10) < $today) {
                $stale[$now[(int) $s->id]][] = (int) $s->id;
            }
        }
        foreach ($chain as $id => $c) {
            $purchased = (int) $c->SessionCount;
            if ($after['taught'][$id] > $purchased) {
                if (!$restore) {
                    $add('taught_exceeds_purchased', ['contract_id' => $id]);
                }
            } elseif ($after['taught'][$id] + $after['reserved'][$id] > $purchased) {
                $overflows[] = ['contract_id' => $id, 'purchased' => $purchased, 'taught' => $after['taught'][$id], 'reserved' => $after['reserved'][$id]];
                if (!$restore && $manifest['future_reservation_policy'] === 'block') {
                    $add('future_reservations_exceed_remaining', ['contract_id' => $id]);
                }
            }
            $contracts[] = ['contract_id' => $id, 'start_date' => substr((string) $c->StartDate, 0, 10), 'active' => $this->isActive($c),
                'purchased' => $purchased,
                'before' => ['taught' => $before['taught'][$id], 'reserved' => $before['reserved'][$id]],
                'after' => ['taught' => $after['taught'][$id], 'reserved' => $after['reserved'][$id]],
                'stale_scheduled_session_ids' => $stale[$id] ?? []]; // reported only; the stale-session operation cancels them
        }
        $orderOnly = $this->orderOnlyMoves($applied);
        if (!$restore && $orderOnly !== [] && !$manifest['allow_order_only_moves']) {
            foreach ($orderOnly as $number) {
                $add('decision_required_order_only', ['move' => $number, 'session_id' => $manifest['moves'][$number - 1]['session_id']]);
            }
        }

        $observed = ['date' => $today,
            'contracts' => array_map(fn (\stdClass $c): array => [(int) $c->ID, (int) $c->SessionCount, (int) $c->Stop, (string) $c->closed_reason,
                $c->settlement_locked_at !== null, (int) $c->TeacherID, (int) $c->SessionDuration, (int) $c->standard_lesson_minutes,
                (string) $c->deduction_basis, substr((string) $c->StartDate, 0, 10)], array_values($chain)),
            'sessions' => $sessions->map(fn (\stdClass $s): array => [(int) $s->id, (int) $s->StudentClassID, substr((string) $s->SessionDate, 0, 10),
                (string) $s->StartTime, strtolower((string) $s->Status), $s->SubjectID === null ? null : (int) $s->SubjectID,
                $s->session_charge === null ? null : (int) $s->session_charge, $ledgerNet[(int) $s->id] ?? 0,
                $mirrorState[(int) $s->id] ?? []])->values()->all(),
            'usage' => array_map(fn (int $id): array => [$id, $diagnostics[$id]['ledger_used'] ?? null, $diagnostics[$id]['observed_used'] ?? null], $chainIds)];
        $digest = hash('sha256', json_encode(['manifest' => $manifest, 'observed' => $observed], JSON_THROW_ON_ERROR));
        $codes = $this->codes($findings);
        $state = $codes !== [] ? 'rejected' : ($want === '' ? 'unpinned' : (hash_equals($digest, $want) ? 'pinned' : 'digest_mismatch'));
        if ($state === 'digest_mismatch') {
            $codes = ['digest_mismatch'];
        }

        // What execute stores per moved lesson: the prior owner, the lesson as planned, and its mirror rows (count per table, net deduction).
        $rows = array_map(fn (array $m): array => ['id' => $m['session_id'], 'from_class_id' => $m['from'], 'to_class_id' => $m['to'],
            'date' => $m['date'], 'start_time' => $m['start_time'], 'status' => $m['status'],
            'mirrors' => $mirrorState[$m['session_id']] ?? [], 'ledger_net' => $ledgerNet[$m['session_id']] ?? 0], $applied);

        return ['ok' => $codes === [], 'errors' => $codes, 'findings' => $findings, 'state' => $state, 'mode' => $mode,
            'operation' => self::OPERATION, 'campus_id' => $manifest['campus_id'], 'student_id' => $manifest['student_id'],
            'subject_id' => $manifest['subject_id'], 'digest' => $digest, 'contracts' => $contracts, 'moves' => $manifest['moves'],
            'restore_request_id' => $manifest['restore_request_id'], 'restore_rows' => $restoreRows, 'manifest' => $manifest, 'rows' => $rows,
            'order_only_moves' => $orderOnly, 'reservation_overflows' => $overflows,
            'totals' => ['contracts' => count($chain), 'moves' => count($manifest['moves']), 'taught_before' => array_sum($before['taught']),
                'taught_after' => array_sum($after['taught']), 'affected_teacher_months' => count($teacherMonths), 'teacher_changes' => $teacherChanges],
            'advisor' => $this->advisor($chain, $sessions->all(), $owner, $now, $today)];
    }

    /**
     * Runs a pinned plan, all or nothing. A refusal is returned (`ok=false` and a code), never half-applied; anything
     * else that goes wrong is thrown after the transaction has undone every move.
     *
     * A request that already has a succeeded execute record gets that same result back with `already_applied` and writes
     * nothing (POP answers a replay from its record before it reaches this method; this is the second line).
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $context `operation_id` = the POP request id (required), `actor`
     * @return array<string,mixed>
     */
    public function execute(array $plan, array $context): array
    {
        $requestId = (string) ($context['operation_id'] ?? '');
        if (!Str::isUuid($requestId)) {
            return $this->refusal(['request_id_invalid']);
        }
        try {
            return DB::transaction(function () use ($plan, $context, $requestId): array {
                // A locking read: it sees the latest committed record and does not start this transaction's read view.
                $prior = DB::table('pop_execution_records')->where('operation_id', $requestId)->where('phase', 'execute')
                    ->where('result', 'succeeded')->orderByDesc('attempt_no')->lockForUpdate()->first(['payload']);
                $stored = $prior ? json_decode((string) $prior->payload, true) : null;
                if (is_array($stored) && $this->validSnapshot($stored['snapshot'] ?? null, null) && $stored['snapshot']['request_id'] === $requestId) {
                    return ['ok' => true, 'already_applied' => true]
                        + array_intersect_key($stored, array_flip(['mode', 'request_id', 'digest', 'moved', 'contracts', 'totals', 'restore_moves', 'snapshot']));
                }
                $manifest = $plan['manifest'] ?? null;
                if (($plan['ok'] ?? false) !== true || ($plan['state'] ?? null) !== 'pinned' || !is_array($manifest)
                    || !is_string($plan['digest'] ?? null) || !preg_match('/^[0-9a-f]{64}$/', $plan['digest'])) {
                    throw new ChainRebalanceRefused($this->refusal(['plan_not_pinned']));
                }
                $suffix = ($manifest['restore_request_id'] ?? '') !== '' ? '.restore' : '';

                return $this->apply($manifest, $plan['digest'], null, $requestId, $suffix, (string) ($context['actor'] ?? ''));
            }, 3);
        } catch (ChainRebalanceRefused $refused) {
            return $refused->result; // the transaction has already undone every move
        }
    }

    /**
     * Read-only post-conditions, from the stored execute result (the plan of an executed request no longer matches).
     * Answers with the per-contract structure the plan promised as "after", counted again from the database.
     *
     * `money_changed` compares the fingerprint taken before the first move with the same rows now. It covers this chain only
     * (its contracts, their invoices, items, payments and payment reports); money of other students or subjects never
     * affects it. POP verifies right after the execute. A payment, an invoice or a contract edit made on the chain after
     * the execute changes the fingerprint, so a verification repeated by hand later can answer
     * `money_changed` for that legitimate reason: it means the chain's money rows are no longer the fingerprinted ones,
     * not that the moves wrote money. (A restore takes its own fingerprint before its first move, so it is not affected.)
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function verify(array $plan, array $result): array
    {
        $checks = ['snapshot_present', 'lessons_on_target', 'lessons_unchanged', 'mirrors_follow', 'reassignment_recorded',
            'taught_within_purchased', 'counts_as_planned', 'taught_total_conserved', 'counters_derived', 'money_untouched'];
        $snapshot = $result['snapshot'] ?? null;
        if (($result['ok'] ?? false) !== true) {
            return ['ok' => false, 'errors' => ['execution_result_missing'], 'checks' => $checks, 'contracts' => []];
        }
        if (!$this->validSnapshot($snapshot, null)) {
            return ['ok' => false, 'errors' => ['snapshot_missing'], 'checks' => $checks, 'contracts' => []];
        }
        [$errors, $contracts] = $this->check($snapshot);

        return ['ok' => $errors === [], 'errors' => $errors, 'checks' => $checks, 'mode' => $snapshot['mode'], 'contracts' => $contracts,
            'totals' => ['moves' => count($snapshot['rows']), 'taught_before' => array_sum(array_map(fn (array $c): int => (int) $c['before']['taught'], $contracts)),
                'taught_after' => array_sum(array_map(fn (array $c): int => (int) $c['after']['taught'], $contracts))]];
    }

    /**
     * POP rollback phase of an executed request: moves every lesson of the snapshot back to its prior owner, in reverse
     * order, all or nothing. Refuses (`ok=false`, `rows` = one state per lesson) when any moved lesson is not as the execute
     * left it; nothing is restored in that case.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function rollback(array $snapshot, array $context): array
    {
        if (!$this->validSnapshot($snapshot, 'rebalance')) {
            throw new RuntimeException('chain_rebalance_rollback_snapshot_invalid'); // a restore run is undone by a new rebalance request
        }
        $requestId = (string) ($context['operation_id'] ?? $snapshot['request_id']);
        // The chain as it is now (a contract bought after the execute belongs to it), plus the contracts of the snapshot.
        $chain = DB::table('StudentClass')->where('StudentID', $snapshot['student_id'])->where('SubjectID', $snapshot['subject_id'])
            ->orderBy('ID')->get(['ID', 'PackageID', 'ScheduleMode'])
            ->filter(fn (\stdClass $c): bool => (int) $c->PackageID <= 0 && strtolower((string) $c->ScheduleMode) === 'count')
            ->map(fn (\stdClass $c): int => (int) $c->ID)->all();
        $contractIds = array_values(array_unique(array_merge(array_map('intval', $snapshot['contract_ids']), $chain)));
        sort($contractIds);
        $manifest = ['campus_id' => (int) $snapshot['campus_id'], 'student_id' => (int) $snapshot['student_id'], 'subject_id' => (int) $snapshot['subject_id'],
            'contract_ids' => $contractIds, 'moves' => $this->inverseMoves($snapshot['rows']), 'allow_active_contracts' => true,
            'allow_order_only_moves' => true, 'future_reservation_policy' => 'report', 'restore_request_id' => '',
            'reason' => 'rollback', 'decision_reference' => 'rollback:' . $snapshot['request_id']];
        try {
            $result = DB::transaction(fn (): array => $this->apply($manifest, '', $snapshot, $requestId, '.rollback', (string) ($context['actor'] ?? '')), 3);
        } catch (ChainRebalanceRefused $refused) {
            return $refused->result + ['restored' => 0]; // nothing was restored
        }

        return ['ok' => true, 'restored' => $result['moved'], 'rows' => $result['rows'], 'contracts' => $result['contracts'],
            'restored_snapshot' => $result['snapshot']];
    }

    /**
     * The write path shared by execute and rollback; runs inside the caller's transaction. Order: locks (student, contracts
     * by id, lessons and their mirror rows by id), a fresh evaluation under those locks, the moves in list order, the post-conditions, the audit
     * event. A refusal is thrown as ChainRebalanceRefused so the transaction undoes whatever was already moved.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed>|null $source snapshot being undone (rollback phase); null for a request
     * @return array<string,mixed>
     */
    private function apply(array $manifest, string $digest, ?array $source, string $requestId, string $suffix, string $actor): array
    {
        ClassSession::resetSettlementLockCache();
        // Campus isolation at write time: the student row is locked and must still belong to the manifest's campus.
        $campus = DB::table('Student')->where('id', $manifest['student_id'])->lockForUpdate()->value('CampusID');
        if ($campus === null || (int) $campus !== (int) $manifest['campus_id']) {
            throw new ChainRebalanceRefused($this->refusal([$digest !== '' ? 'digest_drift' : 'restore_refused', 'student_campus_mismatch']));
        }
        if (!$this->lockChain($manifest)) {
            throw new ChainRebalanceRefused($this->refusal([$digest !== '' ? 'digest_drift' : 'restore_refused', 'concurrent_change']));
        }

        $fresh = $this->evaluate($manifest, $digest, $source);
        $pinned = $digest === '' || (($fresh['state'] ?? null) === 'pinned' && hash_equals($digest, (string) ($fresh['digest'] ?? '')));
        if (($fresh['ok'] ?? false) !== true || !$pinned) {
            // The first code says what was refused; the plan's own codes (why the state no longer matches) follow.
            throw new ChainRebalanceRefused($this->refusal(array_values(array_unique(array_merge([$digest !== '' ? 'digest_drift' : 'restore_refused'],
                array_values(array_diff($fresh['errors'] ?? [], ['digest_mismatch']))))))
                + ['findings' => $fresh['findings'] ?? [], 'rows' => $fresh['restore_rows'] ?? []]);
        }

        $mode = $fresh['mode'];
        $chainIds = array_map(fn (array $c): int => (int) $c['contract_id'], $fresh['contracts']);
        $reason = 'POP ' . self::OPERATION . ' ' . $requestId . $suffix;
        $counters = [];
        foreach (DB::table('StudentClass')->whereIn('ID', $chainIds)->orderBy('ID')->get(['ID', 'UsedSessions', 'RemainingSessions']) as $c) {
            $counters[(int) $c->ID] = ['used_sessions' => (int) $c->UsedSessions, 'remaining_sessions' => (int) $c->RemainingSessions];
        }
        $snapshot = ['version' => self::SNAPSHOT_VERSION, 'operation' => self::OPERATION, 'mode' => $mode, 'request_id' => $requestId,
            'restore_source_request_id' => $source !== null ? (string) $source['request_id'] : (string) $manifest['restore_request_id'],
            'digest' => (string) $fresh['digest'], 'reason' => $reason, 'campus_id' => $manifest['campus_id'], 'student_id' => $manifest['student_id'],
            'subject_id' => $manifest['subject_id'], 'contract_ids' => $chainIds, 'rows' => $fresh['rows'],
            'contracts' => array_map(fn (array $c): array => ['contract_id' => (int) $c['contract_id'], 'purchased' => (int) $c['purchased'],
                'before' => $c['before'] + ($counters[(int) $c['contract_id']] ?? []), 'after' => $c['after']], $fresh['contracts']),
            // Nothing is cancelled here: a reservation beyond what is left is only listed (policy `report`) and stays as it was.
            'reservation_overflows' => $fresh['reservation_overflows'], 'released_reservations' => [],
            'money_hash' => $this->moneyHash($chainIds)];

        $reassigner = new ClassSessionContractReassigner();
        foreach ($fresh['rows'] as $index => $row) {
            $moved = $reassigner->reassign((int) $row['id'], (int) $row['to_class_id'], $reason, null, false);
            if (isset($moved['error']) || isset($moved['locked'])) {
                throw new ChainRebalanceRefused($this->refusal(['move_refused']) + ['failed_move' => ['move' => $index + 1,
                    'session_id' => (int) $row['id'], 'cause' => isset($moved['locked']) ? 'assessment_locked' : 'reassigner_refused']]);
            }
        }

        [$errors, $contracts] = $this->check($snapshot);
        if ($errors !== []) {
            throw new ChainRebalanceRefused($this->refusal(array_merge(['postcondition_failed'], $errors)));
        }
        $this->audit($suffix, (int) $manifest['campus_id'], $requestId, (string) $fresh['digest'], count($fresh['rows']), (string) $manifest['reason'], $actor);

        return ['ok' => true, 'mode' => $mode, 'request_id' => $requestId, 'digest' => (string) $fresh['digest'], 'moved' => count($fresh['rows']),
            'contracts' => $contracts, 'totals' => $fresh['totals'], 'rows' => $fresh['restore_rows'],
            // The exact `moves` list of a restore request for this run (only a rebalance run can be restored).
            'restore_moves' => $mode === 'rebalance' ? $this->inverseMoves($fresh['rows']) : [],
            'snapshot' => $snapshot];
    }

    /**
     * Row locks in a fixed order: the student's contracts of the chain by id, then their lessons, sign-ins, learning records
     * and ledger rows by id. A locking read returns the latest committed rows; the plain reads of the evaluation that follows
     * may come from a read view that began earlier (POP wraps the phase in its own transaction and plans before it calls
     * execute). So each locked set is compared with what a plain read sees: any difference means another transaction changed
     * the chain in between, and the caller refuses instead of evaluating rows that are already stale.
     *
     * @param array<string,mixed> $manifest
     */
    private function lockChain(array $manifest): bool
    {
        $contracts = fn () => DB::table('StudentClass')->where('StudentID', $manifest['student_id'])
            ->where(fn ($q) => $q->whereIn('ID', $manifest['contract_ids'])->orWhere('SubjectID', $manifest['subject_id']))->orderBy('ID');
        $locked = $contracts()->lockForUpdate()->get();
        $same = json_encode($locked) === json_encode($contracts()->get());
        $ids = $locked->pluck('ID')->map(fn ($id): int => (int) $id)->all();
        foreach ([['ClassSession', 'StudentClassID'], ['StudentSingIn', 'StudentClassID'], ['LearningRecord', 'StudentClassID'],
            ['session_deduction_ledger', 'student_class_id']] as [$table, $column]) {
            $rows = fn () => DB::table($table)->whereIn($column, $ids)->orderBy('id');
            $same = json_encode($rows()->lockForUpdate()->get()) === json_encode($rows()->get()) && $same;
        }

        return $same;
    }

    /**
     * Post-conditions of a snapshot against the database, read-only: every moved lesson is on its target with its date, time
     * and status as planned; its sign-in, learning record and ledger rows follow it and none was added or removed; each move
     * has its reassignment row; per contract the taught and reserved counts are the planned "after" (a rebalance never leaves
     * taught above purchased) and the total taught is conserved; the two contracts of each move carry the derived counters;
     * and the money fingerprint of the chain is the one taken before the first move.
     *
     * @param array<string,mixed> $snapshot
     * @return array{0: list<string>, 1: list<array<string,mixed>>}
     */
    private function check(array $snapshot): array
    {
        $errors = [];
        $touched = [];
        $sessions = DB::table('ClassSession')->whereIn('id', array_column($snapshot['rows'], 'id'))
            ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'Status'])->keyBy('id');
        foreach ($snapshot['rows'] as $r) {
            $sid = (int) $r['id'];
            $to = (int) $r['to_class_id'];
            $touched[(int) $r['from_class_id']] = true;
            $touched[$to] = true;
            $s = $sessions->get($sid);
            if (!$s || (int) $s->StudentClassID !== $to) {
                $errors[] = "not_moved_{$sid}";
                continue;
            }
            if (substr((string) $s->SessionDate, 0, 10) !== $r['date'] || substr((string) $s->StartTime, 0, 5) !== $r['start_time']
                || strtolower((string) $s->Status) !== $r['status']) {
                $errors[] = "lesson_changed_{$sid}";
            }
            foreach (self::MIRRORS as [$table, $sessionColumn, $ownerColumn]) {
                $owners = DB::table($table)->where($sessionColumn, $sid)->pluck($ownerColumn)->map(fn ($id): int => (int) $id)->all();
                if (count($owners) !== (int) ($r['mirrors'][$table] ?? 0)) {
                    $errors[] = "mirror_count_changed_{$sid}";
                }
                if (array_diff($owners, [$to]) !== []) {
                    $errors[] = "mirror_owner_differs_{$sid}";
                }
            }
            if (!DB::table('class_session_reassignments')->where('class_session_id', $sid)->where('old_student_class_id', (int) $r['from_class_id'])
                ->where('new_student_class_id', $to)->where('reason', (string) $snapshot['reason'])->exists()) {
                $errors[] = "reassignment_missing_{$sid}";
            }
        }

        $chainIds = array_map('intval', $snapshot['contract_ids']);
        $today = now()->toDateString();
        $taught = array_fill_keys($chainIds, 0);
        $reserved = array_fill_keys($chainIds, 0);
        foreach (DB::table('ClassSession')->whereIn('StudentClassID', $chainIds)->get(['StudentClassID', 'SessionDate', 'Status']) as $s) {
            $taught[(int) $s->StudentClassID] += $this->isTaught($s, $today) ? 1 : 0;
            $reserved[(int) $s->StudentClassID] += $this->isReserved($s, $today) ? 1 : 0;
        }
        $courses = DB::table('StudentClass')->whereIn('ID', $chainIds)->get(['ID', 'SessionCount', 'UsedSessions', 'RemainingSessions'])->keyBy('ID');
        $diagnostics = SessionDeductionService::batchExpectedUsedSessionDiagnostics($chainIds);
        $contracts = [];
        $before = 0;
        foreach ($snapshot['contracts'] as $c) {
            $id = (int) $c['contract_id'];
            $before += (int) $c['before']['taught'];
            $course = $courses->get($id);
            if (!$course) {
                $errors[] = "contract_missing_{$id}";
                continue;
            }
            $purchased = (int) $course->SessionCount;
            if ($purchased !== (int) $c['purchased']) {
                $errors[] = "contract_purchased_changed_{$id}";
            }
            if ($snapshot['mode'] === 'rebalance' && $taught[$id] > $purchased) {
                $errors[] = "contract_over_{$id}";
            }
            if ($taught[$id] !== (int) $c['after']['taught'] || $reserved[$id] !== (int) $c['after']['reserved']) {
                $errors[] = "contract_count_differs_{$id}";
            }
            $d = $diagnostics[$id] ?? null;
            if ($d === null || $d['uncapped_used'] !== $taught[$id] || $d['ledger_used'] !== $d['observed_used']) {
                $errors[] = "mirror_inconsistent_{$id}";
            }
            // The stored counters are capped at the bought count; only the contracts a move touched were recomputed.
            $used = min($purchased, $taught[$id]);
            if (isset($touched[$id]) && ((int) $course->UsedSessions !== $used || (int) $course->RemainingSessions !== max(0, $purchased - $used))) {
                $errors[] = "counter_differs_{$id}";
            }
            $contracts[] = ['contract_id' => $id, 'purchased' => $purchased,
                'before' => ['taught' => (int) $c['before']['taught'], 'reserved' => (int) $c['before']['reserved']],
                'after' => ['taught' => $taught[$id], 'reserved' => $reserved[$id]]];
        }
        if (array_sum($taught) !== $before) {
            $errors[] = 'taught_total_not_conserved';
        }
        if (!hash_equals((string) $snapshot['money_hash'], $this->moneyHash($chainIds))) {
            $errors[] = 'money_changed';
        }

        return [array_values(array_unique($errors)), $contracts];
    }

    /**
     * Fingerprint of everything money-related on the chain: every contract column except the four counters the reassigner
     * recomputes (so Charge, Pay, Paid, PayDate, SessionCount, dates, Stop and closed_reason are all inside), and every row
     * of the chain's invoices, invoice items, payments and payment reports. Only a hash leaves this method.
     *
     * @param list<int> $contractIds
     */
    private function moneyHash(array $contractIds): string
    {
        sort($contractIds);
        $all = fn (\Illuminate\Database\Query\Builder $query): array => $query->orderBy('id')->get()->map(fn (\stdClass $row): array => (array) $row)->all();
        $contracts = DB::table('StudentClass')->whereIn('ID', $contractIds)->orderBy('ID')->get()
            ->map(fn (\stdClass $row): array => array_diff_key((array) $row, array_flip(self::COUNTER_COLUMNS)))->all();
        $invoices = $all(DB::table('Invoice')->whereIn('StudentClassID', $contractIds));
        $invoiceIds = array_map(fn (array $row): int => (int) $row['id'], $invoices);
        $items = $all(DB::table('InvoiceItem')->where(fn ($q) => $q->whereIn('InvoiceID', $invoiceIds)->orWhereIn('StudentClassID', $contractIds)));
        $payments = $all(DB::table('Payment')->whereIn('InvoiceID', $invoiceIds));
        $reports = $all(DB::table('payment_reports')->whereIn('StudentClassID', $contractIds));

        return hash('sha256', json_encode([$contracts, $invoices, $items, $payments, $reports], JSON_THROW_ON_ERROR));
    }

    /**
     * The snapshot of a succeeded execute of this operation (a rebalance, never a restore) for the same campus, student and
     * subject, or null. Read from the POP records, the way ForwardOverdeductReassignStrategy does.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>|null
     */
    private function sourceSnapshot(string $requestId, array $manifest): ?array
    {
        $request = DB::table('pop_operation_requests')->where('id', $requestId)->first(['operation_id', 'parameters']);
        $parameters = $request ? json_decode((string) $request->parameters, true) : null;
        if (!$request || (string) $request->operation_id !== self::OPERATION || !is_array($parameters) || ($parameters['restore_request_id'] ?? '') !== '') {
            return null;
        }
        $execute = DB::table('pop_execution_records')->where('operation_id', $requestId)->where('phase', 'execute')
            ->where('result', 'succeeded')->orderByDesc('attempt_no')->first(['snapshot']);
        $snapshot = $execute ? json_decode((string) $execute->snapshot, true) : null;
        if (!$this->validSnapshot($snapshot, 'rebalance') || $snapshot['request_id'] !== $requestId) {
            return null;
        }
        foreach (['campus_id', 'student_id', 'subject_id'] as $key) {
            if ((int) $snapshot[$key] !== (int) $manifest[$key]) {
                return null;
            }
        }

        return $snapshot;
    }

    /**
     * The request that already undid the execute of `$requestId`, or null: that request's own POP rollback phase, or a
     * succeeded restore run of this operation whose snapshot names it. Read from the receipts only; they are never rewritten.
     */
    private function restoredBy(string $requestId): ?string
    {
        if (DB::table('pop_execution_records')->where('operation_id', $requestId)->where('phase', 'rollback')->where('result', 'rolled_back')->exists()) {
            return $requestId;
        }
        $by = DB::table('pop_execution_records')->where('phase', 'execute')->where('result', 'succeeded')->where('operation_id', '!=', $requestId)
            ->whereIn('operation_id', DB::table('pop_operation_requests')->where('operation_id', self::OPERATION)->select('id'))
            ->where('snapshot->operation', self::OPERATION)->where('snapshot->mode', 'restore')
            ->where('snapshot->restore_source_request_id', $requestId)->orderBy('created_at')->value('operation_id');

        return $by !== null ? (string) $by : null;
    }

    /** Shape check of a stored snapshot; `$mode` null accepts both kinds. */
    private function validSnapshot(mixed $snapshot, ?string $mode): bool
    {
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== self::SNAPSHOT_VERSION || ($snapshot['operation'] ?? null) !== self::OPERATION
            || !in_array($snapshot['mode'] ?? null, $mode === null ? ['rebalance', 'restore'] : [$mode], true)
            || !is_string($snapshot['request_id'] ?? null) || !is_string($snapshot['reason'] ?? null) || !is_string($snapshot['money_hash'] ?? null)
            || !is_array($snapshot['rows'] ?? null) || !array_is_list($snapshot['rows']) || $snapshot['rows'] === []
            || !is_array($snapshot['contracts'] ?? null) || !is_array($snapshot['contract_ids'] ?? null)) {
            return false;
        }
        foreach (['campus_id', 'student_id', 'subject_id'] as $key) {
            if ($this->id($snapshot[$key] ?? null) === null) {
                return false;
            }
        }
        foreach ($snapshot['rows'] as $r) {
            if (!is_array($r) || $this->id($r['id'] ?? null) === null || $this->id($r['from_class_id'] ?? null) === null
                || $this->id($r['to_class_id'] ?? null) === null || !is_string($r['date'] ?? null) || !is_string($r['start_time'] ?? null)
                || !is_string($r['status'] ?? null) || !is_array($r['mirrors'] ?? null) || !is_int($r['ledger_net'] ?? null)) {
                return false;
            }
        }
        foreach ($snapshot['contracts'] as $c) {
            if (!is_array($c) || $this->id($c['contract_id'] ?? null) === null || !is_int($c['purchased'] ?? null)
                || !is_int($c['before']['taught'] ?? null) || !is_int($c['before']['reserved'] ?? null)
                || !is_int($c['after']['taught'] ?? null) || !is_int($c['after']['reserved'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The undo of executed rows as a manifest move list: reverse order, from and to swapped, same date, time and status.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function inverseMoves(array $rows): array
    {
        return array_map(fn (array $r): array => ['session_id' => (int) $r['id'], 'from' => (int) $r['to_class_id'], 'to' => (int) $r['from_class_id'],
            'date' => (string) $r['date'], 'start_time' => (string) $r['start_time'], 'status' => (string) $r['status']], array_reverse($rows));
    }

    /**
     * @param list<string> $errors
     * @return array<string,mixed>
     */
    private function refusal(array $errors): array
    {
        return ['ok' => false, 'errors' => $errors, 'moved' => 0, 'operation' => self::OPERATION];
    }

    /** One event per request (ids, a reason code, the digest and a count); the write fails when the event is not stored. */
    private function audit(string $suffix, int $campusId, string $requestId, string $digest, int $rows, string $reasonCode, string $actor): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append(self::AUDIT . $suffix, 'success', ['actor_type' => 'pop-runner', 'actor_id' => $actor !== '' ? $actor : null,
            'subject_type' => 'pop_operation', 'subject_id' => $requestId, 'campus_id' => $campusId, 'correlation_id' => $correlationId],
            ['reason_code' => $reasonCode, 'pop_operation_id' => $requestId, 'row_count' => $rows, 'source' => 'digest:' . $digest, 'outcome' => 'success'], true);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('chain_rebalance_audit_not_persisted');
        }
    }

    /**
     * Shape and type checks only; nothing is read. Flags default to the safe value when absent.
     *
     * @param array<string,mixed> $p
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    private function manifest(array $p): array
    {
        $errors = [];
        $ids = [];
        foreach (['campus_id', 'student_id', 'subject_id'] as $key) {
            $ids[$key] = $this->id($p[$key] ?? null);
            if ($ids[$key] === null) {
                $errors[] = $key . '_invalid';
            }
        }
        $contracts = [];
        $rawContracts = $p['contract_ids'] ?? null;
        if (!is_array($rawContracts) || !array_is_list($rawContracts) || $rawContracts === [] || count($rawContracts) > self::MAX_CONTRACTS) {
            $errors[] = 'contract_ids_invalid';
        } else {
            foreach ($rawContracts as $raw) {
                $contracts[] = $this->id($raw);
            }
            if (in_array(null, $contracts, true) || count(array_unique($contracts)) !== count($contracts)) {
                $errors[] = 'contract_ids_invalid';
            }
        }
        $moves = [];
        $rawMoves = $p['moves'] ?? null;
        if (!is_array($rawMoves) || !array_is_list($rawMoves) || $rawMoves === [] || count($rawMoves) > self::MAX_MOVES) {
            $errors[] = 'moves_invalid';
        } else {
            foreach ($rawMoves as $raw) {
                $keys = is_array($raw) ? array_keys($raw) : [];
                sort($keys);
                $move = $keys === ['date', 'from', 'session_id', 'start_time', 'status', 'to'] ? [
                    'session_id' => $this->id($raw['session_id']), 'from' => $this->id($raw['from']), 'to' => $this->id($raw['to']),
                    'date' => is_string($raw['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw['date']) ? $raw['date'] : null,
                    'start_time' => is_string($raw['start_time']) && preg_match('/^\d{2}:\d{2}$/', $raw['start_time']) ? $raw['start_time'] : null,
                    'status' => is_string($raw['status']) && preg_match('/^[a-z_]{1,20}$/', $raw['status']) ? $raw['status'] : null,
                ] : null;
                if ($move === null || in_array(null, $move, true)) {
                    $errors[] = 'moves_invalid';
                    break;
                }
                $moves[] = $move;
            }
        }
        $flags = [];
        foreach (['allow_active_contracts', 'allow_order_only_moves'] as $key) {
            $flags[$key] = $p[$key] ?? false;
            if (!is_bool($flags[$key])) {
                $errors[] = 'flag_invalid';
            }
        }
        $policy = $p['future_reservation_policy'] ?? 'block';
        if (!in_array($policy, ['block', 'report'], true)) {
            $errors[] = 'future_reservation_policy_invalid';
        }
        $want = $p['expected_digest'] ?? '';
        if (!is_string($want) || ($want !== '' && !preg_match('/^[0-9a-f]{64}$/', $want))) {
            $errors[] = 'digest_format';
        }
        $restore = $p['restore_request_id'] ?? '';
        if (!is_string($restore) || ($restore !== '' && !Str::isUuid($restore))) {
            $errors[] = 'restore_request_id_format';
            $restore = '';
        }
        $reason = $p['reason'] ?? '';
        if (!is_string($reason) || !preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $reason)) {
            $errors[] = 'reason_invalid'; // a safe code, never free text
        }
        $reference = $p['decision_reference'] ?? '';
        if (!is_string($reference) || !preg_match('/^[A-Za-z0-9_.:#\/-]{3,128}$/', $reference)) {
            $errors[] = 'decision_reference_invalid';
        }

        return [$ids + ['contract_ids' => $contracts, 'moves' => $moves] + $flags
            + ['future_reservation_policy' => $policy, 'restore_request_id' => $restore, 'reason' => $reason, 'decision_reference' => $reference],
            array_values(array_unique($errors))];
    }

    private function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) ? (int) $value : null;
    }

    /** Taught as SessionDeductionService counts it: one of the taught statuses, dated today or earlier. */
    private function isTaught(\stdClass $session, string $today): bool
    {
        return in_array(strtolower((string) $session->Status), self::TAUGHT, true) && substr((string) $session->SessionDate, 0, 10) <= $today;
    }

    /** A reservation is a lesson still to be taught: `scheduled`, today or later. Older `scheduled` rows are stale and only reported. */
    private function isReserved(\stdClass $session, string $today): bool
    {
        return strtolower((string) $session->Status) === 'scheduled' && substr((string) $session->SessionDate, 0, 10) >= $today;
    }

    private function isActive(\stdClass $contract): bool
    {
        return (int) $contract->Stop !== 1 && trim((string) $contract->closed_reason) === '' && $contract->settlement_locked_at === null;
    }

    /**
     * Moves that only re-order lessons: within one kind (taught, or reserved), the moves that lie on a cycle of contracts
     * (A -> B and B -> A, or a longer loop) leave every contract's count as it was. Returns their 1-based positions.
     *
     * @param list<array<string,mixed>> $applied
     * @return list<int>
     */
    private function orderOnlyMoves(array $applied): array
    {
        $found = [];
        foreach (['taught', 'reserved'] as $kind) {
            $edges = [];
            foreach ($applied as $index => $move) {
                if ($move[$kind]) {
                    $edges[$index] = [$move['from'], $move['to']];
                }
            }
            while (($cycle = $this->cycle($edges)) !== []) {
                foreach ($cycle as $index) {
                    $found[] = $index;
                    unset($edges[$index]);
                }
            }
        }
        $numbers = array_map(fn (int $index): int => (int) $applied[$index]['number'], $found);
        sort($numbers);

        return $numbers;
    }

    /**
     * One directed cycle among the edges, as edge keys; [] when there is none. Depth-first over contracts (at most
     * MAX_CONTRACTS nodes), taking the earliest move between two contracts, so the answer is deterministic and cheap.
     *
     * @param array<int,array{0:int,1:int}> $edges
     * @return list<int>
     */
    private function cycle(array $edges): array
    {
        $next = [];
        foreach ($edges as $key => $edge) {
            $next[$edge[0]][$edge[1]] ??= $key;
        }
        $state = [];
        foreach (array_keys($next) as $root) {
            if (!isset($state[$root])) {
                $cycle = $this->walk($root, $next, $state, []);
                if ($cycle !== []) {
                    return $cycle;
                }
            }
        }

        return [];
    }

    /**
     * @param array<int,array<int,int>> $next from contract => to contract => edge key
     * @param array<int,int> $state 1 = on the current path, 2 = finished
     * @param list<array{0:int,1:int}> $trail [contract left, edge key taken] along the current path
     * @return list<int>
     */
    private function walk(int $node, array $next, array &$state, array $trail): array
    {
        $state[$node] = 1;
        foreach ($next[$node] ?? [] as $to => $key) {
            if (($state[$to] ?? 0) === 1) {
                $cycle = [$key];
                for ($i = count($trail) - 1; $i >= 0; $i--) {
                    $cycle[] = $trail[$i][1];
                    if ($trail[$i][0] === $to) {
                        break;
                    }
                }

                return $cycle;
            }
            if (!isset($state[$to])) {
                $cycle = $this->walk($to, $next, $state, array_merge($trail, [[$node, $key]]));
                if ($cycle !== []) {
                    return $cycle;
                }
            }
        }
        $state[$node] = 2;

        return [];
    }

    /**
     * Comparison only, never executed: taught lessons in date order fill the contracts oldest first (StartDate, then id),
     * each up to its bought count; anything beyond the last contract stays on it. Contracts that ran in parallel make this
     * ordering ambiguous, which is why a reviewed list is executed and this is only reported.
     *
     * @param array<int,\stdClass> $chain
     * @param array<int,\stdClass> $sessions
     * @param array<int,int> $owner
     * @param array<int,int> $manifestOwner
     * @return array<string,mixed>
     */
    private function advisor(array $chain, array $sessions, array $owner, array $manifestOwner, string $today): array
    {
        $order = array_values($chain);
        usort($order, fn (\stdClass $a, \stdClass $b): int => [substr((string) $a->StartDate, 0, 10), (int) $a->ID] <=> [substr((string) $b->StartDate, 0, 10), (int) $b->ID]);
        $taught = array_values(array_filter($sessions, fn (\stdClass $s): bool => $this->isTaught($s, $today)));
        usort($taught, fn (\stdClass $a, \stdClass $b): int => [substr((string) $a->SessionDate, 0, 10), (string) $a->StartTime, (int) $a->id]
            <=> [substr((string) $b->SessionDate, 0, 10), (string) $b->StartTime, (int) $b->id]);
        $after = [];
        foreach ($order as $c) {
            $after[(int) $c->ID] = 0;
        }
        $slot = 0;
        $moves = 0;
        $differs = [];
        foreach ($taught as $s) {
            while ($slot < count($order) - 1 && $after[(int) $order[$slot]->ID] >= (int) $order[$slot]->SessionCount) {
                $slot++;
            }
            $target = (int) $order[$slot]->ID;
            $after[$target]++;
            $sid = (int) $s->id;
            $moves += $owner[$sid] !== $target ? 1 : 0;
            if ($manifestOwner[$sid] !== $target) {
                $differs[] = ['session_id' => $sid, 'manifest_contract_id' => $manifestOwner[$sid], 'advisor_contract_id' => $target];
            }
        }
        ksort($after);

        return ['basis' => 'oldest_first_by_start_date', 'executed' => false, 'taught_after' => $after, 'moves_needed' => $moves,
            'agrees_with_manifest' => $differs === [], 'differs_from_manifest' => $differs];
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<string>
     */
    private function codes(array $findings): array
    {
        return array_values(array_unique(array_column($findings, 'code')));
    }

    /**
     * @param list<string> $errors
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function rejected(array $errors, array $findings, string $state, string $mode = 'rebalance'): array
    {
        return ['ok' => false, 'errors' => $errors, 'findings' => $findings, 'state' => $state, 'mode' => $mode, 'operation' => self::OPERATION];
    }
}
