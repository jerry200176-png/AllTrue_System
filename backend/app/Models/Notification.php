<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $CampusID
 * @property string $Type
 * @property string $Severity
 * @property string $Title
 * @property string|null $Body
 * @property string|null $SourceType
 * @property string|null $SourceID
 * @property string $SourceKey
 * @property array<string, mixed>|null $Payload
 * @property \Illuminate\Support\Carbon|null $OccurredAt
 * @property \Illuminate\Support\Carbon|null $ResolvedAt
 * @property string|null $ResolvedBy
 * @property string|null $ResolvedReason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
        'ResolvedBy',
        'ResolvedReason',
    ];

    protected $casts = [
        'Payload' => 'array',
        'OccurredAt' => 'datetime',
        'ResolvedAt' => 'datetime',
    ];

    protected static function booted(): void
    {
        // #413: an open row carries no resolver; any path that re-opens (ResolvedAt = null) clears it.
        static::saving(function (Notification $notification) {
            if ($notification->ResolvedAt === null && ($notification->ResolvedBy !== null || $notification->ResolvedReason !== null)) {
                $notification->ResolvedBy = null;
                $notification->ResolvedReason = null;
            }
        });
    }

    public function reads()
    {
        return $this->hasMany(NotificationRead::class, 'NotificationID', 'id');
    }
}
