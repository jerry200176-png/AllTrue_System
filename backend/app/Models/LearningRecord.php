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
        'SessionDeducted', 'ExcludeFromSubjectCount', 'ReviewNote', 'Subject',
        'SessionDate', 'StartTime', 'EndTime',
        'HomeworkStatus', 'QuizScore', 'Progress', 'NextHomework',
        'Performance', 'Comment',
        'VoidedAt', 'VoidedByUserID', 'VoidReason',
    ];

    protected $casts = [
        'SessionDeducted'        => 'boolean',
        'ExcludeFromSubjectCount' => 'boolean',
        'ApprovedAt'             => 'datetime',
        'VoidedAt'               => 'datetime',
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
