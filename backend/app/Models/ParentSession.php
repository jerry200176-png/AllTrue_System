<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int|string|null $campus_id Optional projection/aggregate attribute; no schema change.
 * @property int|string|null $cnt Optional projection/aggregate attribute; no schema change.
 */
class ParentSession extends Model
{
    protected $table = 'ParentSession';

    protected $fillable = [
        'StudentID',
        'identity_group_id',
        'line_user_id',
        'TokenHash',
        'ExpiresAt',
    ];

    protected $casts = [
        'ExpiresAt' => 'datetime',
    ];
}
