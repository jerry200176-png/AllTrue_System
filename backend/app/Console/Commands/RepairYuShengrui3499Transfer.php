<?php

namespace App\Console\Commands;

use App\Http\Controllers\StudentClassController;
use App\Models\SecurityAuditEvent;
use App\Models\SessionCorrection;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One-case repair (student 2175, contract 3499): the contract was edited from 英文/Ruth蔣(146) Wed 13:00 to
 * 數學/李維(29) Wed 15:00 in place. Restore its true identity, then run the real paid 轉課 (cutover 2026-09-30)
 * so past sessions stay 英文 and 42098/33698-33700 move to a new 數學 contract with a linked transfer ledger.
 *
 * Default dry-run (real code in a rolled-back transaction). Production: --execute --force + ALLOW_PROD_REPAIR=1.
 * Payment 1750 / receipt / payment_report are never edited. Authoritative ledger: session_corrections
 * (REF), whose snapshot_before carries every touched old row for --rollback.
 */
class RepairYuShengrui3499Transfer extends Command
{
    protected $signature = 'repair:yu-shengrui-3499-transfer
                            {--execute}
                            {--verify}
                            {--rollback}
                            {--force}
                            {--actor=}';

    protected $description = 'Restore contract 3499 to 英文/Ruth and 轉課 9/30+ to a new 數學/李維 contract (one case)';

    private const REF = 'repair-yu-shengrui-3499';
    private const STUDENT = 2175;
    private const SC = 3499;
    private const KEY_SESSION = 42098;
    private const INVOICE = 1777;
    private const PAYMENT = 1750;
    private const REPORT = 1741;
    private const START = '2026-09-30';
    private const REASON = '余晟睿 9/30 起英文轉數學（Ruth→李維），一次性修正';
    private const SLOTS = [['weekday' => 3, 'time' => '15:00', 'duration_minutes' => 120]];
    /** Rows snapshotted for rollback: table => [pk, column pointing at the contract]. */
    private const SNAP = ['ClassSession' => ['id', 'StudentClassID'], 'LearningRecord' => ['id', 'StudentClassID'],
        'StudentSingIn' => ['id', 'StudentClassID'], 'schedules' => ['id', 'student_course_id'],
        'session_deduction_ledger' => ['id', 'student_class_id']];
    private const GENERATED = ['StartTimeHM', 'ActiveSlotFlag'];

    public function handle(): int
    {
        if (!Schema::hasTable('session_corrections')) {
            $this->error('session_corrections missing - migrate first');

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
            $errors = $this->postErrors((int) ($applied->snapshot_before['new_contract_id'] ?? 0));
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

        $errors = $this->preErrors();
        $this->line($execute ? '=== EXECUTE repair:yu-shengrui-3499-transfer ===' : '=== DRY RUN repair:yu-shengrui-3499-transfer ===');
        if ($errors !== []) {
            $this->line('REPAIR_STATE=NOT_APPLIED');
            foreach ($errors as $e) {
                $this->error('DRIFT: ' . $e);
            }

            return self::FAILURE;
        }
        $this->line('BEFORE ' . json_encode($this->summary(), JSON_UNESCAPED_UNICODE));
        $this->line('PLAN: (1) SC3499 -> SubjectID 65 / TeacherID 146 / Wed 13:00 (direct write, sessions untouched); '
            . '(2) paid 轉課 from ' . self::START . ' subject 66 teacher 29 Wed 15:00: 3499 -> 4 sessions/6000 (invoice 1777 6000/6000 + Payment -6000 transfer_out), '
            . 'new 數學 contract 4 sessions/6000 (42098,33698,33699,33700 moved) + new invoice 6000 paid + Payment +6000 transfer_in; Payment 1750 untouched.');
        try {
            DB::beginTransaction();
            $newId = $this->apply(!$execute);
            $this->line(($execute ? 'AFTER ' : 'AFTER (simulated; new ids are not kept) ') . json_encode($this->summary($newId), JSON_UNESCAPED_UNICODE));
            $execute ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->line('REPAIR_STATE=NOT_APPLIED');
            $this->error('Aborted, transaction rolled back: ' . $this->reason($e));

            return self::FAILURE;
        }
        if (!$execute) {
            $this->line('Dry-run complete; no data changed.');

            return self::SUCCESS;
        }
        $this->line('REPAIR_STATE=APPLIED_AND_VERIFIED');
        $this->info('Applied ' . self::REF . " new_contract_id={$newId}");

        return self::SUCCESS;
    }

    private function reason(\Throwable $e): string
    {
        return $e instanceof HttpResponseException ? $e->getResponse()->getContent() : $e->getMessage();
    }

    /** @return list<string> */
    private function preErrors(): array
    {
        $e = [];
        $eq = function (string $label, mixed $actual, mixed $expected) use (&$e): void {
            if ((string) $actual !== (string) $expected) {
                $e[] = "{$label}: expected {$expected} got {$actual}";
            }
        };
        $day = fn ($v) => substr((string) $v, 0, 10);
        $sc = DB::table('StudentClass')->where('ID', self::SC)->lockForUpdate()->first();
        if (!$sc) {
            return ['sc_missing'];
        }
        foreach (['StudentID' => self::STUDENT, 'SubjectID' => 66, 'TeacherID' => 29, 'Rate' => 1500, 'rate_unit' => 'session',
            'SessionCount' => 8, 'Charge' => 12000, 'Disconunt' => 0, 'Paid' => 1, 'ScheduleMode' => 'count', 'Stop' => 0,
            'week' => 3, 'SessionDuration' => 120, 'ClassType' => 'one_on_two'] as $col => $want) {
            $eq("sc.{$col}", $sc->{$col}, $want);
        }
        $eq('sc.PayDate', $day($sc->PayDate), '2026-09-09');
        $eq('sc.time', substr((string) $sc->time, 0, 5), '15:00');

        $inv = DB::table('Invoice')->where('StudentClassID', self::SC)->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))->get();
        $eq('invoices', $inv->pluck('id')->implode(','), self::INVOICE);
        $i = $inv->first();
        $eq('invoice.total/paid/status', $i ? "{$i->TotalAmount}/{$i->PaidAmount}/{$i->Status}" : '-', '12000/12000/paid');
        $pays = DB::table('Payment')->where('InvoiceID', self::INVOICE)->get();
        $eq('payments', $pays->map(fn ($p) => "{$p->id}:{$p->Amount}:{$p->Method}")->implode(','), self::PAYMENT . ':12000:cash');
        $rep = DB::table('payment_reports')->where('id', self::REPORT)->first();
        $eq('report', $rep ? "{$rep->StudentClassID}/{$rep->status}" : '-', self::SC . '/confirmed');

        $sessions = DB::table('ClassSession')->where('StudentClassID', self::SC)->get()->keyBy('id');
        $want = [33693 => ['2026-09-02', '13:00', 'attended'], 33694 => ['2026-09-09', '13:00', 'attended'],
            33695 => ['2026-09-16', '13:00', 'attended'], 33696 => ['2026-09-23', '13:00', 'late'],
            33697 => ['2026-09-30', '13:00', 'cancelled'], 33698 => ['2026-10-07', '15:00', 'scheduled'],
            33699 => ['2026-10-14', '15:00', 'scheduled'], 33700 => ['2026-10-21', '15:00', 'scheduled']];
        foreach ($want as $id => [$d, $t, $st]) {
            $s = $sessions[$id] ?? null;
            $eq("session.{$id}", $s ? $day($s->SessionDate) . ' ' . substr((string) $s->StartTime, 0, 5) . ' ' . $s->Status : '-', "{$d} {$t} {$st}");
        }
        $k = $sessions[self::KEY_SESSION] ?? null; // may have been marked attended/late since the probe
        $eq('session.42098', $k ? $day($k->SessionDate) . ' ' . substr((string) $k->StartTime, 0, 5) . ' ' . (in_array($k->Status, ['scheduled', 'attended', 'late'], true) ? 'ok' : $k->Status) : '-', '2026-09-30 15:00 ok');
        $eq('session_count_on_sc', $sessions->count(), 9);
        $pins = DB::table('schedules')->where('student_course_id', self::SC)->pluck('teacher_id', 'id');
        foreach ([12347 => 146, 12349 => 146, 12351 => 146, 12353 => 146, 12355 => 29] as $id => $t) {
            $eq("schedule.{$id}.teacher", $pins[$id] ?? '-', $t);
        }
        $eq('group_members', DB::table('course_contract_group_members')->where('student_class_id', self::SC)->count(), 0);

        return $e;
    }

    /** Compact before/after view: contract fields, invoices+payments, session -> contract map. */
    private function summary(?int $newId = null): array
    {
        $ids = array_filter([self::SC, $newId]);
        $sc = DB::table('StudentClass')->whereIn('ID', $ids)->get(['ID', 'SubjectID', 'TeacherID', 'week', 'time', 'SessionCount', 'Charge', 'Paid', 'Stop'])->map(fn ($r) => (array) $r)->all();
        $inv = DB::table('Invoice')->whereIn('StudentClassID', $ids)->get(['id', 'StudentClassID', 'TotalAmount', 'PaidAmount', 'Status']);
        $pay = DB::table('Payment')->whereIn('InvoiceID', $inv->pluck('id'))->get(['id', 'InvoiceID', 'Amount', 'Method']);

        return ['contracts' => $sc, 'invoices' => $inv->map(fn ($r) => (array) $r)->all(), 'payments' => $pay->map(fn ($r) => (array) $r)->all(),
            'sessions' => DB::table('ClassSession')->whereIn('StudentClassID', $ids)->orderBy('id')->pluck('StudentClassID', 'id')->all()];
    }

    /** Runs inside the caller's transaction; returns the new contract id. */
    private function apply(bool $preview): int
    {
        $errors = $this->preErrors();
        if ($errors !== []) {
            throw new RuntimeException('drift: ' . implode('; ', $errors));
        }
        $now = now();
        $actor = (string) ($this->option('actor') ?: 'artisan:' . self::REF);
        $snap = ['sc' => (array) DB::table('StudentClass')->where('ID', self::SC)->first(),
            'invoice' => (array) DB::table('Invoice')->where('id', self::INVOICE)->first(),
            'items' => DB::table('InvoiceItem')->where('InvoiceID', self::INVOICE)->get()->map(fn ($r) => (array) $r)->all()];
        foreach (self::SNAP as $table => [$pk, $fk]) {
            $snap[$table] = DB::table($table)->where($fk, self::SC)->get()->map(fn ($r) => (array) $r)->all();
        }

        // 1. restore the true original identity (direct write: no update() rebuild, sessions untouched)
        DB::table('StudentClass')->where('ID', self::SC)->update(['SubjectID' => 65, 'TeacherID' => 146, 'week' => 3, 'time' => '13:00:00']);

        // 2. the real paid 轉課 code (private controller method; preview=true skips its audit event)
        $source = \App\Models\StudentClass::query()->findOrFail(self::SC);
        $data = ['start_date' => self::START, 'subject_id' => 66, 'teacher_id' => 29, 'slots' => self::SLOTS, 'reason' => self::REASON];
        $result = (fn () => $this->applyTransfer($source, $data, $preview))->call(app(StudentClassController::class));
        $newId = (int) $result['new_course']['id'];
        // ponytail: applyTransfer leaves the new contract Paid=0 although its invoice is paid; set it here (no-op once fixed upstream)
        DB::table('StudentClass')->where('ID', $newId)->where('Paid', 0)->update(['Paid' => 1, 'Pay' => (int) $result['new_course']['charge'], 'PayDate' => '2026-09-09']);
        $errors = $this->postErrors($newId);
        if ($errors !== []) {
            throw new RuntimeException('post-verify failed: ' . implode('; ', $errors));
        }
        if ($preview) {
            return $newId;
        }

        $snap['new_contract_id'] = $newId;
        $corr = new SessionCorrection([
            'session_id' => self::KEY_SESSION, 'replaced_by_session_id' => null,
            'correction_reason' => 'subject_transfer_repair', 'decision_reference' => self::REF,
            'decided_at' => $now, 'decided_by_user_id' => null, 'decided_by_actor' => $actor,
            'previous_status' => (string) DB::table('ClassSession')->where('id', self::KEY_SESSION)->value('Status'),
            'new_status' => (string) DB::table('ClassSession')->where('id', self::KEY_SESSION)->value('Status'),
            'snapshot_before' => $snap,
        ]);
        $corr->save();
        SecurityAuditEvent::append('repair.yu_shengrui_3499_transfer', 'success', [
            'actor_type' => 'system', 'subject_type' => 'student_class', 'subject_id' => self::SC,
        ], ['new_contract_id' => $newId, 'reason_code' => self::REF, 'actor' => $actor, 'outcome' => 'success']);

        return $newId;
    }

    /** @return list<string> */
    private function postErrors(int $newId): array
    {
        $e = [];
        $eq = function (string $label, mixed $actual, mixed $expected) use (&$e): void {
            if ((string) $actual !== (string) $expected) {
                $e[] = "{$label}: expected {$expected} got {$actual}";
            }
        };
        $fields = fn (?object $c) => $c ? "{$c->SubjectID}/{$c->TeacherID}/{$c->week}/" . substr((string) $c->time, 0, 5) . "/{$c->SessionCount}/{$c->Charge}/{$c->Paid}" : '-';
        $eq('sc', $fields(DB::table('StudentClass')->where('ID', self::SC)->first()), '65/146/3/13:00/4/6000/1');
        $eq('new', $fields(DB::table('StudentClass')->where('ID', $newId)->first()), '66/29/3/15:00/4/6000/1');
        $inv = fn (int $sc) => DB::table('Invoice')->where('StudentClassID', $sc)->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))->get();
        $srcInv = $inv(self::SC);
        $eq('src_invoice', $srcInv->map(fn ($i) => "{$i->id}:{$i->TotalAmount}/{$i->PaidAmount}")->implode(','), self::INVOICE . ':6000/6000');
        $eq('src_payments', DB::table('Payment')->where('InvoiceID', self::INVOICE)->orderBy('id')->get()->map(fn ($p) => "{$p->Amount}:{$p->Method}")->implode(','), '12000:cash,-6000:transfer_out');
        $p = DB::table('Payment')->where('id', self::PAYMENT)->first();
        $eq('payment_1750', $p ? "{$p->Amount}:{$p->Method}" : '-', '12000:cash');
        $newInv = $inv($newId);
        $eq('new_invoice', $newInv->map(fn ($i) => "{$i->TotalAmount}/{$i->PaidAmount}/{$i->Status}")->implode(','), '6000/6000/paid');
        $eq('new_payments', DB::table('Payment')->whereIn('InvoiceID', $newInv->pluck('id'))->get()->map(fn ($p) => "{$p->Amount}:{$p->Method}")->implode(','), '6000:transfer_in');
        $where = fn (int $sc) => DB::table('ClassSession')->where('StudentClassID', $sc)->orderBy('id')->pluck('id')->implode(',');
        $eq('sc_sessions', $where(self::SC), '33693,33694,33695,33696,33697');
        $eq('new_sessions', $where($newId), '33698,33699,33700,42098');
        $eq('group_members', DB::table('course_contract_group_members')->whereIn('student_class_id', [self::SC, $newId])->count(), 2);
        $eq('report_status', DB::table('payment_reports')->where('id', self::REPORT)->value('status'), 'confirmed');

        return $e;
    }

    private function openCorrection(): ?SessionCorrection
    {
        return SessionCorrection::query()->where('session_id', self::KEY_SESSION)
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
        $newId = (int) $snap['new_contract_id'];
        $errors = $this->postErrors($newId);   // rollback only from the exact applied state
        $this->line($execute ? '=== EXECUTE ROLLBACK ===' : '=== DRY RUN ROLLBACK ===');
        foreach ($errors as $e) {
            $this->error('DRIFT: ' . $e);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }
        $this->line("WOULD restore SC3499/invoice 1777/session,LR,signin,schedule,ledger rows from correction {$corr->id}; delete new contract {$newId}, its invoice/items/payments/group; mark rolled_back_at");
        $this->line('AFTER-ROLLBACK EXPECTED: ' . json_encode(['sc' => array_intersect_key($snap['sc'], array_flip(['SubjectID', 'TeacherID', 'week', 'time', 'SessionCount', 'Charge']))], JSON_UNESCAPED_UNICODE));
        if (!$execute) {
            return self::SUCCESS;
        }
        try {
            DB::transaction(function () use ($corr, $snap, $newId): void {
                $restore = function (string $table, string $pk, array $rows): void {
                    foreach ($rows as $vals) {
                        $id = $vals[$pk];
                        unset($vals[$pk]);
                        foreach (self::GENERATED as $g) {
                            unset($vals[$g]);
                        }
                        DB::table($table)->where($pk, $id)->update($vals);
                    }
                };
                $restore('StudentClass', 'ID', [$snap['sc']]);
                $restore('Invoice', 'id', [$snap['invoice']]);
                $restore('InvoiceItem', 'id', $snap['items']);
                foreach (self::SNAP as $table => [$pk]) {
                    $restore($table, $pk, $snap[$table]);
                }
                $newInv = DB::table('Invoice')->where('StudentClassID', $newId)->pluck('id');
                DB::table('Payment')->whereIn('InvoiceID', $newInv)->delete();
                DB::table('Payment')->where('InvoiceID', self::INVOICE)->where('Method', 'transfer_out')->delete();
                DB::table('InvoiceItem')->whereIn('InvoiceID', $newInv)->delete();
                DB::table('Invoice')->whereIn('id', $newInv)->delete();
                $groups = DB::table('course_contract_group_members')->whereIn('student_class_id', [self::SC, $newId])->pluck('group_id')->unique();
                DB::table('course_contract_group_members')->whereIn('group_id', $groups)->delete();
                DB::table('course_contract_groups')->whereIn('id', $groups)->delete();
                DB::table('session_deduction_ledger')->where('student_class_id', $newId)->delete();
                DB::table('StudentClass')->where('ID', $newId)->delete();
                $corr->rolled_back_at = now();
                $corr->save();
                SecurityAuditEvent::append('repair.yu_shengrui_3499_transfer', 'rolled_back', [
                    'actor_type' => 'system', 'subject_type' => 'student_class', 'subject_id' => self::SC,
                ], ['reason_code' => self::REF, 'outcome' => 'rolled_back']);
            });
        } catch (\Throwable $e) {
            $this->error('Rollback aborted, transaction rolled back: ' . $e->getMessage());

            return self::FAILURE;
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
