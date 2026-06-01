<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentLineBinding extends Model
{
    protected $table = 'student_line_bindings';

    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'line_user_id',
        'campus_id',
        'bound_at',
        'notify_learning_feedback',
    ];

    protected $casts = [
        'notify_learning_feedback' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
