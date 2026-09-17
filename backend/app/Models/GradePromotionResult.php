<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
