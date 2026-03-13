<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campus extends Model
{
    protected $table = 'Campus';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'code',
        'Current',
        'SwipeWindowMinutes',
        'LineNotifyID',
        'Client_ID',
        'Client_Secret',
        'LIFFID',
        'LIFF_URL',
        'URL',
        'Token',
        'TelegramToken',
        'TelegramChatID',
        'TelegramURL',
        'TeachLIFFID',
        'TeachLIFF_URL',
    ];
}
