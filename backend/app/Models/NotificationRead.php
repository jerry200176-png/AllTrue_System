<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRead extends Model
{
    protected $table = 'NotificationReads';

    protected $fillable = [
        'NotificationID',
        'UserID',
        'ReadAt',
        'ArchivedAt',
    ];

    protected $casts = [
        'ReadAt' => 'datetime',
        'ArchivedAt' => 'datetime',
    ];

    public function notification()
    {
        return $this->belongsTo(Notification::class, 'NotificationID', 'id');
    }
}
