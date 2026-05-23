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
        'effective_from',
        'use_branch_default',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'base_rates'       => 'array',
        'headcount_bonus'  => 'integer',
        'effective_from'   => 'date',
        'use_branch_default' => 'boolean',
        'created_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->effective_from)) {
                // Backward compatibility: legacy rows without effective date should
                // apply to all historical months until users start managing date cards.
                $model->effective_from = '1970-01-01';
            }
        });
    }

    public static function latestForTeacherBranch(int $teacherUserId, int $branchId, ?string $asOfDate = null): ?self
    {
        $asOfDate = $asOfDate ?: now()->toDateString();

        $rule = static::where('teacher_user_id', $teacherUserId)
            ->where('branch_id', $branchId)
            ->whereDate('effective_from', '<=', $asOfDate)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
        if (!$rule || $rule->use_branch_default) {
            return null;
        }
        return $rule;
    }

    /**
     * Batch-load the latest rule for each teacher in a branch.
     * Returns [teacher_user_id => PayrollTeacherBranchRule].
     */
    public static function latestMapForBranch(int $branchId, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?: now()->toDateString();
        $rows = static::where('branch_id', $branchId)
            ->whereDate('effective_from', '<=', $asOfDate)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            if (!array_key_exists($row->teacher_user_id, $map)) {
                if (!$row->use_branch_default) {
                    $map[$row->teacher_user_id] = $row;
                } else {
                    $map[$row->teacher_user_id] = null;
                }
            }
        }
        $map = array_filter($map, fn ($rule) => $rule instanceof self);
        return $map;
    }

    /**
     * Resolve effective rule context for a specific teacher in a branch.
     * Returns null if no override exists (caller should fall back to branch rule).
     */
    public static function resolveForTeacher(int $teacherUserId, int $branchId, ?string $asOfDate = null): ?array
    {
        $rule = static::latestForTeacherBranch($teacherUserId, $branchId, $asOfDate);
        if (!$rule) {
            return null;
        }
        return [
            'base_rates'       => $rule->base_rates,
            'headcount_bonus'  => $rule->headcount_bonus,
            'rule_id'          => $rule->id,
            'effective_from'   => optional($rule->effective_from)->toDateString(),
        ];
    }
}
