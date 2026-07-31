<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentSession extends Model
{
    protected $table = 'ParentSession';

    protected $fillable = [
        'StudentID',
        'TokenHash',
        'ExpiresAt',
    ];
}
