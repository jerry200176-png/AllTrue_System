<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFID 學生刷卡：一個 transaction 內完成簽到／簽退（含 debounce、重複簽到防呆）與到班扣堂。
 * 扣堂規則在 SessionDeductionService，這裡只決定「何時呼叫」。Controller 只負責驗證分校與組 JSON。
 */
class RecordStudentSwipe
{
    private const DEBOUNCE_SECONDS = 60;

    /**
     * $respond builds the caller's response from the result and runs INSIDE the transaction, so a
     * failure while building it still rolls the swipe back (same as before this was extracted).
     *
     * @param  callable(array{status:int, action:string, record:StudentSignIn, class:?array{id:mixed, teacher_id:mixed}}): mixed  $respond
     */
    public function handle(Student $student, Campus $campus, Carbon $swipeAt, callable $respond): mixed
    {
        $campusId = $campus->id;

        return DB::transaction(function () use ($student, $campusId, $swipeAt, $respond) {
            return $respond($this->record($student, $campusId, $swipeAt));
        });
    }

    /**
     * @return array{status:int, action:string, record:StudentSignIn, class:?array{id:mixed, teacher_id:mixed}}
     */
    private function record(Student $student, int $campusId, Carbon $swipeAt): array
    {
        $today = $swipeAt->toDateString();

        $openRecord = StudentSignIn::where('StudentID', $student->id)
            ->whereDate('SignInDT', $today)
            ->whereNull('SignOutDT')
            ->orderBy('id', 'desc')
            ->first();

        if ($openRecord) {
            // TD-006: debounce — RF bounce 在 60 秒內的重複訊號直接忽略，不自動簽退
            $ageSeconds = Carbon::parse($openRecord->SignInDT)->diffInSeconds($swipeAt);
            if ($ageSeconds <= self::DEBOUNCE_SECONDS) {
                return ['status' => 200, 'action' => 'duplicate_ignored', 'record' => $openRecord, 'class' => null];
            }

            $openRecord->SignOutDT = $swipeAt;
            $openRecord->MDT = $swipeAt;
            $openRecord->save();

            StudentPresenceBackfillService::backfill(
                $student,
                Carbon::parse($openRecord->SignInDT),
                $swipeAt,
                $campusId
            );

            return ['status' => 200, 'action' => 'sign_out', 'record' => $openRecord, 'class' => null];
        }

        [$studentClass, $hours, $classSessionId] = $this->findMatchingClass($student, $swipeAt);

        // TD-007: duplicate sign-in guard — 若同一個 ClassSession 當天已有未作廢的記錄則不重複建立
        if ($classSessionId !== null) {
            $existingSignIn = StudentSignIn::where('StudentID', $student->id)
                ->where('ClassSessionID', $classSessionId)
                ->whereNull('VoidedAt')
                ->first();

            if ($existingSignIn) {
                return ['status' => 200, 'action' => 'duplicate_ignored', 'record' => $existingSignIn, 'class' => null];
            }
        }

        // FR-005: TeacherID fallback — if StudentClass.TeacherID is null but we have
        // a ClassSession, try to get TeacherID from the ClassSession's StudentClass.
        $resolvedTeacherId = $studentClass?->TeacherID;
        if ($resolvedTeacherId === null && $classSessionId !== null) {
            $fallbackSc = ClassSession::find($classSessionId)?->studentClass;
            $resolvedTeacherId = $fallbackSc?->TeacherID ?? null;
            if ($resolvedTeacherId === null) {
                Log::warning('[swipe] TeacherID resolved to null', [
                    'student_id'       => $student->id,
                    'class_session_id' => $classSessionId,
                    'student_class_id' => $studentClass?->ID,
                ]);
            }
        }

        $signIn = StudentSignIn::create([
            'StudentClassID'   => $studentClass?->ID,
            'StudentID'        => $student->id,
            'TeacherID'        => $resolvedTeacherId,
            'RecordedByUserID' => null,
            'GradeID'          => $studentClass?->GradeID,
            'SubjectID'        => $studentClass?->SubjectID,
            'Get1byID'         => $studentClass?->by1,
            'Hours'            => $hours,
            'Memo'             => $studentClass ? 'swipe-rfid' : 'self_study',
            'SignInDT'         => $swipeAt,
            'SignOutDT'        => null,
            'MDT'              => $swipeAt,
            'ClassSessionID'   => $classSessionId,
            'Status'           => 'present',
            'CampusID'         => $campusId,
            'PersonType'       => 'student',
            'SessionDeducted'  => false,
        ]);

        // FR-001/FR-002: Sync ClassSession.Status after successful swipe.
        // Only updates when Status = 'scheduled' (guard prevents overwriting human decisions).
        if ($classSessionId !== null) {
            $classSession = ClassSession::find($classSessionId);
            if ($classSession) {
                $swipeStatus = AttendanceEffectsService::resolveSwipeStatus($classSession, $swipeAt);
                AttendanceEffectsService::applySessionStatus($classSession, $swipeStatus);
            }
        }

        // Deduct session on sign-in (點名成功才扣堂)
        if ($studentClass && !$signIn->SessionDeducted) {
            SessionDeductionService::deductOnAttendance($studentClass, $signIn);
            StudentPresenceBackfillService::alertIfNotDeducted($signIn, $campusId);
        }

        return [
            'status' => 201,
            'action' => 'sign_in',
            'record' => $signIn,
            'class'  => $studentClass ? [
                'id'         => $studentClass->ID,
                'teacher_id' => $studentClass->TeacherID,
            ] : null,
        ];
    }

    /**
     * 依當日 ClassSession 找出最接近刷卡時間的課堂。
     * 無符合的 ClassSession → self_study（不扣堂）。不再依 StudentClass week/time 回退：
     * 沒有真實 ClassSession 就不得標記出席或扣堂（#2809 Founder 2026-09-29）。
     */
    private function findMatchingClass(Student $student, Carbon $swipeAt): array
    {
        $sessions = ClassSession::with('studentClass')
            ->whereDate('SessionDate', $swipeAt->toDateString())
            ->whereNotIn(DB::raw('LOWER(Status)'), array_merge(
                [\App\Support\SessionStatus::CANCELLED],
                \App\Support\SessionStatus::leaveFamily()
            ))
            ->whereHas('studentClass', function ($q) use ($student) {
                $q->where('StudentID', $student->id)->where('Stop', 0);
            })
            ->get();

        if (!$sessions->isEmpty()) {
            // TD-011: 窗口從「距 StartTime ≤ 30 min」擴展為「StartTime-30min ≤ swipeAt ≤ EndTime」
            // 優先選 ongoing sessions（startTime ≤ swipeAt ≤ endTime），再選最近的 upcoming session。
            $ongoingSessions  = [];
            $upcomingSessions = [];

            foreach ($sessions as $session) {
                if (!$session->EndTime) {
                    continue;
                }
                $startTime   = Carbon::parse($session->SessionDate . ' ' . $session->StartTime);
                $endTime     = Carbon::parse($session->SessionDate . ' ' . $session->EndTime);
                $windowStart = $startTime->copy()->subMinutes(30);

                if (!$swipeAt->between($windowStart, $endTime)) {
                    continue;
                }

                if ($swipeAt->greaterThanOrEqualTo($startTime)) {
                    // Ongoing: startTime ≤ swipeAt ≤ endTime
                    $ongoingSessions[] = ['session' => $session, 'start_ts' => $startTime->timestamp];
                } else {
                    // Upcoming: windowStart ≤ swipeAt < startTime
                    $upcomingSessions[] = ['session' => $session, 'start_ts' => $startTime->timestamp];
                }
            }

            // Ongoing 優先：取最近啟動的（最大 startTime = 遲到最少）
            if (!empty($ongoingSessions)) {
                usort($ongoingSessions, fn ($a, $b) => $b['start_ts'] <=> $a['start_ts']);
                $best = $ongoingSessions[0]['session'];
            } elseif (!empty($upcomingSessions)) {
                // 無 ongoing：取最近即將開始的（最小 startTime）
                usort($upcomingSessions, fn ($a, $b) => $a['start_ts'] <=> $b['start_ts']);
                $best = $upcomingSessions[0]['session'];
            } else {
                $best = null;
            }

            if ($best) {
                $sc    = $best->studentClass;
                $hours = $sc->TotalHours ? (int) $sc->TotalHours : null;
                return [$sc, $hours, $best->id];
            }
        }

        return [null, null, null];
    }
}
