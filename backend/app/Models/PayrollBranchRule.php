<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollBranchRule extends Model
{
    protected $table = 'payroll_branch_rules';
    public $timestamps = false;

    protected $fillable = [
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

    /**
     * Get the latest rule for a branch. Falls back to config defaults if none exists.
     */
    public static function latestForBranch(int $branchId): ?self
    {
        return static::where('branch_id', $branchId)->orderByDesc('id')->first();
    }

    /**
     * Resolve effective base_rates and headcount_bonus for a branch.
     * Returns [base_rates => [...], headcount_bonus => int, rule_id => int|null].
     */
    public static function resolveForBranch(int $branchId): array
    {
        $rule = static::latestForBranch($branchId);
        if ($rule) {
            return [
                'base_rates'       => $rule->base_rates,
                'headcount_bonus'  => $rule->headcount_bonus,
                'rule_id'          => $rule->id,
            ];
        }

        // Fallback to config (transitional / dev only)
        \Log::warning("PayrollBranchRule: no DB rule for branch {$branchId}, falling back to config");
        return [
            'base_rates'      => config('payroll.base_rates', []),
            'headcount_bonus' => config('payroll.headcount_bonus', 50),
            'rule_id'         => null,
        ];
    }

    /**
     * Resolve for a locked month — uses the snapshot rule_version_id if available.
     */
    public static function resolveForLockedMonth(int $branchId, ?int $ruleVersionId): array
    {
        if ($ruleVersionId) {
            $rule = static::find($ruleVersionId);
            if ($rule && $rule->branch_id === $branchId) {
                return [
                    'base_rates'       => $rule->base_rates,
                    'headcount_bonus'  => $rule->headcount_bonus,
                    'rule_id'          => $rule->id,
                ];
            }
        }
        return static::resolveForBranch($branchId);
    }
}
