<?php

namespace App\Operations\Strategies;

use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * GitHub #4901 (in-app #499): the legacy contract-teacher-change pin pass added a second history pin (rescheduled anchor +
 * scheduled child) over a slot that already had a live pin and a sign-in at the real teacher. The newest pin wins in the
 * effective-teacher resolver, so a taught past lesson showed the former contract teacher. This deletes exactly those pins.
 *
 * A pin pair is `delete` only when the slot's attendance sign-in (non-voided) names teacher E, an older live pin on the same
 * slot already names E and this one does not (a duplicate), and the pair's anchor has no other live child.
 * Any other slot where a pin disagrees with the sign-in is `review`: listed, never written. A lone pin is never deleted: RFID and
 * leave sign-ins stamp the contract teacher, so a real substitute or reschedule pin has the same shape.
 * Only past slots (before today, Asia/Taipei) are scanned. Execute is bound to the reviewed dry run by `expected_digest`
 * (Td076 pattern), re-checks under lock, snapshots the deleted rows (rollback re-inserts them by id) and writes an audit event.
 */
final class DuplicateHistoryPinRepairStrategy
{
    private const REF = 'repair-duplicate-history-pins-20261104';

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
        $todo = array_filter($rows, fn ($r) => $r['class'] === 'delete');
        $state = $want === '' ? 'unpinned' : ($todo === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));

        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'campus_id' => (int) $campus, 'digest' => $digest, 'totals' => $totals, 'manifest' => $rows];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            return ['ok' => true, 'already_applied' => true, 'deleted' => 0, 'snapshot' => ['rows' => [], 'incomplete' => true]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') {
            throw new RuntimeException('duplicate_pin_plan_not_pinned');
        }

        return DB::transaction(function () use ($plan): array {
            $ids = array_merge(...array_map(fn ($r) => [$r['pin_id'], $r['anchor_id']], $plan['manifest']));
            DB::table('schedules')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            [$rows] = $this->build($plan['campus_id']);
            if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                throw new RuntimeException('duplicate_pin_digest_drift');
            }
            $del = array_values(array_filter($rows, fn ($r) => $r['class'] === 'delete'));
            $delIds = array_merge(...array_map(fn ($r) => [$r['pin_id'], $r['anchor_id']], $del));
            $snapshot = DB::table('schedules')->whereIn('id', $delIds)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            DB::table('schedules')->whereIn('id', $delIds)->delete();
            if (count($snapshot) !== count($delIds) || DB::table('schedules')->whereIn('id', $delIds)->exists()) {
                throw new RuntimeException('duplicate_pin_incomplete');
            }
            $this->audit(['reason_code' => self::REF, 'row_count' => count($delIds), 'outcome' => 'success']);

            return ['ok' => true, 'deleted' => count($delIds), 'snapshot' => ['rows' => $snapshot]];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($result['snapshot']['incomplete'] ?? false) === true) $errors[] = 'snapshot_incomplete_manual_resolution';
        $ids = array_column($result['snapshot']['rows'] ?? [], 'id');
        if (DB::table('schedules')->whereIn('id', $ids)->exists()) $errors[] = 'not_deleted';
        foreach ($this->build((int) ($plan['campus_id'] ?? 0))[0] as $r) {
            if ($r['class'] === 'delete') $errors[] = "still_duplicate_{$r['pin_id']}";
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['pins_deleted', 'no_duplicate_left']];
    }

    /** Re-inserts exactly the deleted rows by id; an id taken since is skipped and reported. */
    public function rollback(array $snapshot, array $context): array
    {
        $rows = $snapshot['rows'] ?? null;
        if (!is_array($rows) || ($snapshot['incomplete'] ?? false) === true) {
            throw new RuntimeException('duplicate_pin_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($rows): array {
            $restored = 0;
            $skipped = [];
            foreach ($rows as $r) {
                if (DB::table('schedules')->where('id', $r['id'])->exists()) {
                    $skipped[] = (int) $r['id'];
                    continue;
                }
                DB::table('schedules')->insert($r);
                $restored++;
            }
            $this->audit(['reason_code' => self::REF, 'row_count' => $restored, 'outcome' => 'success'], '.rollback');

            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'restored' => $restored, 'skipped_ids' => $skipped];
        }, 3);
    }

    private function audit(array $metadata, string $suffix = ''): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append('pop.duplicate_history_pin_repair' . $suffix, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'schedule_batch',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('duplicate_pin_audit_not_persisted');
        }
    }

    /**
     * Ids-only rows, one per pin pair that disagrees with its slot's sign-in teacher.
     *
     * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    private function build(int $campusId): array
    {
        $today = now('Asia/Taipei')->toDateString();
        $pins = DB::table('schedules as p')
            ->join('StudentClass as sc', 'sc.ID', '=', 'p.student_course_id')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->join('schedules as a', 'a.id', '=', 'p.original_schedule_id')
            ->where('p.status', 'scheduled')->whereNotNull('p.original_schedule_id')
            ->where(fn ($q) => $q->whereNull('p.type')->orWhere('p.type', '<>', 'extra'))
            ->where('a.status', 'rescheduled')->whereDate('p.schedule_date', '<', $today)
            ->when($campusId > 0, fn ($q) => $q->where('st.CampusID', $campusId))
            ->orderBy('p.id')
            ->get(['p.id as pin_id', 'a.id as anchor_id', 'p.teacher_id', 'p.student_course_id as class_id', 'st.CampusID as campus_id',
                'sc.TeacherID as contract_teacher_id', 'p.schedule_date', 'p.start_time']);
        $groups = [];
        foreach ($pins as $p) {
            $groups[$p->class_id . '|' . substr((string) $p->schedule_date, 0, 10) . '|' . substr((string) $p->start_time, 0, 5)][] = $p;
        }
        $rows = [];
        foreach ($groups as $key => $group) {
            [$classId, $date, $start] = explode('|', $key);
            $sessionIds = DB::table('ClassSession')->where('StudentClassID', $classId)->whereDate('SessionDate', $date)
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$start])->pluck('id')->all();
            $evidence = (int) (DB::table('StudentSingIn')->whereIn('ClassSessionID', $sessionIds)->whereNull('VoidedAt')->where('TeacherID', '>', 0)
                ->orderBy('SignInDT')->value('TeacherID') ?? 0);
            if ($evidence === 0) {
                continue;
            }
            $first = $group[0];
            foreach ($group as $i => $p) {
                if ((int) $p->teacher_id === $evidence) {
                    continue;
                }
                $older = $i > 0 && (int) $first->teacher_id === $evidence;
                $rows[] = ['class_id' => (int) $classId, 'campus_id' => (int) $p->campus_id, 'session_date' => $date, 'start_time' => $start,
                    'pin_id' => (int) $p->pin_id, 'anchor_id' => (int) $p->anchor_id, 'pin_teacher_id' => (int) $p->teacher_id,
                    'evidence_teacher_id' => $evidence, 'class' => $older ? 'delete' : 'review',
                    'reason' => $older ? 'duplicate_over_older_pin' : 'pin_disagrees_with_sign_in'];
            }
        }
        // An anchor that another live child (or another delete row) still uses is never deleted: list the group instead.
        $children = DB::table('schedules')->whereIn('original_schedule_id', array_column($rows, 'anchor_id'))->where('status', 'scheduled')
            ->selectRaw('original_schedule_id as anchor_id, COUNT(*) as n')->groupBy('original_schedule_id')->pluck('n', 'anchor_id');
        foreach ($rows as &$r) {
            if ($r['class'] === 'delete' && (int) ($children[$r['anchor_id']] ?? 0) !== 1) {
                [$r['class'], $r['reason']] = ['review', 'shared_anchor'];
            }
        }
        unset($r);
        usort($rows, fn ($a, $b) => $a['pin_id'] <=> $b['pin_id']);
        $totals = ['rows' => count($rows), 'delete' => 0, 'review' => 0];
        foreach ($rows as $r) {
            $totals[$r['class']]++;
        }

        return [$rows, $totals];
    }
}
