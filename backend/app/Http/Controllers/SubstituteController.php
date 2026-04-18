<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\LearningRecordTeacherChange;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SubstituteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * SubstituteController — 代課流程 UX 優化（PRD 9c058f19）
 *
 * - GET  /api/v1/teachers/{id}/availability            跨分校忙碌時段
 * - POST /api/v1/class-sessions/{id}/substitute/undo   時間窗內撤銷代課
 * - GET  /api/v1/substitutes/recent                    近 7 天代課記錄（主任儀表板）
 */
class SubstituteController extends Controller
{
    /**
     * Undo 時間窗設計（業界 Gmail Undo Send 模式）：
     *  - UI countdown = SystemSetting.substitute.undo_window_seconds（預設 5 秒）
     *  - Server grace = UI + SERVER_GRACE_SECONDS，容忍網路/時鐘偏差
     *  - 允許值：5 / 10 / 20 / 30
     */
    public const ALLOWED_UNDO_WINDOWS = [5, 10, 20, 30];
    public const DEFAULT_UNDO_WINDOW_SECONDS = 5;
    public const SERVER_GRACE_SECONDS = 60;
    public const SETTING_KEY = 'substitute.undo_window_seconds';

    public static function resolveUiUndoWindow(): int
    {
        $raw = (int) SystemSetting::get(self::SETTING_KEY, self::DEFAULT_UNDO_WINDOW_SECONDS);
        return in_array($raw, self::ALLOWED_UNDO_WINDOWS, true) ? $raw : self::DEFAULT_UNDO_WINDOW_SECONDS;
    }

    public static function resolveServerUndoWindow(): int
    {
        return self::resolveUiUndoWindow() + self::SERVER_GRACE_SECONDS;
    }

    public function availability(Request $request, int $teacherId)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin', 'teacher_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'date' => 'required|date',
        ]);

        $managedCampusIds = $role === 'super_admin'
            ? []
            : array_map('intval', $request->attributes->get('auth_campus_ids', []));

        // 授權：對象老師必須綁定任一 operator 管理分校
        if (!empty($managedCampusIds)) {
            $bound = UserCampus::where('UserID', $teacherId)
                ->whereIn('CampusID', $managedCampusIds)
                ->exists();
            if (!$bound) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $svc = app(SubstituteService::class);
        $busy = $svc->collectTeacherBusySlots($teacherId, $data['date']);

        // PRD 第 9 節 Info Disclosure：只回 {start_time, end_time, campus_id}
        $response = array_map(static fn ($s) => [
            'start_time' => $s['start_time'],
            'end_time' => $s['end_time'],
            'campus_id' => (int) $s['campus_id'],
        ], $busy);

        return response()->json([
            'teacher_id' => $teacherId,
            'date' => Carbon::parse($data['date'])->toDateString(),
            'busy_slots' => $response,
        ]);
    }

    public function undo(Request $request, int $classSessionId)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $session = ClassSession::find($classSessionId);
        if (!$session) {
            return response()->json(['message' => '找不到該堂次'], 404);
        }

        $studentClass = StudentClass::find($session->StudentClassID);
        if (!$studentClass) {
            return response()->json(['message' => '找不到對應課程'], 404);
        }

        $student = Student::find($studentClass->StudentID);
        if (!$student) {
            return response()->json(['message' => '找不到學生資料'], 422);
        }

        $campusId = (int) ($student->CampusID ?? 0);
        $managedCampusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
        if (!empty($managedCampusIds) && !in_array($campusId, $managedCampusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
        } catch (\Throwable $e) {
            return response()->json(['message' => '堂次日期格式無效'], 422);
        }

        // 找到最近一次的代課通知（作為時間窗依據）
        $lastNotification = Notification::where('Type', 'substitute')
            ->where('SourceType', 'ClassSession')
            ->where('SourceID', $classSessionId)
            ->orderBy('id', 'desc')
            ->first();
        if (!$lastNotification) {
            return response()->json(['message' => '找不到可撤銷的代課記錄'], 404);
        }
        if ($lastNotification->ResolvedAt) {
            return response()->json(['message' => '此代課已被撤銷'], 409);
        }

        $createdAt = $lastNotification->created_at ? Carbon::parse($lastNotification->created_at) : null;
        $serverWindow = self::resolveServerUndoWindow();
        if ($createdAt && $createdAt->diffInSeconds(now()) > $serverWindow) {
            return response()->json([
                'message' => '已超過可撤銷時間窗',
                'server_window_seconds' => $serverWindow,
            ], 410);
        }

        $payload = is_array($lastNotification->Payload) ? $lastNotification->Payload : [];
        $operatorOfCreate = (int) ($payload['operator_id'] ?? 0);
        $authUser = $request->attributes->get('auth_user');
        $currentOperatorId = (int) ($authUser->id ?? 0);
        // Undo 僅限原操作者或同分校另一位 director（跨分校主任可撤銷自己管理分校的操作）
        if ($operatorOfCreate > 0 && $currentOperatorId !== $operatorOfCreate) {
            if ($role !== 'super_admin' && !in_array($campusId, $managedCampusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $oldTeacherId = (int) ($payload['old_teacher_id'] ?? 0);
        $newTeacherId = (int) ($payload['new_teacher_id'] ?? 0);
        $courseId = (int) $studentClass->ID;
        $startTime = $this->hhmm($session->StartTime);

        // PRD f0cce4d5 FR-008：若本次代課含「換時間」，需同時還原 ClassSession 的原始日期/時間。
        // 原始時間由 createParentNotification 在成功時寫入 Payload；無此欄位時視為純代課（回歸）。
        $operationType = (string) ($payload['operation_type'] ?? 'substitute');
        $origDate = trim((string) ($payload['original_session_date'] ?? ''));
        $origStart = $this->hhmm($payload['original_start_time'] ?? '');
        $origEnd = $this->hhmm($payload['original_end_time'] ?? '');
        $shouldRestoreTime = $operationType === 'substitute_with_reschedule'
            && $origDate !== '' && $origStart !== '' && $origEnd !== ''
            && ($origDate !== $sessionDate || $origStart !== $startTime || $origEnd !== $this->hhmm($session->EndTime));

        try {
            $result = DB::transaction(function () use (
                $session, $courseId, $sessionDate, $startTime, $oldTeacherId, $newTeacherId,
                $lastNotification, $currentOperatorId, $payload,
                $shouldRestoreTime, $origDate, $origStart, $origEnd
            ) {
                // 找當前代課 scheduled row：以 rescheduled anchor + 新老師為特徵
                // 注意：合併代課+換時情境下，這些列已位於「新日期」（= 目前 session->SessionDate）。
                $rescheduled = Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'rescheduled')
                    ->first();

                $scheduledRow = null;
                if ($rescheduled) {
                    $scheduledRow = Schedule::where('student_course_id', $courseId)
                        ->whereDate('schedule_date', $sessionDate)
                        ->where('status', 'scheduled')
                        ->where('original_schedule_id', $rescheduled->id)
                        ->first();
                }

                // 回復 schedules：刪除代課 scheduled row + 刪除 rescheduled row（回到正班顯示）
                if ($scheduledRow) {
                    $scheduledRow->delete();
                }
                if ($rescheduled) {
                    $rescheduled->delete();
                }

                // 回復 LearningRecord.TeacherID（若有）
                $lrTable = (new LearningRecord())->getTable();
                $lrRowQuery = DB::table($lrTable)->where('ClassSessionID', $session->id);
                if (Schema::hasColumn($lrTable, 'VoidedAt')) {
                    $lrRowQuery->whereNull('VoidedAt');
                }
                $lrRow = $lrRowQuery->first();
                if ($lrRow && $oldTeacherId > 0) {
                    $lrUpdate = [
                        'TeacherID' => $oldTeacherId,
                        'updated_at' => now(),
                    ];
                    // 合併代課+換時的 Undo：同步還原 LR 的時間欄位
                    if ($shouldRestoreTime) {
                        $lrUpdate['SessionDate'] = $origDate;
                        $lrUpdate['StartTime'] = $origStart;
                        $lrUpdate['EndTime'] = $origEnd;
                    }
                    DB::table($lrTable)->where('id', (int) $lrRow->id)->update($lrUpdate);
                    if (Schema::hasTable('learning_record_teacher_changes')) {
                        try {
                            LearningRecordTeacherChange::create([
                                'learning_record_id' => (int) $lrRow->id,
                                'old_teacher_id' => $newTeacherId > 0 ? $newTeacherId : null,
                                'new_teacher_id' => $oldTeacherId > 0 ? $oldTeacherId : null,
                                'changed_by' => $currentOperatorId,
                                'reason' => $shouldRestoreTime ? '代課+換時撤銷（Undo）' : '代課撤銷（Undo）',
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('substitute_undo: audit write skipped', ['message' => $e->getMessage()]);
                        }
                    }
                } elseif ($lrRow && $shouldRestoreTime) {
                    // 沒有換老師的還原需求但仍需還原時間（理論上不會到此分支，但 defensive）
                    DB::table($lrTable)->where('id', (int) $lrRow->id)->update([
                        'SessionDate' => $origDate,
                        'StartTime' => $origStart,
                        'EndTime' => $origEnd,
                        'updated_at' => now(),
                    ]);
                }

                // PRD f0cce4d5 FR-008：還原 ClassSession 時間欄位
                if ($shouldRestoreTime) {
                    $session->SessionDate = $origDate;
                    $session->StartTime = $origStart;
                    $session->EndTime = $origEnd;
                    $session->save();
                }

                // 作廢通知（FR-011）
                $newPayload = $payload;
                $newPayload['voided'] = true;
                $newPayload['voided_by'] = $currentOperatorId;
                $newPayload['voided_at_ms'] = (int) round(microtime(true) * 1000);
                $lastNotification->Payload = $newPayload;
                $lastNotification->ResolvedAt = now();
                $lastNotification->save();

                return [
                    'reverted_scheduled_id' => $scheduledRow ? (int) $scheduledRow->id : null,
                    'reverted_rescheduled_id' => $rescheduled ? (int) $rescheduled->id : null,
                    'restored_teacher_id' => $oldTeacherId,
                    'restored_time' => $shouldRestoreTime,
                    'restored_session_date' => $shouldRestoreTime ? $origDate : null,
                    'restored_start_time' => $shouldRestoreTime ? $origStart : null,
                    'restored_end_time' => $shouldRestoreTime ? $origEnd : null,
                    'voided_notification_id' => (int) $lastNotification->id,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('[substitute_undo] failed', [
                'class_session_id' => $classSessionId,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => '代課撤銷失敗，請稍後再試'], 500);
        }

        Log::info('[substitute_undo] applied', array_merge($result, [
            'class_session_id' => $classSessionId,
            'operator_id' => $currentOperatorId,
            'original_operator_id' => $operatorOfCreate,
            'campus_id' => $campusId,
        ]));

        return response()->json([
            'message' => '已復原代課',
            'class_session_id' => $classSessionId,
        ] + $result);
    }

    /**
     * GET /api/v1/system/settings/substitute-undo
     * 任何已登入 director+ 可讀，用來驅動前端 ToastWithUndo 的倒數時間。
     */
    public function getUndoSetting(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin', 'teacher_admin', 'teacher'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'undo_window_seconds' => self::resolveUiUndoWindow(),
            'server_window_seconds' => self::resolveServerUndoWindow(),
            'allowed_values' => self::ALLOWED_UNDO_WINDOWS,
            'default' => self::DEFAULT_UNDO_WINDOW_SECONDS,
        ]);
    }

    /**
     * PUT /api/v1/system/settings/substitute-undo
     * 僅 super_admin 可改；value 必須為 ALLOWED_UNDO_WINDOWS 之一（Gmail Undo Send 模式）。
     */
    public function setUndoSetting(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if ($role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden — 僅 super_admin 可調整全域設定'], 403);
        }

        $data = $request->validate([
            'undo_window_seconds' => 'required|integer|in:'.implode(',', self::ALLOWED_UNDO_WINDOWS),
        ]);

        SystemSetting::set(self::SETTING_KEY, (string) $data['undo_window_seconds']);

        $operator = $request->attributes->get('auth_user');
        Log::info('[substitute_undo_setting_changed]', [
            'operator_id' => $operator->id ?? null,
            'new_value' => (int) $data['undo_window_seconds'],
        ]);

        return response()->json([
            'message' => '已更新 Undo 時間窗設定',
            'undo_window_seconds' => (int) $data['undo_window_seconds'],
            'server_window_seconds' => self::resolveServerUndoWindow(),
        ]);
    }

    public function recent(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $managedCampusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId > 0) {
            if ($role !== 'super_admin' && !in_array($branchId, $managedCampusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $scopeCampusIds = [$branchId];
        } else {
            $scopeCampusIds = $managedCampusIds;
        }

        $since = now()->subDays(7);
        $query = Notification::where('Type', 'substitute')
            ->where('created_at', '>=', $since)
            ->whereNull('ResolvedAt')
            ->orderBy('id', 'desc')
            ->limit(50);
        if (!empty($scopeCampusIds)) {
            $query->whereIn('CampusID', $scopeCampusIds);
        }
        $rows = $query->get();

        $items = $rows->map(function (Notification $n) {
            $p = is_array($n->Payload) ? $n->Payload : [];

            return [
                'id' => (int) $n->id,
                'class_session_id' => (int) ($n->SourceID ?? 0),
                'campus_id' => (int) $n->CampusID,
                'student_id' => $p['student_id'] ?? null,
                'student_name' => (string) ($p['student_name'] ?? ''),
                'subject' => (string) ($p['subject'] ?? ''),
                'session_date' => (string) ($p['session_date'] ?? ''),
                'start_time' => (string) ($p['start_time'] ?? ''),
                'end_time' => (string) ($p['end_time'] ?? ''),
                'old_teacher_id' => $p['old_teacher_id'] ?? null,
                'old_teacher_name' => (string) ($p['old_teacher_name'] ?? ''),
                'new_teacher_id' => $p['new_teacher_id'] ?? null,
                'new_teacher_name' => (string) ($p['new_teacher_name'] ?? ''),
                'reason' => (string) ($p['reason'] ?? ''),
                'cross_campus' => (bool) ($p['cross_campus'] ?? false),
                // PRD f0cce4d5 P1：儀表板顯示「含換時」chip 的資料來源
                'operation_type' => (string) ($p['operation_type'] ?? 'substitute'),
                'original_session_date' => (string) ($p['original_session_date'] ?? ''),
                'original_start_time' => (string) ($p['original_start_time'] ?? ''),
                'original_end_time' => (string) ($p['original_end_time'] ?? ''),
                'created_at' => optional($n->created_at)->toIso8601String(),
            ];
        })->values()->all();

        return response()->json([
            'items' => $items,
            'total' => count($items),
            'since' => $since->toIso8601String(),
        ]);
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
