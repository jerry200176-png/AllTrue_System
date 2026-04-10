<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'Notifications';

    protected $fillable = [
        'CampusID',
        'Type',
        'Severity',
        'Title',
        'Body',
        'SourceType',
        'SourceID',
        'SourceKey',
        'Payload',
        'OccurredAt',
        'ResolvedAt',
    ];

    protected $casts = [
        'Payload' => 'array',
        'OccurredAt' => 'datetime',
        'ResolvedAt' => 'datetime',
    ];

    public function reads()
    {
        return $this->hasMany(NotificationRead::class, 'NotificationID', 'id');
    }
}
