<?php

namespace App\Console\Commands;

use App\Models\SessionCorrection;
use App\Services\SessionDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-off, auditable repair for in-app #234.  The duplicate old-contract
 * attendance is cancelled; the new-contract attendance remains authoritative.
 * This deliberately refuses issued invoices/payments: those need an accounting
 * adjustment, not an in-place contract charge rewrite.
 */
class RepairRenewalOverlap234 extends Command
{
    protected $signature = 'repair:renewal-overlap-234
                            {--dry-run}
                            {--execute}
                            {--force}
                            {--snapshot=}
                            {--actor=}
                            {--actor-user-id=}
                            {--rollback}';

    protected $description = 'Repair the verified #234 renewal overlap with immutable evidence and rollback';

    private const OLD_CLASS = 1050;
    private const NEW_CLASS = 3226;
    private const OLD_SESSION = 30430;
    private const NEW_SESSION = 30421;
    private const REF = 'in-app #234';

    public function handle(): int
    {
        if ($this->option('rollback')) return $this->rollback();
        $execute = (bool) $this->option('execute');
        if ($execute && !$this->prodOk()) return self::FAILURE;

        $plan = $this->plan();
        if ($plan['error'] !== null) {
            $this->error((string) $plan['error']);
            return self::FAILURE;
        }
        $this->line($execute ? '=== EXECUTE repair:renewal-overlap-234 ===' : '=== DRY RUN repair:renewal-overlap-234 ===');
        $this->line(json_encode($plan['summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!$execute || $plan['already']) return self::SUCCESS;

        $path = (string) ($this->option('snapshot') ?: storage_path('app/repair-snapshots/234-renewal-overlap-' . now()->format('YmdHis') . '.json'));
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, json_encode(['case' => 234, 'generated_at' => now()->toIso8601String(), 'before' => $plan['before'], 'will_change' => $plan['summary']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        $this->line("Snapshot: {$path}");

        DB::transaction(function () use ($plan): void {
            $old = DB::table('StudentClass')->where('ID', self::OLD_CLASS)->lockForUpdate()->first();
            $session = DB::table('ClassSession')->where('id', self::OLD_SESSION)->lockForUpdate()->first();
            if (!$old || !$session || (string) $session->Status !== 'attended') throw new RuntimeException('REPAIR_234_DRIFT');

            DB::table('ClassSession')->where('id', self::OLD_SESSION)->update([
                'Status' => 'cancelled',
                'Note' => trim((string) $session->Note . ' superseded-by:' . self::NEW_SESSION . ' ' . self::REF),
                'updated_at' => now(),
            ]);
            DB::table('LearningRecord')->where('ClassSessionID', self::OLD_SESSION)->whereNull('VoidedAt')->update([
                'VoidedAt' => now(), 'updated_at' => now(),
            ]);
            // The counter projection only includes canonical attendance sources.
            // Keep the reason in the immutable note, but use `retro_leave` for
            // the compensating ledger event so the reversal participates in
            // every counter/reconciliation projection.
            SessionDeductionService::reverseForSession(self::OLD_CLASS, self::OLD_SESSION, 'retro_leave', $this->actorId(), self::REF);
            SessionDeductionService::recomputeCounters(self::OLD_CLASS);
            $used = (int) DB::table('StudentClass')->where('ID', self::OLD_CLASS)->value('UsedSessions');
            DB::table('StudentClass')->where('ID', self::OLD_CLASS)->update(['Charge' => $used * 1100]);
            $correction = new SessionCorrection([
                'session_id' => self::OLD_SESSION,
                'replaced_by_session_id' => self::NEW_SESSION,
                'correction_reason' => 'duplicate_after_renewal',
                'decision_reference' => self::REF,
                'decided_at' => now(),
                'decided_by_user_id' => $this->actorId(),
                'decided_by_actor' => (string) ($this->option('actor') ?: 'repair:renewal-overlap-234'),
                'previous_status' => 'attended', 'new_status' => 'cancelled',
                'preserved_learning_record_id' => null,
                'keeper_learning_record_id' => DB::table('LearningRecord')->where('ClassSessionID', self::NEW_SESSION)->whereNull('VoidedAt')->value('id'),
                'snapshot_before' => $plan['before'],
            ]);
            $correction->save();
        });
        $this->info('Applied #234 repair; rerun --dry-run to verify.');
        return self::SUCCESS;
    }

    /** @return array{error:?string,already:bool,before:array<string,mixed>,summary:array<string,mixed>} */
    private function plan(): array
    {
        $old = DB::table('StudentClass')->where('ID', self::OLD_CLASS)->first();
        $new = DB::table('StudentClass')->where('ID', self::NEW_CLASS)->first();
        $oldSession = DB::table('ClassSession')->where('id', self::OLD_SESSION)->first();
        $newSession = DB::table('ClassSession')->where('id', self::NEW_SESSION)->first();
        $invoices = DB::table('Invoice')->where('StudentClassID', self::OLD_CLASS)->count();
        $payments = DB::table('Payment as p')->join('Invoice as i', 'i.id', '=', 'p.InvoiceID')->where('i.StudentClassID', self::OLD_CLASS)->count();
        $correction = SessionCorrection::query()->where('session_id', self::OLD_SESSION)->where('decision_reference', self::REF)->whereNull('rolled_back_at')->first();
        $before = compact('old', 'new', 'oldSession', 'newSession', 'invoices', 'payments');
        if ($correction) return ['error' => null, 'already' => true, 'before' => $before, 'summary' => ['already_applied' => true, 'correction_id' => $correction->id]];
        if (!$old || !$new || !$oldSession || !$newSession) return ['error' => 'REPAIR_234_MISSING_ROW', 'already' => false, 'before' => $before, 'summary' => []];
        if ((int) $oldSession->StudentClassID !== self::OLD_CLASS || (int) $newSession->StudentClassID !== self::NEW_CLASS || (string) $oldSession->Status !== 'attended' || (string) $newSession->Status !== 'attended') return ['error' => 'REPAIR_234_SESSION_DRIFT', 'already' => false, 'before' => $before, 'summary' => []];
        if (substr((string) $oldSession->SessionDate, 0, 10) !== '2026-08-08' || substr((string) $newSession->SessionDate, 0, 10) !== '2026-08-08' || substr((string) $oldSession->StartTime, 0, 5) !== '15:00' || substr((string) $newSession->StartTime, 0, 5) !== '15:00') return ['error' => 'REPAIR_234_SLOT_DRIFT', 'already' => false, 'before' => $before, 'summary' => []];
        if ((int) $old->Rate !== 1100 || (int) $old->Charge !== 8800 || (int) $old->UsedSessions !== 8 || (int) $old->RemainingSessions !== 0 || (int) $old->Paid !== 0 || $invoices !== 0 || $payments !== 0) return ['error' => 'REPAIR_234_FINANCIAL_GATE_BLOCKED', 'already' => false, 'before' => $before, 'summary' => []];
        return ['error' => null, 'already' => false, 'before' => $before, 'summary' => ['keeper_session' => self::NEW_SESSION, 'cancel_session' => self::OLD_SESSION, 'old_contract' => ['used' => '8 -> 7', 'remaining' => '0 -> 1', 'charge' => '8800 -> 7700'], 'billing' => 'no Invoice/Payment rows; no receipt mutation', 'learning_record' => 'void pending duplicate record', 'ledger' => 'append reverse event']];
    }

    private function rollback(): int
    {
        $corr = SessionCorrection::query()->where('session_id', self::OLD_SESSION)->where('decision_reference', self::REF)->whereNull('rolled_back_at')->latest('id')->first();
        if (!$corr) { $this->error('No active #234 correction to roll back'); return self::FAILURE; }
        if (!$this->prodOk()) return self::FAILURE;
        DB::transaction(function () use ($corr): void {
            DB::table('ClassSession')->where('id', self::OLD_SESSION)->update(['Status' => 'attended', 'updated_at' => now()]);
            DB::table('LearningRecord')->where('ClassSessionID', self::OLD_SESSION)->update(['VoidedAt' => null, 'updated_at' => now()]);
            DB::table('session_deduction_ledger')->insert(['student_class_id' => self::OLD_CLASS, 'class_session_id' => self::OLD_SESSION, 'event_type' => 'deduct', 'source' => 'renewal_overlap_rollback', 'note' => self::REF, 'created_by' => $this->actorId(), 'created_at' => now(), 'updated_at' => now()]);
            SessionDeductionService::recomputeCounters(self::OLD_CLASS);
            DB::table('StudentClass')->where('ID', self::OLD_CLASS)->update(['Charge' => 8800]);
            $corr->rolled_back_at = now(); $corr->save();
        });
        $this->info('Rolled back #234 repair.'); return self::SUCCESS;
    }

    private function actorId(): ?int { $id = (int) $this->option('actor-user-id'); return $id > 0 ? $id : null; }
    private function prodOk(): bool { if (!app()->environment('production')) return true; if (!$this->option('force') || env('ALLOW_PROD_REPAIR') !== '1') { $this->error('Production requires --force and ALLOW_PROD_REPAIR=1'); return false; } return true; }
}
