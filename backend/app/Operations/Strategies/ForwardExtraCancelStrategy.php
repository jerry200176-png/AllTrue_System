<?php

namespace App\Operations\Strategies;

use App\Models\ClassSession;
use App\Models\SecurityAuditEvent;
use App\Services\ForwardSessionExtras;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * GitHub #4758 (in-app #452): the nightly forward generator added a `scheduled` lesson to courses whose bought lessons were
 * all used already, so marking it would deduct one more than was bought. The courses come from the shared scan the
 * forward-overdeduct probe uses (ForwardSessionExtras: ledger-used >= bought, not over yet, a generated lesson still scheduled).
 * Each such lesson is `cancel` when nothing points at it (no live sign-in, learning record or ledger row, course not settled)
 * and it is after today (Asia/Taipei) or a later course of the same student and subject holds a live lesson at the same
 * date and start (a duplicate). Every other lesson is `review`: listed, never written (a past unmarked lesson may have been taught).
 *
 * Cancel goes through the ClassSession model (creating/updating guards, ClassSessionObserver audit), after resetting the
 * settlement-lock cache. Only ClassSession.Status changes; the generator creates no Schedule row, and no counter is
 * recomputed: verify checks the course counters did not move. Execute is bound to the reviewed dry run by `expected_digest`
 * (Td076 pattern) and re-checks every row under lock. Rollback sets Status back to `scheduled` for exactly the cancelled ids.
 * Restore (the reachable rollback, #4736 pattern) = a new Founder-approved request of this operation with `restore_request_id` =
 * the cancel request: its plan reads that request's stored snapshot and lists each lesson still cancelled.
 */
final class ForwardExtraCancelStrategy
{
    private const REF = 'cancel-forward-extra-sessions-20261101';
    private const OPERATION = 'forward-extra-cancel-20261101';
    private const COURSE_COLUMNS = ['ID', 'SessionCount', 'UsedSessions', 'RemainingSessions', 'Charge', 'Paid'];

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
        $todo = array_filter($rows, fn ($r) => in_array($r['class'], ['cancel', 'restore'], true));
        $state = $want === '' ? 'unpinned' : ($todo === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));

        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'mode' => $source === '' ? 'cancel' : 'restore', 'restore_request_id' => $source,
            'campus_id' => (int) $campus, 'digest' => $digest, 'totals' => $totals, 'manifest' => $rows];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            // Nothing left to cancel (retry after the execution record failed to persist): the rows touched are in
            // schedule_audit_logs, not in this run's result, so no snapshot is guessed. Operator resolves.
            return ['ok' => true, 'already_applied' => true, 'cancelled' => 0, 'snapshot' => ['rows' => [], 'incomplete' => true]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') {
            throw new RuntimeException('forward_extra_cancel_plan_not_pinned');
        }

        if (($plan['mode'] ?? 'cancel') === 'restore') {
            return DB::transaction(function () use ($plan, $context): array {
                $snapshot = $this->sourceSnapshot((string) $plan['restore_request_id'], (int) $plan['campus_id']) ?? [];
                ClassSession::query()->whereIn('id', array_column($snapshot, 'id'))->orderBy('id')->lockForUpdate()->get();
                if (hash('sha256', json_encode($this->restoreRows($snapshot), JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                    throw new RuntimeException('forward_extra_cancel_digest_drift');
                }
                $ids = array_column(array_filter($this->restoreRows($snapshot), fn ($r) => $r['class'] === 'restore'), 'session_id');
                $result = $this->rollback(['rows' => array_values(array_filter($snapshot, fn ($r) => in_array((int) $r['id'], $ids, true)))],
                    $context + ['source_request_id' => (string) $plan['restore_request_id']]);
                if (($result['ok'] ?? false) !== true) {
                    throw new RuntimeException('forward_extra_cancel_restore_incomplete'); // all or nothing: a skipped row rolls back
                }

                return ['ok' => true, 'restored' => $result['restored'], 'snapshot' => ['restored_ids' => $ids]];
            }, 3);
        }

        return DB::transaction(function () use ($plan, $context): array {
            ClassSession::resetSettlementLockCache(); // the model's settled-course guard must see the live courses
            $sessionIds = array_column($plan['manifest'], 'session_id');
            $courseIds = array_values(array_unique(array_column($plan['manifest'], 'class_id')));
            // Lock the reviewed lessons and their courses before re-checking: a concurrent sign-in or move waits or is seen.
            ClassSession::query()->whereIn('id', $sessionIds)->orderBy('id')->lockForUpdate()->get();
            DB::table('StudentClass')->whereIn('ID', $courseIds)->orderBy('ID')->lockForUpdate()->get();
            [$rows] = $this->build($plan['campus_id']);
            if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                throw new RuntimeException('forward_extra_cancel_digest_drift');
            }
            $ids = array_column(array_filter($rows, fn ($r) => $r['class'] === 'cancel'), 'session_id');
            $snapshot = DB::table('ClassSession')->whereIn('id', $ids)->orderBy('id')->get(['id', 'StudentClassID', 'Status', 'Note'])->map(fn ($r) => (array) $r)->all();
            $courses = DB::table('StudentClass')->whereIn('ID', array_unique(array_column($snapshot, 'StudentClassID')))->orderBy('ID')
                ->get(self::COURSE_COLUMNS)->map(fn ($r) => (array) $r)->all();
            ClassSession::query()->whereIn('id', $ids)->orderBy('id')->get()->each(function (ClassSession $s): void {
                $s->Status = 'cancelled'; // ClassSessionObserver::updated audits each row
                $s->save();
            });
            if (count($snapshot) !== count($ids) || DB::table('ClassSession')->whereIn('id', $ids)->whereRaw("LOWER(Status) <> 'cancelled'")->exists()) {
                throw new RuntimeException('forward_extra_cancel_incomplete');
            }
            $this->audit(['reason_code' => self::REF, 'operation_id' => (string) ($context['operation_id'] ?? ''),
                'row_count' => count($ids), 'review_count' => count($rows) - count($ids), 'outcome' => 'success']);

            return ['ok' => true, 'cancelled' => count($ids), 'snapshot' => ['rows' => $snapshot, 'courses' => $courses]];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        if (($plan['mode'] ?? 'cancel') === 'restore') {
            $ids = $result['snapshot']['restored_ids'] ?? [];
            $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
            if (DB::table('ClassSession')->whereIn('id', $ids)->whereRaw("LOWER(Status) = 'cancelled'")->exists()) $errors[] = 'not_restored';

            return ['ok' => $errors === [], 'errors' => $errors, 'checks' => ['restored']];
        }
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($result['snapshot']['incomplete'] ?? false) === true) $errors[] = 'snapshot_incomplete_manual_resolution';
        $ids = array_column($result['snapshot']['rows'] ?? [], 'id');
        if (DB::table('ClassSession')->whereIn('id', $ids)->whereRaw("LOWER(Status) <> 'cancelled'")->exists()) $errors[] = 'not_cancelled';
        foreach ($result['snapshot']['courses'] ?? [] as $before) {
            $now = (array) DB::table('StudentClass')->where('ID', $before['ID'])->first(self::COURSE_COLUMNS);
            foreach (self::COURSE_COLUMNS as $col) { // no counter or amount may move; name the column and both values (numbers only)
                if ((string) ($now[$col] ?? '') !== (string) ($before[$col] ?? '')) $errors[] = "course_changed_{$before['ID']}:{$col}:" . ($before[$col] ?? 'null') . '->' . ($now[$col] ?? 'null');
            }
        }
        foreach ($this->build((int) ($plan['campus_id'] ?? 0))[0] as $r) {
            if ($r['class'] === 'cancel') $errors[] = "still_cancellable_{$r['session_id']}";
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['cancelled', 'course_counters_unchanged']];
    }

    /** Sets Status back to `scheduled` for exactly the cancelled ids; a row that moved on or whose slot is taken is skipped and reported. */
    public function rollback(array $snapshot, array $context): array
    {
        $rows = $snapshot['rows'] ?? null;
        if (!is_array($rows) || ($snapshot['incomplete'] ?? false) === true) {
            throw new RuntimeException('forward_extra_cancel_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($rows): array {
            ClassSession::resetSettlementLockCache();
            $restored = 0;
            $skipped = [];
            foreach ($rows as $r) {
                $s = ClassSession::query()->whereKey($r['id'])->lockForUpdate()->first();
                if (!$s || strtolower((string) $s->Status) !== 'cancelled') {
                    $skipped[] = (int) $r['id'];
                    continue;
                }
                $s->Status = $r['Status'];
                try {
                    $s->save(); // the updating guard re-checks the student's slot
                } catch (ValidationException) {
                    $skipped[] = (int) $r['id'];
                    continue;
                }
                $restored++;
            }
            $this->audit(['reason_code' => self::REF, 'row_count' => $restored, 'skipped' => count($skipped), 'outcome' => 'success'], '.rollback');

            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'restored' => $restored, 'skipped_ids' => $skipped];
        }, 3);
    }

    /** The stored snapshot of a succeeded cancel request of this operation for the same campus, or null. @return list<array<string,mixed>>|null */
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

    /** ids-only rows: `restore` while the lesson is still cancelled, else `skip` (it moved on since). @return list<array<string,mixed>> */
    private function restoreRows(array $snapshot): array
    {
        $now = DB::table('ClassSession')->whereIn('id', array_column($snapshot, 'id'))->pluck('Status', 'id');

        return array_map(fn (array $r): array => ['session_id' => (int) $r['id'], 'class_id' => (int) $r['StudentClassID'],
            'class' => strtolower((string) ($now[$r['id']] ?? '')) === 'cancelled' ? 'restore' : 'skip'], array_values($snapshot));
    }

    private function audit(array $metadata, string $suffix = ''): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append('pop.forward_extra_cancel' . $suffix, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'class_session_batch',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('forward_extra_cancel_audit_not_persisted');
        }
    }

    /**
     * Ids-only rows (no names), one per still-scheduled generated lesson on a fully used course, each classified.
     *
     * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    private function build(int $campusId): array
    {
        $today = now('Asia/Taipei')->toDateString();
        $rows = [];
        foreach (ForwardSessionExtras::scan(DB::connection(), $campusId) as $x) {
            if ($x['over'] || !$x['full'] || $x['waiting'] === []) {
                continue;
            }
            $classId = (int) $x['c']['class_id'];
            $later = array_values(array_filter(array_map('intval', explode(',', (string) $x['c']['later_course_ids']))));
            $settled = DB::table('StudentClass')->where('ID', $classId)
                ->where(fn ($q) => $q->whereNotNull('settlement_locked_at')->orWhereIn('closed_reason', ['usage_settled', 'contract_amended', 'waived']))->exists();
            foreach ($x['waiting'] as $sid) {
                $s = DB::table('ClassSession')->where('id', $sid)->first();
                if (!$s || strtolower((string) $s->Status) !== 'scheduled') {
                    continue;
                }
                $date = substr((string) $s->SessionDate, 0, 10);
                $start = substr((string) $s->StartTime, 0, 5);
                $activity = DB::table('StudentSingIn')->where('ClassSessionID', $sid)->whereNull('VoidedAt')->exists()
                    || DB::table('LearningRecord')->where('ClassSessionID', $sid)->whereNull('VoidedAt')->exists()
                    || DB::table('session_deduction_ledger')->where('class_session_id', $sid)->exists()
                    // A legacy record with no ClassSessionID counts toward usage by course + date (+ start), so it protects the lesson too.
                    || DB::table('LearningRecord')->where('StudentClassID', $classId)->whereNull('VoidedAt')
                        ->where(fn ($q) => $q->whereNull('ClassSessionID')->orWhere('ClassSessionID', '<=', 0))->whereDate('SessionDate', $date)
                        ->where(fn ($q) => $q->whereNull('StartTime')->orWhereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$start]))->exists()
                    // Likewise a live sign-in not bound to an existing lesson, same course and day (usage counts it too).
                    || DB::table('StudentSingIn as si')->leftJoin('ClassSession as cs', 'cs.id', '=', 'si.ClassSessionID')
                        ->where('si.StudentClassID', $classId)->whereNull('si.VoidedAt')->whereNull('cs.id')->whereDate('si.SignInDT', $date)->exists();
                $duplicate = $later !== [] && DB::table('ClassSession')->whereIn('StudentClassID', $later)->whereDate('SessionDate', $date)
                    ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$start])->whereRaw("LOWER(Status) NOT IN ('cancelled', 'voided')")->exists();
                [$class, $reason] = match (true) {
                    $activity => ['review', 'activity'],
                    $settled => ['review', 'course_settled'],
                    $date > $today => ['cancel', 'future'], // today's may have ended unmarked: duplicate or review
                    $duplicate => ['cancel', 'past_duplicate_later_course'],
                    default => ['review', 'past_no_duplicate'],
                };
                $rows[] = ['class_id' => $classId, 'campus_id' => (int) $x['c']['campus_id'], 'session_id' => (int) $sid, 'session_date' => $date,
                    'start_time' => $start, 'class' => $class, 'reason' => $reason];
            }
        }
        usort($rows, fn ($a, $b) => $a['session_id'] <=> $b['session_id']);
        $totals = ['rows' => count($rows), 'cancel' => 0, 'review' => 0, 'by_campus' => []];
        foreach ($rows as $r) {
            $totals[$r['class']]++;
            $totals['by_campus'][$r['campus_id']][$r['class']] = ($totals['by_campus'][$r['campus_id']][$r['class']] ?? 0) + 1;
        }
        ksort($totals['by_campus']);

        return [$rows, $totals];
    }
}
