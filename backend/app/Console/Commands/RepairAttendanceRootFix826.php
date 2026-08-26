<?php

namespace App\Console\Commands;

use App\Models\ScheduleAuditLog;
use App\Models\SessionCorrection;
use App\Services\SessionDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Allowlisted, auditable repair for the 2026-08-26 attendance incidents.
 * The manifest and target rows are deliberately fixed; this command never
 * accepts arbitrary production session IDs.
 */
class RepairAttendanceRootFix826 extends Command
{
    protected $signature = 'repair:attendance-root-fix-826
                            {--manifest= : Immutable repair manifest JSON}
                            {--dry-run}
                            {--execute}
                            {--force}
                            {--snapshot=}
                            {--actor=}
                            {--actor-user-id=4}';

    protected $description = 'Repair the allowlisted 2026-08-26 attendance root-fix sessions';

    private const REF = 'attendance-root-fix-2026-08-26';

    /** @var list<array{class_id:int,session_id:int,date:string,status:string,schedule_ids:list<int>,reason:string}> */
    private const TARGETS = [
        ['class_id' => 2819, 'session_id' => 28451, 'date' => '2026-08-03', 'status' => 'attended', 'schedule_ids' => [], 'reason' => '大安生物無課程與行事曆來源的補建已上堂次'],
        ['class_id' => 2819, 'session_id' => 28448, 'date' => '2026-08-26', 'status' => 'scheduled', 'schedule_ids' => [8867, 8879], 'reason' => '大安生物 8/26 非正式課程，清除 leave 殘留堂次與重複排課'],
        ['class_id' => 2081, 'session_id' => 16731, 'date' => '2026-08-01', 'status' => 'attended', 'schedule_ids' => [], 'reason' => '周芮緗 8/1 經主任確認未上課，撤銷人工誤點名'],
        ['class_id' => 2081, 'session_id' => 31739, 'date' => '2026-08-02', 'status' => 'completed', 'schedule_ids' => [], 'reason' => '周芮緗 8/2 補建堂次不具課程來源'],
        ['class_id' => 2081, 'session_id' => 31740, 'date' => '2026-08-03', 'status' => 'completed', 'schedule_ids' => [], 'reason' => '周芮緗 8/3 補建堂次不具課程來源'],
    ];

    public function handle(): int
    {
        if (!$this->validateManifest()) {
            return self::FAILURE;
        }
        $execute = (bool) $this->option('execute');
        if ($execute && !$this->productionAllowed()) {
            return self::FAILURE;
        }

        $plan = $this->plan();
        $this->line($execute ? '=== EXECUTE repair:attendance-root-fix-826 ===' : '=== DRY RUN repair:attendance-root-fix-826 ===');
        $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $actionable = array_values(array_filter($plan, fn (array $row): bool => $row['error'] === null && !$row['already']));
        if (!$execute || $actionable === []) {
            return self::SUCCESS;
        }

        $path = (string) ($this->option('snapshot') ?: storage_path('app/repair-snapshots/attendance-root-fix-826-' . now()->format('YmdHis') . '.json'));
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode(['case' => self::REF, 'generated_at' => now()->toIso8601String(), 'plan' => $plan], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        $this->line("Snapshot: {$path}");

        foreach ($actionable as $row) {
            $this->applyOne($row);
        }
        $this->info('Applied attendance root-fix repair for ' . count($actionable) . ' session(s).');
        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function plan(): array
    {
        $out = [];
        foreach (self::TARGETS as $target) {
            $session = DB::table('ClassSession')->where('id', $target['session_id'])->first();
            $correction = SessionCorrection::query()
                ->where('session_id', $target['session_id'])
                ->where('decision_reference', self::REF)
                ->whereNull('rolled_back_at')->first();
            $before = [
                'session' => $session,
                'learning_record_ids' => DB::table('LearningRecord')->where('ClassSessionID', $target['session_id'])->whereNull('VoidedAt')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'sign_in_ids' => DB::table('StudentSingIn')->where('ClassSessionID', $target['session_id'])->whereNull('VoidedAt')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'schedule_ids' => DB::table('schedules')->whereIn('id', $target['schedule_ids'])->where('status', 'scheduled')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ];
            $error = null;
            if (!$session) {
                $error = 'MISSING_SESSION';
            } elseif ((int) $session->StudentClassID !== $target['class_id']
                || substr((string) $session->SessionDate, 0, 10) !== $target['date']
                || strtolower((string) $session->Status) !== $target['status']) {
                $error = 'SESSION_DRIFT';
            } elseif ($correction) {
                $error = null;
            }
            $out[] = ['target' => $target, 'error' => $error, 'already' => $correction !== null, 'before' => $before];
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function applyOne(array $row): void
    {
        $target = $row['target'];
        $before = $row['before'];
        DB::transaction(function () use ($target, $before): void {
            $session = DB::table('ClassSession')->where('id', $target['session_id'])->lockForUpdate()->first();
            if (!$session || (int) $session->StudentClassID !== $target['class_id']
                || substr((string) $session->SessionDate, 0, 10) !== $target['date']
                || strtolower((string) $session->Status) !== $target['status']) {
                throw new \RuntimeException('REPAIR_826_SESSION_DRIFT_' . $target['session_id']);
            }

            $reason = self::REF . ' — ' . $target['reason'];
            $now = now();
            $actorId = $this->actorId();
            DB::table('StudentSingIn')->where('ClassSessionID', $target['session_id'])->whereNull('VoidedAt')->update([
                'VoidedAt' => $now, 'VoidedByUserID' => $actorId, 'VoidReason' => $reason,
            ]);
            DB::table('LearningRecord')->where('ClassSessionID', $target['session_id'])->whereNull('VoidedAt')->update([
                'VoidedAt' => $now,
                'VoidedByUserID' => $actorId,
                'VoidReason' => $reason,
                'updated_at' => $now,
            ]);
            SessionDeductionService::reverseForSession(
                (int) $target['class_id'], (int) $target['session_id'], 'status_adjust', $actorId, $reason
            );

            $newNote = trim((string) ($session->Note ?? '') . ' ' . $reason);
            DB::table('ClassSession')->where('id', $target['session_id'])->update([
                'Status' => 'cancelled', 'Note' => $newNote, 'updated_at' => $now,
            ]);
            $branchId = (int) (DB::table('StudentClass as sc')->join('Student as st', 'st.id', '=', 'sc.StudentID')->where('sc.ID', $target['class_id'])->value('st.CampusID') ?? 0);
            $audit = new ScheduleAuditLog([
                'session_id' => $target['session_id'], 'action_type' => 'update',
                'description' => '資料修復：' . $reason, 'operator_id' => $actorId,
                'branch_id' => $branchId ?: null, 'old_data' => (array) $session,
                'new_data' => array_merge((array) $session, ['Status' => 'cancelled', 'Note' => $newNote, 'updated_at' => $now]),
            ]);
            $audit->save();
            if ($target['schedule_ids'] !== []) {
                DB::table('schedules')->whereIn('id', $target['schedule_ids'])->where('student_course_id', $target['class_id'])->where('status', 'scheduled')->update(['status' => 'cancelled', 'updated_at' => $now]);
            }
            $correction = new SessionCorrection([
                'session_id' => $target['session_id'], 'replaced_by_session_id' => null,
                'correction_reason' => 'attendance_root_fix', 'decision_reference' => self::REF,
                'decided_at' => $now, 'decided_by_user_id' => $actorId,
                'decided_by_actor' => (string) ($this->option('actor') ?: self::REF),
                'previous_status' => (string) $session->Status, 'new_status' => 'cancelled',
                'snapshot_before' => $before,
            ]);
            $correction->save();
            SessionDeductionService::recomputeCounters((int) $target['class_id']);
        });
    }

    private function validateManifest(): bool
    {
        $path = (string) ($this->option('manifest') ?: '');
        if ($path === '' || !is_file($path)) {
            $this->error('--manifest is required and must point to the committed repair manifest');
            return false;
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        $ids = array_map(fn (array $t): int => (int) $t['session_id'], self::TARGETS);
        $manifestIds = array_map('intval', (array) ($manifest['approved_session_ids'] ?? []));
        if (($manifest['kind'] ?? '') !== 'attendance_root_fix_repair_manifest'
            || ($manifest['decision_reference'] ?? '') !== self::REF
            || $manifestIds !== $ids) {
            $this->error('repair manifest mismatch');
            return false;
        }
        return true;
    }

    private function actorId(): ?int
    {
        $id = (int) $this->option('actor-user-id');
        return $id > 0 ? $id : null;
    }

    private function productionAllowed(): bool
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
