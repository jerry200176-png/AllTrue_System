<?php

namespace App\Support;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Services\SubstituteScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * in-app #314 Option 2B: mutable LR work may follow current course teacher;
 * historical teaching evidence must not be rewritten. Fail closed.
 */
final class LearningRecordMutableOwnership
{
    /** @var list<string> */
    public const TAUGHT_SESSION_STATUSES = [
        'attended', 'late', 'leave', 'excused', 'completed', 'absent', 'leave_adjusted',
    ];

    private const PLACEHOLDER_CONTENT = ['', '（評量表）'];

    public static function canFollowCurrentCourseTeacher(LearningRecord $record): bool
    {
        return !self::hasHistoricalInstructorEvidence($record);
    }

    public static function hasHistoricalInstructorEvidence(LearningRecord $record): bool
    {
        if (self::hasAuthorshipOrAttendanceEvidence($record)) {
            return true;
        }

        $studentClassId = (int) ($record->getAttribute('StudentClassID') ?? 0);
        if ($studentClassId <= 0) {
            return false;
        }

        return SubstituteScheduleService::resolveSubstituteUserId(
            $studentClassId,
            $record->getAttribute('SessionDate'),
            $record->getAttribute('StartTime')
        ) !== null;
    }

    /** Evidence without substitute (shared by #207 pin / #312 clear). */
    public static function hasAuthorshipOrAttendanceEvidence(LearningRecord $record): bool
    {
        $status = strtolower(trim((string) ($record->getAttribute('Status') ?? '')));
        if ($status !== 'pending' || $record->getAttribute('ApprovedAt') !== null || (bool) ($record->getAttribute('SessionDeducted') ?? false)) {
            return true;
        }
        if (self::hasSubstantiveTeacherContent($record)) {
            return true;
        }

        $session = self::resolveSession($record);
        if ($session !== null) {
            $sessionStatus = strtolower(trim((string) ($session->getAttribute('Status') ?? '')));
            if (in_array($sessionStatus, self::TAUGHT_SESSION_STATUSES, true)
                || in_array($sessionStatus, SessionStatus::leaveFamily(), true)
                || self::sessionHasAttendanceSignIn((int) $session->getAttribute('id'))) {
                return true;
            }
        } elseif ((int) ($record->getAttribute('StudentClassID') ?? 0) > 0 && $record->getAttribute('SessionDate')) {
            if (self::slotHasAttendanceSignIn((int) $record->getAttribute('StudentClassID'), (string) $record->getAttribute('SessionDate'))) {
                return true;
            }
        }

        return false;
    }

    public static function hasSubstantiveTeacherContent(LearningRecord $record): bool
    {
        $content = trim((string) ($record->getAttribute('Content') ?? ''));
        if ($content !== '' && !in_array($content, self::PLACEHOLDER_CONTENT, true)) {
            return true;
        }
        foreach (['Progress', 'NextHomework', 'NextWeekTestScope', 'QuizScore', 'HomeworkStatus', 'Performance', 'Comment'] as $field) {
            if (trim((string) ($record->getAttribute($field) ?? '')) !== '') {
                return true;
            }
        }
        if (trim((string) ($record->getAttribute('AttachmentUrl') ?? '')) !== '') {
            return true;
        }
        if (Schema::hasTable('learning_record_teacher_comments')
            && DB::table('learning_record_teacher_comments')
                ->where('learning_record_id', (int) $record->getAttribute('id'))
                ->exists()) {
            return true;
        }

        return false;
    }

    /** True when auth teacher may act (display/queue/edit aligned). */
    public static function isActionableOwner(LearningRecord $record, int $authTeacherId): bool
    {
        if ($authTeacherId <= 0) {
            return false;
        }
        $studentClassId = (int) ($record->getAttribute('StudentClassID') ?? 0);
        $sub = $studentClassId > 0
            ? SubstituteScheduleService::resolveSubstituteUserId(
                $studentClassId,
                $record->getAttribute('SessionDate'),
                $record->getAttribute('StartTime')
            )
            : null;
        if ($sub !== null) {
            return $sub === $authTeacherId;
        }
        if (self::canFollowCurrentCourseTeacher($record)) {
            $contract = (int) (DB::table('StudentClass')->where('ID', $studentClassId)->value('TeacherID') ?? 0);

            return $contract > 0 && $contract === $authTeacherId;
        }

        return (int) ($record->getAttribute('TeacherID') ?? 0) === $authTeacherId;
    }

    /**
     * SQL: TeacherID alone authorizes only when authorship/attendance history exists
     * (parity with hasAuthorshipOrAttendanceEvidence; substitute handled by outer scope).
     */
    public static function constrainWhereTeacherIdIsHistoricalOwner($query, string $lrTable): void
    {
        $query->where(function ($outer) use ($lrTable) {
            $outer->whereRaw("LOWER(TRIM(COALESCE({$lrTable}.Status, ''))) != ?", ['pending'])
                ->orWhereNotNull("{$lrTable}.ApprovedAt")
                ->orWhere("{$lrTable}.SessionDeducted", 1)
                ->orWhere(function ($fields) use ($lrTable) {
                    $fields->where(function ($c) use ($lrTable) {
                        $c->whereNotNull("{$lrTable}.Content")
                            ->whereRaw("TRIM({$lrTable}.Content) != ''")
                            ->whereRaw("TRIM({$lrTable}.Content) != ?", ['（評量表）']);
                    });
                    foreach (['Progress', 'NextHomework', 'NextWeekTestScope', 'QuizScore', 'HomeworkStatus', 'Performance', 'Comment', 'AttachmentUrl'] as $col) {
                        $fields->orWhere(function ($p) use ($lrTable, $col) {
                            $p->whereNotNull("{$lrTable}.{$col}")->whereRaw("TRIM({$lrTable}.{$col}) != ''");
                        });
                    }
                });
            if (Schema::hasTable('learning_record_teacher_comments')) {
                $outer->orWhereExists(function ($sub) use ($lrTable) {
                    $sub->select(DB::raw(1))
                        ->from('learning_record_teacher_comments as lrtc')
                        ->whereColumn('lrtc.learning_record_id', "{$lrTable}.id");
                });
            }
            $outer->orWhereExists(function ($sub) use ($lrTable) {
                    $sub->select(DB::raw(1))
                        ->from('ClassSession as cs_hist')
                        ->whereColumn('cs_hist.id', "{$lrTable}.ClassSessionID")
                        ->where(function ($st) {
                            $st->whereIn('cs_hist.Status', self::TAUGHT_SESSION_STATUSES)
                                ->orWhereIn('cs_hist.Status', SessionStatus::leaveFamily());
                        });
                })
                ->orWhereExists(function ($sub) use ($lrTable) {
                    $sub->select(DB::raw(1))
                        ->from('StudentSingIn as ssi_hist')
                        ->where(function ($ssi) use ($lrTable) {
                            $ssi->where(function ($byCs) use ($lrTable) {
                                $byCs->whereColumn('ssi_hist.ClassSessionID', "{$lrTable}.ClassSessionID")
                                    ->whereNotNull('ssi_hist.ClassSessionID');
                            })->orWhere(function ($slot) use ($lrTable) {
                                $slot->whereColumn('ssi_hist.StudentClassID', "{$lrTable}.StudentClassID")
                                    ->whereRaw('DATE(ssi_hist.SignInDT) = DATE('.$lrTable.'.SessionDate)');
                            });
                        });
                });
        });
    }

    private static function resolveSession(LearningRecord $record): ?ClassSession
    {
        $sessionId = (int) ($record->getAttribute('ClassSessionID') ?? 0);
        if ($sessionId <= 0) {
            return null;
        }
        $row = DB::table('ClassSession')->where('id', $sessionId)->first();
        if (!$row) {
            return null;
        }
        $session = new ClassSession();
        $session->forceFill((array) $row);
        $session->exists = true;

        return $session;
    }

    private static function sessionHasAttendanceSignIn(int $classSessionId): bool
    {
        return $classSessionId > 0 && DB::table('StudentSingIn')->where('ClassSessionID', $classSessionId)->exists();
    }

    private static function slotHasAttendanceSignIn(int $studentClassId, string $sessionDate): bool
    {
        try {
            $date = Carbon::parse($sessionDate)->toDateString();
        } catch (\Throwable) {
            return false;
        }

        return DB::table('StudentSingIn')
            ->where('StudentClassID', $studentClassId)
            ->whereDate('SignInDT', $date)
            ->exists();
    }
}
