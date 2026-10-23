<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCampus extends Model
{
    protected $table = 'UserCampus';
    public $timestamps = false;

    protected $fillable = [
        'CampusID',
        'UserID',
        'Admin',
        'Approved',
        'RFID',
    ];

    /** A link counts as approved unless it is explicitly 0/false: legacy rows carry NULL (StaffCapabilityAuthorizer::legacyCampusIds). */
    public static function isApproved(mixed $approved): bool
    {
        return $approved === null || (bool) $approved;
    }
}
