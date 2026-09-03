<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $teacher_id
 * @property int|null $branch_id
 * @property string $base_salary
 * @property string $effective_from
 * @property int|null $created_by
 *
 * @method static \Illuminate\Database\Eloquent\Builder|FulltimeSalaryProfile where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static static create(array $attributes = [])
 */
class FulltimeSalaryProfile extends Model
{
    protected $table = 'fulltime_salary_profiles';

    protected $fillable = [
        'teacher_id', 'branch_id', 'base_salary', 'manual_multiplier_pct', 'effective_from', 'status',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'base_salary' => 'decimal:2',
        'manual_multiplier_pct' => 'decimal:2',
    ];

    /**
     * Base salary effective on the given date (latest APPROVED profile at or
     * before it). TD-078: unapproved writes must never feed payroll.
     */
    public static function effectiveFor(int $teacherId, string $onDate): ?self
    {
        return self::where('teacher_id', $teacherId)
            ->where('status', 'approved')
            ->where('effective_from', '<=', $onDate)
            ->orderByDesc('effective_from')
            ->first();
    }
}
