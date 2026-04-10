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

    /** @var list<string> created_at 需可寫入：swipe 更新 RFID 時一併刷新，供 5 分鐘效期計算 */
    protected $fillable = ['CampusID', 'RFID', 'created_at'];

    public const VALID_MINUTES = 5;
}
