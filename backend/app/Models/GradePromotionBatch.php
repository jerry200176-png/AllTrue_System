<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campus_id
 * @property int $season_year
 * @property string $idempotency_key
 * @property int $actor_user_id
 * @property string $status
 * @property array<string, mixed>|null $summary
 * @property \Carbon\Carbon|null $created_at
 * @method static static create(array $attributes = [])
 */
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
