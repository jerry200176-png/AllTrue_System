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
 * The rule is DB facts, not the path: a lesson dated FLOOR..yesterday (Asia/Taipei), status completed/attended, ledger net
 * exactly 1 for its course and lesson, and no sign-in row at all (live or voided). A row is `write` when the payee resolves
 * (occurrence, substitute, else contract teacher, as part-time payroll does) to an existing user; otherwise `review`, listed only.
 * Pending evaluations that were never debited fail the ledger rule and stay with the normal approve / 補登 flow.
 *
 * Execute writes one present sign-in per `write` row (payee as teacher, scheduled times, SessionDeducted=1 because the ledger
 * already holds the debit) with the query builder. It never calls the deduction service, so no ledger row or course counter
 * moves; the lesson status is left as is (completed and attended are read the same). Payroll tables are never written: a
 * locked month's run keeps its snapshot, and the row's `month_locked` flags it for next month's adjustment (Founder).
 * Bound to the reviewed dry run by `expected_digest` (Td076 pattern); rollback deletes exactly the created sign-ins.
 */
final class TaughtLessonSignInRepairStrategy
{
    private const REF = 'repair-taught-lesson-signin-20261105';
    private const FLOOR = '2026-08-01'; // part-time payroll reads sign-ins from 2026-08 on; ponytail: widen only by a new Founder decision
    private const MEMO = 'pop_taught_signin_20261105';

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
        $todo = array_filter($rows, fn ($r) => $r['class'] === 'write');
        $state = $want === '' ? 'unpinned' : ($todo === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));

        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'campus_id' => (int) $campus, 'digest' => $digest, 'totals' => $totals, 'manifest' => $rows];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            return ['ok' => true, 'already_applied' => true, 'created' => 0, 'snapshot' => ['rows' => [], 'incomplete' => true]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') {
            throw new RuntimeException('taught_signin_plan_not_pinned');
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
                $id = DB::table('StudentSingIn')->insertGetId([
                    'StudentClassID' => (int) $s->ID, 'StudentID' => (int) $s->StudentID, 'TeacherID' => $r['payee_teacher_id'],
                    'RecordedByUserID' => null, 'GradeID' => $s->GradeID, 'SubjectID' => $s->SubjectID, 'Get1byID' => $s->by1,
                    'SignInDT' => $date . ' ' . ($s->StartTime ?? '00:00:00'), 'SignOutDT' => $date . ' ' . ($s->EndTime ?? '23:59:00'),
                    'MDT' => now(), 'ClassSessionID' => $r['session_id'], 'Status' => 'present', 'CampusID' => $s->CampusID,
                    'PersonType' => 'student', 'SessionDeducted' => 1, 'Memo' => self::MEMO,
                ]);
                $snapshot[] = ['sign_in_id' => (int) $id, 'session_id' => $r['session_id'], 'prior_status' => (string) $s->Status];
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
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($result['snapshot']['incomplete'] ?? false) === true) $errors[] = 'snapshot_incomplete_manual_resolution';
        foreach ($result['snapshot']['rows'] ?? [] as $r) {
            $live = DB::table('StudentSingIn')->where('ClassSessionID', $r['session_id'])->whereNull('VoidedAt')->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($live !== [$r['sign_in_id']]) $errors[] = "sign_in_not_single_{$r['session_id']}";
            if ((string) DB::table('ClassSession')->where('id', $r['session_id'])->value('Status') !== $r['prior_status']) $errors[] = "status_changed_{$r['session_id']}";
        }
        $sessionIds = array_column($result['snapshot']['rows'] ?? [], 'session_id');
        foreach ($this->ledger($sessionIds) as $sessionId => $l) {
            if ($l['net'] !== 1) $errors[] = "ledger_net_not_one_{$sessionId}";
        }
        foreach ($this->build((int) ($plan['campus_id'] ?? 0))[0] as $r) {
            if ($r['class'] === 'write') $errors[] = "still_missing_{$r['session_id']}";
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['one_live_sign_in', 'ledger_net_one', 'none_left']];
    }

    /** Deletes exactly the created sign-ins (by id, lesson and marker). The lesson status was never written, so there is none to restore. */
    public function rollback(array $snapshot, array $context): array
    {
        $rows = $snapshot['rows'] ?? null;
        if (!is_array($rows) || ($snapshot['incomplete'] ?? false) === true) {
            throw new RuntimeException('taught_signin_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($rows): array {
            $deleted = 0;
            $skipped = [];
            foreach ($rows as $r) {
                if (DB::table('StudentSingIn')->where('id', $r['sign_in_id'])->where('ClassSessionID', $r['session_id'])->where('Memo', self::MEMO)->delete() === 1) {
                    $deleted++;
                } else {
                    $skipped[] = (int) $r['sign_in_id'];
                }
            }
            $this->audit(['reason_code' => self::REF, 'row_count' => $deleted, 'outcome' => 'success'], '.rollback');

            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'deleted' => $deleted, 'skipped_ids' => $skipped];
        }, 3);
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
     * Ids-only rows, one per taught, debited lesson with no sign-in row.
     *
     * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    private function build(int $campusId): array
    {
        $today = now('Asia/Taipei')->toDateString();
        $lessons = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->whereIn(DB::raw('LOWER(TRIM(cs.Status))'), ['completed', 'attended'])
            ->where('cs.SessionDate', '>=', self::FLOOR)->where('cs.SessionDate', '<', $today)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('StudentSingIn as si')->whereColumn('si.ClassSessionID', 'cs.id'))
            ->whereRaw("(SELECT COALESCE(SUM(CASE WHEN l.event_type='deduct' THEN 1 WHEN l.event_type='reverse' THEN -1 ELSE 0 END), 0)
                FROM session_deduction_ledger l WHERE l.student_class_id = sc.ID AND l.class_session_id = cs.id) = 1")
            ->when($campusId > 0, fn ($q) => $q->where('st.CampusID', $campusId))
            ->orderBy('cs.id')
            ->get(['cs.id', 'cs.StudentClassID', 'cs.SessionDate', 'cs.StartTime', 'cs.Note', 'st.CampusID', 'sc.TeacherID']);
        $records = DB::table('LearningRecord')->whereIn('ClassSessionID', $lessons->pluck('id')->all())->whereNull('VoidedAt')
            ->where('Status', 'approved')->pluck('Content', 'ClassSessionID');
        $rows = [];
        foreach ($lessons as $l) {
            $date = substr((string) $l->SessionDate, 0, 10);
            $payee = (int) ((SubstituteScheduleService::occurrence((int) $l->StudentClassID, $date, $l->StartTime)[0] ?? null)
                ?? SubstituteScheduleService::resolveSubstituteUserId((int) $l->StudentClassID, $date, $l->StartTime) ?? $l->TeacherID ?? 0);
            $type = $payee > 0 ? DB::table('User')->where('id', $payee)->value('employment_type') : null;
            $known = $payee > 0 && DB::table('User')->where('id', $payee)->exists();
            $month = substr($date, 0, 7);
            $content = (string) ($records[$l->id] ?? '');
            $rows[] = ['session_id' => (int) $l->id, 'class_id' => (int) $l->StudentClassID, 'campus_id' => (int) $l->CampusID,
                'session_date' => $date, 'start_time' => substr((string) $l->StartTime, 0, 5), 'payee_teacher_id' => $payee,
                'employment_type' => $type === null ? null : (preg_match('/^[a-z_]{1,32}$/', (string) $type) ? (string) $type : 'other'),
                'month' => $month, 'month_locked' => DB::table('payroll_month_status')->where('branch_id', (int) $l->CampusID)->where('month', $month)
                    ->where('status', 'locked')->whereNotNull('current_run_id')->exists(),
                'source' => match (true) {
                    $content === '（系統自動核准）' => 'enrollment_confirmed',
                    $content === '（系統補登加課）' => 'add_session_auto_approve',
                    str_contains((string) $l->Note, '系統判定補登') => 'enrollment_backfill',
                    default => 'other',
                },
                'class' => $known ? 'write' : 'review', 'reason' => $known ? 'taught_debited_no_sign_in' : 'payee_unresolved'];
        }
        $totals = ['rows' => count($rows), 'write' => 0, 'review' => 0, 'part_time' => 0, 'month_locked' => 0, 'by_source' => [], 'by_campus' => []];
        foreach ($rows as $r) {
            $totals[$r['class']]++;
            if ($r['class'] !== 'write') continue;
            $totals['part_time'] += $r['employment_type'] === 'part_time' ? 1 : 0;
            $totals['month_locked'] += $r['month_locked'] ? 1 : 0;
            $totals['by_source'][$r['source']] = ($totals['by_source'][$r['source']] ?? 0) + 1;
            $totals['by_campus'][$r['campus_id']]['write'] = ($totals['by_campus'][$r['campus_id']]['write'] ?? 0) + 1;
            $totals['by_campus'][$r['campus_id']]['other'] = ($totals['by_campus'][$r['campus_id']]['other'] ?? 0) + ($r['source'] === 'other' ? 1 : 0);
        }
        ksort($totals['by_source']);
        ksort($totals['by_campus']);

        return [$rows, $totals];
    }
}
