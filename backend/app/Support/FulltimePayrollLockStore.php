<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FulltimePayrollLockStore
{
    public static function tablesReady(): bool
    {
        return Schema::hasTable('fulltime_payroll_runs')
            && Schema::hasTable('fulltime_payroll_run_teachers');
    }

    public static function lockedRun(int $branchId, string $month): ?object
    {
        if (!self::tablesReady()) {
            return null;
        }
        return DB::table('fulltime_payroll_runs')
            ->where('branch_id', $branchId)
            ->where('month', $month)
            ->where('status', 'locked')
            ->orderByDesc('id')
            ->first();
    }

    public static function monthIsLocked(int $branchId, string $month): bool
    {
        return self::lockedRun($branchId, $month) !== null;
    }

    /**
     * @return list<array>
     */
    public static function snapshotTeachers(int $runId): array
    {
        return DB::table('fulltime_payroll_run_teachers')
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(function ($row) {
                $payload = json_decode((string) $row->payload, true);
                return is_array($payload) ? $payload : [];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param list<array> $teachers
     */
    public static function lock(int $branchId, string $month, array $teachers, int $userId, string $policyVersion): int
    {
        $reviewing = collect($teachers)->first(fn ($row) => !empty($row['review_required']));
        if ($reviewing) {
            throw new \InvalidArgumentException('有老師仍待人工確認，不能鎖定本月結算。');
        }

        return (int) DB::transaction(function () use ($branchId, $month, $teachers, $userId, $policyVersion) {
            $existing = self::lockedRun($branchId, $month);
            if ($existing) {
                throw new \RuntimeException('Already locked');
            }

            $runId = DB::table('fulltime_payroll_runs')->insertGetId([
                'branch_id' => $branchId,
                'month' => $month,
                'status' => 'locked',
                'locked_by' => $userId,
                'locked_at' => now(),
                'teacher_count' => count($teachers),
                'branch_subject_total' => round(collect($teachers)->sum(fn ($row) => (float) ($row['settlement']['payroll_subject_count'] ?? 0)), 4),
                'policy_version' => $policyVersion,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($teachers as $teacher) {
                DB::table('fulltime_payroll_run_teachers')->insert([
                    'run_id' => $runId,
                    'teacher_id' => (int) $teacher['teacher_id'],
                    'review_required' => !empty($teacher['review_required']) ? 1 : 0,
                    'total_payout' => (float) ($teacher['settlement']['total_payout'] ?? 0),
                    'payload' => json_encode($teacher, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            self::audit($branchId, $month, 'lock', $userId, ['run_id' => $runId, 'teacher_count' => count($teachers)]);
            return $runId;
        });
    }

    public static function reopen(int $branchId, string $month, int $userId, string $reason): void
    {
        $run = self::lockedRun($branchId, $month);
        if (!$run) {
            throw new \RuntimeException('Not locked');
        }
        DB::table('fulltime_payroll_runs')->where('id', $run->id)->update([
            'status' => 'reopened',
            'reopened_by' => $userId,
            'reopened_at' => now(),
            'reopen_reason' => $reason,
            'updated_at' => now(),
        ]);
        self::audit($branchId, $month, 'reopen', $userId, ['run_id' => $run->id, 'reason' => $reason]);
    }

    /**
     * @return array<int, list<array{field:string,delta:float,label:?string,note:?string}>>
     */
    public static function adjustmentsByTeacher(int $branchId, string $month): array
    {
        if (!Schema::hasTable('fulltime_payroll_adjustments')) {
            return [];
        }
        $grouped = [];
        foreach (DB::table('fulltime_payroll_adjustments')
            ->where('branch_id', $branchId)
            ->where('month', $month)
            ->orderBy('id')
            ->get() as $row) {
            $tid = (int) $row->teacher_id;
            $grouped[$tid][] = [
                'field' => (string) $row->field,
                'delta' => (float) $row->delta,
                'label' => $row->label,
                'note' => $row->note,
            ];
        }
        return $grouped;
    }

    public static function addAdjustment(array $row): int
    {
        return (int) DB::table('fulltime_payroll_adjustments')->insertGetId([
            ...$row,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function audit(int $branchId, string $month, string $action, ?int $userId, array $meta = []): void
    {
        if (!Schema::hasTable('fulltime_payroll_audit_logs')) {
            return;
        }
        DB::table('fulltime_payroll_audit_logs')->insert([
            'branch_id' => $branchId,
            'month' => $month,
            'action' => $action,
            'user_id' => $userId,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
