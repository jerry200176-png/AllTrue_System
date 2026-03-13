<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningRecord extends Model
{
    protected $table = 'LearningRecord';
    public $timestamps = true;

    protected $fillable = [
        'StudentClassID', 'ClassSessionID', 'TeacherID', 'CreatedByUserID',
        'Content', 'AttachmentUrl', 'Status', 'ApprovedBy', 'ApprovedAt',
        'ExcludeFromSubjectCount', 'ReviewNote', 'Subject',
        'SessionDate', 'StartTime', 'EndTime',
        'HomeworkStatus', 'QuizScore', 'Progress', 'NextHomework',
        'Performance', 'Comment',
    ];

    protected $casts = [
        'ExcludeFromSubjectCount' => 'boolean',
        'ApprovedAt' => 'datetime',
    ];

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }

    public function classSession()
    {
        return $this->belongsTo(ClassSession::class, 'ClassSessionID', 'id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'TeacherID', 'id');
    }
}
