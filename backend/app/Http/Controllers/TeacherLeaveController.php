<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SubstituteService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TeacherLeaveController — 老師請假批次代課（PRD 9c058f19）
 *
 * - POST /api/v1/teacher-leaves/preview          預覽受影響堂次
 * - POST /api/v1/teacher-leaves/batch-substitute 批次指派代課（交易）
 */
class TeacherLeaveController extends Controller
{
    private const MAX_RANGE_DAYS = 30;
    private const MAX_SESSIONS = 50;

    public function preview(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'teacher_id' => 'required|integer|exists:User,id',
            'date_start' => 'required|date',
            'date_end' => 'required|date|after_or_equal:date_start',
        ]);

        $start = Carbon::parse($data['date_start'])->toDateString();
        $end = Carbon::parse($data['date_end'])->toDateString();
        $diffDays = Carbon::parse($start)->diffInDays(Carbon::parse($end));
        if ($diffDays > self::MAX_RANGE_DAYS) {
            return response()->json([
                'message' => '請假區間最多 '.self::MAX_RANGE_DAYS.' 天',
                'errors' => ['date_end' => ['區間過長，請分段處理。']],
            ], 422);
        }

        $managedCampusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
        $teacherId = (int) $data['teacher_id'];

        $svc = app(SubstituteService::class);
        if (!$svc->teacherBoundToAny($teacherId, $managedCampusIds)) {
            return response()->json(['message' => '該老師不在您管理的分校'], 403);
        }

        // 取得該老師在區間內所有 ClassSession（正班 StudentClass.TeacherID = $teacherId）
        $rowsQuery = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'cs.StudentClassID', '=', 'sc.ID')
            ->leftJoin('Student as st', 'sc.StudentID', '=', 'st.id')
            ->leftJoin('Subject as sb', 'sc.SubjectID', '=', 'sb.id')
            ->where('sc.TeacherID', $teacherId)
            ->whereDate('cs.SessionDate', '>=', $start)
            ->whereDate('cs.SessionDate', '<=', $end)
            ->whereNotIn('cs.Status', ['cancelled', 'leave'])
            ->select(
                'cs.id as class_session_id',
                'cs.SessionDate as session_date',
                'cs.StartTime as start_time',
                'cs.EndTime as end_time',
                'cs.Status as status',
                'sc.ID as student_class_id',
                'sc.StudentID as student_id',
                'sc.SubjectID as subject_id',
                'sc.ClassType as class_type',
                'st.Name as student_name',
                'st.CampusID as campus_id',
                'sb.Subject_Name as subject_name'
            )
            ->orderBy('cs.SessionDate')
            ->orderBy('cs.StartTime')
            ->limit(self::MAX_SESSIONS + 1);

        $rows = $rowsQuery->get();
        $truncated = $rows->count() > self::MAX_SESSIONS;
        $rows = $rows->take(self::MAX_SESSIONS);

        $affected = $rows->filter(function ($r) use ($managedCampusIds, $role) {
            if ($role === 'super_admin') {
                return true;
            }
            $cid = (int) ($r->campus_id ?? 0);

            return $cid > 0 && in_array($cid, $managedCampusIds, true);
        })->map(static fn ($r) => [
            'class_session_id' => (int) $r->class_session_id,
            'student_class_id' => (int) $r->student_class_id,
            'student_id' => (int) $r->student_id,
            'student_name' => (string) ($r->student_name ?? ''),
            'campus_id' => (int) ($r->campus_id ?? 0),
            'subject_id' => $r->subject_id ? (int) $r->subject_id : null,
            'subject_name' => (string) ($r->subject_name ?? ''),
            'session_date' => substr((string) $r->session_date, 0, 10),
            'start_time' => substr((string) $r->start_time, 0, 5),
            'end_time' => substr((string) $r->end_time, 0, 5),
            'class_type' => (string) ($r->class_type ?? 'one_on_one'),
            'status' => (string) ($r->status ?? 'scheduled'),
        ])->values()->all();

        return response()->json([
            'teacher_id' => $teacherId,
            'date_start' => $start,
            'date_end' => $end,
            'sessions' => $affected,
            'total' => count($affected),
            'truncated' => $truncated,
            'max_sessions' => self::MAX_SESSIONS,
        ]);
    }

    public function batchSubstitute(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'assignments' => 'required|array|min:1|max:'.self::MAX_SESSIONS,
            'assignments.*.class_session_id' => 'required|integer|exists:ClassSession,id',
            'assignments.*.substitute_teacher_id' => 'required|integer|exists:User,id',
            'assignments.*.reason' => 'nullable|string|max:255',
            'atomic' => 'nullable|boolean',
        ]);
        $assignments = $data['assignments'];
        // PRD Edge Case Open Question：預設「整批回滾」以優先保證一致性
        $atomic = filter_var($request->input('atomic', true), FILTER_VALIDATE_BOOLEAN);

        $managedCampusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
        $svc = app(SubstituteService::class);

        // 預檢查：所有代課老師需綁定任一管理分校 + 跨分校衝堂（物理）
        $prechecks = [];
        foreach ($assignments as $idx => $a) {
            $sessionId = (int) $a['class_session_id'];
            $teacherId = (int) $a['substitute_teacher_id'];
            $check = $this->precheckAssignment($sessionId, $teacherId, $managedCampusIds, $svc);
            $prechecks[$idx] = $check;
        }

        $hasBlocking = collect($prechecks)->contains(fn ($c) => !$c['ok']);
        if ($hasBlocking && $atomic) {
            return response()->json([
                'message' => '有堂次無法指派（跨分校衝堂或授權不符），已取消整批',
                'results' => array_map(static fn ($c, $i) => [
                    'index' => $i,
                    'ok' => $c['ok'],
                    'error' => $c['error'],
                    'class_session_id' => $c['class_session_id'] ?? null,
                ], $prechecks, array_keys($prechecks)),
            ], 422);
        }

        $results = [];
        $operator = $request->attributes->get('auth_user');
        $operatorId = (int) ($operator->id ?? 0);

        try {
            $results = DB::transaction(function () use ($assignments, $prechecks, $request, $operatorId, $svc) {
                $out = [];
                foreach ($assignments as $idx => $a) {
                    $pc = $prechecks[$idx];
                    $sessionId = (int) $a['class_session_id'];
                    if (!$pc['ok']) {
                        $out[] = [
                            'index' => $idx,
                            'class_session_id' => $sessionId,
                            'ok' => false,
                            'error' => $pc['error'],
                        ];
                        continue;
                    }
                    try {
                        $inner = $this->performSingleSubstitute(
                            $request,
                            $sessionId,
                            (int) $a['substitute_teacher_id'],
                            (string) ($a['reason'] ?? '老師請假批次代課'),
                            $operatorId,
                            $svc
                        );
                        $out[] = [
                            'index' => $idx,
                            'class_session_id' => $sessionId,
                            'ok' => true,
                            'substitute_teacher_id' => $inner['substitute_teacher_id'],
                            'notification_id' => $inner['notification_id'],
                            'cross_campus' => $inner['cross_campus'],
                        ];
                    } catch (\Throwable $e) {
                        $out[] = [
                            'index' => $idx,
                            'class_session_id' => $sessionId,
                            'ok' => false,
                            'error' => $e->getMessage(),
                        ];
                        // atomic 時拋出會觸發整批回滾
                        throw $e;
                    }
                }

                return $out;
            });
        } catch (\Throwable $e) {
            Log::error('[teacher_leave_batch] rolled_back', [
                'operator_id' => $operatorId,
                'message' => $e->getMessage(),
                'count' => count($assignments),
            ]);

            return response()->json([
                'message' => '批次代課失敗，整批已回滾：'.$e->getMessage(),
                'rolled_back' => true,
            ], 500);
        }

        $successCount = collect($results)->where('ok', true)->count();
        $failCount = count($results) - $successCount;
        $crossCampusCount = collect($results)->where('cross_campus', true)->count();

        Log::info('[teacher_leave_batch]', [
            'operator_id' => $operatorId,
            'total' => count($results),
            'success' => $successCount,
            'fail' => $failCount,
            'cross_campus' => $crossCampusCount,
        ]);

        return response()->json([
            'message' => $failCount === 0 ? '批次代課完成' : '批次代課完成，部分堂次需處理',
            'summary' => [
                'total' => count($results),
                'success' => $successCount,
                'fail' => $failCount,
                'cross_campus' => $crossCampusCount,
            ],
            'results' => $results,
        ]);
    }

    /**
     * @return array{ok:bool, error:?string, class_session_id:int}
     */
    private function precheckAssignment(int $classSessionId, int $teacherId, array $managedCampusIds, SubstituteService $svc): array
    {
        $session = ClassSession::find($classSessionId);
        if (!$session) {
            return ['ok' => false, 'error' => '找不到堂次', 'class_session_id' => $classSessionId];
        }
        $studentClass = StudentClass::find($session->StudentClassID);
        if (!$studentClass) {
            return ['ok' => false, 'error' => '找不到課程', 'class_session_id' => $classSessionId];
        }
        $student = Student::find($studentClass->StudentID);
        if (!$student) {
            return ['ok' => false, 'error' => '找不到學生', 'class_session_id' => $classSessionId];
        }
        $campusId = (int) ($student->CampusID ?? 0);
        if (!empty($managedCampusIds) && !in_array($campusId, $managedCampusIds, true)) {
            return ['ok' => false, 'error' => '此堂次不在您管理分校', 'class_session_id' => $classSessionId];
        }
        if (!$svc->teacherBoundToAny($teacherId, $managedCampusIds)) {
            return ['ok' => false, 'error' => '代課老師未綁定任一您管理的分校', 'class_session_id' => $classSessionId];
        }
        if ((int) $studentClass->TeacherID === $teacherId) {
            return ['ok' => false, 'error' => '代課老師與正班老師相同', 'class_session_id' => $classSessionId];
        }

        try {
            $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '日期格式無效', 'class_session_id' => $classSessionId];
        }
        $startTime = $this->hhmm($session->StartTime);
        $endTime = $this->hhmm($session->EndTime);

        $conflicts = $svc->detectCrossCampusConflict($teacherId, $sessionDate, $startTime, $endTime);
        if (!empty($conflicts)) {
            return [
                'ok' => false,
                'error' => '該老師於此時段於其他分校另有課程',
                'class_session_id' => $classSessionId,
            ];
        }

        return ['ok' => true, 'error' => null, 'class_session_id' => $classSessionId];
    }

    /**
     * 直接重用既有 ClassSessionController::substitute 的邏輯（透過 HTTP 轉呼會切斷交易，改用直接 controller 呼叫）。
     * 實作上簡化：直接呼叫現行 substitute 流程以重用驗證、寫 schedules、寫 LearningRecord、寫通知。
     *
     * @return array{substitute_teacher_id:int, notification_id:?int, cross_campus:bool}
     */
    private function performSingleSubstitute(
        Request $request,
        int $classSessionId,
        int $teacherId,
        string $reason,
        int $operatorId,
        SubstituteService $svc
    ): array {
        $sub = app(ClassSessionController::class);
        $inner = $request->duplicate();
        $inner->setMethod('POST');
        $inner->merge([
            'substitute_teacher_id' => $teacherId,
            'reason' => $reason,
        ]);
        $response = $sub->substitute($inner, $classSessionId);
        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getContent(), true) ?: [];
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException($json['message'] ?? '代課失敗');
        }

        return [
            'substitute_teacher_id' => (int) ($json['substitute_teacher_id'] ?? $teacherId),
            'notification_id' => $json['notification_id'] ?? null,
            'cross_campus' => (bool) ($json['cross_campus'] ?? false),
        ];
    }

    private function hhmm($value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('H:i');
        }
        $s = trim((string) $value);
        if ($s === '') {
            return '';
        }
        if (preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?/', $s, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $s;
    }
}
