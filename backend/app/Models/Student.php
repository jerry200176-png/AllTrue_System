<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property int $CampusID
 */
class Student extends Model
{
    protected $table = 'Student';

    protected $fillable = [
        'name',
        'CampusID',
        'ClassID',
        'SchoolName',
        'RFID',
        'LineID',
        'TelegramID',
        'TelegramID1',
        'TelegramID2',
        'enable',
        'MDT',
        'Notify_Token',
        'Phone',
        'parent_name',
        'parent_phone',
        'notes',
        'status',
    ];

    public $timestamps = false;

    public function classes()
    {
        return $this->hasMany(StudentClass::class, 'StudentID', 'id');
    }
}
