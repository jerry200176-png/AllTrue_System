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
 */
final class ForwardExtraCancelStrategy
{
    private const REF = 'cancel-forward-extra-sessions-20261101';
    private const COURSE_COLUMNS = ['ID', 'SessionCount', 'UsedSessions', 'RemainingSessions', 'Charge', 'Paid'];

    public function plan(array $parameters): array
    {
        $campus = $parameters['campus_id'] ?? null;
        $want = (string) ($parameters['expected_digest'] ?? '');
        $errors = [];
        if (($parameters['decision_reference'] ?? null) !== self::REF) $errors[] = 'case_parameters_mismatch';
        if (!is_numeric($campus) || (int) $campus < 0 || (string) (int) $campus !== (string) $campus) $errors[] = 'campus_id_invalid';
        if ($want !== '' && !preg_match('/^[0-9a-f]{64}$/', $want)) $errors[] = 'digest_format';
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'state' => 'invalid'];
        }
        [$rows, $totals] = $this->build((int) $campus);
        $digest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $todo = array_filter($rows, fn ($r) => $r['class'] === 'cancel');
        $state = $want === '' ? 'unpinned' : ($todo === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));

        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'campus_id' => (int) $campus, 'digest' => $digest, 'totals' => $totals, 'manifest' => $rows];
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
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($result['snapshot']['incomplete'] ?? false) === true) $errors[] = 'snapshot_incomplete_manual_resolution';
        $ids = array_column($result['snapshot']['rows'] ?? [], 'id');
        if (DB::table('ClassSession')->whereIn('id', $ids)->whereRaw("LOWER(Status) <> 'cancelled'")->exists()) $errors[] = 'not_cancelled';
        foreach ($result['snapshot']['courses'] ?? [] as $before) {
            $now = (array) DB::table('StudentClass')->where('ID', $before['ID'])->first(self::COURSE_COLUMNS);
            if (array_map('strval', $now) !== array_map('strval', $before)) $errors[] = "course_changed_{$before['ID']}"; // no counter or amount may move
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
                    || DB::table('session_deduction_ledger')->where('class_session_id', $sid)->exists();
                $duplicate = $later !== [] && DB::table('ClassSession')->whereIn('StudentClassID', $later)->whereDate('SessionDate', $date)
                    ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$start])->whereRaw("LOWER(Status) <> 'cancelled'")->exists();
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
