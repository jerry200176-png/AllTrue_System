<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningRecordFeedback extends Model
{
    protected $table = 'learning_record_feedbacks';

    protected $fillable = [
        'learning_record_id',
        'student_id',
        'student_class_id',
        'class_session_id',
        'teacher_id',
        'campus_id',
        'content',
        'parent_session_id',
        'last_read_by_teacher_at',
        'last_read_by_director_at',
    ];

    protected $casts = [
        'last_read_by_teacher_at' => 'datetime',
        'last_read_by_director_at' => 'datetime',
    ];

    public function learningRecord()
    {
        return $this->belongsTo(LearningRecord::class, 'learning_record_id', 'id');
    }
}
