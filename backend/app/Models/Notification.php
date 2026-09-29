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
    ];

    protected $casts = [
        'Payload' => 'array',
        'OccurredAt' => 'datetime',
        'ResolvedAt' => 'datetime',
    ];

    public function reads()
    {
        return $this->hasMany(NotificationRead::class, 'NotificationID', 'id');
    }
}
