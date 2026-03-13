<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
