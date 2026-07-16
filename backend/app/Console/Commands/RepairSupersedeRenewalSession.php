<?php

namespace App\Console\Commands;

use App\Models\SessionCorrection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * in-app #173 option B: supersede old-contract overlap session after renewal.
 *
 * Default: dry-run. Production writes require --execute --force + ALLOW_PROD_REPAIR=1.
 * Never DELETE rows; never rewrite created_at / creator / Invoice / Used / Remaining.
 * Never void or move LearningRecord — only link via session_corrections.
 */
class RepairSupersedeRenewalSession extends Command
{
    protected $signature = 'repair:supersede-renewal-session
                            {--case=173 : Case id (173 = approved B for in-app #173)}
                            {--dry-run : Preview only (default when --execute omitted)}
                            {--execute : Apply changes}
                            {--force : Required with --execute on production}
                            {--snapshot= : JSON snapshot path before writes}
                            {--actor= : Audit actor label (email / login / artisan)}
                            {--actor-user-id= : Optional User.id for decided_by_user_id}
                            {--rollback : Restore previous_status from open correction}';

    protected $description = 'Supersede renewal-overlap ClassSession with auditable session_corrections (#173 B)';

    private const CASE_173 = [
        'supersede_session_id' => 11292,
        'keep_session_id' => 16951,
        'old_student_class_id' => 114,
        'new_student_class_id' => 2076,
        'correction_reason' => 'duplicate_after_renewal',
        'decision_reference' => 'in-app #173',
        'note_tag' => 'superseded-by:16951 #173 duplicate_after_renewal',
    ];

    public function handle(): int
    {
        $case = (string) $this->option('case');
        if ($case !== '173') {
            $this->error('--case currently supports only 173');

            return self::FAILURE;
        }

        if (!Schema::hasTable('session_corrections')) {
            $this->error('session_corrections table missing — run migrations first');

            return self::FAILURE;
        }

        if ($this->option('rollback')) {
            return $this->runRollback();
        }

        $execute = (bool) $this->option('execute');
        $dryRun = !$execute;

        if ($execute && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }

        $plan = $this->buildPlan173();
        if ($plan['error'] !== null) {
            $this->error($plan['error']);

            return self::FAILURE;
        }

        $this->line($dryRun ? '=== DRY RUN repair:supersede-renewal-session ===' : '=== EXECUTE repair:supersede-renewal-session ===');
        $this->line('case=173 decision=B decision_reference=in-app #173');
        $this->renderPlan($plan);

        if ($dryRun) {
            return self::SUCCESS;
        }

        if (($plan['action']['type'] ?? '') === 'noop_already_applied') {
            $this->info('Already applied — no write');

            return self::SUCCESS;
        }

        $snapshotPath = $this->option('snapshot')
            ?: storage_path('app/repair-snapshots/173-supersede-' . now()->format('YmdHis') . '.json');
        $this->writeSnapshot($snapshotPath, $plan);
        $this->info("Snapshot: {$snapshotPath}");

        DB::transaction(function () use ($plan): void {
            $this->applySupersede($plan);
        });

        $this->info('Applied supersede for session ' . self::CASE_173['supersede_session_id']
            . ' → replaced_by ' . self::CASE_173['keep_session_id']);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlan173(): array
    {
        $cfg = self::CASE_173;
        $supersedeId = $cfg['supersede_session_id'];
        $keepId = $cfg['keep_session_id'];

        $supersede = DB::table('ClassSession')->where('id', $supersedeId)->first();
        $keep = DB::table('ClassSession')->where('id', $keepId)->first();
        if (!$supersede || !$keep) {
            return ['error' => "ClassSession {$supersedeId} or {$keepId} not found", 'action' => null];
        }

        if ((int) $supersede->StudentClassID !== $cfg['old_student_class_id']) {
            return ['error' => "Session {$supersedeId} SC mismatch (expected {$cfg['old_student_class_id']})", 'action' => null];
        }
        if ((int) $keep->StudentClassID !== $cfg['new_student_class_id']) {
            return ['error' => "Session {$keepId} SC mismatch (expected {$cfg['new_student_class_id']})", 'action' => null];
        }

        $dateS = substr((string) $supersede->SessionDate, 0, 10);
        $dateK = substr((string) $keep->SessionDate, 0, 10);
        $hmS = substr((string) $supersede->StartTime, 0, 5);
        $hmK = substr((string) $keep->StartTime, 0, 5);
        if ($dateS !== $dateK || $hmS !== $hmK) {
            return ['error' => "Slot mismatch: {$dateS} {$hmS} vs {$dateK} {$hmK}", 'action' => null];
        }

        $oldSc = DB::table('StudentClass')->where('ID', $cfg['old_student_class_id'])->first();
        $newSc = DB::table('StudentClass')->where('ID', $cfg['new_student_class_id'])->first();
        if (!$oldSc || !$newSc) {
            return ['error' => 'StudentClass rows missing', 'action' => null];
        }

        $preservedLr = DB::table('LearningRecord')
            ->where('ClassSessionID', $supersedeId)
            ->whereNull('VoidedAt')
            ->orderByDesc('id')
            ->first();
        $keeperLr = DB::table('LearningRecord')
            ->where('ClassSessionID', $keepId)
            ->whereNull('VoidedAt')
            ->orderByDesc('id')
            ->first();

        $signInsSupersede = DB::table('StudentSingIn')
            ->where('ClassSessionID', $supersedeId)
            ->get();
        $signInsKeep = DB::table('StudentSingIn')
            ->where('ClassSessionID', $keepId)
            ->get();

        $ledgerSupersede = Schema::hasTable('session_deduction_ledger')
            ? DB::table('session_deduction_ledger')->where('class_session_id', $supersedeId)->get()
            : collect();
        $ledgerKeep = Schema::hasTable('session_deduction_ledger')
            ? DB::table('session_deduction_ledger')->where('class_session_id', $keepId)->get()
            : collect();

        $invoices = $this->relatedInvoices([(int) $oldSc->ID, (int) $newSc->ID]);

        $openCorrection = SessionCorrection::query()
            ->where('session_id', $supersedeId)
            ->where('decision_reference', $cfg['decision_reference'])
            ->whereNull('rolled_back_at')
            ->orderByDesc('id')
            ->first();

        $status = strtolower((string) $supersede->Status);
        $already = $openCorrection
            && $status === 'cancelled'
            && (int) $openCorrection->replaced_by_session_id === $keepId;

        $unchanged = [
            'learning_records' => 'keep VoidedAt null; no move/overwrite (preserved LR stays on superseded session)',
            'student_class_counters' => 'UsedSessions / RemainingSessions / SessionCount / Charge / Paid untouched',
            'invoices_payments' => 'Invoice / Payment / reconciled_at untouched',
            'keeper_session' => "ClassSession {$keepId} status/note untouched",
            'created_at_creator' => 'no rewrite of created_at / historical creator fields',
            'deduction_ledger' => 'no reverseForSession / recomputeCounters (Remaining must not change)',
            'hard_delete' => 'no DELETE of ClassSession / LearningRecord / StudentSingIn',
        ];

        $willChange = $already ? [] : [
            'class_session' => [
                'id' => $supersedeId,
                'Status' => ['from' => (string) $supersede->Status, 'to' => 'cancelled'],
                'Note' => 'append tag only: ' . $cfg['note_tag'],
            ],
            'session_corrections' => [
                'session_id' => $supersedeId,
                'replaced_by_session_id' => $keepId,
                'correction_reason' => $cfg['correction_reason'],
                'decision_reference' => $cfg['decision_reference'],
                'preserved_learning_record_id' => $preservedLr ? (int) $preservedLr->id : null,
                'keeper_learning_record_id' => $keeperLr ? (int) $keeperLr->id : null,
            ],
        ];

        $action = $already
            ? ['type' => 'noop_already_applied', 'correction_id' => $openCorrection->id]
            : [
                'type' => 'supersede_session',
                'session_id' => $supersedeId,
                'replaced_by_session_id' => $keepId,
                'previous_status' => (string) $supersede->Status,
                'new_status' => 'cancelled',
                'correction_reason' => $cfg['correction_reason'],
                'decision_reference' => $cfg['decision_reference'],
                'preserved_learning_record_id' => $preservedLr ? (int) $preservedLr->id : null,
                'keeper_learning_record_id' => $keeperLr ? (int) $keeperLr->id : null,
                'note_tag' => $cfg['note_tag'],
            ];

        return [
            'error' => null,
            'action' => $action,
            'will_change' => $willChange,
            'will_not_change' => $unchanged,
            'snapshot' => [
                'supersede_session' => $supersede,
                'keep_session' => $keep,
                'old_student_class' => $oldSc,
                'new_student_class' => $newSc,
                'preserved_learning_record' => $preservedLr,
                'keeper_learning_record' => $keeperLr,
                'sign_ins_supersede' => $signInsSupersede,
                'sign_ins_keep' => $signInsKeep,
                'ledger_supersede' => $ledgerSupersede,
                'ledger_keep' => $ledgerKeep,
                'invoices' => $invoices,
                'open_correction' => $openCorrection,
            ],
        ];
    }

    /**
     * @param  list<int>  $studentClassIds
     * @return list<object>
     */
    private function relatedInvoices(array $studentClassIds): array
    {
        if (!Schema::hasTable('Invoice')) {
            return [];
        }

        $q = DB::table('Invoice')->whereIn('StudentClassID', $studentClassIds);
        $rows = $q->get();

        $out = [];
        foreach ($rows as $inv) {
            $payments = Schema::hasTable('Payment')
                ? DB::table('Payment')->where('InvoiceID', $inv->id ?? $inv->ID ?? 0)->get()
                : collect();
            $out[] = (object) [
                'invoice' => $inv,
                'payments' => $payments,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function renderPlan(array $plan): void
    {
        $action = $plan['action'];
        $prefix = ($this->option('execute') && ($action['type'] ?? '') !== 'noop_already_applied') ? 'DID' : 'WOULD';
        if (($action['type'] ?? '') === 'noop_already_applied') {
            $this->line('ALREADY APPLIED correction_id=' . $action['correction_id']);
        } else {
            $this->line(sprintf(
                '%s supersede ClassSession id=%d → Status=cancelled; replaced_by_session_id=%d; reason=%s; ref=%s',
                $prefix,
                $action['session_id'],
                $action['replaced_by_session_id'],
                $action['correction_reason'],
                $action['decision_reference']
            ));
            $this->line(sprintf(
                '  preserved_lr=%s keeper_lr=%s (LR not voided/moved)',
                $action['preserved_learning_record_id'] ?? 'null',
                $action['keeper_learning_record_id'] ?? 'null'
            ));
        }

        $this->line('--- WILL CHANGE ---');
        $this->line(json_encode($plan['will_change'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('--- WILL NOT CHANGE ---');
        foreach ($plan['will_not_change'] as $k => $v) {
            $this->line("  {$k}: {$v}");
        }

        $snap = $plan['snapshot'];
        $oldSc = $snap['old_student_class'];
        $newSc = $snap['new_student_class'];
        $this->line(sprintf(
            'COUNTERS (must stay): SC%d Used=%s Remaining=%s | SC%d Used=%s Remaining=%s',
            (int) $oldSc->ID,
            $oldSc->UsedSessions,
            $oldSc->RemainingSessions,
            (int) $newSc->ID,
            $newSc->UsedSessions,
            $newSc->RemainingSessions
        ));
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function applySupersede(array $plan): void
    {
        $action = $plan['action'];
        $cfg = self::CASE_173;
        $sessionId = (int) $action['session_id'];
        $row = DB::table('ClassSession')->where('id', $sessionId)->lockForUpdate()->first();
        if (!$row) {
            throw new \RuntimeException("ClassSession {$sessionId} not found at apply");
        }

        $note = trim((string) ($row->Note ?? ''));
        if ($note === '') {
            $note = $cfg['note_tag'];
        } elseif (strpos($note, '#173') === false) {
            $note = trim($note . ' ' . $cfg['note_tag']);
            if (strlen($note) > 255) {
                $note = substr($cfg['note_tag'], 0, 255);
            }
        }

        DB::table('ClassSession')->where('id', $sessionId)->update([
            'Status' => 'cancelled',
            'Note' => $note,
            'updated_at' => now(),
        ]);

        $actor = (string) ($this->option('actor') ?: 'artisan:repair:supersede-renewal-session');
        $actorUserId = $this->option('actor-user-id');
        $actorUserId = $actorUserId !== null && $actorUserId !== '' ? (int) $actorUserId : null;

        SessionCorrection::create([
            'session_id' => $sessionId,
            'replaced_by_session_id' => (int) $action['replaced_by_session_id'],
            'correction_reason' => $action['correction_reason'],
            'decision_reference' => $action['decision_reference'],
            'decided_at' => now(),
            'decided_by_user_id' => $actorUserId,
            'decided_by_actor' => $actor,
            'previous_status' => $action['previous_status'],
            'new_status' => 'cancelled',
            'preserved_learning_record_id' => $action['preserved_learning_record_id'],
            'keeper_learning_record_id' => $action['keeper_learning_record_id'],
            'snapshot_before' => [
                'class_session' => (array) $plan['snapshot']['supersede_session'],
                'student_class' => (array) $plan['snapshot']['old_student_class'],
                'learning_record' => $plan['snapshot']['preserved_learning_record']
                    ? (array) $plan['snapshot']['preserved_learning_record']
                    : null,
            ],
            'rolled_back_at' => null,
        ]);
    }

    private function runRollback(): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }

        $cfg = self::CASE_173;
        $correction = SessionCorrection::query()
            ->where('session_id', $cfg['supersede_session_id'])
            ->where('decision_reference', $cfg['decision_reference'])
            ->whereNull('rolled_back_at')
            ->orderByDesc('id')
            ->first();

        if (!$correction) {
            $this->error('No open session_corrections row to roll back for in-app #173');

            return self::FAILURE;
        }

        $this->line($execute ? '=== EXECUTE ROLLBACK ===' : '=== DRY RUN ROLLBACK ===');
        $this->line(sprintf(
            'WOULD restore ClassSession %d Status=%s; mark correction %d rolled_back_at',
            $correction->session_id,
            $correction->previous_status,
            $correction->id
        ));

        if (!$execute) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($correction): void {
            DB::table('ClassSession')->where('id', $correction->session_id)->update([
                'Status' => $correction->previous_status,
                'updated_at' => now(),
            ]);
            $correction->rolled_back_at = now();
            $correction->save();
        });

        $this->info('Rollback complete for correction id=' . $correction->id);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function writeSnapshot(string $path, array $plan): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $encode = static function ($value) {
            return json_decode(json_encode($value), true);
        };

        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'case' => '173',
            'decision' => 'B',
            'decision_reference' => self::CASE_173['decision_reference'],
            'action' => $plan['action'],
            'will_change' => $plan['will_change'],
            'will_not_change' => $plan['will_not_change'],
            'before' => $encode($plan['snapshot']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    private function assertProductionAllowed(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force')) {
            $this->error('Production requires --force');

            return false;
        }
        if (env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires ALLOW_PROD_REPAIR=1 in .env');

            return false;
        }

        return true;
    }
}
