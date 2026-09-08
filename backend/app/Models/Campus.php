<?php

namespace App\Models;

use App\Models\Scopes\OperationalTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new OperationalTenantScope());
    }
}
