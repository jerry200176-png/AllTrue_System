<?php

namespace App\Operations\Strategies;

use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exact-case POP strategy (in-app #341; second case course 3380, in-app #550): past ClassSession rows that are still
 * `scheduled` on an early-ended contract become `cancelled`. Only ClassSession.Status changes; they have no
 * attendance, learning record or deduction ledger row (checked in plan and again under lock), so no billing,
 * Charge, Paid or SessionCount value is touched. Written with the query builder on purpose: the ClassSession
 * model blocks edits on settled courses.
 */
final class PastScheduledSessionsCancelStrategy
{
    private const REF = 'cancel-past-scheduled-course2942-20261008';
    private const REF_3380 = 'cancel-past-scheduled-course3380-20261118';

    /** Case chosen by decision_reference (plan) or snapshot reference (rollback); default is the original 2942 case. */
    private string $ref = self::REF;

    /** @var array<int,array{course_id:int,date:string,campus_id:int}>|null test-only override */
    private static ?array $override = null;

    /** @param array<int,array{course_id:int,date:string,campus_id:int}>|null $cases */
    public static function useCasesForTesting(?array $cases): void
    {
        self::$override = $cases;
    }

    /** @return array<int,array{course_id:int,date:string,campus_id:int}> session id => expected state */
    public static function cases(string $ref = self::REF): array
    {
        return self::$override ?? [
            // Read-only Pi SELECT 2026-10-08 (GitHub #3199): StudentClass 2942, campus 15, contract_amended.
            self::REF => [
                27142 => ['course_id' => 2942, 'date' => '2026-09-03', 'campus_id' => 15],
                27143 => ['course_id' => 2942, 'date' => '2026-09-10', 'campus_id' => 15],
                27144 => ['course_id' => 2942, 'date' => '2026-09-17', 'campus_id' => 15],
            ],
            // Read-only triage DB 2026-11-18 (in-app #550, GitHub #5361): StudentClass 3380, campus 16, closed waived.
            self::REF_3380 => [
                32305 => ['course_id' => 3380, 'date' => '2026-08-28', 'campus_id' => 16],
                32306 => ['course_id' => 3380, 'date' => '2026-09-01', 'campus_id' => 16],
                32307 => ['course_id' => 3380, 'date' => '2026-09-04', 'campus_id' => 16],
                32308 => ['course_id' => 3380, 'date' => '2026-09-08', 'campus_id' => 16],
                32309 => ['course_id' => 3380, 'date' => '2026-09-11', 'campus_id' => 16],
                32310 => ['course_id' => 3380, 'date' => '2026-09-15', 'campus_id' => 16],
                32311 => ['course_id' => 3380, 'date' => '2026-09-18', 'campus_id' => 16],
            ],
        ][$ref];
    }

    private function useRef(mixed $ref): bool
    {
        $known = in_array($ref, [self::REF, self::REF_3380], true);
        $this->ref = $known ? $ref : self::REF;

        return $known;
    }

    /** @return array<int,array{course_id:int,date:string,campus_id:int}> */
    private function selected(): array
    {
        return self::cases($this->ref);
    }

    public function plan(array $parameters): array
    {
        $paramErrors = $this->useRef($parameters['decision_reference'] ?? null) ? [] : ['case_parameters_mismatch'];
        $rows = $this->inspect(false);
        $errors = $this->beforeErrors($rows);
        $state = 'before';
        if ($errors !== [] && $this->afterErrors($rows) === []) {
            $errors = [];
            $state = 'after';
        }
        $errors = array_values(array_unique([...$paramErrors, ...$errors]));

        return ['ok' => $errors === [], 'errors' => $errors, 'state' => $state,
            'digest' => hash('sha256', json_encode([$state, $this->snapshot($rows)], JSON_THROW_ON_ERROR)),
            'counts' => ['sessions' => count($rows), 'to_cancel' => $state === 'before' ? count($rows) : 0],
            'reference' => $this->ref,
            'manifest' => array_map(fn ($r) => ['status' => $r['status'], 'course_id' => $r['course_id'], 'date' => $r['date']], $rows),
            'snapshot' => $errors === [] ? $this->snapshot($rows) : []];
    }

    public function execute(array $plan, array $context): array
    {
        $this->useRef($plan['reference'] ?? self::REF);
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            // Already cancelled before this request: do not manufacture authority to undo someone else's work.
            if ($this->ref === self::REF_3380) return ['ok' => true, 'already_applied' => true, 'updated' => 0,
                'snapshot' => ['reference' => $this->ref, 'rows' => []]];
            // Already applied (retry after the execution record failed to persist): idempotent, let verify run.
            // The rollback snapshot restores the known original status.
            $snapshot = $plan['snapshot'] ?? [];
            foreach ($snapshot['rows'] ?? [] as $id => $row) {
                $snapshot['rows'][$id]['status'] = 'scheduled';
            }

            $snapshot['reference'] = $this->ref;

            return ['ok' => true, 'already_applied' => true, 'updated' => 0, 'snapshot' => $snapshot];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'before') {
            throw new RuntimeException('past_scheduled_plan_not_ready');
        }

        return DB::transaction(function () use ($context): array {
            $ids = array_keys($this->selected());
            $rows = $this->inspect(true);
            $errors = $this->beforeErrors($rows);
            if ($errors !== []) {
                throw new RuntimeException('past_scheduled_drift:' . implode(',', $errors));
            }
            $n = DB::table('ClassSession')->whereIn('id', $ids)->where('Status', 'scheduled')
                ->update(['Status' => 'cancelled', 'updated_at' => now()]);
            if ($n !== count($ids)) {
                throw new RuntimeException("past_scheduled_updated_{$n}_of_" . count($ids));
            }
            $this->audit('pop.past_scheduled_sessions_cancel', ['reason_code' => $this->ref,
                'operation_id' => (string) ($context['operation_id'] ?? ''), 'session_count' => $n, 'outcome' => 'success']);

            return ['ok' => true, 'snapshot' => $this->snapshot($rows) + ['reference' => $this->ref], 'updated' => $n];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $this->useRef($plan['reference'] ?? self::REF);
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $rows = $this->inspect(false);
        $errors = array_values(array_unique([...$errors, ...$this->afterErrors($rows)]));
        $before = $plan['snapshot'] ?? $result['snapshot'] ?? [];
        foreach ($rows as $id => $r) {
            if (isset($before['rows'][$id]['course']) && $before['rows'][$id]['course'] !== $r['course']) {
                $errors[] = "course_changed_{$id}"; // Charge / Paid / SessionCount must not move
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'checks' => ['cancelled', 'no_billing_change']];
    }

    /** A new native rollback must refer to the complete before-state this request actually changed. */
    public static function hasNativeRecoverySnapshot(array $snapshot): bool
    {
        $rows = $snapshot['rows'] ?? null;
        if (($snapshot['reference'] ?? null) !== self::REF_3380 || !is_array($rows) || array_keys($rows) !== array_keys(self::cases(self::REF_3380))) return false;
        foreach (self::cases(self::REF_3380) as $id => $case) {
            $row = $rows[$id];
            if (!is_array($row) || ($row['status'] ?? null) !== 'scheduled' || ($row['course_id'] ?? null) !== $case['course_id']
                || ($row['date'] ?? null) !== $case['date'] || !is_array($row['course'] ?? null)) return false;
        }
        return true;
    }

    public function rollback(array $snapshot, array $context): array
    {
        if (!$this->useRef($snapshot['reference'] ?? self::REF)) { // old snapshots without reference remain 2942
            throw new RuntimeException('past_scheduled_rollback_reference_invalid');
        }
        $before = $snapshot['rows'] ?? [];
        if (!is_array($before) || array_keys($before) !== array_keys($this->selected())) {
            throw new RuntimeException('past_scheduled_rollback_snapshot_invalid');
        }
        if ($this->ref === self::REF_3380 && !self::hasNativeRecoverySnapshot($snapshot)) throw new RuntimeException('past_scheduled_rollback_snapshot_invalid');

        return DB::transaction(function () use ($before): array {
            $now = $this->inspect(true);
            $restored = 0;
            $skippedIds = [];
            foreach ($before as $id => $old) {
                $row = $now[$id] ?? null;
                if ($this->ref === self::REF_3380) {
                    $case = $this->selected()[$id];
                    if (!$row || $row['course_id'] !== $case['course_id'] || $row['date'] !== $case['date']
                        || $row['campus_id'] !== $case['campus_id'] || ($old['course_id'] ?? null) !== $case['course_id']
                        || ($old['date'] ?? null) !== $case['date'] || ($old['status'] ?? null) !== 'scheduled') {
                        $skippedIds[] = $id;
                        continue;
                    }
                    // A previous partial attempt already restored this row. No overwrite or second write.
                    if ($row['status'] === 'scheduled' && $row['activity'] === 0) continue;
                }
                if (!$row || $row['status'] !== 'cancelled' || $row['activity'] !== 0) {
                    $skippedIds[] = $id; // already moved on or has activity since: leave it alone
                    continue;
                }
                $n = DB::table('ClassSession')->where('id', $id)->where('Status', 'cancelled')
                    ->update(['Status' => $old['status'], 'updated_at' => now()]);
                if ($n !== 1) {
                    throw new RuntimeException("past_scheduled_rollback_row_{$id}");
                }
                $restored++;
            }
            $this->audit('pop.past_scheduled_sessions_cancel.rollback', ['reason_code' => $this->ref,
                'restored' => $restored, 'skipped' => count($skippedIds), 'outcome' => 'success']);

            return ['ok' => $skippedIds === [], 'partial' => $skippedIds !== [], 'restored' => $restored, 'skipped_ids' => $skippedIds];
        }, 3);
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. */
    private function audit(string $event, array $metadata): void
    {
        $correlationId = (string) \Illuminate\Support\Str::uuid();
        SecurityAuditEvent::append($event, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'class_session_batch',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('past_scheduled_audit_not_persisted');
        }
    }

    /** @return array<int,array<string,mixed>> session id => live state (ids, dates, counts only) */
    private function inspect(bool $lock): array
    {
        $current = $lock && $this->ref === self::REF_3380;
        $ids = array_keys($this->selected());
        $q = DB::table('ClassSession')->whereIn('id', $ids)->orderBy('id');
        $courseIds = array_values(array_unique(array_column($this->selected(), 'course_id')));
        $cq = DB::table('StudentClass')->whereIn('ID', $courseIds);
        // Match attendance's course -> session locks. All rechecks must be current reads,
        // even when safePlan established an earlier REPEATABLE READ snapshot.
        $courses = $current ? $cq->lockForUpdate()->get()->keyBy('ID') : null;
        $sessions = ($lock ? $q->lockForUpdate() : $q)->get()->keyBy('id');
        $courses ??= ($lock ? $cq->lockForUpdate() : $cq)->get()->keyBy('ID');
        $out = [];
        foreach ($sessions as $id => $s) {
            $course = $courses->get((int) $s->StudentClassID);
            $student = $course ? DB::table('Student')->where('id', $course->StudentID) : null;
            $out[(int) $id] = [
                'status' => (string) $s->Status,
                'course_id' => (int) $s->StudentClassID,
                'date' => substr((string) $s->SessionDate, 0, 10),
                'campus_id' => $student ? (int) ($current ? $student->lockForUpdate() : $student)->value('CampusID') : 0,
                'activity' => $this->activityCount('StudentSingIn', 'ClassSessionID', $id, $current)
                    + $this->activityCount('LearningRecord', 'ClassSessionID', $id, $current)
                    + $this->activityCount('session_deduction_ledger', 'class_session_id', $id, $current),
                'course' => $course ? ['charge' => (int) $course->Charge, 'paid' => (int) $course->Paid,
                    'session_count' => (int) $course->SessionCount] : null,
            ];
        }

        return $out;
    }

    private function activityCount(string $table, string $key, int $id, bool $current): int
    {
        $query = DB::table($table)->where($key, $id);
        return $current ? $query->lockForUpdate()->pluck($key)->count() : $query->count();
    }

    /** @param array<int,array<string,mixed>> $rows @return list<string> */
    private function beforeErrors(array $rows): array
    {
        $errors = [];
        $today = now()->toDateString();
        foreach ($this->selected() as $id => $case) {
            $r = $rows[$id] ?? null;
            if (!$r) { $errors[] = "missing_{$id}"; continue; }
            if ($r['status'] !== 'scheduled') $errors[] = "status_{$id}";
            if ($r['course_id'] !== $case['course_id'] || $r['date'] !== $case['date']) $errors[] = "identity_{$id}";
            if ($r['campus_id'] !== $case['campus_id']) $errors[] = "campus_{$id}";
            if ($r['date'] >= $today) $errors[] = "not_past_{$id}";
            if ($r['activity'] !== 0) $errors[] = "activity_{$id}";
        }

        return $errors;
    }

    /** @param array<int,array<string,mixed>> $rows @return list<string> */
    private function afterErrors(array $rows): array
    {
        $errors = [];
        foreach (array_keys($this->selected()) as $id) {
            if (($rows[$id]['status'] ?? null) !== 'cancelled') {
                $errors[] = "not_cancelled_{$id}";
            }
        }

        return $errors;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function snapshot(array $rows): array
    {
        return ['rows' => array_map(fn ($r) => array_intersect_key($r, array_flip(['status', 'course_id', 'date', 'course'])), $rows)];
    }
}
