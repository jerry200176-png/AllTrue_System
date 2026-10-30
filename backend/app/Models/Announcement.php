<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $campus_id Optional projection/aggregate attribute; no schema change.
 * @property string|null $campus_name Optional projection/aggregate attribute; no schema change.
 */
class Announcement extends Model
{
    protected $table = 'Announcements';

    protected $fillable = [
        'Title',
        'Content',
        'BranchID',
        'TargetStudentID',
        'IsActive',
    ];
}
