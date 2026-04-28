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
        'HomeworkStatus', 'QuizScore', 'Progress', 'NextHomework', 'NextWeekTestScope',
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

    /**
     * 堂次已請假 (ClassSession.Status in leave / leave_adjusted / excused) 時，
     * pending / changes_requested 評量不應出現在待填／待審清單。
     * 已核准等狀態仍保留可查詢。
     *
     * FR-005 (2026-04-22): 新增 'excused' — 與 AttendanceController::LEAVE_STATUSES 及
     * 前端 SmartCalendar LEAVE_STATUSES 對齊。
     */
    public function scopeExcludeLeaveSessionPendingReview($query)
    {
        $t = $query->getModel()->getTable();

        return $query->where(function ($outer) use ($t) {
            $outer->whereNotIn("{$t}.Status", ['pending', 'changes_requested'])
                ->orWhereDoesntHave('classSession', function ($cs) {
                    $cs->whereIn('Status', ['leave', 'leave_adjusted', 'excused']);
                });
        });
    }

    /**
     * 課程暫停 (StudentClass.Stop=1) 時，待審／需修改評量不應再出現在清單（不需填寫）；
     * 已核准等狀態仍保留可查詢。
     */
    public function scopeExcludePausedCoursePendingReview($query)
    {
        $t = $query->getModel()->getTable();

        return $query->where(function ($outer) use ($t) {
            $outer->whereNotIn("{$t}.Status", ['pending', 'changes_requested'])
                ->orWhereHas('classSession', function ($cs) {
                    $cs->whereIn('Status', ['attended', 'completed', 'late', 'absent']);
                })
                ->orWhereHas('studentClass', function ($sc) {
                    $sc->where(function ($w) {
                        $w->where('Stop', 0)->orWhereNull('Stop');
                    });
                });
        });
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

    public function feedback()
    {
        return $this->hasOne(LearningRecordFeedback::class, 'learning_record_id', 'id');
    }

    public function teacherComment()
    {
        return $this->hasOne(LearningRecordTeacherComment::class, 'learning_record_id', 'id');
    }
}
