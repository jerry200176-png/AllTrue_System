<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 暫存 RFID：當 swipe-rfid 收到未綁定的卡片時寫入
 * 每個分校只存一筆，有效時間 5 分鐘
 */
class TempRfid extends Model
{
    protected $table = 'TempRfid';
    public $timestamps = false;

    protected $fillable = ['CampusID', 'RFID'];

    public const VALID_MINUTES = 5;
}
