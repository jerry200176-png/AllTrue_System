<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $table = 'Teacher';
    public $timestamps = false;

    protected $fillable = [
        'CampusID',
        'T_Name',
        'Phone',
        'RFID',
        'LineID',
        'TelegramID',
        'TelegramID1',
        'TelegramID2',
        'Notify_Token',
        'Enable',
        'MDT',
    ];
}
