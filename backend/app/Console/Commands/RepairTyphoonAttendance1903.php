<?php

namespace App\Console\Commands;

use App\Models\SessionCorrection;
use App\Services\SessionDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off, auditable repair for in-app #1903: 2026-07-11 (Sat) was a
 * campus-closure (typhoon) day for 新店 (campus 9). Two sessions were
 * nonetheless marked ClassSession.Status='attended' — both by the same
 * account, one second apart (2026-07-29 17:00:13 / :14), so this was one
 * batch action rather than two independent live sign-ins. No holiday/closure
 * calendar exists in this codebase to have prevented it (see #1903 for the
 * broader systemic gap); this command only reverses the two known-bad rows.
 */
class RepairTyphoonAttendance1903 extends Command
{
    protected $signature = 'repair:typhoon-attendance-1903
                            {--dry-run}
                            {--execute}
                            {--force}
                            {--snapshot=}
                            {--actor=}
                            {--actor-user-id=}
                            {--rollback}';

    protected $description = 'Repair the two 2026-07-11 typhoon-day sessions wrongly marked attended (in-app #1903)';

    private const REF = 'in-app #1903';

    /** @return list<array{class_id:int,session_id:int}> */
    private const TARGETS = [
        ['class_id' => 1849, 'session_id' => 22302], // 陳畇寧
        ['class_id' => 2081, 'session_id' => 16728], // 周芮緗
    ];

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }
        $execute = (bool) $this->option('execute');
        if ($execute && !$this->prodOk()) {
            return self::FAILURE;
        }

        $plan = $this->plan();
        $this->line($execute ? '=== EXECUTE repair:typhoon-attendance-1903 ===' : '=== DRY RUN repair:typhoon-attendance-1903 ===');
        $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $actionable = array_filter($plan, fn ($row) => $row['error'] === null && !$row['already']);
        if (!$execute || $actionable === []) {
            return self::SUCCESS;
        }

        $path = (string) ($this->option('snapshot') ?: storage_path('app/repair-snapshots/1903-typhoon-attendance-' . now()->format('YmdHis') . '.json'));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode(['case' => 1903, 'generated_at' => now()->toIso8601String(), 'plan' => $plan], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        $this->line("Snapshot: {$path}");

        foreach ($actionable as $row) {
            $this->applyOne((int) $row['class_id'], (int) $row['session_id'], $row['before']);
        }

        $this->info('Applied #1903 repair for ' . count($actionable) . ' session(s); rerun --dry-run to verify.');
        return self::SUCCESS;
    }

    private function applyOne(int $classId, int $sessionId, array $before): void
    {
        DB::transaction(function () use ($classId, $sessionId, $before): void {
            $session = DB::table('ClassSession')->where('id', $sessionId)->lockForUpdate()->first();
            if (!$session || (string) $session->Status !== 'attended' || (int) $session->StudentClassID !== $classId) {
                throw new \RuntimeException("REPAIR_1903_DRIFT_session_{$sessionId}");
            }

            DB::table('ClassSession')->where('id', $sessionId)->update([
                'Status' => 'cancelled',
                'Note' => trim((string) $session->Note . ' typhoon-closure-mismarked ' . self::REF),
                'updated_at' => now(),
            ]);
            $liveLearningRecordIds = DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->pluck('id')->all();
            if ($liveLearningRecordIds !== []) {
                DB::table('LearningRecord')->whereIn('id', $liveLearningRecordIds)->update(['VoidedAt' => now(), 'updated_at' => now()]);
            }

            SessionDeductionService::reverseForSession($classId, $sessionId, 'retro_leave', $this->actorId(), self::REF);
            SessionDeductionService::recomputeCounters($classId);

            $correction = new SessionCorrection([
                'session_id' => $sessionId,
                'replaced_by_session_id' => null,
                'correction_reason' => 'campus_closure_mismarked_attended',
                'decision_reference' => self::REF,
                'decided_at' => now(),
                'decided_by_user_id' => $this->actorId(),
                'decided_by_actor' => (string) ($this->option('actor') ?: 'repair:typhoon-attendance-1903'),
                'previous_status' => 'attended',
                'new_status' => 'cancelled',
                'preserved_learning_record_id' => null,
                'keeper_learning_record_id' => null,
                'snapshot_before' => $before,
            ]);
            $correction->save();
        });
    }

    /** @return list<array{class_id:int,session_id:int,error:?string,already:bool,before:array<string,mixed>}> */
    private function plan(): array
    {
        $out = [];
        foreach (self::TARGETS as $target) {
            $classId = $target['class_id'];
            $sessionId = $target['session_id'];
            $session = DB::table('ClassSession')->where('id', $sessionId)->first();
            $correction = SessionCorrection::query()->where('session_id', $sessionId)->where('decision_reference', self::REF)->whereNull('rolled_back_at')->first();
            $before = [
                'session' => $session,
                'liveLearningRecordIds' => DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ];
            if ($correction) {
                $out[] = ['class_id' => $classId, 'session_id' => $sessionId, 'error' => null, 'already' => true, 'before' => $before];
                continue;
            }
            if (!$session) {
                $out[] = ['class_id' => $classId, 'session_id' => $sessionId, 'error' => 'REPAIR_1903_MISSING_SESSION', 'already' => false, 'before' => $before];
                continue;
            }
            if ((int) $session->StudentClassID !== $classId || (string) $session->Status !== 'attended' || substr((string) $session->SessionDate, 0, 10) !== '2026-07-11') {
                $out[] = ['class_id' => $classId, 'session_id' => $sessionId, 'error' => 'REPAIR_1903_SESSION_DRIFT', 'already' => false, 'before' => $before];
                continue;
            }
            $out[] = ['class_id' => $classId, 'session_id' => $sessionId, 'error' => null, 'already' => false, 'before' => $before];
        }
        return $out;
    }

    private function rollback(): int
    {
        if (!$this->option('execute')) {
            $this->line('=== DRY RUN ROLLBACK repair:typhoon-attendance-1903 ===');
            $this->line('WOULD restore Status=attended and un-void the captured LearningRecord ids for each active correction.');
            return self::SUCCESS;
        }
        if (!$this->prodOk()) {
            return self::FAILURE;
        }

        $restored = 0;
        foreach (self::TARGETS as $target) {
            $sessionId = $target['session_id'];
            $classId = $target['class_id'];
            $corr = SessionCorrection::query()->where('session_id', $sessionId)->where('decision_reference', self::REF)->whereNull('rolled_back_at')->latest('id')->first();
            if (!$corr) {
                continue;
            }
            DB::transaction(function () use ($corr, $classId, $sessionId): void {
                DB::table('ClassSession')->where('id', $sessionId)->update(['Status' => 'attended', 'updated_at' => now()]);
                $ids = array_map('intval', (array) (($corr->snapshot_before ?? [])['liveLearningRecordIds'] ?? []));
                if ($ids !== []) {
                    DB::table('LearningRecord')->whereIn('id', $ids)->where('ClassSessionID', $sessionId)->update(['VoidedAt' => null, 'updated_at' => now()]);
                }
                SessionDeductionService::deductForSession($classId, $sessionId, 'retro_leave', $this->actorId(), self::REF);
                SessionDeductionService::recomputeCounters($classId);
                $corr->rolled_back_at = now();
                $corr->save();
            });
            $restored++;
        }
        $this->info("Rolled back #1903 repair for {$restored} session(s).");
        return self::SUCCESS;
    }

    private function actorId(): ?int
    {
        $id = (int) $this->option('actor-user-id');
        return $id > 0 ? $id : null;
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
