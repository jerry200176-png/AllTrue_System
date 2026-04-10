<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningRecordTeacherChange extends Model
{
    protected $table = 'learning_record_teacher_changes';

    protected $fillable = [
        'learning_record_id',
        'old_teacher_id',
        'new_teacher_id',
        'changed_by',
        'reason',
    ];
}
