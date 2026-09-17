<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradePromotionBatch extends Model
{
    protected $table = 'grade_promotion_batches';

    public $timestamps = false;

    protected $fillable = [
        'campus_id',
        'season_year',
        'idempotency_key',
        'actor_user_id',
        'status',
        'summary',
        'created_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'created_at' => 'datetime',
    ];
}
