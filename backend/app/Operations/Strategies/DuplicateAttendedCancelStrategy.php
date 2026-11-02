<?php

namespace App\Operations\Strategies;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\SecurityAuditEvent;
use App\Services\CourseLeaveCascadeService;
use App\Services\PackageDeductionService;
use App\Services\SessionDeductionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * GitHub #4554 / #4753, Founder decision B (2026-11-02): cancel lessons counted twice and retire duplicate package records.
 * Hard-coded ids; guards, writes and restore are described in docs/incidents/RM-DUPLICATE-ATTENDED-CANCEL-20261102.md.
 */
final class DuplicateAttendedCancelStrategy
{
    private const REF = 'cancel-duplicate-attended-20261102';
    private const OPERATION = 'duplicate-attended-cancel-20261102';
    private const CLOSED_REASON = 'duplicate_record';
    private const ATTENDED = ['attended', 'completed', 'late'];
    /** [cancel session, its course, twin session, twin course] */
    private const CANCELS = [[41783, 2879, 43450, 4264], [41799, 3571, 43852, 4312], [42975, 3226, 43985, 4329]];
    /** [duplicate package, twin package] */
    private const CLOSES = [[189, 190], [200, 201]];

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
            if (count(array_unique(array_column($rows, 'class'))) > 1) { // some rows to restore, some moved on: never a partial undo
                return ['ok' => false, 'errors' => ['restore_incomplete_source_changed'], 'state' => 'invalid'];
            }
            $totals = ['rows' => count($rows), 'restore' => count(array_filter($rows, fn ($r) => $r['class'] === 'restore'))];
        } else {
            [$rows, $totals] = $this->build((int) $campus);
        }
        $digest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $todo = array_filter($rows, fn ($r) => in_array($r['class'], ['cancel', 'close', 'restore'], true));
        $state = $want === '' ? 'unpinned' : ($todo === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));
        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'mode' => $source === '' ? 'cancel' : 'restore', 'restore_request_id' => $source,
            'campus_id' => (int) $campus, 'digest' => $digest, 'totals' => $totals, 'manifest' => $rows];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            return ['ok' => true, 'already_applied' => true, 'snapshot' => ['rows' => [], 'packages' => [], 'incomplete' => true]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') {
            throw new RuntimeException('duplicate_attended_plan_not_pinned');
        }
        return DB::transaction(function () use ($plan, $context): array {
            ClassSession::resetSettlementLockCache(); // the model's settled-course guard must see the live courses
            $sessionIds = array_merge(...array_map(fn ($c) => [$c[0], $c[2]], self::CANCELS));
            $courseIds = array_merge(...array_map(fn ($c) => [$c[1], $c[3]], self::CANCELS));
            $packageIds = array_merge(...array_map(fn ($c) => $c, self::CLOSES));
            sort($sessionIds);
            sort($courseIds);
            sort($packageIds);
            if ($plan['mode'] === 'restore') {
                return $this->executeRestore($plan);
            }
            // Lock lessons, courses and packages before re-checking: a concurrent sign-in or edit waits or is seen.
            ClassSession::query()->whereIn('id', $sessionIds)->orderBy('id')->lockForUpdate()->get();
            DB::table('StudentClass')->whereIn('ID', $courseIds)->orderBy('ID')->lockForUpdate()->get();
            CoursePackage::query()->whereIn('id', $packageIds)->orderBy('id')->lockForUpdate()->get();
            [$rows] = $this->build($plan['campus_id']);
            if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                throw new RuntimeException('duplicate_attended_digest_drift');
            }
            $cancel = array_values(array_filter($rows, fn ($r) => $r['class'] === 'cancel'));
            $close = array_values(array_filter($rows, fn ($r) => $r['class'] === 'close'));
            $snap = ['rows' => [], 'packages' => []];
            foreach ($cancel as $r) {
                $snap['rows'][] = $this->cancelOne((int) $r['session_id'], (int) $r['class_id']);
            }
            foreach ($close as $r) {
                $pkg = CoursePackage::query()->whereKey($r['package_id'])->lockForUpdate()->firstOrFail();
                $snap['packages'][] = ['id' => (int) $pkg->id, 'stop' => (int) $pkg->stop, 'closed_reason' => $pkg->closed_reason];
                $pkg->stop = true;
                $pkg->closed_reason = self::CLOSED_REASON;
                $pkg->save();
            }
            if ($this->failures($snap) !== []) {
                throw new RuntimeException('duplicate_attended_incomplete'); // all or nothing: any miss rolls everything back
            }
            $this->audit(['reason_code' => self::REF, 'operation_id' => (string) ($context['operation_id'] ?? ''),
                'row_count' => count($cancel) + count($close), 'review_count' => count($rows) - count($cancel) - count($close), 'outcome' => 'success']);

            return ['ok' => true, 'cancelled' => count($cancel), 'closed' => count($close), 'snapshot' => $snap];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        if (($plan['mode'] ?? 'cancel') === 'restore') {
            $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
            foreach ($result['snapshot']['restored'] ?? [] as $r) {
                $now = (array) DB::table('ClassSession')->where('id', $r['id'])->first(['Status', 'Note']);
                if ((string) ($now['Status'] ?? '') !== (string) $r['Status'] || (string) ($now['Note'] ?? '') !== (string) $r['Note']) $errors[] = "not_restored_{$r['id']}";
                if (DB::table('session_deduction_ledger')->whereIn('id', $r['ledger_ids'])->exists()) $errors[] = "ledger_not_removed_{$r['id']}";
                if (DB::table('StudentSingIn')->whereIn('id', $r['signin_ids'])->whereNotNull('VoidedAt')->exists()) $errors[] = "signin_still_void_{$r['id']}";
            }
            foreach ($result['snapshot']['restored_packages'] ?? [] as $p) {
                $now = DB::table('course_packages')->where('id', $p['id'])->first(['stop', 'closed_reason']);
                if ((int) $now->stop !== (int) $p['stop'] || $now->closed_reason !== $p['closed_reason']) $errors[] = "package_not_restored_{$p['id']}";
            }

            return ['ok' => $errors === [], 'errors' => $errors, 'checks' => ['restored']];
        }
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($result['snapshot']['incomplete'] ?? false) === true) $errors[] = 'snapshot_incomplete_manual_resolution';
        $errors = array_merge($errors, $this->failures($result['snapshot'] ?? []));
        foreach ($this->build((int) ($plan['campus_id'] ?? 0))[0] as $r) {
            if (in_array($r['class'], ['cancel', 'close'], true)) $errors[] = 'still_actionable_' . ($r['session_id'] ?? 'p' . ($r['package_id'] ?? 0));
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['cancelled', 'ledger_net_zero', 'twin_unchanged', 'course_not_over', 'package_retired']];
    }

    /** Restore one run's snapshot: a skipped row means the world moved on, so the whole restore rolls back. */
    public function rollback(array $snapshot, array $context): array
    {
        if (!is_array($snapshot['rows'] ?? null) || ($snapshot['incomplete'] ?? false) === true) {
            throw new RuntimeException('duplicate_attended_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($snapshot): array {
            ClassSession::resetSettlementLockCache();
            $restored = 0;
            foreach ($snapshot['rows'] as $r) {
                $s = ClassSession::query()->whereKey($r['id'])->lockForUpdate()->first();
                if (!$s || strtolower((string) $s->Status) !== 'cancelled' || !$this->reversalsPresent($r)) {
                    throw new RuntimeException('duplicate_attended_restore_incomplete');
                }
                DB::table('session_deduction_ledger')->whereIn('id', $r['ledger_ids'])->where('class_session_id', $r['id'])->where('event_type', 'reverse')->delete();
                DB::table('package_session_ledger')->whereIn('id', $r['package_ledger_ids'])->where('class_session_id', $r['id'])->delete();
                DB::table('StudentSingIn')->whereIn('id', $r['signin_ids'])->update(['VoidedAt' => null, 'VoidedByUserID' => null, 'VoidReason' => null]);
                DB::table('LearningRecord')->whereIn('id', $r['learning_ids'])->update(['VoidedAt' => null, 'VoidedByUserID' => null, 'VoidReason' => null]);
                $s->Status = $r['Status'];
                $s->Note = $r['Note'];
                $s->setAllowStudentOverlap(); // the twin holds the same slot: that overlap is the original state
                $s->save();
                SessionDeductionService::recomputeCounters((int) $r['StudentClassID']); // counters follow the ledger as it is now, never the snapshot
                if (($r['package_id'] ?? null) !== null) PackageDeductionService::recomputeCounters((int) $r['package_id']);
                $restored++;
            }
            foreach ($snapshot['packages'] ?? [] as $p) {
                $pkg = CoursePackage::query()->whereKey($p['id'])->lockForUpdate()->first();
                if (!$pkg || !$pkg->stop || $pkg->closed_reason !== self::CLOSED_REASON) {
                    throw new RuntimeException('duplicate_attended_restore_incomplete');
                }
                $pkg->stop = (bool) $p['stop'];
                $pkg->closed_reason = $p['closed_reason'];
                $pkg->save();
                $restored++;
            }
            $this->audit(['reason_code' => self::REF, 'row_count' => $restored, 'outcome' => 'success'], '.rollback');

            return ['ok' => true, 'partial' => false, 'restored' => $restored, 'skipped_ids' => []];
        }, 3);
    }

    private function executeRestore(array $plan): array
    {
        $snapshot = $this->sourceSnapshot((string) $plan['restore_request_id'], (int) $plan['campus_id']) ?? [];
        ClassSession::query()->whereIn('id', array_column($snapshot['rows'], 'id'))->orderBy('id')->lockForUpdate()->get();
        if (hash('sha256', json_encode($this->restoreRows($snapshot), JSON_THROW_ON_ERROR)) !== $plan['digest']) {
            throw new RuntimeException('duplicate_attended_digest_drift');
        }
        $this->rollback($snapshot, []); // the digest pins every row as `restore`; rollback throws on any row that moved on

        return ['ok' => true, 'restored' => count($snapshot['rows']) + count($snapshot['packages']),
            'snapshot' => ['restored' => $snapshot['rows'], 'restored_packages' => $snapshot['packages']]];
    }

    /** Cancel one lesson the way the attended -> cancelled status change does; returns its restore record (ids only). */
    private function cancelOne(int $sessionId, int $courseId): array
    {
        $s = ClassSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
        $pkgId = (int) DB::table('StudentClass')->where('ID', $courseId)->value('PackageID');
        $ledgerBefore = DB::table('session_deduction_ledger')->where('class_session_id', $sessionId)->pluck('id')->all();
        $pkgLedgerBefore = DB::table('package_session_ledger')->where('class_session_id', $sessionId)->pluck('id')->all();
        $signins = DB::table('StudentSingIn')->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->pluck('id')->all();
        $learning = DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->pluck('id')->all();
        $row = ['id' => $sessionId, 'StudentClassID' => $courseId, 'Status' => (string) $s->Status, 'Note' => $s->Note, 'signin_ids' => $signins,
            'learning_ids' => $learning, 'package_id' => $pkgId > 0 ? $pkgId : null];

        CourseLeaveCascadeService::voidLiveArtifactsForNonAttendance($sessionId, CourseLeaveCascadeService::VOID_REASON_CANCELLED);
        SessionDeductionService::reverseForSession($courseId, $sessionId, 'status_adjust', null, 'duplicate_attended_cancel'); // + package ledger
        $s->Status = 'cancelled'; // ClassSessionObserver audits the row
        $s->Note = trim((string) $s->Note) === '' ? 'cancelled-after-attended' : (str_contains((string) $s->Note, 'cancelled-after-attended') ? $s->Note : $s->Note . '; cancelled-after-attended');
        $s->save();
        SessionDeductionService::recomputeCounters($courseId);

        $row['ledger_ids'] = array_values(array_diff(DB::table('session_deduction_ledger')->where('class_session_id', $sessionId)->pluck('id')->all(), $ledgerBefore));
        $row['package_ledger_ids'] = array_values(array_diff(DB::table('package_session_ledger')->where('class_session_id', $sessionId)->pluck('id')->all(), $pkgLedgerBefore));

        return $row;
    }

    /** Post-write checks on a run's snapshot (inside execute, so a miss rolls back; again at verify). @return list<string> */
    private function failures(array $snap): array
    {
        $errors = [];
        foreach ($snap['rows'] ?? [] as $r) {
            $id = $r['id'];
            if (strtolower((string) DB::table('ClassSession')->where('id', $id)->value('Status')) !== 'cancelled') $errors[] = "not_cancelled_{$id}";
            if ($this->net($id, (int) $r['StudentClassID']) !== 0) $errors[] = "ledger_net_not_zero_{$id}";
            if (DB::table('StudentSingIn')->where('ClassSessionID', $id)->whereNull('VoidedAt')->exists()) $errors[] = "active_signin_{$id}";
            $d = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$r['StudentClassID']])[$r['StudentClassID']] ?? null;
            if ($d === null || $d['uncapped_used'] > $d['session_count']) $errors[] = "course_still_over_{$r['StudentClassID']}";
        }
        foreach (self::CANCELS as [$sid, , $twin, $twinCourse]) {
            if (!in_array($sid, array_column($snap['rows'] ?? [], 'id'), true)) continue;
            $t = DB::table('ClassSession')->where('id', $twin)->first(['Status']);
            if (!$t || !in_array(strtolower((string) $t->Status), self::ATTENDED, true) || $this->net($twin, $twinCourse) <= 0) $errors[] = "twin_changed_{$twin}";
        }
        foreach ($snap['packages'] ?? [] as $p) {
            $now = DB::table('course_packages')->where('id', $p['id'])->first(['stop', 'closed_reason']);
            if (!$now || !(int) $now->stop || $now->closed_reason !== self::CLOSED_REASON) $errors[] = "package_not_retired_{$p['id']}";
        }

        return $errors;
    }

    private function net(int $sessionId, int $courseId): int
    {
        return (int) DB::table('session_deduction_ledger')->where('class_session_id', $sessionId)->where('student_class_id', $courseId)
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type = 'deduct' THEN 1 WHEN event_type = 'reverse' THEN -1 ELSE 0 END), 0) as n")->value('n');
    }

    /** The stored snapshot of a succeeded run of this operation for the same campus, or null. @return array{rows: list<array>, packages: list<array>}|null */
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
        $rows = $snapshot['rows'] ?? [];
        $packages = $snapshot['packages'] ?? [];

        return is_array($rows) && is_array($packages) && ($rows !== [] || $packages !== []) ? ['rows' => $rows, 'packages' => $packages] : null;
    }

    /** ids-only rows: `restore` while the lesson is still cancelled (package still retired by this run), else `skip`. @return list<array<string,mixed>> */
    private function restoreRows(array $snapshot): array
    {
        $out = [];
        foreach ($snapshot['rows'] as $r) {
            $now = strtolower((string) DB::table('ClassSession')->where('id', $r['id'])->value('Status'));
            $out[] = ['type' => 'session', 'id' => (int) $r['id'], 'class_id' => (int) $r['StudentClassID'], 'class' => $now === 'cancelled' && $this->reversalsPresent($r) ? 'restore' : 'skip'];
        }
        foreach ($snapshot['packages'] as $p) {
            $now = DB::table('course_packages')->where('id', $p['id'])->first(['stop', 'closed_reason']);
            $out[] = ['type' => 'package', 'id' => (int) $p['id'], 'package_id' => (int) $p['id'],
                'class' => $now && (int) $now->stop === 1 && $now->closed_reason === self::CLOSED_REASON ? 'restore' : 'skip'];
        }

        return $out;
    }

    /** This run's own reversal rows still exist: false once that run was restored (a later cancel writes new ids). */
    private function reversalsPresent(array $r): bool
    {
        return DB::table('session_deduction_ledger')->whereIn('id', $r['ledger_ids'])->where('class_session_id', $r['id'])->where('event_type', 'reverse')->count() === count($r['ledger_ids'])
            && DB::table('package_session_ledger')->whereIn('id', $r['package_ledger_ids'])->where('class_session_id', $r['id'])->count() === count($r['package_ledger_ids']);
    }

    private function audit(array $metadata, string $suffix = ''): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append('pop.duplicate_attended_cancel' . $suffix, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'class_session_batch',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('duplicate_attended_audit_not_persisted');
        }
    }

    /**
     * Ids-only rows, one per hard-coded cancel and close pair, each classified.
     *
     * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    private function build(int $campusId): array
    {
        $over = SessionDeductionService::batchExpectedUsedSessionDiagnostics(array_column(self::CANCELS, 1));
        $rows = [];
        foreach (self::CANCELS as [$sid, $cid, $tid, $tcid]) {
            $s = DB::table('ClassSession')->where('id', $sid)->where('StudentClassID', $cid)->first();
            $t = DB::table('ClassSession')->where('id', $tid)->where('StudentClassID', $tcid)->first();
            $c = DB::table('StudentClass as sc')->leftJoin('Student as st', 'st.id', '=', 'sc.StudentID')->where('sc.ID', $cid)
                ->first(['sc.StudentID', 'st.CampusID', 'sc.settlement_locked_at', 'sc.closed_reason']);
            $tc = DB::table('StudentClass')->where('ID', $tcid)->value('StudentID');
            $campus = (int) ($c->CampusID ?? 0);
            if ($campusId !== 0 && $campus !== $campusId) {
                continue;
            }
            $date = $s ? substr((string) $s->SessionDate, 0, 10) : '';
            $start = $s ? substr((string) $s->StartTime, 0, 5) : '';
            $reason = match (true) {
                !$s || !$c => 'session_missing',
                !$t || $tc === null => 'twin_missing',
                (int) $c->StudentID !== (int) $tc => 'student_mismatch',
                $date !== substr((string) $t->SessionDate, 0, 10) || $start !== substr((string) $t->StartTime, 0, 5) => 'slot_mismatch',
                !in_array(strtolower((string) $s->Status), self::ATTENDED, true) => 'not_attended',
                !in_array(strtolower((string) $t->Status), self::ATTENDED, true) => 'twin_not_attended',
                $this->net($sid, $cid) <= 0 => 'no_deduction',
                $this->net($tid, $tcid) <= 0 => 'twin_no_deduction',
                !isset($over[$cid]) || $over[$cid]['uncapped_used'] <= $over[$cid]['session_count'] => 'course_not_over',
                $c->settlement_locked_at !== null || in_array($c->closed_reason, ['usage_settled', 'contract_amended', 'waived'], true) => 'course_settled',
                str_contains((string) $s->Note, 'projected-monthly-materialized') => 'projected_monthly_session', // the app's cancel also edits its schedule row
                default => null,
            };
            $rows[] = ['type' => 'session', 'class_id' => $cid, 'campus_id' => $campus, 'session_id' => $sid, 'session_date' => $date, 'start_time' => $start,
                'class' => $reason === null ? 'cancel' : 'review', 'reason' => $reason ?? 'duplicate_attended_over_used'];
        }
        foreach (self::CLOSES as [$did, $tid]) {
            $d = DB::table('course_packages')->where('id', $did)->first();
            $t = DB::table('course_packages')->where('id', $tid)->first();
            $campus = (int) ($d->campus_id ?? 0);
            if ($campusId !== 0 && $campus !== $campusId) {
                continue;
            }
            $sum = fn (int $id): int => (int) DB::table('package_session_ledger')->where('package_id', $id)->sum('delta');
            $reason = match (true) {
                !$d => 'package_missing',
                !$t => 'twin_missing',
                (int) $d->student_id !== (int) $t->student_id => 'student_mismatch',
                DB::table('StudentClass')->where('PackageID', $did)->exists() => 'still_linked',
                !DB::table('StudentClass')->where('PackageID', $tid)->exists() => 'twin_unlinked',
                [(int) $d->total_sessions, (int) $d->remaining_sessions, (int) $d->used_sessions, $sum($did)]
                    !== [(int) $t->total_sessions, (int) $t->remaining_sessions, (int) $t->used_sessions, $sum($tid)] => 'twin_mismatch',
                (bool) $d->stop => 'already_stopped',
                default => null,
            };
            $rows[] = ['type' => 'package', 'package_id' => $did, 'campus_id' => $campus, 'class' => $reason === null ? 'close' : 'review',
                'reason' => $reason ?? 'duplicate_package_record'];
        }
        usort($rows, fn ($a, $b) => [$a['type'], $a['session_id'] ?? $a['package_id']] <=> [$b['type'], $b['session_id'] ?? $b['package_id']]);
        $totals = ['rows' => count($rows), 'cancel' => 0, 'close' => 0, 'review' => 0];
        foreach ($rows as $r) {
            $totals[$r['class']]++;
        }

        return [$rows, $totals];
    }
}
