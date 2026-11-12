<?php

namespace App\Operations\Strategies;

use App\Models\StudentClass;
use App\Services\Scheduling\ClassSessionContractReassigner;
use App\Services\Scheduling\DeductionBasis;
use App\Services\SessionDeductionService;
use App\Support\LearningRecordMutableOwnership;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * GitHub #5124 / #5162 C1, PR 1 of the design (issuecomment-6813739987): the PLAN half of a chain-aware contract repair.
 *
 * One student's count contracts of one subject form a chain. A reviewed, explicitly ordered list of moves (lesson -> from
 * contract -> to contract) is validated as ONE plan: scope (campus, student, subject, the whole chain), drift (owner, date,
 * time, status), the result (taught <= bought on every contract, total taught unchanged, reservations fit), money locks, the
 * reassigner's own refusals (slot taken, locked assessment) and the two Founder decisions, which default to "no":
 * `allow_active_contracts` and `allow_order_only_moves`. The oldest-first allocation is computed for comparison only.
 *
 * This release only reads. `execute()`, `verify()` and `rollback()` refuse with `strategy_planned_only`; the catalog entry is
 * `lifecycle: planned`, so POP never issues an approval for it. Output carries ids, dates, statuses and counts: no names, no amounts.
 */
final class CourseContractChainRebalanceStrategy
{
    public const OPERATION = 'course-contract-chain-rebalance';
    public const PLANNED_ONLY = 'strategy_planned_only';

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
            return $this->rejected($errors, [], 'invalid');
        }
        $want = (string) ($parameters['expected_digest'] ?? '');

        // Campus isolation first (acceptance 15): a student of another campus is refused before any contract or lesson is read.
        // No role is exempt: a super_admin may ask for any campus, but the manifest's campus must be the student's own.
        $studentCampus = DB::table('Student')->where('id', $manifest['student_id'])->value('CampusID');
        if ($studentCampus === null || (int) $studentCampus !== $manifest['campus_id']) {
            return $this->rejected(['student_campus_mismatch'], [], 'rejected');
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
            return $this->rejected($this->codes($findings), $findings, 'rejected');
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
            return $this->rejected(['chain_too_large'], [], 'rejected');
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
        $sessionIds = array_keys($owner);
        $ledgerNet = [];
        $mirrorState = [];
        foreach (array_chunk($sessionIds, 500) as $chunk) {
            foreach ([['StudentSingIn', 'ClassSessionID', 'StudentClassID'], ['LearningRecord', 'ClassSessionID', 'StudentClassID'],
                ['session_deduction_ledger', 'class_session_id', 'student_class_id']] as [$table, $sessionColumn, $ownerColumn]) {
                $select = [$sessionColumn . ' as sid', $ownerColumn . ' as cid'];
                if ($table === 'session_deduction_ledger') {
                    $select[] = 'event_type';
                }
                foreach (DB::table($table)->whereIn($sessionColumn, $chunk)->orderBy('id')->get($select) as $m) {
                    $sid = (int) $m->sid;
                    if ((int) $m->cid !== $owner[$sid]) {
                        $add('mirror_inconsistent', ['session_id' => $sid]);
                    }
                    $mirrorState[$sid][$table] = ($mirrorState[$sid][$table] ?? 0) + 1;
                    if ($table === 'session_deduction_ledger') {
                        $ledgerNet[$sid] = ($ledgerNet[$sid] ?? 0) + ((string) $m->event_type === 'deduct' ? 1 : -1);
                    }
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
            if (($ledgerNet[$sid] ?? 0) !== ($isTaught ? 1 : 0)) {
                $add('mirror_inconsistent', $at); // the move must shift the ledger exactly as it shifts the taught count
            }
            $from = $chain[$move['from']];
            $to = $chain[$move['to']];
            if ((!$manifest['allow_active_contracts']) && ($this->isActive($from) || $this->isActive($to))) {
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
                $add('taught_exceeds_purchased', ['contract_id' => $id]);
            } elseif ($after['taught'][$id] + $after['reserved'][$id] > $purchased) {
                $overflows[] = ['contract_id' => $id, 'purchased' => $purchased, 'taught' => $after['taught'][$id], 'reserved' => $after['reserved'][$id]];
                if ($manifest['future_reservation_policy'] === 'block') {
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
        if ($orderOnly !== [] && !$manifest['allow_order_only_moves']) {
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

        return ['ok' => $codes === [], 'errors' => $codes, 'findings' => $findings, 'state' => $state, 'mode' => 'plan_only',
            'operation' => self::OPERATION, 'campus_id' => $manifest['campus_id'], 'student_id' => $manifest['student_id'],
            'subject_id' => $manifest['subject_id'], 'digest' => $digest, 'contracts' => $contracts, 'moves' => $manifest['moves'],
            'order_only_moves' => $orderOnly, 'reservation_overflows' => $overflows,
            'totals' => ['contracts' => count($chain), 'moves' => count($manifest['moves']), 'taught_before' => array_sum($before['taught']),
                'taught_after' => array_sum($after['taught']), 'affected_teacher_months' => count($teacherMonths), 'teacher_changes' => $teacherChanges],
            'advisor' => $this->advisor($chain, $sessions->all(), $owner, $now, $today)];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $context */
    public function execute(array $plan, array $context): array
    {
        throw new RuntimeException(self::PLANNED_ONLY);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $result @return array<string,mixed> */
    public function verify(array $plan, array $result): array
    {
        return ['ok' => false, 'errors' => [self::PLANNED_ONLY], 'checks' => []];
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $context */
    public function rollback(array $snapshot, array $context): array
    {
        throw new RuntimeException(self::PLANNED_ONLY);
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
        if (($p['restore_request_id'] ?? '') !== '') {
            $errors[] = 'restore_not_available_plan_only';
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
            + ['future_reservation_policy' => $policy, 'reason' => $reason, 'decision_reference' => $reference], array_values(array_unique($errors))];
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
    private function rejected(array $errors, array $findings, string $state): array
    {
        return ['ok' => false, 'errors' => $errors, 'findings' => $findings, 'state' => $state, 'mode' => 'plan_only', 'operation' => self::OPERATION];
    }
}
