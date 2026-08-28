<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\ScheduleAuditLog;
use App\Models\StudentClass;
use App\Services\AttendanceLearningRecordIntegrityService;
use App\Services\LearningRecordBackfillService;
use App\Services\SessionDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Controlled incident repair for the confirmed 2026-08-28 attendance case.
 *
 * The target is deliberately fixed in code and validated before every write;
 * this command cannot be turned into an arbitrary production row editor.
 */
class RepairConfirmedAttendanceAssessment extends Command
{
    protected $signature = 'repair:confirmed-attendance-assessment
                            {--dry-run}
                            {--execute}
                            {--force}
                            {--actor=}
                            {--actor-user-id=4}';

    protected $description = 'Repair the confirmed attendance/evaluation incident and sweep integrity drift';

    private const TARGET = [
        'session_id' => 29212,
        'class_id' => 3112,
        'date' => '2026-08-28',
        'start' => '13:00',
        'end' => '15:00',
        'expected_status' => 'scheduled',
        'reason' => 'confirmed attendance: restore attended status and LearningRecord',
    ];

    public function handle(
        AttendanceLearningRecordIntegrityService $integrity,
        LearningRecordBackfillService $backfill
    ): int {
        $execute = (bool) $this->option('execute');
        if ($execute && app()->environment('production')
            && (!$this->option('force') || env('ALLOW_PROD_REPAIR') !== '1')) {
            $this->error('Production requires --force and ALLOW_PROD_REPAIR=1');
            return self::FAILURE;
        }

        $before = $integrity->scan(null, 2000);
        $this->line(json_encode([
            'target' => $this->targetPlan(),
            'integrity_before' => $before['counts'],
            'mode' => $execute ? 'execute' : 'dry-run',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (!$execute) {
            return self::SUCCESS;
        }

        try {
            $targetResult = DB::transaction(function () use ($backfill): array {
                $target = self::TARGET;
                $session = ClassSession::query()->whereKey($target['session_id'])->lockForUpdate()->first();
                if (!$session
                    || (int) $session->StudentClassID !== $target['class_id']
                    || substr((string) $session->SessionDate, 0, 10) !== $target['date']
                    || substr((string) $session->StartTime, 0, 5) !== $target['start']
                    || substr((string) $session->EndTime, 0, 5) !== $target['end']) {
                    throw new \RuntimeException('confirmed_attendance_target_drift');
                }

                $activeRecord = LearningRecord::query()
                    ->where('ClassSessionID', $target['session_id'])
                    ->whereNull('VoidedAt')
                    ->exists();
                if (strtolower((string) $session->Status) === 'attended' && $activeRecord) {
                    return ['status' => 'already_repaired', 'session_id' => $target['session_id']];
                }
                if (strtolower((string) $session->Status) !== $target['expected_status']) {
                    throw new \RuntimeException('confirmed_attendance_status_drift');
                }

                $old = (array) $session->getAttributes();
                $session->Status = 'attended';
                $session->Note = trim((string) $session->Note . ' integrity-repair-2026-08-28');
                $session->save();

                $sc = StudentClass::query()->whereKey($target['class_id'])->lockForUpdate()->first();
                if (!$sc) {
                    throw new \RuntimeException('confirmed_attendance_class_missing');
                }
                SessionDeductionService::deductOnAttendance($sc, null, $target['session_id']);
                $backfill->ensureRequiredForAttendanceSession($session);
                SessionDeductionService::recomputeCounters($target['class_id']);

                $audit = new ScheduleAuditLog([
                    'session_id' => $target['session_id'],
                    'action_type' => 'update',
                    'description' => '資料修復：confirmed attendance assessment integrity',
                    'operator_id' => $this->actorId(),
                    'branch_id' => DB::table('Student as st')->join('StudentClass as sc', 'sc.StudentID', '=', 'st.id')->where('sc.ID', $target['class_id'])->value('st.CampusID'),
                    'old_data' => $old,
                    'new_data' => (array) $session->fresh(),
                ]);
                $audit->save();

                return ['status' => 'repaired', 'session_id' => $target['session_id']];
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $sweep = $integrity->repair($this->actorId() ?? 0);
        $this->line(json_encode([
            'target' => $targetResult,
            'integrity_repair' => [
                'created' => $sweep['created'],
                'voided' => $sweep['voided'],
                'blocked' => $sweep['blocked'],
                'after' => $sweep['after']['counts'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $sweep['blocked'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function targetPlan(): array
    {
        $target = self::TARGET;
        $session = ClassSession::query()->find($target['session_id']);
        $active = LearningRecord::query()->where('ClassSessionID', $target['session_id'])->whereNull('VoidedAt')->count();
        $all = LearningRecord::query()->where('ClassSessionID', $target['session_id'])->count();

        return [
            'session_id' => $target['session_id'],
            'class_id' => $target['class_id'],
            'date' => $target['date'],
            'start' => $target['start'],
            'end' => $target['end'],
            'current_status' => $session ? strtolower((string) $session->Status) : null,
            'active_learning_records' => $active,
            'all_learning_records' => $all,
            'expected_status' => $target['expected_status'],
        ];
    }

    private function actorId(): ?int
    {
        $id = (int) $this->option('actor-user-id');
        return $id > 0 ? $id : null;
    }
}
