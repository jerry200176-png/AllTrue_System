<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherSignInAdjustment extends Model
{
    protected $table = 'teacher_signin_adjustments';

    public $timestamps = false;

    protected $fillable = [
        'teacher_signin_id',
        'adjusted_by_user_id',
        'adjust_reason',
        'original_signin_dt',
        'original_signout_dt',
        'new_signin_dt',
        'new_signout_dt',
    ];

    public function teacherSignIn(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TeacherSignIn::class, 'teacher_signin_id');
    }
}
