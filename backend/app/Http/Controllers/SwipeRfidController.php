<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\TempRfid;
use App\Models\StudentClass;
use App\Models\StudentLineBinding;
use App\Models\StudentSignIn;
use App\Models\TeacherSignIn;
use App\Models\User;
use App\Services\AttendanceEffectsService;
use App\Services\SessionDeductionService;
use App\Services\StudentPresenceBackfillService;
use App\Services\TeacherAttendanceMonth;
use App\Services\TeacherClassCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RFID 刷卡 API
 * 接收分校代碼、RFID，判斷為學生或老師並建立刷卡紀錄
 */
class SwipeRfidController extends Controller
{
    /**
     * POST /api/v1/swipe-rfid
     * Body: { branch_code: "daan", rfid: "xxx" }
     */
    public function swipe(Request $request)
    {
        try {
            $data = $request->validate([
                'branch_code' => 'required|string|max:32',
                'rfid'        => 'required|string|max:32',
            ]);

            $branchCode = trim($data['branch_code']);
            $rfid = trim($data['rfid']);
            $swipeAt = now();

            $campus = null;
            if (is_numeric($branchCode)) {
                $campus = Campus::find($branchCode);
            } else {
                $campus = Campus::where('code', $branchCode)->first();
                if (!$campus) {
                    $campus = Campus::where('name', 'like', "%{$branchCode}%")->first();
                }
            }
            if (!$campus) {
                return response()->json([
                    'ok'     => false,
                    'error'  => 'branch_not_found',
                    'message' => '分校代碼不存在',
                ], 404);
            }

            $authHeader = $request->header('Authorization');
            $bearerToken = null;
            if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
                $bearerToken = trim($m[1]);
            }
            if (!$bearerToken || $bearerToken !== ($campus->Token ?? '')) {
                return response()->json([
                    'ok'     => false,
                    'error'  => 'unauthorized',
                    'message' => 'Authorization Bearer Token 無效或未提供',
                ], 401);
            }

            $campusId = $campus->id;

            // 優先：UserCampus 每分校 RFID。若同一張卡誤綁到學生，老師本人打卡
            // 仍應進 TeacherSingIn，避免靜默寫成學生出勤而在老師打卡列表消失。
            $teacher = null;
            $teacherInCampus = false;
            if (Schema::hasColumn('UserCampus', 'RFID')) {
                $uc = DB::table('UserCampus')
                    ->where('CampusID', $campusId)
                    ->where('RFID', $rfid)
                    ->whereNotNull('RFID')
                    ->where('RFID', '!=', '')
                    ->first();
                if ($uc) {
                    $teacher = User::where('id', (int) $uc->UserID)
                        ->where('type', 'T')
                        ->where(function ($query) {
                            $query->whereNull('status')->orWhere('status', 'active');
                        })
                        ->first();
                    $teacherInCampus = (bool) $teacher;
                }
            }
            if ($teacherInCampus) {
                return $this->handleTeacherSwipe($teacher, $campus, $swipeAt);
            }

            $student = Student::where('RFID', $rfid)->where('CampusID', $campusId)->where('enable', 1)->first();
            if ($student) {
                return $this->handleStudentSwipe($student, $campus, $swipeAt);
            }

            // 每分校僅一筆：更新 RFID 時必須刷新 created_at，否則效期仍沿用舊時間，
            // TempRfidController 會誤判過期並刪除，導致後台「綁定卡片」顯示暫無刷卡資料。
            TempRfid::updateOrCreate(
                ['CampusID' => $campusId],
                ['RFID' => $rfid, 'created_at' => now()]
            );

            return response()->json([
                'ok'     => false,
                'error'  => 'rfid_not_found',
                'message' => 'RFID 未綁定學生或老師（已暫存，請於 5 分鐘內綁定）',
                'campus' => ['TelegramToken' => $campus->TelegramToken ?? null],
            ], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'error' => 'attendance_not_allowed',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function handleStudentSwipe(Student $student, Campus $campus, Carbon $swipeAt)
    {
        $campusId = $campus->id;
        return DB::transaction(function () use ($student, $campusId, $campus, $swipeAt) {
            $today = $swipeAt->toDateString();

            $openRecord = StudentSignIn::where('StudentID', $student->id)
                ->whereDate('SignInDT', $today)
                ->whereNull('SignOutDT')
                ->orderBy('id', 'desc')
                ->first();

            if ($openRecord) {
                // TD-006: debounce — RF bounce 在 60 秒內的重複訊號直接忽略，不自動簽退
                $ageSeconds = Carbon::parse($openRecord->SignInDT)->diffInSeconds($swipeAt);
                if ($ageSeconds <= self::STUDENT_SWIPE_DEBOUNCE_SECONDS) {
                    return response()->json([
                        'ok'     => true,
                        'type'   => 'student',
                        'action' => 'duplicate_ignored',
                        'record' => $openRecord,
                        'student' => $this->studentPayload($student),
                        'campus' => ['TelegramToken' => $campus->TelegramToken ?? null],
                    ], 200);
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

                return response()->json([
                    'ok'       => true,
                    'type'     => 'student',
                    'action'   => 'sign_out',
                    'record'   => $openRecord,
                    'student'  => $this->studentPayload($student),
                    'campus'   => ['TelegramToken' => $campus->TelegramToken ?? null],
                ], 200);
            }

            [$studentClass, $hours, $classSessionId] = $this->findMatchingClass($student, $swipeAt);

            // TD-007: duplicate sign-in guard — 若同一個 ClassSession 當天已有未作廢的記錄則不重複建立
            if ($classSessionId !== null) {
                $existingSignIn = StudentSignIn::where('StudentID', $student->id)
                    ->where('ClassSessionID', $classSessionId)
                    ->whereNull('VoidedAt')
                    ->first();

                if ($existingSignIn) {
                    return response()->json([
                        'ok'     => true,
                        'type'   => 'student',
                        'action' => 'duplicate_ignored',
                        'record' => $existingSignIn,
                        'student' => $this->studentPayload($student),
                        'campus' => ['TelegramToken' => $campus->TelegramToken ?? null],
                    ], 200);
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

            return response()->json([
                'ok'       => true,
                'type'     => 'student',
                'action'   => 'sign_in',
                'record'   => $signIn,
                'student'  => $this->studentPayload($student),
                'class'    => $studentClass ? [
                    'id'       => $studentClass->ID,
                    'teacher_id' => $studentClass->TeacherID,
                ] : null,
                'campus'   => ['TelegramToken' => $campus->TelegramToken ?? null],
            ], 201);
        });
    }

    /**
     * 刷卡回應的學生資訊。LineIDs = 已驗證綁定的家長 LINE userId，供讀卡機用 LINE Bot 推播。
     */
    private function studentPayload(Student $student): array
    {
        return [
            'id'          => $student->id,
            'name'        => $student->name,
            'TelegramID'  => $student->TelegramID,
            'TelegramID1' => $student->TelegramID1,
            'TelegramID2' => $student->TelegramID2,
            'LineIDs'     => StudentLineBinding::query()->where('student_id', $student->id)
                ->whereNotNull('verified_at')
                ->pluck('line_user_id')
                ->values()
                ->all(),
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

    private const STUDENT_SWIPE_DEBOUNCE_SECONDS = 60;
    private const TEACHER_SWIPE_DEBOUNCE_SECONDS = 60;

    /**
     * @param User $teacher
     */
    private function handleTeacherSwipe(User $teacher, Campus $campus, Carbon $swipeAt)
    {
        $campusId    = $campus->id;
        $today       = $swipeAt->toDateString();
        $teacherName = $teacher->Name ?? '';

        $openRecord = TeacherSignIn::where('TeacherID', $teacher->id)
            ->whereDate('SignInDT', $today)
            ->whereNull('SignOutDT')
            ->orderBy('id', 'desc')
            ->first();

        // 還沒簽退就到別間分校刷卡：前一筆標「跨校自動簽退」（月表算異常讓主任確認），這裡開新的上班
        if ($openRecord && (int) $openRecord->CampusID !== (int) $campusId) {
            $openRecord->SignOutDT = $swipeAt;
            $openRecord->Memo = TeacherAttendanceMonth::CROSS_CAMPUS_MEMO;
            $openRecord->MDT = $swipeAt;
            $openRecord->save();
            $openRecord = null;
        }

        if ($openRecord) {
            // NFR-003: RF bounce debounce — 60 秒內重複訊號直接忽略，不自動簽退
            $ageSeconds = Carbon::parse($openRecord->SignInDT)->diffInSeconds($swipeAt);
            if ($ageSeconds <= self::TEACHER_SWIPE_DEBOUNCE_SECONDS) {
                return response()->json([
                    'ok'     => true,
                    'type'   => 'teacher',
                    'action' => 'duplicate_ignored',
                    'record' => $openRecord,
                    'teacher' => ['id' => $teacher->id, 'name' => $teacherName],
                    'campus' => [
                        'TelegramChatID' => $campus->TelegramChatID ?? null,
                        'TelegramToken'  => $campus->TelegramToken ?? null,
                    ],
                ], 200);
            }

            $openRecord->SignOutDT = $swipeAt;
            $openRecord->MDT = $swipeAt;
            $openRecord->save();

            return response()->json([
                'ok'     => true,
                'type'   => 'teacher',
                'action' => 'sign_out',
                'record' => $openRecord->fresh(),
                'teacher' => ['id' => $teacher->id, 'name' => $teacherName],
                'campus' => [
                    'TelegramChatID' => $campus->TelegramChatID ?? null,
                    'TelegramToken'  => $campus->TelegramToken ?? null,
                ],
            ], 200);
        }

        $status = $this->resolveTeacherSignInStatus($teacher->id, $campusId, $swipeAt);

        $record = TeacherSignIn::create([
            'TeacherID'  => $teacher->id,
            'CampusID'   => $campusId,
            'SignInDT'   => $swipeAt,
            'SignOutDT'  => null,
            'MDT'        => $swipeAt,
            'Source'     => 'rfid',
            'Status'     => $status,
        ]);

        return response()->json([
            'ok'     => true,
            'type'   => 'teacher',
            'action' => 'sign_in',
            'record' => $record,
            'teacher' => ['id' => $teacher->id, 'name' => $teacherName],
            'campus' => [
                'TelegramChatID' => $campus->TelegramChatID ?? null,
                'TelegramToken'  => $campus->TelegramToken ?? null,
            ],
        ], 201);
    }

    /**
     * 計算老師簽到的異常狀態：只比「這間分校」今天第一堂，且只看今天第一次到這間分校（跑校不誤判）。
     * 月表／今日頁會用 TeacherAttendanceMonth 重算；這裡只是刷卡當下的快照。
     * 失敗時 fallback 為 pending_review，不中斷打卡流程。
     */
    private function resolveTeacherSignInStatus(int $teacherId, int $campusId, Carbon $swipeAt): string
    {
        try {
            $today = $swipeAt->toDateString();

            $classes = TeacherClassCalendar::load($today, $today, [$campusId], $teacherId)[$teacherId][$today] ?? [];
            if ($classes === []) {
                return 'source_only';
            }

            $arrivedEarlier = TeacherSignIn::query()->where('TeacherID', $teacherId)
                ->where('CampusID', $campusId)
                ->whereDate('SignInDT', $today)
                ->where('SignInDT', '<', $swipeAt)
                ->exists();
            if ($arrivedEarlier) {
                return 'normal';
            }

            $classStart = Carbon::parse("{$today} " . min(array_column($classes, 'start')));
            $threshold  = $classStart->copy()->addMinutes(TeacherAttendanceMonth::LATE_GRACE_MINUTES);

            return $swipeAt->lte($threshold) ? 'normal' : 'late';
        } catch (\Throwable $e) {
            Log::warning('resolveTeacherSignInStatus failed, fallback to pending_review', [
                'teacher_id' => $teacherId,
                'swipe_at'   => $swipeAt->toIso8601String(),
                'error'      => $e->getMessage(),
            ]);
            return 'pending_review';
        }
    }
}
