<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEngagementXpEvent extends Model
{
    protected $table = 'user_engagement_xp_events';

    protected $fillable = [
        'idempotency_key',
        'user_id',
        'event_type',
        'xp_delta',
        'learning_record_id',
    ];

    protected $casts = [
        'xp_delta' => 'integer',
        'learning_record_id' => 'integer',
    ];
}
