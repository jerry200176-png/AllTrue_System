<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackPushLog extends Model
{
    protected $table = 'feedback_push_log';

    protected $fillable = [
        'feedback_id',
        'direction',
        'campus_id',
        'learning_record_id',
        'last_pushed_at',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];
}
