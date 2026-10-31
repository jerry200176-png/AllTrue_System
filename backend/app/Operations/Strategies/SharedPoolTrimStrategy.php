<?php

namespace App\Operations\Strategies;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Services\ClassSessionMaterializationService;
use App\Services\SharedPackagePlanningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Founder GO 2026-11-01 (A, #4734): before #4730 every subject of a shared count pool pre-planned the whole total. Per campus,
 * this deletes the planned lessons past what each pool has left, i.e. SharedPackagePlanningService::sharedPoolExcess, the rule
 * a total cut already applies. Those rows never happened (no sign-in, ledger, note, coverage or payroll row points at them), so
 * they are deleted, not cancelled: a cancelled date stays taken and a renewal would plan past it, leaving a gap. No billing,
 * ledger, Charge, Paid or SessionCount value is written. The plan is computed live and execute is bound to the reviewed dry
 * run by `expected_digest` (Td076 pattern). The snapshot keeps every deleted row whole.
 *
 * Restore = a new Founder-approved request of this operation with `restore_request_id` = the trim request: its plan reads that
 * request's stored execute snapshot and lists, digest-pinned the same way, the rows it can put back (future, id and course slot
 * free, student slot free by the write path's own guard). A row whose slot was taken since is reported, never doubled.
 */
final class SharedPoolTrimStrategy
{
    private const REF = 'trim-shared-pool-overplan-20261101';
    private const OPERATION = 'shared-pool-trim-overplan-20261101';
    private const GENERATED = ['StartTimeHM' => true, 'ActiveSlotFlag' => true];

    public function plan(array $parameters): array
    {
        $campuses = $this->campusIds($parameters['campus_ids'] ?? null);
        $want = (string) ($parameters['expected_digest'] ?? '');
        $source = (string) ($parameters['restore_request_id'] ?? '');
        $errors = [];
        if (($parameters['decision_reference'] ?? null) !== self::REF) $errors[] = 'case_parameters_mismatch';
        if ($campuses === []) $errors[] = 'campus_ids_required';
        if ($want !== '' && !preg_match('/^[0-9a-f]{64}$/', $want)) $errors[] = 'digest_format';
        if ($source !== '' && !Str::isUuid($source)) $errors[] = 'restore_request_id_format';
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'state' => 'invalid'];
        }
        if ($source !== '') {
            $snapshot = $this->sourceSnapshot($source, $campuses);
            if ($snapshot === null) {
                return ['ok' => false, 'errors' => ['restore_source_not_found'], 'state' => 'invalid'];
            }
            [$rows, $totals] = $this->restoreRows($snapshot);
            $todo = array_filter($rows, fn ($r) => $r['action'] === 'restore');
        } else {
            [$rows, $totals] = $this->build($campuses);
            $todo = $rows;
        }
        $digest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $state = $want === '' ? 'unpinned' : ($todo === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));

        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'mode' => $source === '' ? 'trim' : 'restore', 'restore_request_id' => $source, 'campus_ids' => $campuses,
            'digest' => $digest, 'totals' => $totals, 'manifest' => $rows];
    }

    public function execute(array $plan, array $context): array
    {
        $restore = ($plan['mode'] ?? 'trim') === 'restore';
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            // Nothing left to do (retry after the execution record failed to persist): the rows touched are in
            // schedule_audit_logs, not in this run's result, so no snapshot is guessed. Operator resolves.
            return ['ok' => true, 'already_applied' => true, 'deleted' => 0, 'restored' => 0, 'snapshot' => ['rows' => [], 'incomplete' => true]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') {
            throw new RuntimeException('shared_pool_trim_plan_not_pinned');
        }

        return DB::transaction(function () use ($plan, $context, $restore): array {
            if ($restore) {
                $snapshot = $this->sourceSnapshot((string) $plan['restore_request_id'], $plan['campus_ids']);
                $byId = array_column($snapshot ?? [], null, 'id');
                [$rows] = $this->restoreRows($snapshot ?? [], true);
                if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                    throw new RuntimeException('shared_pool_trim_digest_drift');
                }
                $ids = [];
                foreach ($rows as $r) {
                    if ($r['action'] === 'restore') {
                        // The model's creating guard re-checks course and student slot (fail closed); the observer audits it.
                        (new ClassSession())->forceFill(array_diff_key($byId[$r['session_id']], self::GENERATED))->save();
                        $ids[] = $r['session_id'];
                    }
                }
                $this->audit(['reason_code' => self::REF, 'operation_id' => (string) ($context['operation_id'] ?? ''),
                    'source_request_id' => (string) $plan['restore_request_id'], 'row_count' => count($ids), 'outcome' => 'success'], '.restore');

                return ['ok' => true, 'restored' => count($ids), 'snapshot' => ['restored_ids' => $ids]];
            }
            // Lock the reviewed lessons, then the pools' planned rows, before recomputing: a concurrent reschedule waits or is seen.
            ClassSession::query()->whereIn('id', array_column($plan['manifest'], 'session_id'))->lockForUpdate()->get();
            CoursePackage::query()->whereIn('id', array_unique(array_column($plan['manifest'], 'package_id')))->lockForUpdate()->get();
            [$rows] = $this->build($plan['campus_ids'], true);
            if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                throw new RuntimeException('shared_pool_trim_digest_drift');
            }
            $ids = array_column($rows, 'session_id');
            $snapshot = DB::table('ClassSession')->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            ClassSession::query()->whereIn('id', $ids)->get()->each->delete(); // ClassSessionObserver::deleted audits each row
            if (DB::table('ClassSession')->whereIn('id', $ids)->exists() || count($snapshot) !== count($ids)) {
                throw new RuntimeException('shared_pool_trim_delete_incomplete');
            }
            $this->audit(['reason_code' => self::REF, 'operation_id' => (string) ($context['operation_id'] ?? ''),
                'pool_count' => count(array_unique(array_column($rows, 'package_id'))), 'row_count' => count($ids), 'outcome' => 'success']);

            return ['ok' => true, 'deleted' => count($ids), 'snapshot' => ['rows' => $snapshot]];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($result['snapshot']['incomplete'] ?? false) === true) $errors[] = 'snapshot_incomplete_manual_resolution';
        if (($plan['campus_ids'] ?? []) === []) $errors[] = 'verify_plan_missing';
        if (($plan['mode'] ?? 'trim') === 'restore') {
            $ids = $result['snapshot']['restored_ids'] ?? [];
            if (DB::table('ClassSession')->whereIn('id', $ids)->count() !== count($ids)) $errors[] = 'restored_rows_missing';

            return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['restored']];
        }
        $ids = array_column($result['snapshot']['rows'] ?? [], 'id');
        if ($ids !== [] && DB::table('ClassSession')->whereIn('id', $ids)->exists()) $errors[] = 'deleted_rows_present';
        // Executed pools now plan no more than they have left (lessons that something points at stay and are only reported).
        $executed = array_flip(array_column($plan['manifest'] ?? [], 'package_id'));
        foreach ($this->build($plan['campus_ids'] ?? [])[0] as $r) {
            if (isset($executed[$r['package_id']])) $errors[] = "still_over_{$r['package_id']}";
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['deleted', 'no_executed_pool_over']];
    }

    /** In-request rollback (inside the approval window); later recovery is a restore request (see the class doc). */
    public function rollback(array $snapshot, array $context): array
    {
        if (($snapshot['incomplete'] ?? false) === true) {
            return ['ok' => false, 'partial' => true, 'restored' => 0, 'errors' => ['snapshot_incomplete_manual_resolution']];
        }
        if (isset($snapshot['restored_ids'])) {
            // Undoing a restore is a new trim dry-run: the restored rows are ordinary planned lessons again.
            return ['ok' => false, 'partial' => true, 'restored' => 0, 'errors' => ['restore_is_undone_by_a_new_trim']];
        }
        $rows = $snapshot['rows'] ?? null;
        if (!is_array($rows)) {
            throw new RuntimeException('shared_pool_trim_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($rows): array {
            $restored = 0;
            $skipped = [];
            foreach ($rows as $r) {
                if ($this->restoreAction($r, true) !== 'restore') {
                    $skipped[] = (int) $r['id'];
                    continue;
                }
                (new ClassSession())->forceFill(array_diff_key($r, self::GENERATED))->save();
                $restored++;
            }
            $this->audit(['reason_code' => self::REF, 'row_count' => $restored, 'skipped' => count($skipped), 'outcome' => 'success'], '.rollback');

            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'restored' => $restored, 'skipped_ids' => $skipped];
        }, 3);
    }

    /** The deleted rows of a succeeded trim request of this operation over the same campuses; null when there is none. */
    private function sourceSnapshot(string $requestId, array $campuses): ?array
    {
        $request = DB::table('pop_operation_requests')->where('id', $requestId)->first();
        $parameters = $request ? json_decode((string) $request->parameters, true) : null;
        if (!$request || (string) $request->operation_id !== self::OPERATION || !is_array($parameters)
            || ($parameters['restore_request_id'] ?? '') !== '' || $this->campusIds($parameters['campus_ids'] ?? null) !== $campuses) {
            return null;
        }
        $execute = DB::table('pop_execution_records')->where('operation_id', $requestId)->where('phase', 'execute')
            ->where('result', 'succeeded')->orderByDesc('attempt_no')->first();
        $snapshot = $execute ? json_decode((string) $execute->snapshot, true) : null;

        return is_array($snapshot['rows'] ?? null) && $snapshot['rows'] !== [] ? $snapshot['rows'] : null;
    }

    /** @return array{0: list<array<string,mixed>>, 1: array<string,int>} ids-only rows, each with the action restore would take */
    private function restoreRows(array $snapshot, bool $lock = false): array
    {
        $rows = [];
        foreach (collect($snapshot)->sortBy('id') as $r) {
            $rows[] = ['session_id' => (int) $r['id'], 'course_id' => (int) $r['StudentClassID'], 'date' => substr((string) $r['SessionDate'], 0, 10),
                'start' => substr((string) $r['StartTime'], 0, 5), 'action' => $this->restoreAction($r, $lock)];
        }
        $totals = ['rows' => count($rows)];
        foreach ($rows as $r) {
            $totals[$r['action']] = ($totals[$r['action']] ?? 0) + 1;
        }

        return [$rows, $totals];
    }

    /** restore, or why not: present (id back), course_slot (that course has a lesson then), student_slot, past, course_missing. */
    private function restoreAction(array $r, bool $lock): string
    {
        $date = substr((string) $r['SessionDate'], 0, 10);
        $course = StudentClass::query()->whereKey($r['StudentClassID'])->first();

        return match (true) {
            DB::table('ClassSession')->where('id', $r['id'])->when($lock, fn ($q) => $q->lockForUpdate())->exists() => 'present',
            $date <= now()->toDateString() => 'past',
            !$course => 'course_missing',
            DB::table('ClassSession')->where('StudentClassID', $r['StudentClassID'])->whereDate('SessionDate', $date)
                ->where('StartTime', $r['StartTime'])->exists() => 'course_slot',
            app(ClassSessionMaterializationService::class)->findStudentSlotConflict($course, $date, (string) $r['StartTime'],
                (string) $r['EndTime'], null, [], $lock) !== null => 'student_slot',
            default => 'restore',
        };
    }

    /** @return list<int> */
    private function campusIds(mixed $raw): array
    {
        $raw = is_string($raw) ? explode(',', $raw) : (is_array($raw) ? $raw : []);
        $ids = array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $raw);

        return in_array(0, $ids, true) || count($ids) !== count($raw) ? [] : array_values(array_unique($ids));
    }

    private function audit(array $metadata, string $suffix = ''): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append('pop.shared_pool_trim' . $suffix, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'class_session_batch',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('shared_pool_trim_audit_not_persisted');
        }
    }

    /**
     * Ids-only manifest (no names): one row per lesson to delete, plus per-pool counts. A pool belongs to the campus by its
     * campus_id or a member's student (as inapp-package-ledger-probe picks them).
     *
     * @param list<int> $campuses @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    private function build(array $campuses, bool $lock = false): array
    {
        $planning = app(SharedPackagePlanningService::class);
        $packages = $campuses === [] ? collect() : CoursePackage::query()->where('billing_mode', CoursePackage::BILLING_MODE_SESSION)
            ->where(fn ($q) => $q->whereIn('campus_id', $campuses)->orWhereIn('id', DB::table('StudentClass as sc')
                ->join('Student as s', 's.id', '=', 'sc.StudentID')->whereIn('s.CampusID', $campuses)->whereNotNull('sc.PackageID')->select('sc.PackageID')))
            ->orderBy('id')->get();
        $rows = [];
        $pools = [];
        foreach ($packages as $package) {
            $x = $planning->sharedPoolExcess($package, $lock);
            if ($x['rows']->isEmpty() && $x['blocked'] === 0) {
                continue;
            }
            $pools[] = ['package_id' => (int) $package->id, 'remaining' => $x['remaining'], 'planned' => $x['planned'],
                'remove' => $x['rows']->count(), 'blocked' => $x['blocked']];
            foreach ($x['rows']->sortBy('id') as $s) {
                $rows[] = ['package_id' => (int) $package->id, 'session_id' => (int) $s->id, 'course_id' => (int) $s->StudentClassID,
                    'date' => substr((string) $s->SessionDate, 0, 10), 'start' => substr((string) $s->StartTime, 0, 5)];
            }
        }

        return [$rows, ['pools' => count($pools), 'rows' => count($rows), 'blocked' => array_sum(array_column($pools, 'blocked')), 'by_pool' => $pools]];
    }
}
