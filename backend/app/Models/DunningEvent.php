<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DunningEvent extends Model
{
    public $timestamps = false;

    protected $table = 'dunning_events';

    protected $fillable = [
        'student_id',
        'student_class_id',
        'campus_id',
        'rule_key',
        'channel',
        'triggered_by',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
