<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\LearningRecordBackfillService;
use App\Support\AttendanceStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ApprovalSessionSyncService
{
    /**
     * 核准評量後同步堂次狀態與扣堂（與點名走同一管線）。
     *
     * 守衛規則（任一命中即 return，不拋例外）：
     * 1. 找不到對應 ClassSession → skip
     * 2. ClassSession 為 leave/leave_adjusted/cancelled → skip
     * 3. SessionDate > today → skip（未來堂次不預扣）
     * 4. 已有 active + SessionDeducted SignIn → idempotent skip
     * 5. 已有 active 但不可扣堂的 SignIn（如缺席）→ skip，不再新增第二筆（#4931）
     */
    public static function syncOnApprove(LearningRecord $lr, StudentClass $sc, int $approvedByUserId): void
    {
        $cs = self::resolveClassSession($lr, $sc);
        if (!$cs) {
            return;
        }

        $skipStatuses = ['leave', 'leave_adjusted', 'cancelled'];
        if (in_array(strtolower(trim((string) $cs->Status)), $skipStatuses, true)) {
            return;
        }

        $sessionDate = substr((string) $cs->SessionDate, 0, 10);
        if ($sessionDate > now()->toDateString()) {
            return;
        }

        if ((int) ($lr->ClassSessionID ?? 0) <= 0) {
            $lr->ClassSessionID = $cs->id;
            $lr->save();
        }

        if (self::hasDeductedSignIn((int) $cs->id)) {
            if (strtolower(trim((string) $cs->Status)) === 'scheduled') {
                $cs->Status = 'attended';
                $cs->save();
            }
            return;
        }

        $signIn = self::payableSignIn($cs, $sc, 'present', $lr->TeacherID ?? $sc->TeacherID, $approvedByUserId, 'lr_approve');
        if (!$signIn) {
            return; // #4931: the live 缺席 sign-in is the attendance decision; approving the evaluation adds no second row
        }

        $cs->Status = 'attended';
        $cs->save();

        SessionDeductionService::deductOnAttendance($sc, $signIn);
        app(LearningRecordBackfillService::class)->ensureRequiredForAttendanceSession($cs);
    }

    /**
     * The one sign-in a taught lesson is debited against (#4931): the live deductible one if there is one, a new one (or the latest voided one, un-voided)
     * when the lesson has no live sign-in, or null when a live non-deductible sign-in (缺席) already records the lesson.
     * The caller debits it with SessionDeductionService::deductOnAttendance.
     */
    public static function payableSignIn(ClassSession $cs, StudentClass $sc, string $status, ?int $teacherId, ?int $recordedBy, string $memo): ?StudentSignIn
    {
        // #4937: production has no unique index per lesson, so concurrent callers (calendar status, evaluation approval)
        // are serialised on the lesson row; the second one then sees the first one's sign-in.
        return DB::transaction(function () use ($cs, $sc, $status, $teacherId, $recordedBy, $memo) {
            ClassSession::whereKey($cs->id)->lockForUpdate()->first();
            $live = StudentSignIn::where('ClassSessionID', $cs->id)->active()->orderBy('id')->lockForUpdate()->get(); // locking read: current rows, not the outer transaction's REPEATABLE READ snapshot
            if ($live->isNotEmpty()) {
                return $live->first(fn (StudentSignIn $s) => in_array(strtolower((string) $s->Status), AttendanceStatus::deductibleCodes(), true));
            }

            $fields = [
                'StudentClassID'  => $sc->ID,
                'StudentID'       => $sc->StudentID,
                'TeacherID'       => $teacherId,
                'RecordedByUserID' => $recordedBy ?: null,
                'GradeID'         => $sc->GradeID,
                'SubjectID'       => $sc->SubjectID,
                'Get1byID'        => $sc->by1,
                'SignInDT'        => Carbon::parse($cs->SessionDate . ' ' . ($cs->StartTime ?? '00:00')),
                'SignOutDT'       => Carbon::parse($cs->SessionDate . ' ' . ($cs->EndTime ?? '23:59')),
                'MDT'             => now(),
                'ClassSessionID'  => $cs->id,
                'Status'          => $status,
                'CampusID'        => Student::find($sc->StudentID)->CampusID ?? null,
                'PersonType'      => 'student',
                'SessionDeducted' => false,
                'Memo'            => $memo,
            ];

            // Test/staging carry a unique index on ClassSessionID (production does not): reuse the latest voided row
            // instead of inserting a second one, so 已上→未上→已上 never hits duplicate key 1062. The lesson may have moved
            // since the void, so the row takes the lesson's current values (times, course, student, campus).
            $voided = StudentSignIn::where('ClassSessionID', $cs->id)->voided()->orderByDesc('id')->lockForUpdate()->first();
            if ($voided) {
                $voided->update($fields + ['VoidedAt' => null, 'VoidedByUserID' => null, 'VoidReason' => null]);
                return $voided;
            }

            return StudentSignIn::create($fields);
        });
    }

    /** An active, deducted sign-in: the lesson is recorded as taught for attendance, usage and part-time payroll. */
    public static function hasDeductedSignIn(int $classSessionId): bool
    {
        return StudentSignIn::where('ClassSessionID', $classSessionId)->active()->where('SessionDeducted', true)->exists();
    }

    /**
     * rollback 核准時沖回由核准驅動的扣堂（不影響獨立點名）。
     */
    public static function syncOnRollback(LearningRecord $lr, StudentClass $sc, int $rolledBackByUserId): void
    {
        $classSessionId = (int) ($lr->ClassSessionID ?? 0);
        if ($classSessionId <= 0 || $sc->isTerminalMoneyClosure()) {
            return; // a waived or refunded contract's usage is frozen
        }

        $approvalSignIn = StudentSignIn::where('ClassSessionID', $classSessionId)
            ->active()
            ->where('Memo', 'lr_approve')
            ->where('SessionDeducted', true)
            ->first();

        if (!$approvalSignIn) {
            return;
        }

        $approvalSignIn->update([
            'VoidedAt'       => now(),
            'VoidedByUserID' => $rolledBackByUserId ?: null,
            'VoidReason'     => '評量核准退回',
        ]);

        SessionDeductionService::reverseForSession(
            $sc->ID,
            $classSessionId,
            'status_adjust',
            $rolledBackByUserId ?: null,
            '評量退回沖回'
        );

        $otherDeducted = StudentSignIn::where('ClassSessionID', $classSessionId)
            ->active()
            ->where('SessionDeducted', true)
            ->exists();

        if (!$otherDeducted) {
            ClassSession::where('id', $classSessionId)
                ->whereIn('Status', ['attended', 'completed', 'late'])
                ->update(['Status' => 'scheduled']);
        }

        SessionDeductionService::recomputeCounters($sc->ID);
    }

    private static function resolveClassSession(LearningRecord $lr, StudentClass $sc): ?ClassSession
    {
        if ((int) ($lr->ClassSessionID ?? 0) > 0) {
            $cs = ClassSession::find($lr->ClassSessionID);
            if ($cs && (int) $cs->StudentClassID === (int) $sc->ID) {
                return $cs;
            }
        }

        $query = ClassSession::where('StudentClassID', $sc->ID)
            ->whereNotIn('Status', ['leave', 'cancelled']);

        $sessionDate = $lr->SessionDate
            ? substr((string) $lr->SessionDate, 0, 10)
            : null;

        if (!$sessionDate) {
            return null;
        }

        $query->where('SessionDate', $sessionDate);

        $startTime = $lr->StartTime ? substr((string) $lr->StartTime, 0, 5) : null;
        if ($startTime) {
            $matches = (clone $query)
                ->where('StartTime', $startTime)
                ->limit(2)
                ->get();

            // A slot match is safe only when it is unique. Never bind an LR to
            // an arbitrary row when legacy duplicate data makes the identity
            // ambiguous; the caller will leave the record unbound for review.
            return $matches->count() === 1 ? $matches->first() : null;
        }

        $matches = $query->limit(2)->get();

        // Without StartTime, multiple sessions on the same day are equally
        // plausible. The old id-desc fallback silently picked one and could
        // deduct the wrong occurrence.
        return $matches->count() === 1 ? $matches->first() : null;
    }
}
