<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingSwipe extends Model
{
    protected $table = 'PendingSwipe';

    protected $fillable = [
        'RFID',
        'StudentID',
        'CampusID',
        'SwipeAt',
        'Reason',
        'Payload',
    ];
}
