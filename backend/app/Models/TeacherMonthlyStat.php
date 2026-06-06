<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherMonthlyStat extends Model
{
    protected $table = 'teacher_monthly_stats';

    protected $fillable = [
        'TeacherID',
        'YearMonth',
        'SessionCount',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'TeacherID');
    }
}
