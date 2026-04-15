<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollTeacherBranchRule extends Model
{
    protected $table = 'payroll_teacher_branch_rules';
    public $timestamps = false;

    protected $fillable = [
        'teacher_user_id',
        'branch_id',
        'base_rates',
        'headcount_bonus',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'base_rates'       => 'array',
        'headcount_bonus'  => 'integer',
        'created_at'       => 'datetime',
    ];

    public static function latestForTeacherBranch(int $teacherUserId, int $branchId): ?self
    {
        return static::where('teacher_user_id', $teacherUserId)
            ->where('branch_id', $branchId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Batch-load the latest rule for each teacher in a branch.
     * Returns [teacher_user_id => PayrollTeacherBranchRule].
     */
    public static function latestMapForBranch(int $branchId): array
    {
        $rows = static::where('branch_id', $branchId)
            ->orderByDesc('id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[$row->teacher_user_id])) {
                $map[$row->teacher_user_id] = $row;
            }
        }
        return $map;
    }

    /**
     * Resolve effective rule context for a specific teacher in a branch.
     * Returns null if no override exists (caller should fall back to branch rule).
     */
    public static function resolveForTeacher(int $teacherUserId, int $branchId): ?array
    {
        $rule = static::latestForTeacherBranch($teacherUserId, $branchId);
        if (!$rule) {
            return null;
        }
        return [
            'base_rates'       => $rule->base_rates,
            'headcount_bonus'  => $rule->headcount_bonus,
            'rule_id'          => $rule->id,
        ];
    }
}
