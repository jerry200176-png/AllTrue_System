<?php

namespace App\Operations\Strategies;

use App\Models\SecurityAuditEvent;
use App\Services\SubstituteScheduleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * GitHub #4925 #4927 #4931 (in-app #502, #504): several paths marked a past lesson taught and debited it but wrote no sign-in
 * (enrollment 已上過 / 系統判定補登, add-session auto-approve, the calendar's scheduled → attended). Part-time payroll and
 * attendance read sign-ins, so the lesson was taught and debited but unpaid. Founder approved the repair 2026-11-05.
 *
 * The rule is DB facts, not the path: a lesson dated FLOOR..yesterday (Asia/Taipei), status completed/attended/late, ledger net
 * exactly 1 for its course and lesson, and no live sign-in. Each row is in one `group`: `missing` (no sign-in row at all),
 * `late` (status late, no row: the old calendar's 遲到 debited without a sign-in) or `voided` (only voided rows; the latest
 * one is un-voided, because test/staging carry a unique index on the session id and a second insert would fail 1062). A row is `write` when the payee resolves
 * (occurrence, substitute, else contract teacher, as part-time payroll does) to an existing user; otherwise `review`, listed only.
 * Pending evaluations that were never debited fail the ledger rule and stay with the normal approve / 補登 flow.
 *
 * Execute writes one present (late for a late lesson) sign-in per `write` row (payee as teacher, scheduled times, SessionDeducted=1
 * because the ledger already holds the debit) with the query builder: an insert, or for `voided` the un-void of the latest voided row. It never calls the deduction service, so no ledger row or course counter
 * moves; the lesson status is left as is (completed and attended are read the same). Payroll tables are never written: a
 * locked month's run keeps its snapshot, and the row's `month_locked` flags it for next month's adjustment (Founder).
 * Bound to the reviewed dry run by `expected_digest` (Td076 pattern); rollback deletes exactly the created sign-ins and re-voids
 * exactly the un-voided ones from their snapshotted original state.
 * Restore (the reachable rollback, #4736 pattern) = a new Founder-approved request of this operation with `restore_request_id` =
 * the repair request: its plan reads that request's stored snapshot and lists each created sign-in still present (`delete`)
 * and each un-voided one still carrying the marker (`revoid`).
 */
final class TaughtLessonSignInRepairStrategy
{
    private const REF = 'repair-taught-lesson-signin-20261105';
    private const OPERATION = 'taught-lesson-signin-repair-20261105';
    private const FLOOR = '2026-08-01'; // part-time payroll reads sign-ins from 2026-08 on; ponytail: widen only by a new Founder decision
    private const MEMO = 'pop_taught_signin_20261105';

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
            $totals = ['rows' => count($rows), 'delete' => count(array_filter($rows, fn ($r) => $r['class'] === 'delete')),
                'revoid' => count(array_filter($rows, fn ($r) => $r['class'] === 'revoid'))];
        } else {
            [$rows, $totals] = $this->build((int) $campus);
        }
        $digest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $todo = array_filter($rows, fn ($r) => in_array($r['class'], ['write', 'delete', 'revoid'], true));
        $state = $want === '' ? 'unpinned' : ($todo === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));

        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'mode' => $source === '' ? 'repair' : 'restore', 'restore_request_id' => $source,
            'campus_id' => (int) $campus, 'digest' => $digest, 'totals' => $totals, 'manifest' => $rows];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            return ['ok' => true, 'already_applied' => true, 'created' => 0, 'snapshot' => ['rows' => [], 'incomplete' => true]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') {
            throw new RuntimeException('taught_signin_plan_not_pinned');
        }

        if (($plan['mode'] ?? 'repair') === 'restore') {
            return DB::transaction(function () use ($plan, $context): array {
                $snapshot = $this->sourceSnapshot((string) $plan['restore_request_id'], (int) $plan['campus_id']) ?? [];
                DB::table('StudentSingIn')->whereIn('id', array_column($snapshot, 'sign_in_id'))->orderBy('id')->lockForUpdate()->get();
                $rows = $this->restoreRows($snapshot);
                if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                    throw new RuntimeException('taught_signin_digest_drift');
                }
                $deleteIds = array_column(array_filter($rows, fn ($r) => $r['class'] === 'delete'), 'sign_in_id');
                $revoidIds = array_column(array_filter($rows, fn ($r) => $r['class'] === 'revoid'), 'sign_in_id');
                $ids = array_merge($deleteIds, $revoidIds);
                $result = $this->rollback(['rows' => array_values(array_filter($snapshot, fn ($r) => in_array((int) $r['sign_in_id'], $ids, true)))],
                    $context + ['source_request_id' => (string) $plan['restore_request_id']]);
                if (($result['ok'] ?? false) !== true) {
                    throw new RuntimeException('taught_signin_restore_incomplete'); // all or nothing
                }

                return ['ok' => true, 'deleted' => $result['deleted'], 'revoided' => $result['revoided'],
                    'snapshot' => ['deleted_sign_in_ids' => $deleteIds, 'revoided_sign_in_ids' => $revoidIds]];
            }, 3);
        }

        return DB::transaction(function () use ($plan): array {
            $ids = array_column(array_filter($plan['manifest'], fn ($r) => $r['class'] === 'write'), 'session_id');
            DB::table('ClassSession')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            [$rows] = $this->build($plan['campus_id']);
            if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                throw new RuntimeException('taught_signin_digest_drift');
            }
            $ledgerBefore = $this->ledger($ids);
            $snapshot = [];
            foreach (array_filter($rows, fn ($r) => $r['class'] === 'write') as $r) {
                $s = DB::table('ClassSession as cs')->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                    ->join('Student as st', 'st.id', '=', 'sc.StudentID')->where('cs.id', $r['session_id'])
                    ->first(['cs.SessionDate', 'cs.StartTime', 'cs.EndTime', 'cs.Status', 'sc.ID', 'sc.StudentID', 'sc.GradeID', 'sc.SubjectID', 'sc.by1', 'st.CampusID']);
                $date = substr((string) $s->SessionDate, 0, 10);
                $status = strtolower(trim((string) $s->Status)) === 'late' ? 'late' : 'present';
                if ($r['group'] === 'voided') {
                    $id = (int) $r['voided_sign_in_id'];
                    $original = (array) DB::table('StudentSingIn')->where('id', $id)->where('ClassSessionID', $r['session_id'])->whereNotNull('VoidedAt')
                        ->first(['VoidedAt', 'VoidedByUserID', 'VoidReason', 'Status', 'TeacherID', 'SessionDeducted', 'MDT', 'Memo']);
                    if ($original === []) {
                        throw new RuntimeException('taught_signin_voided_row_missing');
                    }
                    DB::table('StudentSingIn')->where('id', $id)->update(['VoidedAt' => null, 'VoidedByUserID' => null, 'VoidReason' => null,
                        'Status' => $status, 'TeacherID' => $r['payee_teacher_id'], 'SessionDeducted' => 1, 'MDT' => now(), 'Memo' => self::MEMO]);
                    $snapshot[] = ['sign_in_id' => $id, 'session_id' => $r['session_id'], 'prior_status' => (string) $s->Status, 'action' => 'unvoid', 'original' => $original];
                    continue;
                }
                $id = DB::table('StudentSingIn')->insertGetId([
                    'StudentClassID' => (int) $s->ID, 'StudentID' => (int) $s->StudentID, 'TeacherID' => $r['payee_teacher_id'],
                    'RecordedByUserID' => null, 'GradeID' => $s->GradeID, 'SubjectID' => $s->SubjectID, 'Get1byID' => $s->by1,
                    'SignInDT' => $date . ' ' . ($s->StartTime ?? '00:00:00'), 'SignOutDT' => $date . ' ' . ($s->EndTime ?? '23:59:00'),
                    'MDT' => now(), 'ClassSessionID' => $r['session_id'], 'Status' => $status, 'CampusID' => $s->CampusID,
                    'PersonType' => 'student', 'SessionDeducted' => 1, 'Memo' => self::MEMO,
                ]);
                $snapshot[] = ['sign_in_id' => (int) $id, 'session_id' => $r['session_id'], 'prior_status' => (string) $s->Status, 'action' => 'create'];
            }
            foreach ($snapshot as $row) {
                if (DB::table('StudentSingIn')->where('ClassSessionID', $row['session_id'])->whereNull('VoidedAt')->count() !== 1) {
                    throw new RuntimeException('taught_signin_not_exactly_one');
                }
            }
            if ($this->ledger($ids) !== $ledgerBefore) {
                throw new RuntimeException('taught_signin_ledger_moved');
            }
            $this->audit(['reason_code' => self::REF, 'row_count' => count($snapshot), 'outcome' => 'success']);

            return ['ok' => true, 'created' => count($snapshot), 'snapshot' => ['rows' => $snapshot]];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        if (($plan['mode'] ?? 'repair') === 'restore') {
            $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
            if (DB::table('StudentSingIn')->whereIn('id', $result['snapshot']['deleted_sign_in_ids'] ?? [])->exists()) $errors[] = 'not_deleted';
            $revoided = $result['snapshot']['revoided_sign_in_ids'] ?? [];
            if ($revoided !== [] && DB::table('StudentSingIn')->whereIn('id', $revoided)->whereNotNull('VoidedAt')->count() !== count($revoided)) $errors[] = 'not_revoided';

            return ['ok' => $errors === [], 'errors' => $errors, 'checks' => ['deleted', 'revoided']];
        }
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($result['snapshot']['incomplete'] ?? false) === true) $errors[] = 'snapshot_incomplete_manual_resolution';
        foreach ($result['snapshot']['rows'] ?? [] as $r) {
            $live = DB::table('StudentSingIn')->where('ClassSessionID', $r['session_id'])->whereNull('VoidedAt')->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($live !== [$r['sign_in_id']]) $errors[] = "sign_in_not_single_{$r['session_id']}";
            if ((string) DB::table('ClassSession')->where('id', $r['session_id'])->value('Status') !== $r['prior_status']) $errors[] = "status_changed_{$r['session_id']}";
        }
        $sessionIds = array_column($result['snapshot']['rows'] ?? [], 'session_id');
        $ledger = $this->ledger($sessionIds);
        foreach ($sessionIds as $sessionId) { // every repaired lesson must still carry exactly its one debit; a missing ledger fails closed
            if (($ledger[$sessionId]['net'] ?? 0) !== 1) $errors[] = "ledger_net_not_one_{$sessionId}";
        }
        foreach ($this->build((int) ($plan['campus_id'] ?? 0))[0] as $r) {
            if ($r['class'] === 'write') $errors[] = "still_missing_{$r['session_id']}";
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['one_live_sign_in', 'ledger_net_one', 'none_left']];
    }

    /** Deletes exactly the created sign-ins and re-voids exactly the un-voided ones to their snapshotted state (by id, lesson and marker). The lesson status was never written. */
    public function rollback(array $snapshot, array $context): array
    {
        $rows = $snapshot['rows'] ?? null;
        if (!is_array($rows) || ($snapshot['incomplete'] ?? false) === true) {
            throw new RuntimeException('taught_signin_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($rows): array {
            $deleted = 0;
            $revoided = 0;
            $skipped = [];
            foreach ($rows as $r) {
                $mine = DB::table('StudentSingIn')->where('id', $r['sign_in_id'])->where('ClassSessionID', $r['session_id'])->where('Memo', self::MEMO);
                if (($r['action'] ?? 'create') === 'unvoid') {
                    $done = is_array($r['original'] ?? null) && $mine->whereNull('VoidedAt')->update($r['original']) === 1;
                    $revoided += $done ? 1 : 0;
                } else {
                    $done = $mine->delete() === 1;
                    $deleted += $done ? 1 : 0;
                }
                if (!$done) {
                    $skipped[] = (int) $r['sign_in_id'];
                }
            }
            $this->audit(['reason_code' => self::REF, 'row_count' => $deleted + $revoided, 'outcome' => 'success'], '.rollback');

            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'deleted' => $deleted, 'revoided' => $revoided, 'skipped_ids' => $skipped];
        }, 3);
    }

    /** The stored snapshot of a succeeded repair request of this operation for the same campus, or null. @return list<array<string,mixed>>|null */
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

    /** ids-only rows: `delete` (created) or `revoid` (un-voided) while the row still carries the marker, else `skip` (gone or changed since). @return list<array<string,mixed>> */
    private function restoreRows(array $snapshot): array
    {
        $live = DB::table('StudentSingIn')->whereIn('id', array_column($snapshot, 'sign_in_id'))->where('Memo', self::MEMO)
            ->whereNull('VoidedAt')->pluck('ClassSessionID', 'id');

        return array_map(function (array $r) use ($live): array {
            $here = (int) ($live[$r['sign_in_id']] ?? 0) === (int) $r['session_id'];

            return ['sign_in_id' => (int) $r['sign_in_id'], 'session_id' => (int) $r['session_id'],
                'class' => !$here ? 'skip' : (($r['action'] ?? 'create') === 'unvoid' ? 'revoid' : 'delete')];
        }, array_values($snapshot));
    }

    private function audit(array $metadata, string $suffix = ''): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append('pop.taught_lesson_signin_repair' . $suffix, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'sign_in_batch',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('taught_signin_audit_not_persisted');
        }
    }

    /** @return array<int, array{rows:int, net:int}> ledger row count and net per lesson */
    private function ledger(array $sessionIds): array
    {
        $out = [];
        foreach (DB::table('session_deduction_ledger')->whereIn('class_session_id', $sessionIds)->orderBy('class_session_id')
            ->selectRaw("class_session_id, COUNT(*) as n, SUM(CASE WHEN event_type='deduct' THEN 1 WHEN event_type='reverse' THEN -1 ELSE 0 END) as net")
            ->groupBy('class_session_id')->get() as $l) {
            $out[(int) $l->class_session_id] = ['rows' => (int) $l->n, 'net' => (int) $l->net];
        }

        return $out;
    }

    /**
     * Ids-only rows, one per taught, debited lesson with no live sign-in.
     *
     * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    private function build(int $campusId): array
    {
        $today = now('Asia/Taipei')->toDateString();
        $lessons = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->whereIn(DB::raw('LOWER(TRIM(cs.Status))'), ['completed', 'attended', 'late'])
            ->where('cs.SessionDate', '>=', self::FLOOR)->where('cs.SessionDate', '<', $today)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('StudentSingIn as si')->whereColumn('si.ClassSessionID', 'cs.id')->whereNull('si.VoidedAt'))
            ->whereRaw("(SELECT COALESCE(SUM(CASE WHEN l.event_type='deduct' THEN 1 WHEN l.event_type='reverse' THEN -1 ELSE 0 END), 0)
                FROM session_deduction_ledger l WHERE l.student_class_id = sc.ID AND l.class_session_id = cs.id) = 1")
            ->when($campusId > 0, fn ($q) => $q->where('st.CampusID', $campusId))
            ->orderBy('cs.id')
            ->get(['cs.id', 'cs.StudentClassID', 'cs.SessionDate', 'cs.StartTime', 'cs.EndTime', 'cs.Note', 'cs.Status', 'sc.StudentID', 'sc.GradeID', 'sc.SubjectID', 'st.CampusID', 'sc.TeacherID']);
        $records = DB::table('LearningRecord')->whereIn('ClassSessionID', $lessons->pluck('id')->all())->whereNull('VoidedAt')
            ->where('Status', 'approved')->pluck('Content', 'ClassSessionID');
        $voided = DB::table('StudentSingIn')->whereIn('ClassSessionID', $lessons->pluck('id')->all())->whereNotNull('VoidedAt')
            ->groupBy('ClassSessionID')->selectRaw('ClassSessionID, MAX(id) as id')->pluck('id', 'ClassSessionID');
        $voidedRows = DB::table('StudentSingIn')->whereIn('id', $voided->values()->all())
            ->get(['id', 'StudentClassID', 'StudentID', 'CampusID', 'GradeID', 'SubjectID', 'PersonType', 'SignInDT', 'SignOutDT'])->keyBy('id');
        $rows = [];
        foreach ($lessons as $l) {
            $voidedId = (int) ($voided[$l->id] ?? 0);
            // The un-void keeps the row's own lesson fields, so it is only safe when they already equal what an insert would write.
            $date0 = substr((string) $l->SessionDate, 0, 10);
            $want = ['StudentClassID' => (int) $l->StudentClassID, 'StudentID' => (int) $l->StudentID, 'CampusID' => (int) $l->CampusID, 'GradeID' => (int) $l->GradeID,
                'SubjectID' => (int) $l->SubjectID, 'PersonType' => 'student', 'SignInDT' => $date0 . ' ' . ($l->StartTime ?? '00:00:00'), 'SignOutDT' => $date0 . ' ' . ($l->EndTime ?? '23:59:00')];
            $have = $voidedId > 0 ? (array) $voidedRows[$voidedId] : [];
            $same = $voidedId <= 0 || array_reduce(array_keys($want), fn ($ok, $k) => $ok && ($k === 'PersonType' ? (string) ($have[$k] ?? '') === 'student'
                : (str_ends_with($k, 'DT') ? ($have[$k] ?? null) !== null && strtotime((string) $have[$k]) === strtotime($want[$k]) : (int) ($have[$k] ?? 0) === $want[$k])), true);
            $group = $voidedId > 0 ? 'voided' : (strtolower(trim((string) $l->Status)) === 'late' ? 'late' : 'missing');
            $date = substr((string) $l->SessionDate, 0, 10);
            $payee = (int) ((SubstituteScheduleService::occurrence((int) $l->StudentClassID, $date, $l->StartTime)[0] ?? null)
                ?? SubstituteScheduleService::resolveSubstituteUserId((int) $l->StudentClassID, $date, $l->StartTime) ?? $l->TeacherID ?? 0);
            $type = $payee > 0 ? DB::table('User')->where('id', $payee)->value('employment_type') : null;
            $known = $payee > 0 && DB::table('User')->where('id', $payee)->exists();
            $month = substr($date, 0, 7);
            $content = (string) ($records[$l->id] ?? '');
            $rows[] = ['session_id' => (int) $l->id, 'class_id' => (int) $l->StudentClassID, 'campus_id' => (int) $l->CampusID,
                'session_date' => $date, 'start_time' => substr((string) $l->StartTime, 0, 5), 'end_time' => substr((string) $l->EndTime, 0, 5), 'payee_teacher_id' => $payee,
                'employment_type' => $type === null ? null : (preg_match('/^[a-z_]{1,32}$/', (string) $type) ? (string) $type : 'other'),
                'group' => $group, 'voided_sign_in_id' => $voidedId, 'voided_fields' => $voidedId > 0 ? array_map(fn ($v) => $v === null ? null : (string) $v, array_diff_key($have, ['id' => 1])) : null, 'month' => $month, 'month_locked' => DB::table('payroll_month_status')->where('branch_id', (int) $l->CampusID)->where('month', $month)
                    ->where('status', 'locked')->whereNotNull('current_run_id')->exists(),
                'source' => match (true) {
                    $content === '（系統自動核准）' => 'enrollment_confirmed',
                    $content === '（系統補登加課）' => 'add_session_auto_approve',
                    str_contains((string) $l->Note, '系統判定補登') => 'enrollment_backfill',
                    default => 'other',
                },
                'class' => $known && $same ? 'write' : 'review',
                'reason' => !$known ? 'payee_unresolved' : (!$same ? 'voided_row_fields_differ' : ($group === 'voided' ? 'taught_debited_only_voided_sign_in' : 'taught_debited_no_sign_in'))];
        }
        $totals = ['rows' => count($rows), 'write' => 0, 'review' => 0, 'part_time' => 0, 'month_locked' => 0, 'by_group' => [], 'by_source' => [], 'by_campus' => []];
        foreach ($rows as $r) {
            $totals[$r['class']]++;
            $totals['by_group'][$r['group']] = ($totals['by_group'][$r['group']] ?? 0) + 1;
            if ($r['class'] !== 'write') continue;
            $totals['part_time'] += $r['employment_type'] === 'part_time' ? 1 : 0;
            $totals['month_locked'] += $r['month_locked'] ? 1 : 0;
            $totals['by_source'][$r['source']] = ($totals['by_source'][$r['source']] ?? 0) + 1;
            $totals['by_campus'][$r['campus_id']]['write'] = ($totals['by_campus'][$r['campus_id']]['write'] ?? 0) + 1;
            $totals['by_campus'][$r['campus_id']]['other'] = ($totals['by_campus'][$r['campus_id']]['other'] ?? 0) + ($r['source'] === 'other' ? 1 : 0);
        }
        ksort($totals['by_group']);
        ksort($totals['by_source']);
        ksort($totals['by_campus']);

        return [$rows, $totals];
    }
}
