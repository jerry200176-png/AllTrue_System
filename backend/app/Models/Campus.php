<?php

namespace App\Models;

use App\Models\Scopes\OperationalTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $swipe_line_notify 學生刷卡到班是否推 LINE 給家長
 */
class Campus extends Model
{
    use HasFactory;

    protected $table = 'Campus';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'code',
        'active',
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
        'TelegramWebhookSecret',
        'TelegramURL',
        'TeachLIFFID',
        'TeachLIFF_URL',
        'is_test',
    ];

    protected $casts = [
        'is_test' => 'boolean',
        'swipe_line_notify' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new OperationalTenantScope());
    }
}
