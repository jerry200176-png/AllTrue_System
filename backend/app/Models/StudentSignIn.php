<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentSignIn extends Model
{
    protected $table = 'StudentSingIn';
    public $timestamps = false;

    protected $fillable = [
        'StudentClassID',
        'StudentID',
        'TeacherID',
        'RecordedByUserID',
        'GradeID',
        'SubjectID',
        'Get1byID',
        'Hours',
        'Memo',
        'SignInDT',
        'SignOutDT',
        'MDT',
        'ClassSessionID',
        'Status',
        'PersonType',
        'CampusID',
        'SessionDeducted',
        'VoidedAt',
        'VoidedByUserID',
        'VoidReason',
    ];

    protected $casts = [
        'SessionDeducted' => 'boolean',
        'VoidedAt'        => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->whereNull('VoidedAt');
    }

    public function scopeVoided($query)
    {
        return $query->whereNotNull('VoidedAt');
    }

    public function isVoided(): bool
    {
        return $this->VoidedAt !== null;
    }
}
