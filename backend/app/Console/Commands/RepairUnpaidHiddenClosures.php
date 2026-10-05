<?php

namespace App\Console\Commands;

use App\Models\SecurityAuditEvent;
use App\Models\SessionCorrection;
use App\Models\StudentClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bulk repair (2026-10-05, Founder chose "全部放進待對帳"): contracts closed as settled/completed while still
 * unpaid vanished from 帳務中心 and tuition alerts (renewMonthly / monthly pause wrote the reason with no
 * payment check; fixed in #3529). This flips only closed_reason -> 'settled_pending' so they re-enter the
 * reconciliation queue. Money (Invoice / Payment / Charge / Paid) is never touched.
 *
 * Default dry-run prints the candidate IDs and a digest. --execute requires --expect-digest from that
 * dry-run (pins the exact set) and, in production, --force + ALLOW_PROD_REPAIR=1. Ledger: one
 * session_corrections row (session_id 0 = contract-level key, REF below) whose snapshot_before holds every
 * old reason for --rollback.
 */
class RepairUnpaidHiddenClosures extends Command
{
    protected $signature = 'repair:unpaid-hidden-closures
                            {--execute}
                            {--verify}
                            {--rollback}
                            {--force}
                            {--expect-digest=}
                            {--actor=}';

    protected $description = 'Move unpaid contracts closed as settled/completed back to settled_pending (待對帳)';

    private const REF = 'repair-unpaid-hidden-closures';
    private const KEY_SESSION = 0;

    public function handle(): int
    {
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
            $this->line('REPAIR_STATE=ALREADY_APPLIED correction_id=' . $applied->id);
            foreach ($errors as $e) {
                $this->error('VERIFY: ' . $e);
            }

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($this->option('verify')) {
            $this->error('VERIFY: repair not applied (no open correction)');

            return self::FAILURE;
        }

        $rows = $this->candidates();
        $digest = self::digest($rows);
        $this->line($execute ? '=== EXECUTE ' . self::REF . ' ===' : '=== DRY RUN ' . self::REF . ' ===');
        foreach ($rows as $r) {
            $this->line(sprintf('course=%d student=%d campus=%s mode=%s reason=%s charge=%d invoice_paid=%d end=%s',
                $r['id'], $r['student_id'], $r['campus_id'] ?? '-', $r['mode'], $r['closed_reason'], $r['charge'], $r['invoice_paid'], $r['end_date']));
        }
        $this->line('CANDIDATES=' . count($rows) . ' OUTSTANDING_TOTAL=' . array_sum(array_map(fn ($r) => $r['charge'] - $r['invoice_paid'], $rows)));
        $this->line('DIGEST=' . $digest);
        if (!$execute) {
            $this->line('Dry-run complete; no data changed.');

            return self::SUCCESS;
        }
        if ($rows === [] || $this->option('expect-digest') !== $digest) {
            $this->line('REPAIR_STATE=NOT_APPLIED');
            $this->error('DRIFT: --expect-digest does not match the current candidate set');

            return self::FAILURE;
        }

        DB::transaction(function () use ($rows, $digest): void {
            $ids = array_column($rows, 'id');
            // Re-check under lock: the set must be unchanged since the dry-run.
            StudentClass::query()->whereIn('ID', $ids)->lockForUpdate()->get();
            if (self::digest($this->candidates()) !== $digest) {
                throw new \RuntimeException('candidate set changed under lock');
            }
            DB::table('StudentClass')->whereIn('ID', $ids)->update(['closed_reason' => 'settled_pending']);
            (new SessionCorrection([
                'session_id' => self::KEY_SESSION, 'replaced_by_session_id' => null,
                'correction_reason' => 'unpaid_hidden_closure_repair', 'decision_reference' => self::REF,
                'decided_at' => now(), 'decided_by_actor' => substr((string) ($this->option('actor') ?: 'cli'), 0, 128),
                'previous_status' => 'settled_or_completed', 'new_status' => 'settled_pending',
                'snapshot_before' => ['digest' => $digest, 'rows' => array_map(fn ($r) => ['id' => $r['id'], 'closed_reason' => $r['closed_reason']], $rows)],
            ]))->save();
            SecurityAuditEvent::append('repair.unpaid_hidden_closures', 'applied', [
                'actor_type' => 'system', 'subject_type' => 'student_class_batch',
            ], ['reason_code' => self::REF, 'outcome' => 'applied']);
        });
        $errors = $this->postErrors($this->openCorrection());
        foreach ($errors as $e) {
            $this->error('VERIFY: ' . $e);
        }
        $this->line($errors === [] ? 'REPAIR_STATE=APPLIED_AND_VERIFIED' : 'REPAIR_STATE=APPLIED_POSTVERIFY_FAILED');

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Closed as settled/completed, unpaid, with a positive charge not covered by non-void invoice payments.
     * Same rule as StudentClassController::courseNeedsPaymentReconciliation; tutoring is always free.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(): array
    {
        $courses = StudentClass::query()->with('student:id,CampusID')
            ->where('Stop', 1)->whereIn('closed_reason', ['settled', 'completed'])
            ->where(fn ($q) => $q->where('Paid', 0)->orWhereNull('Paid'))
            ->where('Charge', '>', 0)
            ->where(fn ($q) => $q->whereNull('class_type')->orWhere('class_type', '!=', 'tutoring'))
            ->orderBy('ID')->get();
        $paid = DB::table('Invoice')->whereIn('StudentClassID', $courses->pluck('ID')->all() ?: [0])
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
            ->groupBy('StudentClassID')->selectRaw('StudentClassID, SUM(PaidAmount) as paid')->pluck('paid', 'StudentClassID');
        $rows = [];
        foreach ($courses as $c) {
            $charge = (int) $c->Charge;
            $invoicePaid = (int) ($paid[$c->ID] ?? 0);
            if ($c->isEffectivelyPaid() || $c->isFullyPaidWithInvoiceAmount($invoicePaid, $charge)) {
                continue;
            }
            $rows[] = ['id' => (int) $c->ID, 'student_id' => (int) $c->StudentID, 'campus_id' => $c->student?->CampusID,
                'mode' => (string) $c->ScheduleMode, 'closed_reason' => (string) $c->closed_reason, 'charge' => $charge,
                'invoice_paid' => $invoicePaid, 'end_date' => substr((string) $c->EndDate, 0, 10)];
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function digest(array $rows): string
    {
        return substr(hash('sha256', implode(',', array_map(fn ($r) => $r['id'] . ':' . $r['closed_reason'], $rows))), 0, 16);
    }

    /** @return list<string> */
    private function postErrors(?SessionCorrection $corr): array
    {
        if (!$corr) {
            return ['no open correction'];
        }
        $want = collect($corr->snapshot_before['rows'] ?? [])->pluck('id')->all();
        $now = DB::table('StudentClass')->whereIn('ID', $want ?: [0])->pluck('closed_reason', 'ID');
        $bad = collect($want)->filter(fn ($id) => ($now[$id] ?? null) !== 'settled_pending')->values()->all();

        return $bad === [] ? [] : ['not settled_pending: ' . implode(',', $bad)];
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
        $rows = $corr->snapshot_before['rows'] ?? [];
        // Only restore rows still in the state this repair left them (a director may have since reconciled one).
        $ids = array_column($rows, 'id');
        $still = DB::table('StudentClass')->whereIn('ID', $ids ?: [0])->where('closed_reason', 'settled_pending')
            ->where(fn ($q) => $q->where('Paid', 0)->orWhereNull('Paid'))->pluck('ID')->map(fn ($id) => (int) $id)->all();
        $this->line(($execute ? '=== EXECUTE ROLLBACK ===' : '=== DRY RUN ROLLBACK ===') . ' restore=' . count($still) . ' skip=' . (count($ids) - count($still)));
        if (!$execute) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($rows, $still, $corr): void {
            foreach ($rows as $r) {
                if (in_array((int) $r['id'], $still, true)) {
                    DB::table('StudentClass')->where('ID', $r['id'])->update(['closed_reason' => $r['closed_reason']]);
                }
            }
            $corr->rolled_back_at = now();
            $corr->save();
            SecurityAuditEvent::append('repair.unpaid_hidden_closures', 'rolled_back', [
                'actor_type' => 'system', 'subject_type' => 'student_class_batch',
            ], ['reason_code' => self::REF, 'outcome' => 'rolled_back']);
        });
        $this->line('REPAIR_STATE=ROLLED_BACK');

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
