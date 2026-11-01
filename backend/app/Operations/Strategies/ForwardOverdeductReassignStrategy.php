<?php

namespace App\Operations\Strategies;

use App\Models\ClassSession;
use App\Models\SecurityAuditEvent;
use App\Services\ForwardSessionExtras;
use App\Services\Scheduling\ClassSessionContractReassigner;
use App\Services\SessionDeductionService;
use App\Support\LearningRecordMutableOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * GitHub #4554 (in-app #452), Founder decision 2A: the nightly forward generator added lessons to count courses and some were
 * taught, so the app counts more used lessons than were bought. For each such course (ForwardSessionExtras `over`) the taught
 * generated lessons beyond the bought count (newest first) move to the same student's later contract of the same subject that
 * still has room (`move`); with no later contract the lesson stays as a gift (`keep`); anything doubtful is `review`
 * (partial minutes, settled course, no target with room, target slot taken, assessment locked). Review and keep are never written.
 *
 * A move goes only through ClassSessionContractReassigner (lesson + sign-in + ledger + learning record, counters recomputed,
 * reassignment + audit rows); money is untouched. Execute is bound to the reviewed dry run by `expected_digest` (Td076 pattern),
 * re-checks every row under lock and is all or nothing. Rollback / restore (#4736 pattern: a new Founder-approved request with
 * `restore_request_id` = the reassign request) moves each lesson back with the same service.
 */
final class ForwardOverdeductReassignStrategy
{
    private const REF = 'reassign-forward-overdeduct-20261101';
    private const OPERATION = 'forward-overdeduct-reassign-20261101';
    private const REASON = 'POP forward-overdeduct-reassign #4554';

    public function plan(array $parameters): array
    {
        $campus = $parameters['campus_id'] ?? null;
        $want = (string) ($parameters['expected_digest'] ?? '');
        $source = (string) ($parameters['restore_request_id'] ?? '');
        $errors = [];
        if (($parameters['decision_reference'] ?? null) !== self::REF) $errors[] = 'case_parameters_mismatch';
        if (!is_numeric($campus) || (int) $campus < 0 || (string) (int) $campus !== (string) $campus) $errors[] = 'campus_id_invalid';
        if ($want !== '' && !preg_match('/^[0-9a-f]{64}$/', $want)) $errors[] = 'digest_format';
        if ($source !== '' && !Str::isUuid($source)) $errors[] = 'restore_request_id_format';
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'state' => 'invalid'];
        }
        if ($source !== '') {
            $snapshot = $this->sourceSnapshot($source, (int) $campus);
            if ($snapshot === null) {
                return ['ok' => false, 'errors' => ['restore_source_not_found'], 'state' => 'invalid'];
            }
            $rows = $this->restoreRows($snapshot);
            $totals = ['rows' => count($rows), 'restore' => count(array_filter($rows, fn ($r) => $r['class'] === 'restore'))];
        } else {
            [$rows, $totals] = $this->build((int) $campus);
        }
        $digest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $todo = array_filter($rows, fn ($r) => in_array($r['class'], ['move', 'restore'], true));
        $state = $want === '' ? 'unpinned' : ($todo === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));

        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'mode' => $source === '' ? 'reassign' : 'restore', 'restore_request_id' => $source,
            'campus_id' => (int) $campus, 'digest' => $digest, 'totals' => $totals, 'manifest' => $rows];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            // Nothing left to move (retry after the execution record failed to persist): no snapshot is guessed; the operator resolves.
            return ['ok' => true, 'already_applied' => true, 'moved' => 0, 'snapshot' => ['rows' => [], 'incomplete' => true]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') {
            throw new RuntimeException('forward_overdeduct_reassign_plan_not_pinned');
        }

        if (($plan['mode'] ?? 'reassign') === 'restore') {
            return DB::transaction(function () use ($plan, $context): array {
                $snapshot = $this->sourceSnapshot((string) $plan['restore_request_id'], (int) $plan['campus_id']) ?? [];
                $this->lockCourses(array_merge(array_column($snapshot, 'from_class_id'), array_column($snapshot, 'to_class_id')));
                $rows = $this->restoreRows($snapshot);
                if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                    throw new RuntimeException('forward_overdeduct_reassign_digest_drift');
                }
                $ids = array_column(array_filter($rows, fn ($r) => $r['class'] === 'restore'), 'session_id');
                $back = array_values(array_filter($snapshot, fn ($r) => in_array((int) $r['id'], $ids, true)));
                $result = $this->rollback(['rows' => $back], $context + ['source_request_id' => (string) $plan['restore_request_id']]);
                if (($result['ok'] ?? false) !== true) {
                    throw new RuntimeException('forward_overdeduct_reassign_restore_incomplete'); // all or nothing
                }

                return ['ok' => true, 'restored' => $result['restored'], 'snapshot' => ['restored_rows' => $back]];
            }, 3);
        }

        return DB::transaction(function () use ($plan, $context): array {
            ClassSession::resetSettlementLockCache();
            $this->lockCourses(array_merge(array_column($plan['manifest'], 'class_id'), array_column($plan['manifest'], 'to_class_id')));
            [$rows] = $this->build($plan['campus_id']);
            if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                throw new RuntimeException('forward_overdeduct_reassign_digest_drift');
            }
            $snapshot = [];
            foreach (array_filter($rows, fn ($r) => $r['class'] === 'move') as $r) {
                $res = (new ClassSessionContractReassigner())->reassign($r['session_id'], $r['to_class_id'], self::REASON, null, false);
                if (isset($res['error']) || isset($res['locked'])) {
                    throw new RuntimeException('forward_overdeduct_reassign_refused'); // all or nothing
                }
                $snapshot[] = ['id' => $r['session_id'], 'from_class_id' => $r['class_id'], 'to_class_id' => $r['to_class_id']];
            }
            $this->audit(['reason_code' => self::REF, 'operation_id' => (string) ($context['operation_id'] ?? ''),
                'row_count' => count($snapshot), 'review_count' => count($rows) - count($snapshot), 'outcome' => 'success']);

            return ['ok' => true, 'moved' => count($snapshot), 'snapshot' => ['rows' => $snapshot]];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($plan['mode'] ?? 'reassign') === 'restore') {
            foreach ($result['snapshot']['restored_rows'] ?? [] as $r) {
                if ((int) DB::table('ClassSession')->where('id', $r['id'])->value('StudentClassID') !== (int) $r['from_class_id']) $errors[] = "not_restored_{$r['id']}";
            }

            return ['ok' => $errors === [], 'errors' => $errors, 'checks' => ['restored']];
        }
        if (($result['snapshot']['incomplete'] ?? false) === true) $errors[] = 'snapshot_incomplete_manual_resolution';
        $moved = $result['snapshot']['rows'] ?? [];
        foreach ($moved as $r) {
            if ((int) DB::table('ClassSession')->where('id', $r['id'])->value('StudentClassID') !== (int) $r['to_class_id']) $errors[] = "not_moved_{$r['id']}";
        }
        $left = $this->build((int) ($plan['campus_id'] ?? 0))[0];
        foreach ($left as $r) {
            if ($r['class'] === 'move') $errors[] = "still_movable_{$r['session_id']}";
        }
        $stillReview = array_column(array_filter($left, fn ($r) => $r['class'] === 'review'), 'class_id');
        $targets = array_unique(array_column($moved, 'to_class_id'));
        $sources = array_diff(array_unique(array_column($moved, 'from_class_id')), $stillReview); // sources with every candidate moved
        foreach (SessionDeductionService::batchExpectedUsedSessionDiagnostics(array_merge($targets, $sources)) as $id => $u) {
            if ($u['uncapped_used'] > $u['session_count']) $errors[] = "course_over_{$id}";
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['moved', 'targets_not_over', 'sources_not_over']];
    }

    /** Moves each snapshot lesson back; a lesson no longer on its target, or refused by the service, is skipped and reported. */
    public function rollback(array $snapshot, array $context): array
    {
        $rows = $snapshot['rows'] ?? null;
        if (!is_array($rows) || ($snapshot['incomplete'] ?? false) === true) {
            throw new RuntimeException('forward_overdeduct_reassign_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($rows): array {
            $restored = 0;
            $skipped = [];
            foreach ($rows as $r) {
                $here = (int) DB::table('ClassSession')->where('id', $r['id'])->value('StudentClassID') === (int) $r['to_class_id'];
                $res = $here ? (new ClassSessionContractReassigner())->reassign((int) $r['id'], (int) $r['from_class_id'], self::REASON . ' restore', null, false) : ['error' => 'moved_on'];
                if (isset($res['error']) || isset($res['locked'])) {
                    $skipped[] = (int) $r['id'];
                    continue;
                }
                $restored++;
            }
            $this->audit(['reason_code' => self::REF, 'row_count' => $restored, 'skipped' => count($skipped), 'outcome' => 'success'], '.rollback');

            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'restored' => $restored, 'skipped_ids' => $skipped];
        }, 3);
    }

    /** The stored snapshot of a succeeded reassign request of this operation for the same campus, or null. @return list<array<string,mixed>>|null */
    private function sourceSnapshot(string $requestId, int $campus): ?array
    {
        $request = DB::table('pop_operation_requests')->where('id', $requestId)->first();
        $parameters = $request ? json_decode((string) $request->parameters, true) : null;
        if (!$request || (string) $request->operation_id !== self::OPERATION || !is_array($parameters)
            || ($parameters['restore_request_id'] ?? '') !== '' || (int) ($parameters['campus_id'] ?? -1) !== $campus) {
            return null;
        }
        $execute = DB::table('pop_execution_records')->where('operation_id', $requestId)->where('phase', 'execute')
            ->where('result', 'succeeded')->orderByDesc('attempt_no')->first();
        $snapshot = $execute ? json_decode((string) $execute->snapshot, true) : null;

        return is_array($snapshot['rows'] ?? null) && $snapshot['rows'] !== [] ? $snapshot['rows'] : null;
    }

    /** ids-only rows: `restore` while the lesson is still on the target contract, else `skip`. @return list<array<string,mixed>> */
    private function restoreRows(array $snapshot): array
    {
        $now = DB::table('ClassSession')->whereIn('id', array_column($snapshot, 'id'))->pluck('StudentClassID', 'id');

        return array_map(fn (array $r): array => ['class_id' => (int) $r['from_class_id'], 'session_id' => (int) $r['id'], 'to_class_id' => (int) $r['to_class_id'],
            'class' => (int) ($now[$r['id']] ?? 0) === (int) $r['to_class_id'] ? 'restore' : 'skip'], array_values($snapshot));
    }

    /** @param list<int|string> $ids */
    private function lockCourses(array $ids): void
    {
        DB::table('StudentClass')->whereIn('ID', array_values(array_filter(array_unique(array_map('intval', $ids)))))->orderBy('ID')->lockForUpdate()->get();
    }

    private function minutes(object $course): int
    {
        return (int) $course->SessionDuration >= 1 ? (int) $course->SessionDuration : \App\Models\StudentClass::DEFAULT_SESSION_MINUTES;
    }

    private function settled(object $course): bool
    {
        return $course->settlement_locked_at !== null || in_array((string) $course->closed_reason, ['usage_settled', 'contract_amended', 'waived'], true);
    }

    private function audit(array $metadata, string $suffix = ''): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append('pop.forward_overdeduct_reassign' . $suffix, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'class_session_batch',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('forward_overdeduct_reassign_audit_not_persisted');
        }
    }

    /**
     * Ids-only rows, one per taught generated lesson beyond the bought count (or per doubtful course), each classified.
     *
     * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    private function build(int $campusId): array
    {
        $scan = array_values(array_filter(ForwardSessionExtras::scan(DB::connection(), $campusId), fn ($x) => $x['over']));
        $laterIds = [];
        foreach ($scan as $x) {
            $laterIds = array_merge($laterIds, array_map('intval', array_filter(explode(',', (string) $x['c']['later_course_ids']))));
        }
        $laterIds = array_values(array_unique($laterIds));
        $courses = DB::table('StudentClass')->whereIn('ID', $laterIds)->get(['ID', 'ScheduleMode', 'closed_reason', 'settlement_locked_at', 'TeacherID', 'PackageID', 'SessionDuration'])->keyBy('ID');
        $room = [];
        foreach (SessionDeductionService::batchExpectedUsedSessionDiagnostics($laterIds) as $id => $u) {
            // A minute-based target (partial-duration lesson) counts events, not consumed minutes: never a target (Codex P1).
            $room[$id] = $u['has_partial'] ? 0 : $u['session_count'] - $u['uncapped_used'];
        }
        $taken = [];
        $rows = [];
        foreach ($scan as $x) {
            $classId = (int) $x['c']['class_id'];
            $base = ['class_id' => $classId, 'campus_id' => (int) $x['c']['campus_id']];
            $none = $base + ['session_id' => 0, 'session_date' => '', 'start_time' => '', 'to_class_id' => 0, 'class' => 'review'];
            if ($x['u']['has_partial']) {
                $rows[] = $none + ['reason' => 'partial_minutes'];
                continue;
            }
            $excess = $x['u']['uncapped_used'] - (int) $x['c']['session_count'];
            if ($x['u']['observed_used'] > $x['u']['ledger_used']) {
                // Usage comes from lessons with no ledger row: moving ledger-backed extras would not clear it (Codex P2).
                $rows[] = $none + ['reason' => 'usage_not_from_ledger'];
                continue;
            }
            $candidates = DB::table('ClassSession as cs')->whereIn('cs.id', explode(',', (string) $x['c']['generated_session_ids']))
                // Taught, up to today: each move then lowers both the ledger and the completed-lesson count by one (Codex P2).
                ->whereRaw("LOWER(cs.Status) IN ('attended', 'completed', 'late')")
                ->whereDate('cs.SessionDate', '<=', now()->toDateString())
                ->whereRaw("(SELECT COALESCE(SUM(CASE WHEN l.event_type = 'deduct' THEN 1 ELSE -1 END), 0) FROM session_deduction_ledger l WHERE l.class_session_id = cs.id) > 0")
                ->orderByDesc('cs.SessionDate')->orderByDesc('cs.id')->limit($excess)->get(['cs.id', 'cs.SessionDate', 'cs.StartTime']);
            if ($candidates->count() < $excess) {
                // Fewer movable generated lessons than the excess: a partial move would leave the course over and invisible
                // to the rescan, so nothing on this course moves (Codex P2).
                $rows[] = $none + ['reason' => $candidates->isEmpty() ? 'no_movable_lesson' : 'insufficient_generated_lessons'];
                continue;
            }
            $source = DB::table('StudentClass')->where('ID', $classId)->first(['settlement_locked_at', 'closed_reason', 'TeacherID', 'PackageID', 'SessionDuration']);
            if ((int) $source->PackageID > 0) {
                // The reassigner moves only session_deduction_ledger, not package_session_ledger (Codex P1): packages stay manual.
                $rows[] = $none + ['reason' => 'package_linked'];
                continue;
            }
            // Usage also counts live sign-ins and learning records bound to no lesson; moving lessons cannot carry them, so
            // the excess might not clear (Codex P2): such a course stays manual.
            $unbound = DB::table('StudentSingIn as si')->leftJoin('ClassSession as cs', 'cs.id', '=', 'si.ClassSessionID')
                ->where('si.StudentClassID', $classId)->whereNull('si.VoidedAt')->whereNull('cs.id')->exists()
                || DB::table('LearningRecord')->where('StudentClassID', $classId)->whereNull('VoidedAt')
                    ->where(fn ($q) => $q->whereNull('ClassSessionID')->orWhere('ClassSessionID', '<=', 0))->exists();
            if ($unbound) {
                $rows[] = $none + ['reason' => 'unbound_usage'];
                continue;
            }
            $sourceSettled = $this->settled($source);
            $targets = array_values(array_filter(array_map('intval', array_filter(explode(',', (string) $x['c']['later_course_ids']))),
                fn (int $id) => ($c = $courses->get($id)) && strtolower((string) $c->ScheduleMode) === 'count' && (string) $c->closed_reason !== 'waived' && !$this->settled($c) && (int) $c->PackageID <= 0));
            foreach ($candidates as $s) {
                $date = substr((string) $s->SessionDate, 0, 10);
                $start = substr((string) $s->StartTime, 0, 5);
                $row = $base + ['session_id' => (int) $s->id, 'session_date' => $date, 'start_time' => $start, 'to_class_id' => 0];
                if ($sourceSettled) {
                    $rows[] = $row + ['class' => 'review', 'reason' => 'course_settled'];
                    continue;
                }
                if (trim((string) $x['c']['later_course_ids']) === '') {
                    $rows[] = $row + ['class' => 'keep', 'reason' => 'gift_no_later_contract'];
                    continue;
                }
                $reason = 'no_target_with_room';
                foreach ($targets as $tid) {
                    if (($room[$tid] ?? 0) <= 0) continue;
                    // The moved ledger keeps its minutes: a target with another lesson length would be over-drawn in minutes (Codex P1).
                    if ($this->minutes($courses->get($tid)) !== $this->minutes($source)) {
                        $reason = 'target_duration_differs';
                        continue;
                    }
                    $reason = 'target_slot_taken';
                    if (isset($taken["$tid|$date|$start"]) || DB::table('ClassSession')->where('StudentClassID', $tid)->whereDate('SessionDate', $date)
                        ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$start])->where(fn ($q) => $q->whereNull('Status')->orWhereRaw("LOWER(Status) <> 'cancelled'"))->exists()) continue; // the reassigner's own occupancy rule
                    $locked = LearningRecordMutableOwnership::protectedRecordForSession((int) $s->id, false);
                    // Restore moves it back through the same guard, so the locked assessment's teacher must match both contracts (Codex P1).
                    if ($locked !== null && ((int) $locked->TeacherID !== (int) $courses->get($tid)->TeacherID || (int) $locked->TeacherID !== (int) $source->TeacherID)) {
                        $reason = 'assessment_locked';
                        continue;
                    }
                    $room[$tid]--;
                    $taken["$tid|$date|$start"] = true;
                    $rows[] = array_merge($row, ['to_class_id' => $tid, 'class' => 'move', 'reason' => 'later_contract']);
                    continue 2;
                }
                $rows[] = $row + ['class' => 'review', 'reason' => $reason];
            }
        }
        usort($rows, fn ($a, $b) => [$a['class_id'], $a['session_id']] <=> [$b['class_id'], $b['session_id']]);
        $totals = ['rows' => count($rows), 'move' => 0, 'keep' => 0, 'review' => 0, 'by_campus' => []];
        foreach ($rows as $r) {
            $totals[$r['class']]++;
            $totals['by_campus'][$r['campus_id']][$r['class']] = ($totals['by_campus'][$r['campus_id']][$r['class']] ?? 0) + 1;
        }
        ksort($totals['by_campus']);

        return [$rows, $totals];
    }
}
