<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $LoginName
 * @property string $Name
 * @property string|null $PSW
 * @property string $type
 * @property string|null $status
 * @property string|null $employment_type
 * @property string|null $phone
 * @property string|null $LineID
 * @property string|null $AvatarUrl
 * @property int $TeachingSessionCount
 * @property bool $MustChangePassword
 * @property \Carbon\CarbonInterface|null $PasswordChangedAt
 * @property int|null $PasswordSetByUserID
 * @property \Carbon\CarbonInterface|null $bug_inbox_last_seen_at
 * @property int|null $bug_inbox_last_seen_bug_id
 * @property string|null $pin_hash
 * @property int $pin_failed_attempts
 * @property \Carbon\CarbonInterface|null $pin_locked_until
 * @property \Carbon\CarbonInterface|null $pin_set_at
 */
class User extends Model
{
    protected $table = 'User';
    public $timestamps = false;

    protected $fillable = [
        'LoginName',
        'Name',
        'PSW',
        'type',
        'employment_type',
        'status',
        'phone',
        'LineID',
        'AvatarUrl',
        'TeachingSessionCount',
        'MustChangePassword',
        'PasswordChangedAt',
        'PasswordSetByUserID',
        'bug_inbox_last_seen_at',
        'bug_inbox_last_seen_bug_id',
        'pin_hash',
        'pin_failed_attempts',
        'pin_locked_until',
        'pin_set_at',
    ];

    protected $hidden = ['PSW', 'pin_hash'];

    protected $casts = [
        'MustChangePassword' => 'boolean',
        'PasswordChangedAt' => 'datetime',
        'bug_inbox_last_seen_at' => 'datetime',
        'pin_locked_until' => 'datetime',
        'pin_set_at' => 'datetime',
    ];
}
