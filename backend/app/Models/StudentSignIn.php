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
    ];
}
