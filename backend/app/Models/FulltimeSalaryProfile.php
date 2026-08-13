<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FulltimeSalaryProfile extends Model
{
    protected $table = 'fulltime_salary_profiles';

    protected $fillable = [
        'teacher_id', 'branch_id', 'base_salary', 'effective_from', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'base_salary' => 'decimal:2',
    ];

    /** Base salary effective on the given date (latest profile at or before it). */
    public static function effectiveFor(int $teacherId, string $onDate): ?self
    {
        return self::where('teacher_id', $teacherId)
            ->where('effective_from', '<=', $onDate)
            ->orderByDesc('effective_from')
            ->first();
    }
}
