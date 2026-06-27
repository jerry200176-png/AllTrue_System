<?php

namespace App\Models;

use App\Services\UserEngagementXpAwardService;
use Carbon\Carbon;
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

        // 請假狀態的「單一真相」其實是 StudentSingIn.Status='leave'（請假是用簽到記錄記的）。
        // 但部分請假路徑沒有同步把 ClassSession.Status 改成 leave（殘留 scheduled），
        // 只看 ClassSession.Status 會讓請假堂的 pending 評量漏進待審清單（in-app #170）。
        // 因此除了 ClassSession.Status，再排除「該堂有未作廢的請假簽到」的情況。
        return $query->where(function ($outer) use ($t) {
            $outer->whereNotIn("{$t}.Status", ['pending', 'changes_requested'])
                ->orWhere(function ($keep) use ($t) {
                    $keep->whereDoesntHave('classSession', function ($cs) {
                        $cs->whereIn('Status', \App\Support\SessionStatus::LEAVE);
                    })->whereNotExists(function ($sub) use ($t) {
                        $sub->selectRaw('1')
                            ->from('StudentSingIn as ssi_leave')
                            ->whereColumn('ssi_leave.ClassSessionID', "{$t}.ClassSessionID")
                            ->whereNull('ssi_leave.VoidedAt')
                            ->whereIn('ssi_leave.Status', ['leave', 'excused', 'leave_requested']);
                    });
                });
        });
    }

    /**
     * 課程暫停 (StudentClass.Stop=1) 時，待審／需修改評量不應再出現在清單（不需填寫）；
     * 已核准等狀態仍保留可查詢。
     *
     * 例外：課程已 Stop、但連結的 ClassSession 仍為 scheduled 且堂次結束時間已過（未及時點名／狀態未回寫）
     * 時，待審評量仍應出現在清單，否則會發生「最後一堂／結束後評量永遠載不出」且主任無法退回／審核。
     */
    public function scopeExcludePausedCoursePendingReview($query)
    {
        $t = $query->getModel()->getTable();
        $now = Carbon::now()->format('Y-m-d H:i:s');

        return $query->where(function ($outer) use ($t, $now) {
            $outer->whereNotIn("{$t}.Status", ['pending', 'changes_requested'])
                ->orWhereHas('classSession', function ($cs) {
                    $cs->whereIn('Status', ['attended', 'late', 'absent']);
                })
                ->orWhereHas('studentClass', function ($sc) {
                    $sc->where(function ($w) {
                        $w->where('Stop', 0)->orWhereNull('Stop');
                    });
                })
                ->orWhere(function ($q) use ($t, $now) {
                    $q->whereIn("{$t}.Status", ['pending', 'changes_requested'])
                        ->whereHas('studentClass', function ($sc) {
                            $sc->where('Stop', 1);
                        })
                        ->whereHas('classSession', function ($cs) use ($now) {
                            $tbl = $cs->getModel()->getTable();
                            $cs->whereRaw('LOWER(`' . $tbl . '`.`Status`) = ?', ['scheduled'])
                                ->whereRaw(
                                    "CONCAT(`{$tbl}`.`SessionDate`, ' ', COALESCE(`{$tbl}`.`EndTime`, '23:59:59')) < ?",
                                    [$now]
                                );
                        });
                });
        });
    }

    /**
     * 老師評量清單：已取消堂次（ClassSession.Status=cancelled）不應出現；
     * 主任仍可查（不在全域套用 excludeLeaveSessionPendingReview 以免影響主任）。
     *
     * 無綁定 ClassSessionID 的列仍保留（主任手動補登等邊界）。
     */
    public function scopeExcludeCancelledClassSessionForTeacherList($query)
    {
        $t = $query->getModel()->getTable();

        return $query->where(function ($outer) use ($t) {
            $outer->where(function ($q) use ($t) {
                $q->whereNull("{$t}.ClassSessionID")->orWhere("{$t}.ClassSessionID", '<=', 0);
            })->orWhereHas('classSession', function ($cs) {
                $tbl = $cs->getModel()->getTable();
                $cs->whereRaw('LOWER(`' . $tbl . '`.`Status`) != ?', ['cancelled']);
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

    protected static function booted(): void
    {
        static::updating(function (self $lr) {
            if (!$lr->isDirty('VoidedAt') || $lr->VoidedAt === null) {
                return;
            }
            app(UserEngagementXpAwardService::class)->revokeLearningRecordApproved((int) $lr->id);
        });
    }
}
