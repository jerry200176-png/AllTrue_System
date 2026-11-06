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

    // Credentials never leave the server through model serialization (a course response eager-loads
    // room.campus). Server code still reads them as properties; admin screens use presence flags.
    protected $hidden = [
        'Client_Secret',
        'Token',
        'LineNotifyID',
        'TelegramToken',
        'TelegramChatID',
        'TelegramWebhookSecret',
        'messaging_channel_token',
        'messaging_channel_secret',
    ];

    protected $casts = [
        'is_test' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new OperationalTenantScope());
    }
}
