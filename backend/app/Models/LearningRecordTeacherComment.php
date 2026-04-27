<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningRecordTeacherComment extends Model
{
    protected $table = 'learning_record_teacher_comments';

    protected $fillable = [
        'learning_record_id',
        'student_id',
        'student_class_id',
        'class_session_id',
        'teacher_id',
        'campus_id',
        'author_user_id',
        'content',
        'last_read_by_teacher_at',
    ];

    protected $casts = [
        'last_read_by_teacher_at' => 'datetime',
    ];

    public function learningRecord()
    {
        return $this->belongsTo(LearningRecord::class, 'learning_record_id', 'id');
    }
}
