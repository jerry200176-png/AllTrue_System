<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $StudentClassID
 * @property string $SessionDate
 * @property string $StartTime
 * @property string $EndTime
 */

class ClassSession extends Model
{
    protected $table = 'ClassSession';

    protected $fillable = [
        'StudentClassID',
        'SessionDate',
        'StartTime',
        'EndTime',
        'Status',
        'Note',
    ];

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }

    public function signIns()
    {
        return $this->hasMany(StudentSignIn::class, 'ClassSessionID', 'id');
    }
}
