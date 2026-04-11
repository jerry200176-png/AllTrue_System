<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\Carbon;

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

        $alreadyDeducted = StudentSignIn::where('ClassSessionID', $cs->id)
            ->active()
            ->where('SessionDeducted', true)
            ->exists();
        if ($alreadyDeducted) {
            if (strtolower(trim((string) $cs->Status)) === 'scheduled') {
                $cs->Status = 'attended';
                $cs->save();
            }
            return;
        }

        $student = Student::find($sc->StudentID);

        $signIn = StudentSignIn::create([
            'StudentClassID'  => $sc->ID,
            'StudentID'       => $sc->StudentID,
            'TeacherID'       => $lr->TeacherID ?? $sc->TeacherID,
            'RecordedByUserID' => $approvedByUserId ?: null,
            'GradeID'         => $sc->GradeID,
            'SubjectID'       => $sc->SubjectID,
            'Get1byID'        => $sc->by1,
            'SignInDT'        => Carbon::parse($cs->SessionDate . ' ' . ($cs->StartTime ?? '00:00')),
            'SignOutDT'       => Carbon::parse($cs->SessionDate . ' ' . ($cs->EndTime ?? '23:59')),
            'MDT'             => now(),
            'ClassSessionID'  => $cs->id,
            'Status'          => 'present',
            'CampusID'        => $student->CampusID ?? null,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
            'Memo'            => 'lr_approve',
        ]);

        $cs->Status = 'attended';
        $cs->save();

        SessionDeductionService::deductOnAttendance($sc, $signIn);
    }

    /**
     * rollback 核准時沖回由核准驅動的扣堂（不影響獨立點名）。
     */
    public static function syncOnRollback(LearningRecord $lr, StudentClass $sc, int $rolledBackByUserId): void
    {
        $classSessionId = (int) ($lr->ClassSessionID ?? 0);
        if ($classSessionId <= 0) {
            return;
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
            $exact = (clone $query)->where('StartTime', $startTime)->orderBy('id', 'desc')->first();
            if ($exact) {
                return $exact;
            }
        }

        return $query->orderBy('id', 'desc')->first();
    }
}
