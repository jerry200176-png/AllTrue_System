<?php

namespace App\Console\Commands;

use App\Models\SessionCorrection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * in-app #173 B: supersede old-contract overlap after renewal.
 * Default dry-run. Production: --execute --force + ALLOW_PROD_REPAIR=1.
 * No DELETE; no LR void/move; no Used/Remaining/Invoice writes.
 */
class RepairSupersedeRenewalSession extends Command
{
    protected $signature = 'repair:supersede-renewal-session
                            {--case=173}
                            {--dry-run}
                            {--execute}
                            {--force}
                            {--snapshot=}
                            {--actor=}
                            {--actor-user-id=}
                            {--rollback}';

    protected $description = 'Supersede renewal-overlap ClassSession via session_corrections (#173 B)';

    private const C173 = [
        'sid' => 11292,
        'kid' => 16951,
        'old_sc' => 114,
        'new_sc' => 2076,
        'reason' => 'duplicate_after_renewal',
        'ref' => 'in-app #173',
        'tag' => 'superseded-by:16951 #173 duplicate_after_renewal',
    ];

    public function handle(): int
    {
        if ((string) $this->option('case') !== '173') {
            $this->error('--case supports only 173');

            return self::FAILURE;
        }
        if (!Schema::hasTable('session_corrections')) {
            $this->error('session_corrections missing — migrate first');

            return self::FAILURE;
        }
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $execute = (bool) $this->option('execute');
        if ($execute && !$this->prodOk()) {
            return self::FAILURE;
        }

        $plan = $this->plan173();
        if ($plan['error']) {
            $this->error($plan['error']);

            return self::FAILURE;
        }

        $this->line($execute ? '=== EXECUTE repair:supersede-renewal-session ===' : '=== DRY RUN repair:supersede-renewal-session ===');
        $this->printPlan($plan, $execute);

        if (!$execute || ($plan['action']['type'] ?? '') === 'noop') {
            if (($plan['action']['type'] ?? '') === 'noop') {
                $this->info('Already applied — no write');
            }

            return self::SUCCESS;
        }

        $path = $this->option('snapshot')
            ?: storage_path('app/repair-snapshots/173-supersede-' . now()->format('YmdHis') . '.json');
        $this->dumpSnapshot($path, $plan);
        $this->info("Snapshot: {$path}");

        DB::transaction(fn () => $this->apply($plan));
        $this->info('Applied supersede ' . self::C173['sid'] . ' → ' . self::C173['kid']);

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function plan173(): array
    {
        $c = self::C173;
        $s = DB::table('ClassSession')->where('id', $c['sid'])->first();
        $k = DB::table('ClassSession')->where('id', $c['kid'])->first();
        if (!$s || !$k) {
            return ['error' => "session {$c['sid']}/{$c['kid']} missing", 'action' => null];
        }
        if ((int) $s->StudentClassID !== $c['old_sc'] || (int) $k->StudentClassID !== $c['new_sc']) {
            return ['error' => 'StudentClassID mismatch', 'action' => null];
        }
        $ds = substr((string) $s->SessionDate, 0, 10);
        $dk = substr((string) $k->SessionDate, 0, 10);
        $hs = substr((string) $s->StartTime, 0, 5);
        $hk = substr((string) $k->StartTime, 0, 5);
        if ($ds !== $dk || $hs !== $hk) {
            return ['error' => "slot mismatch {$ds} {$hs} vs {$dk} {$hk}", 'action' => null];
        }

        $oldSc = DB::table('StudentClass')->where('ID', $c['old_sc'])->first();
        $newSc = DB::table('StudentClass')->where('ID', $c['new_sc'])->first();
        if (!$oldSc || !$newSc) {
            return ['error' => 'StudentClass missing', 'action' => null];
        }

        $plr = DB::table('LearningRecord')->where('ClassSessionID', $c['sid'])->whereNull('VoidedAt')->orderByDesc('id')->first();
        $klr = DB::table('LearningRecord')->where('ClassSessionID', $c['kid'])->whereNull('VoidedAt')->orderByDesc('id')->first();

        $open = SessionCorrection::query()
            ->where('session_id', $c['sid'])
            ->where('decision_reference', $c['ref'])
            ->whereNull('rolled_back_at')
            ->orderByDesc('id')
            ->first();

        $done = $open
            && strtolower((string) $s->Status) === 'cancelled'
            && (int) $open->replaced_by_session_id === $c['kid'];

        $unchanged = [
            'learning_records' => 'no void/move; preserved LR stays on superseded session',
            'counters' => 'UsedSessions/RemainingSessions/Charge/Paid untouched',
            'billing' => 'Invoice/Payment/reconciled untouched',
            'keeper' => "session {$c['kid']} untouched",
            'ledger' => 'no reverseForSession/recomputeCounters',
            'delete' => 'no hard DELETE',
        ];

        $action = $done
            ? ['type' => 'noop', 'correction_id' => $open->id]
            : [
                'type' => 'supersede',
                'session_id' => $c['sid'],
                'replaced_by_session_id' => $c['kid'],
                'previous_status' => (string) $s->Status,
                'new_status' => 'cancelled',
                'correction_reason' => $c['reason'],
                'decision_reference' => $c['ref'],
                'preserved_learning_record_id' => $plr ? (int) $plr->id : null,
                'keeper_learning_record_id' => $klr ? (int) $klr->id : null,
            ];

        $will = $done ? [] : [
            'ClassSession' => ['id' => $c['sid'], 'Status' => [(string) $s->Status, 'cancelled'], 'Note' => 'append ' . $c['tag']],
            'session_corrections' => $action,
        ];

        return [
            'error' => null,
            'action' => $action,
            'will_change' => $will,
            'will_not_change' => $unchanged,
            'snapshot' => compact('s', 'k', 'oldSc', 'newSc', 'plr', 'klr', 'open'),
        ];
    }

    /** @param array<string,mixed> $plan */
    private function printPlan(array $plan, bool $execute): void
    {
        $a = $plan['action'];
        if (($a['type'] ?? '') === 'noop') {
            $this->line('ALREADY APPLIED correction_id=' . $a['correction_id']);
        } else {
            $this->line(sprintf(
                '%s supersede ClassSession id=%d → cancelled; replaced_by_session_id=%d; reason=%s; ref=%s',
                $execute ? 'DID' : 'WOULD',
                $a['session_id'],
                $a['replaced_by_session_id'],
                $a['correction_reason'],
                $a['decision_reference']
            ));
            $this->line(sprintf(
                '  preserved_lr=%s keeper_lr=%s',
                $a['preserved_learning_record_id'] ?? 'null',
                $a['keeper_learning_record_id'] ?? 'null'
            ));
        }
        $this->line('--- WILL CHANGE ---');
        $this->line(json_encode($plan['will_change'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('--- WILL NOT CHANGE ---');
        foreach ($plan['will_not_change'] as $k => $v) {
            $this->line("  {$k}: {$v}");
        }
        $o = $plan['snapshot']['oldSc'];
        $n = $plan['snapshot']['newSc'];
        $this->line(sprintf(
            'COUNTERS (must stay): SC%d Used=%s Rem=%s | SC%d Used=%s Rem=%s',
            (int) $o->ID, $o->UsedSessions, $o->RemainingSessions,
            (int) $n->ID, $n->UsedSessions, $n->RemainingSessions
        ));
    }

    /** @param array<string,mixed> $plan */
    private function apply(array $plan): void
    {
        $c = self::C173;
        $a = $plan['action'];
        $id = (int) $a['session_id'];
        $row = DB::table('ClassSession')->where('id', $id)->lockForUpdate()->first();
        if (!$row) {
            throw new \RuntimeException("ClassSession {$id} missing");
        }

        $note = trim((string) ($row->Note ?? ''));
        if (strpos($note, '#173') === false) {
            $note = trim($note . ' ' . $c['tag']);
            if (strlen($note) > 255) {
                $note = substr($c['tag'], 0, 255);
            }
        }

        DB::table('ClassSession')->where('id', $id)->update([
            'Status' => 'cancelled',
            'Note' => $note === '' ? $c['tag'] : $note,
            'updated_at' => now(),
        ]);

        $actor = (string) ($this->option('actor') ?: 'artisan:repair:supersede-renewal-session');
        $uid = $this->option('actor-user-id');
        $uid = ($uid !== null && $uid !== '') ? (int) $uid : null;

        $corr = new SessionCorrection([
            'session_id' => $id,
            'replaced_by_session_id' => (int) $a['replaced_by_session_id'],
            'correction_reason' => $a['correction_reason'],
            'decision_reference' => $a['decision_reference'],
            'decided_at' => now(),
            'decided_by_user_id' => $uid,
            'decided_by_actor' => $actor,
            'previous_status' => $a['previous_status'],
            'new_status' => 'cancelled',
            'preserved_learning_record_id' => $a['preserved_learning_record_id'],
            'keeper_learning_record_id' => $a['keeper_learning_record_id'],
            'snapshot_before' => [
                'class_session' => (array) $plan['snapshot']['s'],
                'student_class' => (array) $plan['snapshot']['oldSc'],
                'learning_record' => $plan['snapshot']['plr'] ? (array) $plan['snapshot']['plr'] : null,
            ],
        ]);
        $corr->save();
    }

    private function rollback(): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && !$this->prodOk()) {
            return self::FAILURE;
        }
        $c = self::C173;
        $corr = SessionCorrection::query()
            ->where('session_id', $c['sid'])
            ->where('decision_reference', $c['ref'])
            ->whereNull('rolled_back_at')
            ->orderByDesc('id')
            ->first();
        if (!$corr) {
            $this->error('No open correction for in-app #173');

            return self::FAILURE;
        }
        $this->line($execute ? '=== EXECUTE ROLLBACK ===' : '=== DRY RUN ROLLBACK ===');
        $this->line(sprintf(
            'WOULD restore ClassSession %d Status=%s; mark correction %d rolled_back_at',
            $corr->session_id, $corr->previous_status, $corr->id
        ));
        if (!$execute) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($corr): void {
            DB::table('ClassSession')->where('id', $corr->session_id)->update([
                'Status' => $corr->previous_status,
                'updated_at' => now(),
            ]);
            $corr->rolled_back_at = now();
            $corr->save();
        });
        $this->info('Rollback ok correction_id=' . $corr->id);

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $plan */
    private function dumpSnapshot(string $path, array $plan): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'case' => '173',
            'decision' => 'B',
            'action' => $plan['action'],
            'will_change' => $plan['will_change'],
            'will_not_change' => $plan['will_not_change'],
            'before' => json_decode(json_encode($plan['snapshot']), true),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    private function prodOk(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force')) {
            $this->error('Production requires --force');

            return false;
        }
        if (env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires ALLOW_PROD_REPAIR=1');

            return false;
        }

        return true;
    }
}
