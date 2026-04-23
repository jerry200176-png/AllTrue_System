<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\TempRfid;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\Teacher;
use App\Models\TeacherSignIn;
use App\Models\UserCampus;
use App\Services\SessionDeductionService;
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

            $student = Student::where('RFID', $rfid)->where('CampusID', $campusId)->where('enable', 1)->first();
            if ($student) {
                return $this->handleStudentSwipe($student, $campus, $swipeAt);
            }

            // 優先：UserCampus 每分校 RFID；備援：舊版 Teacher.RFID（單一欄位）
            $teacher = null;
            $teacherInCampus = false;
            $matchedUserCampus = false;
            if (Schema::hasColumn('UserCampus', 'RFID')) {
                $uc = DB::table('UserCampus')
                    ->where('CampusID', $campusId)
                    ->where('RFID', $rfid)
                    ->whereNotNull('RFID')
                    ->where('RFID', '!=', '')
                    ->first();
                if ($uc) {
                    $teacher = Teacher::where('id', (int) $uc->UserID)->where('Enable', 1)->first();
                    $teacherInCampus = (bool) $teacher;
                }
            }
            if (!$teacher) {
                $teacher = Teacher::where('RFID', $rfid)->where('Enable', 1)->first();
                if ($teacher) {
                    $matchedUserCampus = UserCampus::where('UserID', $teacher->id)
                        ->where('CampusID', $campusId)
                        ->exists();
                    $teacherInCampus = (int) $teacher->CampusID === (int) $campusId || $matchedUserCampus;
                }
            }
            if ($teacherInCampus) {
                return $this->handleTeacherSwipe($teacher, $campus, $swipeAt);
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
                        'student' => [
                            'id'          => $student->id,
                            'name'        => $student->name,
                            'TelegramID'  => $student->TelegramID,
                            'TelegramID1' => $student->TelegramID1,
                            'TelegramID2' => $student->TelegramID2,
                        ],
                        'campus' => ['TelegramToken' => $campus->TelegramToken ?? null],
                    ], 200);
                }

                $openRecord->SignOutDT = $swipeAt;
                $openRecord->MDT = $swipeAt;
                $openRecord->save();

                $this->backfillPresenceWindow(
                    $student,
                    Carbon::parse($openRecord->SignInDT),
                    $swipeAt,
                    $campus
                );

                return response()->json([
                    'ok'       => true,
                    'type'     => 'student',
                    'action'   => 'sign_out',
                    'record'   => $openRecord,
                    'student'  => [
                        'id' => $student->id,
                        'name' => $student->name,
                        'TelegramID' => $student->TelegramID,
                        'TelegramID1' => $student->TelegramID1,
                        'TelegramID2' => $student->TelegramID2,
                    ],
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
                        'student' => [
                            'id'          => $student->id,
                            'name'        => $student->name,
                            'TelegramID'  => $student->TelegramID,
                            'TelegramID1' => $student->TelegramID1,
                            'TelegramID2' => $student->TelegramID2,
                        ],
                        'campus' => ['TelegramToken' => $campus->TelegramToken ?? null],
                    ], 200);
                }
            }

            $signIn = StudentSignIn::create([
                'StudentClassID'  => $studentClass?->ID,
                'StudentID'        => $student->id,
                'TeacherID'       => $studentClass?->TeacherID,
                'RecordedByUserID' => null,
                'GradeID'         => $studentClass?->GradeID,
                'SubjectID'      => $studentClass?->SubjectID,
                'Get1byID'       => $studentClass?->by1,
                'Hours'          => $hours,
                'Memo'           => $studentClass ? 'swipe-rfid' : 'self_study',
                'SignInDT'       => $swipeAt,
                'SignOutDT'      => null,
                'MDT'            => $swipeAt,
                'ClassSessionID' => $classSessionId,
                'Status'         => 'present',
                'CampusID'       => $campusId,
                'PersonType'     => 'student',
                'SessionDeducted' => false,
            ]);

            // Deduct session on sign-in (點名成功才扣堂)
            if ($studentClass && !$signIn->SessionDeducted) {
                SessionDeductionService::deductOnAttendance($studentClass, $signIn);
            }

            return response()->json([
                'ok'       => true,
                'type'     => 'student',
                'action'   => 'sign_in',
                'record'   => $signIn,
                'student'  => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'TelegramID' => $student->TelegramID,
                    'TelegramID1' => $student->TelegramID1,
                    'TelegramID2' => $student->TelegramID2,
                ],
                'class'    => $studentClass ? [
                    'id'       => $studentClass->ID,
                    'teacher_id' => $studentClass->TeacherID,
                ] : null,
                'campus'   => ['TelegramToken' => $campus->TelegramToken ?? null],
            ], 201);
        });
    }

    /**
     * 依當日 ClassSession 找出最接近刷卡時間的課堂
     * 若無符合的 ClassSession，則依 StudentClass 的 week/time 比對（排課模式）
     */
    private function findMatchingClass(Student $student, Carbon $swipeAt): array
    {
        $sessions = ClassSession::with('studentClass')
            ->whereDate('SessionDate', $swipeAt->toDateString())
            ->where('Status', '!=', 'leave')
            ->whereHas('studentClass', function ($q) use ($student) {
                $q->where('StudentID', $student->id)->where('Stop', 0);
            })
            ->get();

        if (!$sessions->isEmpty()) {
            $windowMinutes = 30;
            $closestSession = null;
            $closestDiff = null;

            foreach ($sessions as $session) {
                $startTime = Carbon::parse($session->SessionDate . ' ' . $session->StartTime);
                $diff = abs($startTime->diffInMinutes($swipeAt));
                if ($diff <= $windowMinutes && ($closestDiff === null || $diff < $closestDiff)) {
                    $closestSession = $session;
                    $closestDiff = $diff;
                }
            }

            if ($closestSession) {
                $sc = $closestSession->studentClass;
                $hours = $sc->TotalHours ? (int) $sc->TotalHours : null;
                return [$sc, $hours, $closestSession->id];
            }
        }

        $classes = StudentClass::where('StudentID', $student->id)
            ->where('Stop', 0)
            ->where('StartDate', '<=', $swipeAt)
            ->where(function ($q) use ($swipeAt) {
                $q->whereNull('EndDate')->orWhere('EndDate', '>=', $swipeAt);
            })
            ->get();

        $dow = $swipeAt->dayOfWeek;
        $windowMinutes = 30;

        foreach ($classes as $sc) {
            for ($w = 1; $w <= 6; $w++) {
                $weekCol = "week{$w}";
                $timeCol = "time{$w}";
                $weekVal = $sc->$weekCol ?? null;
                $timeVal = $sc->$timeCol ?? null;
                if ($weekVal !== null && (int) $weekVal === (int) $dow && $timeVal) {
                    try {
                        $start = Carbon::parse($timeVal);
                        $swipeTime = $swipeAt->format('H:i');
                        $startTime = $start->format('H:i');
                        $diff = abs(Carbon::parse($swipeTime)->diffInMinutes(Carbon::parse($startTime)));
                        if ($diff <= $windowMinutes) {
                            $hours = $sc->TotalHours ? (int) $sc->TotalHours : null;
                            return [$sc, $hours, null];
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        }

        return [null, null, null];
    }

    /**
     * Presence Window：刷退時回溯在場時段，對缺漏的 ClassSession 補建 StudentSignIn 並扣堂。
     * 幂等保護：已有 active StudentSignIn（含老師手動建立）的 ClassSession 會被 whereDoesntHave 排除。
     */
    private function backfillPresenceWindow(
        Student $student,
        Carbon  $signInDT,
        Carbon  $signOutDT,
        Campus  $campus
    ): void {
        $today       = $signInDT->toDateString();
        $signInTime  = $signInDT->format('H:i:s');
        $signOutTime = $signOutDT->format('H:i:s');

        $sessions = ClassSession::query()
            ->with('studentClass')
            ->whereHas('studentClass', fn ($q) => $q
                ->where('StudentID', $student->id)
                ->where('Stop', 0)
            )
            ->whereDate('SessionDate', $today)
            ->whereTime('StartTime', '>=', $signInTime)
            ->whereTime('StartTime', '<=', $signOutTime)
            ->whereDoesntHave('signIns', fn ($q) => $q->whereNull('VoidedAt'))
            ->get();

        foreach ($sessions as $session) {
            $sc = $session->studentClass;
            if (!$sc) {
                continue;
            }

            // TD-009: 防禦 EndTime=null（DB 有 NOT NULL 但舊資料可能例外），跳過避免 SignOutDT=00:00
            if (!$session->EndTime) {
                Log::warning('presence_window_skip_null_end_time', [
                    'class_session_id' => $session->id,
                    'student_id'       => $student->id,
                ]);
                continue;
            }

            $sessionSignInDT  = Carbon::parse($today . ' ' . $session->StartTime);
            $sessionSignOutDT = Carbon::parse($today . ' ' . $session->EndTime);

            $newSignIn = StudentSignIn::create([
                'StudentClassID'   => $sc->ID,
                'StudentID'         => $student->id,
                'TeacherID'        => $sc->TeacherID,
                'RecordedByUserID' => null,
                'GradeID'          => $sc->GradeID,
                'SubjectID'        => $sc->SubjectID,
                'Get1byID'         => $sc->by1,
                'Hours'            => $sc->TotalHours ? (int) $sc->TotalHours : null,
                'Memo'             => 'presence-window',
                'SignInDT'         => $sessionSignInDT,
                'SignOutDT'        => $sessionSignOutDT,
                'MDT'              => now(),
                'ClassSessionID'   => $session->id,
                'Status'           => 'present',
                'CampusID'         => $campus->id,
                'PersonType'       => 'student',
                'SessionDeducted'  => false,
            ]);

            SessionDeductionService::deductOnAttendance($sc, $newSignIn);

            Log::info('presence_window_backfill', [
                'student_id'       => $student->id,
                'student_name'     => $student->name,
                'class_session_id' => $session->id,
                'sign_in_dt'       => $sessionSignInDT->toDateTimeString(),
                'sign_out_dt'      => $sessionSignOutDT->toDateTimeString(),
            ]);
        }
    }

    private const STUDENT_SWIPE_DEBOUNCE_SECONDS = 60;
    private const TEACHER_SWIPE_DEBOUNCE_SECONDS = 60;

    private function handleTeacherSwipe(Teacher $teacher, Campus $campus, Carbon $swipeAt)
    {
        $campusId = $campus->id;
        $today = $swipeAt->toDateString();

        $openRecord = TeacherSignIn::where('TeacherID', $teacher->id)
            ->whereDate('SignInDT', $today)
            ->whereNull('SignOutDT')
            ->orderBy('id', 'desc')
            ->first();

        if ($openRecord) {
            // NFR-003: RF bounce debounce — 60 秒內重複訊號直接忽略，不自動簽退
            $ageSeconds = Carbon::parse($openRecord->SignInDT)->diffInSeconds($swipeAt);
            if ($ageSeconds <= self::TEACHER_SWIPE_DEBOUNCE_SECONDS) {
                return response()->json([
                    'ok'     => true,
                    'type'   => 'teacher',
                    'action' => 'duplicate_ignored',
                    'record' => $openRecord,
                    'teacher' => ['id' => $teacher->id, 'name' => $teacher->T_Name],
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
                'teacher' => ['id' => $teacher->id, 'name' => $teacher->T_Name],
                'campus' => [
                    'TelegramChatID' => $campus->TelegramChatID ?? null,
                    'TelegramToken'  => $campus->TelegramToken ?? null,
                ],
            ], 200);
        }

        $status = $this->resolveTeacherSignInStatus($teacher->id, $swipeAt);

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
            'teacher' => ['id' => $teacher->id, 'name' => $teacher->T_Name],
            'campus' => [
                'TelegramChatID' => $campus->TelegramChatID ?? null,
                'TelegramToken'  => $campus->TelegramToken ?? null,
            ],
        ], 201);
    }

    /**
     * 計算老師簽到的異常狀態。
     * 失敗時 fallback 為 pending_review，不中斷打卡流程。
     */
    private function resolveTeacherSignInStatus(int $teacherId, Carbon $swipeAt): string
    {
        try {
            $today = $swipeAt->toDateString();

            $firstClass = DB::table('schedules')
                ->where('teacher_id', $teacherId)
                ->where('schedule_date', $today)
                ->where('status', '!=', 'cancelled')
                ->orderBy('start_time')
                ->first();

            if (! $firstClass) {
                return 'source_only';
            }

            $classStart = Carbon::parse("{$today} {$firstClass->start_time}");
            $threshold  = $classStart->copy()->addMinutes(10);

            return $swipeAt->lte($threshold) ? 'normal' : 'late';
        } catch (\Throwable $e) {
            return 'pending_review';
        }
    }
}
