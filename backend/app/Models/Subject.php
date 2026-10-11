<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $Subject_Name
 */
class Subject extends Model
{
    protected $table = 'Subject';
    public $timestamps = false;

    protected $fillable = [
        'School_id',
        'Grade_no',
        'Subject_Name',
        'CampusID',
    ];
}
