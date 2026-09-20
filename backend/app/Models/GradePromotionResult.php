<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $batch_id
 * @property int $student_id
 * @property int $season_year
 * @property string|null $from_grade
 * @property string|null $to_grade
 * @property bool $graduated
 * @property \Carbon\Carbon|null $created_at
 * @method static static create(array $attributes = [])
 */
class GradePromotionResult extends Model
{
    protected $table = 'grade_promotion_results';

    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'student_id',
        'season_year',
        'from_grade',
        'to_grade',
        'graduated',
        'created_at',
    ];

    protected $casts = [
        'graduated' => 'boolean',
        'created_at' => 'datetime',
    ];
}
