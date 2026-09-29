<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\SecurityAuditEvent;
use App\Models\SessionCorrection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One-case repair (student 1452, campus 9): attended session 36285 (2026-09-19) was booked on the
 * renewal 3777 but belongs to the just-closed contract 1445 (contract_amended 7 -> 8 sessions).
 *
 * Default dry-run. Production: --execute --force + ALLOW_PROD_REPAIR=1. Raw query builder on purpose:
 * ClassSession::assertCourseIsMutable / isUsageSettlementLocked block writes on the closed 1445.
 * Money (Invoice / Payment / Charge / Paid) is never touched. Authoritative ledger: session_corrections
 * (decision_reference below), whose snapshot_before carries every old row for --rollback.
 */
class RepairYang1445ContractMove extends Command
{
    protected $signature = 'repair:yang-1445-contract-move
                            {--dry-run}
                            {--execute}
                            {--verify}
                            {--rollback}
                            {--force}
                            {--snapshot=}
                            {--actor=}';

    protected $description = 'Move attended session 36285 from renewal 3777 back to closed contract 1445 (one case)';

    private const REF = 'repair-yang-1445';
    private const STUDENT = 1452;
    private const CAMPUS = 9;
    private const OLD = 1445;      // closed contract (gets the session)
    private const NEW = 3777;      // renewal (gives it up, gets a tail)
    private const MOVED = 36285;   // attended 2026-09-19 13:00, on 3777 -> 1445
    private const GHOST = 42097;   // scheduled ghost tail on 1445 -> cancelled
    private const GHOST_NOTE = 'auto-extended-after-leave:ld=2026-07-11:ls=11747';
    private const REF_TAIL = 36292; // 3777 session whose slot/columns the new tail mirrors
    private const SIGNIN = 13108;
    private const LR = 20203;
    private const LEDGER = 15877;
    private const DATE = '2026-09-19';
    private const TAIL_DATE = '2026-11-21';
    private const TAIL_NOTE = 'repair-yang-1445 tail';
    private const GHOST_TAG = '; superseded-by-36285 repair';

    public function handle(): int
    {
        if (!Schema::hasTable('session_corrections') || !Schema::hasTable('class_session_reassignments')) {
            $this->error('session_corrections / class_session_reassignments missing - migrate first');

            return self::FAILURE;
        }
        $execute = (bool) $this->option('execute');
        if ($execute && !$this->prodOk()) {
            return self::FAILURE;
        }
        if ($this->option('rollback')) {
            return $this->rollback($execute);
        }

        $applied = $this->openCorrection();
        if ($applied) {
            $errors = $this->postErrors($applied);
            $this->line('REPAIR_STATE=ALREADY_APPLIED');
            $this->line('ALREADY APPLIED correction_id=' . $applied->id);
            foreach ($errors as $e) {
                $this->error('VERIFY: ' . $e);
            }

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($this->option('verify')) {
            $this->error('VERIFY: repair not applied (no open correction)');

            return self::FAILURE;
        }

        [$errors, $before] = $this->plan();
        $this->line($execute ? '=== EXECUTE repair:yang-1445-contract-move ===' : '=== DRY RUN repair:yang-1445-contract-move ===');
        if ($errors !== []) {
            $this->line('REPAIR_STATE=NOT_APPLIED');
            foreach ($errors as $e) {
                $this->error('DRIFT: ' . $e);
            }

            return self::FAILURE;
        }
        $this->printPlan($before);
        if (!$execute) {
            $this->line('Dry-run complete; no data changed.');

            return self::SUCCESS;
        }

        if ($path = (string) $this->option('snapshot')) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
            $this->info('Snapshot: ' . $path);
        }

        try {
            DB::transaction(fn () => $this->apply());
        } catch (\Throwable $e) {
            ClassSession::resetSettlementLockCache();
            $this->line('REPAIR_STATE=NOT_APPLIED');
            $this->error('Aborted, transaction rolled back: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            ClassSession::resetSettlementLockCache();
        }
        $this->line('REPAIR_STATE=APPLIED_AND_VERIFIED');
        $this->info('Applied ' . self::REF);

        return self::SUCCESS;
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private function plan(bool $lock = false): array
    {
        $e = [];
        $row = function (string $table, string $pk, int $id) use ($lock) {
            $q = DB::table($table)->where($pk, $id);

            return ($lock ? $q->lockForUpdate() : $q)->first();
        };
        $old = $row('StudentClass', 'ID', self::OLD);
        $new = $row('StudentClass', 'ID', self::NEW);
        $moved = $row('ClassSession', 'id', self::MOVED);
        $ghost = $row('ClassSession', 'id', self::GHOST);
        $refTail = $row('ClassSession', 'id', self::REF_TAIL);
        $signin = $row('StudentSingIn', 'id', self::SIGNIN);
        $lr = $row('LearningRecord', 'id', self::LR);
        $ledger = $row('session_deduction_ledger', 'id', self::LEDGER);
        $before = compact('old', 'new', 'moved', 'ghost', 'refTail', 'signin', 'lr', 'ledger');
        foreach ($before as $name => $r) {
            if (!$r) {
                $e[] = "{$name}_missing";
            }
        }
        if ($e !== []) {
            return [$e, $before];
        }
        $eq = function (string $label, mixed $actual, mixed $expected) use (&$e): void {
            if ((string) $actual !== (string) $expected) {
                $e[] = "{$label}: expected {$expected} got {$actual}";
            }
        };
        $day = fn ($v) => substr((string) $v, 0, 10);
        $hm = fn ($v) => substr((string) $v, 0, 5);

        $campus = DB::table('Student')->where('id', self::STUDENT)->value('CampusID');
        $eq('student_campus', $campus, self::CAMPUS);
        foreach ([[$old, 'old', 65, 146, self::OLD], [$new, 'new', 65, 146, self::NEW]] as [$sc, $n, $subj, $teacher, $id]) {
            $eq("{$n}.StudentID", $sc->StudentID, self::STUDENT);
            $eq("{$n}.SubjectID", $sc->SubjectID, $subj);
            $eq("{$n}.TeacherID", $sc->TeacherID, $teacher);
        }
        $eq('old.SessionCount', $old->SessionCount, 7);
        $eq('old.UsedSessions', $old->UsedSessions, 7);
        $eq('old.RemainingSessions', $old->RemainingSessions, 0);
        $eq('old.Stop', $old->Stop, 1);
        $eq('old.closed_reason', $old->closed_reason, 'contract_amended');
        $eq('old.EndDate', $day($old->EndDate), '2026-09-29');
        $eq('old.Charge', $old->Charge, 7200);
        if (($old->settlement_locked_at ?? null) !== null) {
            $e[] = 'old.settlement_locked_at not null';
        }
        $eq('new.SessionCount', $new->SessionCount, 8);
        $eq('new.UsedSessions', $new->UsedSessions, 1);
        $eq('new.RemainingSessions', $new->RemainingSessions, 7);
        $eq('new.Stop', $new->Stop, 0);
        $eq('new.StartDate', $day($new->StartDate), self::DATE);
        $eq('new.EndDate', $day($new->EndDate), '2026-11-14');
        $eq('new.Charge', $new->Charge, 8800);

        $eq('moved.StudentClassID', $moved->StudentClassID, self::NEW);
        $eq('moved.date', $day($moved->SessionDate), self::DATE);
        $eq('moved.start', $hm($moved->StartTime), '13:00');
        $eq('moved.Status', $moved->Status, 'attended');
        $eq('ghost.StudentClassID', $ghost->StudentClassID, self::OLD);
        $eq('ghost.date', $day($ghost->SessionDate), self::DATE);
        $eq('ghost.start', $hm($ghost->StartTime), '13:00');
        $eq('ghost.Status', $ghost->Status, 'scheduled');
        $eq('ghost.Note', $ghost->Note, self::GHOST_NOTE);
        $eq('refTail.StudentClassID', $refTail->StudentClassID, self::NEW);
        $eq('refTail.date', $day($refTail->SessionDate), '2026-11-07');
        $eq('refTail.start', $hm($refTail->StartTime), '13:00');
        $eq('refTail.end', $hm($refTail->EndTime), '15:00');
        $eq('signin.StudentClassID', $signin->StudentClassID, self::NEW);
        $eq('signin.ClassSessionID', $signin->ClassSessionID, self::MOVED);
        $eq('lr.StudentClassID', $lr->StudentClassID, self::NEW);
        $eq('lr.ClassSessionID', $lr->ClassSessionID, self::MOVED);
        $eq('ledger.student_class_id', $ledger->student_class_id, self::NEW);
        $eq('ledger.class_session_id', $ledger->class_session_id, self::MOVED);
        $eq('ledger.event_type', $ledger->event_type, 'deduct');

        $tailClash = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', self::STUDENT)->whereDate('cs.SessionDate', self::TAIL_DATE)
            ->whereRaw("LOWER(cs.Status) <> 'cancelled'")->count();
        $eq('tail_slot_clash_count', $tailClash, 0);
        // Other per-session records we do not move: must not exist (fail closed).
        foreach ([['session_coverages', 'class_session_id'], ['session_entitlement_transfers', 'class_session_id'],
            ['package_session_ledger', 'class_session_id'], ['payroll_run_lines', 'class_session_id'],
            ['class_session_reassignments', 'class_session_id']] as [$t, $c]) {
            if (Schema::hasTable($t) && DB::table($t)->whereIn($c, [self::MOVED, self::GHOST])->exists()) {
                $e[] = "{$t}_rows_reference_session";
            }
        }
        foreach ([self::MOVED => 'moved', self::GHOST => 'ghost'] as $id => $n) {
            $eq("{$n}.signin_count", DB::table('StudentSingIn')->where('ClassSessionID', $id)->count(), $n === 'moved' ? 1 : 0);
            $eq("{$n}.lr_count", DB::table('LearningRecord')->where('ClassSessionID', $id)->count(), $n === 'moved' ? 1 : 0);
            $eq("{$n}.ledger_count", DB::table('session_deduction_ledger')->where('class_session_id', $id)->count(), $n === 'moved' ? 1 : 0);
        }
        $before['counts'] = $this->counts();

        return [$e, $before];
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $n = fn (int $sc, ?array $in) => DB::table('ClassSession')->where('StudentClassID', $sc)
            ->when($in, fn ($q) => $q->whereIn(DB::raw('LOWER(Status)'), $in), fn ($q) => $q->whereRaw("LOWER(Status) <> 'cancelled'"))->count();

        return [
            'old_live' => $n(self::OLD, null), 'old_done' => $n(self::OLD, ['attended', 'completed']),
            'new_live' => $n(self::NEW, null), 'new_done' => $n(self::NEW, ['attended', 'completed']),
        ];
    }

    /** @param array<string,mixed> $b */
    private function printPlan(array $b): void
    {
        $this->line('--- WILL CHANGE ---');
        $this->line('SC1445: SessionCount 7->8 Used 7->8 Remaining 0 Stop 1 closed_reason contract_amended->settled EndDate ->2026-09-19; settlement_snapshot += correction (original kept)');
        $this->line('ClassSession 42097: scheduled -> cancelled, Note += "' . self::GHOST_TAG . '"');
        $this->line('ClassSession 36285 / StudentSingIn 13108 / LearningRecord 20203 / ledger 15877: StudentClassID 3777 -> 1445');
        $this->line('SC3777: Used 1->0 Remaining 7->8 EndDate ->2026-11-21; new tail ClassSession 2026-11-21 13:00-15:00 (mirrors 36292)');
        $this->line('audit: session_corrections(42097) with full snapshot_before, class_session_reassignments(36285), SecurityAuditEvent');
        $this->line('--- WILL NOT CHANGE --- Invoice/Payment/Charge/Paid, schedules, other sessions, 1445 Stop/settlement_locked_at');
        $this->line('COUNTS before: ' . json_encode($b['counts']));
    }

    private function apply(): void
    {
        [$errors, $b] = $this->plan(true);
        if ($errors !== []) {
            throw new RuntimeException('drift: ' . implode('; ', $errors));
        }
        $now = now();
        $actor = (string) ($this->option('actor') ?: 'artisan:' . self::REF);
        $upd = function (string $table, string $pk, int $id, array $vals) use ($now): void {
            if (Schema::hasColumn($table, 'updated_at')) {
                $vals['updated_at'] = $now;
            }
            if (DB::table($table)->where($pk, $id)->update($vals) !== 1) {
                throw new RuntimeException("{$table} {$id} update did not affect exactly one row");
            }
        };

        // 1. free the slot first (uq_class_session_slot), then move the real session in
        $ghostNote = substr(rtrim((string) $b['ghost']->Note) . self::GHOST_TAG, 0, 255);
        $upd('ClassSession', 'id', self::GHOST, ['Status' => 'cancelled', 'Note' => $ghostNote]);
        $upd('ClassSession', 'id', self::MOVED, ['StudentClassID' => self::OLD]);
        $upd('StudentSingIn', 'id', self::SIGNIN, ['StudentClassID' => self::OLD]);
        $upd('LearningRecord', 'id', self::LR, ['StudentClassID' => self::OLD]);
        $upd('session_deduction_ledger', 'id', self::LEDGER, ['student_class_id' => self::OLD]);
        DB::table('class_session_reassignments')->insert([
            'class_session_id' => self::MOVED, 'old_student_class_id' => self::NEW, 'new_student_class_id' => self::OLD,
            'reason' => self::REF . ': attended 2026-09-19 belongs to closed contract 1445 (7->8 sessions)',
            'performed_by' => null, 'created_at' => $now,
        ]);

        // 2. counters
        $orig = (string) ($b['old']->settlement_snapshot ?? '');
        $decoded = $orig === '' ? [] : json_decode($orig, true);
        if (!is_array($decoded)) {
            $decoded = ['original_raw' => $orig];
        }
        $decoded['repair_corrections'][] = [
            'ref' => self::REF, 'at' => $now->toIso8601String(), 'actor' => $actor,
            'before' => ['session_count' => 7, 'used_sessions' => 7, 'closed_reason' => 'contract_amended', 'end_date' => '2026-09-29'],
            'after' => ['session_count' => 8, 'used_sessions' => 8, 'closed_reason' => 'settled', 'end_date' => self::DATE],
            'moved_session_id' => self::MOVED, 'financial_mutation' => 'none',
        ];
        $keepTime = fn ($orig, $d) => $d . substr((string) $orig, 10);
        $upd('StudentClass', 'ID', self::OLD, [
            'SessionCount' => 8, 'UsedSessions' => 8, 'RemainingSessions' => 0, 'closed_reason' => 'settled',
            'EndDate' => $keepTime($b['old']->EndDate, self::DATE),
            'settlement_snapshot' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $upd('StudentClass', 'ID', self::NEW, [
            'UsedSessions' => 0, 'RemainingSessions' => 8, 'EndDate' => $keepTime($b['new']->EndDate, self::TAIL_DATE),
        ]);

        // 3. tail session on 3777, mirroring 36292
        $ref = $b['refTail'];
        $tail = [
            'StudentClassID' => self::NEW, 'SessionDate' => self::TAIL_DATE, 'StartTime' => $ref->StartTime,
            'EndTime' => $ref->EndTime, 'Status' => 'scheduled', 'Note' => self::TAIL_NOTE,
            'created_at' => $now, 'updated_at' => $now,
        ];
        foreach (['SubjectID', 'session_charge', 'IsContractException'] as $col) {
            if (property_exists($ref, $col)) {
                $tail[$col] = $ref->{$col};
            }
        }
        $tailId = (int) DB::table('ClassSession')->insertGetId($tail);

        // 4. audit ledger (session_corrections authoritative; snapshot_before feeds --rollback)
        $rows = [];
        foreach (['old', 'new', 'moved', 'ghost', 'signin', 'lr', 'ledger'] as $k) {
            $rows[$k] = (array) $b[$k];
        }
        $corr = SessionCorrection::query()->create([
            'session_id' => self::GHOST, 'replaced_by_session_id' => self::MOVED,
            'correction_reason' => 'superseded_by_moved_session', 'decision_reference' => self::REF,
            'decided_at' => $now, 'decided_by_user_id' => null, 'decided_by_actor' => $actor,
            'previous_status' => 'scheduled', 'new_status' => 'cancelled',
            'preserved_learning_record_id' => self::LR, 'keeper_learning_record_id' => self::LR,
            'snapshot_before' => ['rows' => $rows, 'counts' => $b['counts'], 'created_tail_session_id' => $tailId],
        ]);
        SecurityAuditEvent::append('repair.yang_1445_contract_move', 'success', [
            'actor_type' => 'system', 'subject_type' => 'student_class', 'subject_id' => self::OLD, 'campus_id' => self::CAMPUS,
        ], [
            'old_session_count' => 7, 'new_session_count' => 8, 'old_remaining_sessions' => 0, 'new_remaining_sessions' => 0,
            'reason_code' => self::REF, 'outcome' => 'success',
        ]);

        $errors = $this->postErrors($corr);
        if ($errors !== []) {
            throw new RuntimeException('post-verify failed: ' . implode('; ', $errors));
        }
    }

    /** @return list<string> */
    private function postErrors(SessionCorrection $corr): array
    {
        $snap = $corr->snapshot_before;
        $tailId = (int) ($snap['created_tail_session_id'] ?? 0);
        $c0 = $snap['counts'] ?? [];
        $e = [];
        $eq = function (string $label, mixed $actual, mixed $expected) use (&$e): void {
            if ((string) $actual !== (string) $expected) {
                $e[] = "{$label}: expected {$expected} got {$actual}";
            }
        };
        $day = fn ($v) => substr((string) $v, 0, 10);
        $old = DB::table('StudentClass')->where('ID', self::OLD)->first();
        $new = DB::table('StudentClass')->where('ID', self::NEW)->first();
        $eq('old.SessionCount', $old->SessionCount, 8);
        $eq('old.UsedSessions', $old->UsedSessions, 8);
        $eq('old.RemainingSessions', $old->RemainingSessions, 0);
        $eq('old.Stop', $old->Stop, 1);
        $eq('old.closed_reason', $old->closed_reason, 'settled');
        $eq('old.EndDate', $day($old->EndDate), self::DATE);
        $eq('old.Charge', $old->Charge, 7200);
        $eq('old.snapshot_keeps_original', str_contains((string) $old->settlement_snapshot, '"kind":"contract_amended"') ? 1 : 0, 1);
        $eq('old.snapshot_has_correction', str_contains((string) $old->settlement_snapshot, self::REF) ? 1 : 0, 1);
        $eq('new.UsedSessions', $new->UsedSessions, 0);
        $eq('new.RemainingSessions', $new->RemainingSessions, 8);
        $eq('new.SessionCount', $new->SessionCount, 8);
        $eq('new.Stop', $new->Stop, 0);
        $eq('new.EndDate', $day($new->EndDate), self::TAIL_DATE);
        $eq('new.Charge', $new->Charge, 8800);
        $eq('moved.StudentClassID', DB::table('ClassSession')->where('id', self::MOVED)->value('StudentClassID'), self::OLD);
        $eq('moved.Status', DB::table('ClassSession')->where('id', self::MOVED)->value('Status'), 'attended');
        $eq('signin.StudentClassID', DB::table('StudentSingIn')->where('id', self::SIGNIN)->value('StudentClassID'), self::OLD);
        $eq('lr.StudentClassID', DB::table('LearningRecord')->where('id', self::LR)->value('StudentClassID'), self::OLD);
        $eq('ledger.student_class_id', DB::table('session_deduction_ledger')->where('id', self::LEDGER)->value('student_class_id'), self::OLD);
        $ghost = DB::table('ClassSession')->where('id', self::GHOST)->first();
        $eq('ghost.Status', $ghost->Status, 'cancelled');
        $eq('ghost.Note_tag', str_ends_with((string) $ghost->Note, self::GHOST_TAG) ? 1 : 0, 1);
        $tail = DB::table('ClassSession')->where('id', $tailId)->first();
        $eq('tail_exists', $tail ? 1 : 0, 1);
        if ($tail) {
            $eq('tail.StudentClassID', $tail->StudentClassID, self::NEW);
            $eq('tail.date', $day($tail->SessionDate), self::TAIL_DATE);
            $eq('tail.start', substr((string) $tail->StartTime, 0, 5), '13:00');
            $eq('tail.Status', $tail->Status, 'scheduled');
        }
        $c1 = $this->counts();
        $eq('old_done', $c1['old_done'], ($c0['old_done'] ?? -99) + 1);
        $eq('old_live', $c1['old_live'], $c0['old_live'] ?? -99);
        $eq('new_done', $c1['new_done'], ($c0['new_done'] ?? -99) - 1);
        $eq('new_live', $c1['new_live'], $c0['new_live'] ?? -99);
        $eq('reassignment_rows', DB::table('class_session_reassignments')->where('class_session_id', self::MOVED)->count(), 1);

        return $e;
    }

    private function openCorrection(): ?SessionCorrection
    {
        return SessionCorrection::query()->where('session_id', self::GHOST)
            ->where('decision_reference', self::REF)->whereNull('rolled_back_at')->orderByDesc('id')->first();
    }

    private function rollback(bool $execute): int
    {
        $corr = $this->openCorrection();
        if (!$corr) {
            $this->error('No open ' . self::REF . ' correction');

            return self::FAILURE;
        }
        $snap = $corr->snapshot_before;
        $tailId = (int) ($snap['created_tail_session_id'] ?? 0);
        $errors = $this->postErrors($corr);   // rollback only from the exact applied state
        $tail = DB::table('ClassSession')->where('id', $tailId)->first();
        if ($tail && (DB::table('StudentSingIn')->where('ClassSessionID', $tailId)->exists()
            || DB::table('LearningRecord')->where('ClassSessionID', $tailId)->exists()
            || DB::table('session_deduction_ledger')->where('class_session_id', $tailId)->exists())) {
            $errors[] = 'tail session has dependent rows';
        }
        $this->line($execute ? '=== EXECUTE ROLLBACK ===' : '=== DRY RUN ROLLBACK ===');
        foreach ($errors as $e) {
            $this->error('DRIFT: ' . $e);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }
        $this->line("WOULD restore old rows from correction {$corr->id}, delete tail session {$tailId}, mark rolled_back_at");
        if (!$execute) {
            return self::SUCCESS;
        }
        try {
            DB::transaction(function () use ($corr, $snap, $tailId): void {
                $pk = ['old' => ['StudentClass', 'ID'], 'new' => ['StudentClass', 'ID'], 'moved' => ['ClassSession', 'id'],
                    'ghost' => ['ClassSession', 'id'], 'signin' => ['StudentSingIn', 'id'], 'lr' => ['LearningRecord', 'id'],
                    'ledger' => ['session_deduction_ledger', 'id']];
                // free the ghost's slot state last: moved leaves 1445 first
                foreach (['moved', 'signin', 'lr', 'ledger', 'ghost', 'old', 'new'] as $k) {
                    [$t, $c] = $pk[$k];
                    $vals = $snap['rows'][$k];
                    $id = $vals[$c];
                    unset($vals[$c]);
                    DB::table($t)->where($c, $id)->update($vals);
                }
                DB::table('ClassSession')->where('id', $tailId)->delete();
                DB::table('class_session_reassignments')->insert([
                    'class_session_id' => self::MOVED, 'old_student_class_id' => self::OLD, 'new_student_class_id' => self::NEW,
                    'reason' => self::REF . ' rollback', 'performed_by' => null, 'created_at' => now(),
                ]);
                $corr->rolled_back_at = now();
                $corr->save();
                SecurityAuditEvent::append('repair.yang_1445_contract_move', 'rolled_back', [
                    'actor_type' => 'system', 'subject_type' => 'student_class', 'subject_id' => self::OLD, 'campus_id' => self::CAMPUS,
                ], ['reason_code' => self::REF, 'outcome' => 'rolled_back']);
            });
        } finally {
            ClassSession::resetSettlementLockCache();
        }
        $this->line('REPAIR_STATE=ROLLED_BACK');
        $this->info('Rollback ok correction_id=' . $corr->id);

        return self::SUCCESS;
    }

    private function prodOk(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force') || env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires --force and ALLOW_PROD_REPAIR=1');

            return false;
        }

        return true;
    }
}
