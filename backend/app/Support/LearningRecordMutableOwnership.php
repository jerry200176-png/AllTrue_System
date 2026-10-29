<?php

namespace App\Support;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Services\SubstituteScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
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

    /**
     * #3824: protected = real assessment body AND Status=approved. Blank / placeholder / system
     * back-fill rows (e.g. 「（系統補登/扣除堂數）」) and unapproved rows stay correctable.
     */
    public static function isFilledAndApproved(LearningRecord $record): bool
    {
        // Approved status alone is enough: legacy/imported approved rows may lack ApprovedAt (review r4247040043).
        if (strtolower(trim((string) $record->getAttribute('Status'))) !== 'approved') {
            return false;
        }
        if (self::isSystemFillContent((string) $record->getAttribute('Content'))) {
            $record = clone $record;
            $record->setAttribute('Content', '');
        }

        return self::hasSubstantiveTeacherContent($record);
    }

    /** System-generated markers (back-fill / auto-approval) are not a teacher-written body. */
    private static function isSystemFillContent(string $content): bool
    {
        $content = trim($content);

        return str_starts_with($content, '（系統補登') || in_array($content, ['（系統自動核准）', '補登／納入科目數'], true);
    }

    /** The live protected LR of a session (row-locked when asked, inside a transaction). */
    public static function protectedRecordForSession(int $classSessionId, bool $lock = false): ?LearningRecord
    {
        if ($classSessionId <= 0) {
            return null;
        }
        $q = LearningRecord::query()->where('ClassSessionID', $classSessionId)->whereNull('VoidedAt');
        $lock && $q->lockForUpdate();
        $lr = $q->first();

        return $lr && self::isFilledAndApproved($lr) ? $lr : null;
    }

    /** Throws a 409 (rolls back the surrounding transaction) when the session's assessment is protected and $newTeacherId is not its teacher. */
    public static function assertTeacherChangeAllowed(int $classSessionId, ?int $newTeacherId = null): void
    {
        $locked = self::protectedRecordForSession($classSessionId, true);
        if ($locked !== null && (int) $locked->TeacherID !== $newTeacherId) {
            throw new HttpResponseException(self::lockedResponse());
        }
    }

    /** Contract transfer boundary: 409 when a moved session's protected assessment names a teacher other than the target contract's. */
    public static function assertTransferAllowed(iterable $sessionIds, int $targetTeacherId): void
    {
        foreach ($sessionIds as $sessionId) {
            self::assertTeacherChangeAllowed((int) $sessionId, $targetTeacherId);
        }
    }

    public static function lockedResponse(): JsonResponse
    {
        return response()->json([
            'message' => '這堂評量已填寫並核准，不能更改授課老師。如記錄有誤，請先退回核准再更正。',
            'code' => 'approved_assessment_teacher_locked',
        ], 409);
    }

    /** The protected LR of the occurrence at course + date + start (HH:MM), if any. */
    public static function protectedRecordForSlot(int $courseId, ?string $date, ?string $start, bool $lock = false): ?LearningRecord
    {
        if ($courseId <= 0 || !$date || !$start) {
            return null;
        }
        $ids = ClassSession::query()->where('StudentClassID', $courseId)->whereDate('SessionDate', substr($date, 0, 10))
            ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [substr($start, 0, 5)])->pluck('id');
        foreach ($ids as $id) {
            if ($lr = self::protectedRecordForSession((int) $id, $lock)) {
                return $lr;
            }
        }

        return null;
    }

    /**
     * "Y-m-d HH:MM" occurrence keys >= $from of a contract's protected assessments (one occurrence, not the whole day).
     * Keys come from the bound ClassSession (authoritative); the LR mirror columns are only a fallback for unbound rows.
     */
    public static function protectedSlots(int $courseId, string $from): array
    {
        $records = LearningRecord::query()->where('StudentClassID', $courseId)->whereNull('VoidedAt')->where('Status', 'approved')->get()
            ->filter(fn ($lr) => self::isFilledAndApproved($lr));
        $sessions = ClassSession::query()->whereIn('id', $records->pluck('ClassSessionID')->filter()->map(fn ($id) => (int) $id)->all())->get()->keyBy('id');

        return $records->map(function ($lr) use ($sessions) {
            $cs = $sessions->get((int) $lr->getAttribute('ClassSessionID'));

            return substr((string) ($cs ? $cs->SessionDate : $lr->getAttribute('SessionDate')), 0, 10)
                . ' ' . substr((string) ($cs ? $cs->StartTime : $lr->getAttribute('StartTime')), 0, 5);
        })->filter(fn ($key) => substr($key, 0, 10) >= $from)->unique()->values()->all();
    }

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
        // #3824: a teacher change cleared the body into a snapshot; the assigned teacher stays the owner of the refill.
        if (DB::table('learning_record_author_snapshots')->where('learning_record_id', (int) $record->getAttribute('id'))->exists()) {
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
        if ($record->relationLoaded('teacherComment')) {
            return $record->getRelation('teacherComment') !== null; // #3059: list pages preload it
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
            $outer->orWhereExists(function ($sub) use ($lrTable) {
                $sub->select(DB::raw(1))->from('learning_record_author_snapshots as lras')->whereColumn('lras.learning_record_id', "{$lrTable}.id");
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

    /** Laravel 8 has no whereNot(Closure); correlate the existing evidence predicate instead. */
    public static function constrainWhereTeacherIdIsMutableOwner($query, string $lrTable): void
    {
        $query->whereNotExists(function ($historical) use ($lrTable) {
            $historical->select(DB::raw(1))
                ->from('LearningRecord as lr_owner_evidence')
                ->whereColumn('lr_owner_evidence.id', "{$lrTable}.id");
            self::constrainWhereTeacherIdIsHistoricalOwner($historical, 'lr_owner_evidence');
        });
    }

    private static function resolveSession(LearningRecord $record): ?ClassSession
    {
        $sessionId = (int) ($record->getAttribute('ClassSessionID') ?? 0);
        if ($sessionId <= 0) {
            return null;
        }
        /** @var ClassSession|null $session */
        $session = ClassSession::query()->where('id', $sessionId)->first();

        return $session instanceof ClassSession ? $session : null;
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
