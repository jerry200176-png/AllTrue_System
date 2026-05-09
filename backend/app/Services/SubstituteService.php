<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * SubstituteService — 代課流程 UX 優化（PRD 9c058f19）
 *
 * 提供跨分校（物理不可分身）衝堂偵測、家長通知建立、
 * 以及代課/取消代課的原子寫入邏輯，供單堂、批次、Undo
 * 等上層 Controller 共用。
 */
class SubstituteService
{
    /**
     * 收集老師某日在所有已綁定分校的忙碌時段。
     *
     * 回傳僅含 {start_time, end_time, campus_id}，不揭露別分校
     * 的學生/科目/教室等敏感欄位。PRD FR-004a / 第 9 節 Info Disclosure。
     *
     * @param  int[]  $excludeScheduleIds  排除這些 schedules.id（例：本次代課 anchor）
     * @return array<int, array{start_time:string,end_time:string,campus_id:int,source:string}>
     */
    public function collectTeacherBusySlots(int $teacherId, string $date, array $excludeScheduleIds = []): array
    {
        if ($teacherId <= 0) {
            return [];
        }
        $ymd = Carbon::parse($date)->toDateString();
        $excludeScheduleIds = array_values(array_unique(array_filter(array_map('intval', $excludeScheduleIds))));

        // 來源 1：schedules 內正班 / 代課 / 加課 (status=scheduled)
        $scheduleQuery = Schedule::query()
            ->where('teacher_id', $teacherId)
            ->whereDate('schedule_date', $ymd)
            ->where('status', 'scheduled')
            ->select('id', 'start_time', 'end_time', 'branch_id');
        if (!empty($excludeScheduleIds)) {
            $scheduleQuery->whereNotIn('id', $excludeScheduleIds);
        }
        $scheduleRows = $scheduleQuery->get();

        // 來源 2：ClassSession.SessionDate 當日 + StudentClass.TeacherID = 老師，
        // 排除已 cancelled / leave，以及已有其他代課老師的堂次（合約老師不需出席）。
        // 由 Student.CampusID 推斷分校。
        $sessionRows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'cs.StudentClassID', '=', 'sc.ID')
            ->leftJoin('Student as st', 'sc.StudentID', '=', 'st.id')
            ->where('sc.TeacherID', $teacherId)
            ->whereDate('cs.SessionDate', $ymd)
            ->whereNotIn('cs.Status', ['cancelled', 'leave'])
            ->whereNotExists(function ($sub) use ($teacherId) {
                // 若該堂已有「其他老師」的代課安排，合約老師視為空閒
                $sub->select(DB::raw(1))
                    ->from('schedules as sub_sched')
                    ->whereColumn('sub_sched.student_course_id', 'cs.StudentClassID')
                    ->whereRaw('DATE(sub_sched.schedule_date) = DATE(cs.SessionDate)')
                    ->whereRaw('SUBSTRING(sub_sched.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)')
                    ->where('sub_sched.status', 'scheduled')
                    ->whereNotNull('sub_sched.original_schedule_id')
                    ->where('sub_sched.teacher_id', '!=', $teacherId);
            })
            ->select(
                'cs.id as class_session_id',
                'cs.StartTime as start_time',
                'cs.EndTime as end_time',
                'st.CampusID as campus_id'
            )
            ->get();

        $busy = [];
        foreach ($scheduleRows as $row) {
            $start = $this->hhmm($row->start_time);
            $end = $this->hhmm($row->end_time);
            if ($start === '' || $end === '') {
                continue;
            }
            $busy[] = [
                'start_time' => $start,
                'end_time' => $end,
                'campus_id' => (int) ($row->branch_id ?? 0),
                'source' => 'schedules',
            ];
        }
        foreach ($sessionRows as $row) {
            $start = $this->hhmm($row->start_time);
            $end = $this->hhmm($row->end_time);
            if ($start === '' || $end === '') {
                continue;
            }
            $busy[] = [
                'start_time' => $start,
                'end_time' => $end,
                'campus_id' => (int) ($row->campus_id ?? 0),
                'source' => 'class_session',
            ];
        }

        return $this->mergeBusySlots($busy);
    }

    /**
     * FR-005：含容量資訊的忙碌時段查詢，供 availability 端點使用。
     *
     * 每個 slot 額外帶有 class_type / student_count / remaining_capacity，
     * 讓前端判斷「重疊是否真的超過容量」，而非任何重疊都標為衝堂。
     *
     * 設計原則：不使用 mergeBusySlots（保留每個 slot 的完整 metadata），
     * 與原有 collectTeacherBusySlots 互相獨立，向下相容不受影響。
     *
     * @param  int[]  $excludeScheduleIds
     * @return array<int, array{start_time:string,end_time:string,campus_id:int,class_type:string,student_count:int,remaining_capacity:int}>
     */
    public function collectTeacherBusySlotsWithCapacity(int $teacherId, string $date, array $excludeScheduleIds = []): array
    {
        if ($teacherId <= 0) {
            return [];
        }
        $ymd = Carbon::parse($date)->toDateString();
        $excludeScheduleIds = array_values(array_unique(array_filter(array_map('intval', $excludeScheduleIds))));

        // 來源 1：ClassSession（含 ClassType，用於計算容量）
        // 排除已有其他代課老師的堂次（合約老師不需出席，不佔用容量）。
        $sessionRows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'cs.StudentClassID', '=', 'sc.ID')
            ->leftJoin('Student as st', 'sc.StudentID', '=', 'st.id')
            ->where('sc.TeacherID', $teacherId)
            ->whereDate('cs.SessionDate', $ymd)
            ->whereNotIn('cs.Status', ['cancelled', 'leave'])
            ->whereNotExists(function ($sub) use ($teacherId) {
                $sub->select(DB::raw(1))
                    ->from('schedules as sub_sched')
                    ->whereColumn('sub_sched.student_course_id', 'cs.StudentClassID')
                    ->whereRaw('DATE(sub_sched.schedule_date) = DATE(cs.SessionDate)')
                    ->whereRaw('SUBSTRING(sub_sched.start_time, 1, 5) = SUBSTRING(cs.StartTime, 1, 5)')
                    ->where('sub_sched.status', 'scheduled')
                    ->whereNotNull('sub_sched.original_schedule_id')
                    ->where('sub_sched.teacher_id', '!=', $teacherId);
            })
            ->select(
                'cs.StartTime as start_time',
                'cs.EndTime as end_time',
                'sc.ClassType as class_type',
                'st.CampusID as campus_id'
            )
            ->get();

        // 來源 2：schedules (status=scheduled)，透過 StudentClass 取得 ClassType
        $scheduleQuery = Schedule::query()
            ->where('schedules.teacher_id', $teacherId)
            ->whereDate('schedules.schedule_date', $ymd)
            ->where('schedules.status', 'scheduled')
            ->leftJoin('StudentClass as sc2', 'sc2.ID', '=', 'schedules.student_course_id')
            ->select(
                'schedules.id',
                'schedules.start_time',
                'schedules.end_time',
                'schedules.branch_id',
                DB::raw("COALESCE(sc2.ClassType, 'one_on_one') as class_type")
            );
        if (!empty($excludeScheduleIds)) {
            $scheduleQuery->whereNotIn('schedules.id', $excludeScheduleIds);
        }
        $scheduleRows = $scheduleQuery->get();

        // 彙整原始 slots（每個 slot = 1 位學生的 1 堂課）
        $rawSlots = [];
        foreach ($sessionRows as $row) {
            $start = $this->hhmm($row->start_time);
            $end   = $this->hhmm($row->end_time);
            if ($start === '' || $end === '') {
                continue;
            }
            $rawSlots[] = [
                'start_time' => $start,
                'end_time'   => $end,
                'campus_id'  => (int) ($row->campus_id ?? 0),
                'class_type' => (string) ($row->class_type ?: 'one_on_one'),
            ];
        }
        foreach ($scheduleRows as $row) {
            $start = $this->hhmm($row->start_time);
            $end   = $this->hhmm($row->end_time);
            if ($start === '' || $end === '') {
                continue;
            }
            $rawSlots[] = [
                'start_time' => $start,
                'end_time'   => $end,
                'campus_id'  => (int) ($row->branch_id ?? 0),
                'class_type' => (string) ($row->class_type ?: 'one_on_one'),
            ];
        }

        if (empty($rawSlots)) {
            return [];
        }

        // 對每個 slot 計算 student_count（與自身重疊的所有 slot 數，含自身）
        // 並以該 slot 的 class_type 容量計算 remaining_capacity。
        $result = [];
        foreach ($rawSlots as $slot) {
            $studentCount = 0;
            foreach ($rawSlots as $other) {
                if ($this->overlaps($slot['start_time'], $slot['end_time'], $other['start_time'], $other['end_time'])) {
                    $studentCount++;
                }
            }
            $capacity          = $this->capacityForClassType($slot['class_type']);
            $remainingCapacity = max(0, $capacity - $studentCount);
            $result[] = [
                'start_time'         => $slot['start_time'],
                'end_time'           => $slot['end_time'],
                'campus_id'          => $slot['campus_id'],
                'class_type'         => $slot['class_type'],
                'student_count'      => $studentCount,
                'remaining_capacity' => $remainingCapacity,
            ];
        }

        return $result;
    }

    /**
     * 跨分校衝堂檢查：若老師在該時段於任一分校已有課，回 conflicts 陣列。
     *
     * 回應只含 campus_id 與時段，不含學生/科目/教室明細。
     *
     * @param  int[]  $excludeScheduleIds
     * @return array<int, array{start_time:string,end_time:string,campus_id:int}>
     */
    public function detectCrossCampusConflict(int $teacherId, string $date, string $start, string $end, array $excludeScheduleIds = []): array
    {
        $start = $this->hhmm($start);
        $end = $this->hhmm($end);
        if ($start === '' || $end === '') {
            return [];
        }
        $busy = $this->collectTeacherBusySlots($teacherId, $date, $excludeScheduleIds);
        $conflicts = [];
        foreach ($busy as $slot) {
            if ($this->overlaps($start, $end, $slot['start_time'], $slot['end_time'])) {
                $conflicts[] = [
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'campus_id' => (int) $slot['campus_id'],
                ];
            }
        }

        return $conflicts;
    }

    /**
     * 判定老師是否綁定於任何一個給定的分校（operator 的 managed_campus_ids 聯集）。
     *
     * @param  int[]  $managedCampusIds
     */
    public function teacherBoundToAny(int $teacherId, array $managedCampusIds): bool
    {
        if ($teacherId <= 0) {
            return false;
        }
        // super_admin 情境 managedCampusIds 可能為空 → 視為通過
        if (empty($managedCampusIds)) {
            return UserCampus::where('UserID', $teacherId)->exists();
        }

        return UserCampus::where('UserID', $teacherId)
            ->whereIn('CampusID', $managedCampusIds)
            ->exists();
    }

    /**
     * 代課成功後建立家長站內通知（Type=substitute）。
     *
     * 會存：學生、科目、時段、原老師、代課老師、原因。
     * PRD FR-010：與代課同一交易；FR-011：Undo 時將 ResolvedAt 標記以 voided 之。
     */
    public function createParentNotification(array $payload): ?Notification
    {
        try {
            $campusId = (int) ($payload['campus_id'] ?? 0);
            if ($campusId <= 0) {
                return null;
            }
            $session = $payload['session'] ?? null;
            $classSessionId = (int) ($payload['class_session_id'] ?? ($session->id ?? 0));
            $studentName = (string) ($payload['student_name'] ?? '');
            $subject = (string) ($payload['subject'] ?? '課程');
            $dateLabel = (string) ($payload['session_date'] ?? '');
            $startTime = (string) ($payload['start_time'] ?? '');
            $endTime = (string) ($payload['end_time'] ?? '');
            $oldName = (string) ($payload['old_teacher_name'] ?? '');
            $newName = (string) ($payload['new_teacher_name'] ?? '');

            // PRD f0cce4d5 FR-006：合併代課+換時使用「課程異動通知」措辭
            $operationType = (string) ($payload['operation_type'] ?? 'substitute');
            $origDate = (string) ($payload['original_session_date'] ?? '');
            $origStart = (string) ($payload['original_start_time'] ?? '');
            $origEnd = (string) ($payload['original_end_time'] ?? '');
            $isCombined = $operationType === 'substitute_with_reschedule'
                && $origDate !== '' && $origStart !== '' && $origEnd !== ''
                && ($origDate !== $dateLabel || $origStart !== $startTime || $origEnd !== $endTime);

            if ($isCombined) {
                $title = sprintf(
                    '%s %s 課程異動通知',
                    $studentName !== '' ? $studentName : '學生',
                    $subject !== '' ? $subject : '課程'
                );
                $body = sprintf(
                    '原定 %s %s~%s 的課程已調整至 %s %s~%s，由 %s 代課。',
                    $origDate,
                    $origStart,
                    $origEnd,
                    $dateLabel,
                    $startTime,
                    $endTime,
                    $newName !== '' ? $newName : '未指派'
                );
            } else {
                $title = sprintf('%s %s 代課通知', $studentName !== '' ? $studentName : '學生', $subject !== '' ? $subject : '課程');
                $body = sprintf(
                    '%s %s~%s 原老師 %s 由 %s 代課。',
                    $dateLabel,
                    $startTime,
                    $endTime,
                    $oldName !== '' ? $oldName : '—',
                    $newName !== '' ? $newName : '未指派'
                );
            }

            $payloadData = [
                'student_id' => $payload['student_id'] ?? null,
                'student_class_id' => $payload['student_class_id'] ?? null,
                'student_name' => $studentName,
                'subject' => $subject,
                'session_date' => $dateLabel,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'old_teacher_id' => $payload['old_teacher_id'] ?? null,
                'old_teacher_name' => $oldName,
                'new_teacher_id' => $payload['new_teacher_id'] ?? null,
                'new_teacher_name' => $newName,
                'reason' => $payload['reason'] ?? null,
                'cross_campus' => (bool) ($payload['cross_campus'] ?? false),
                'operator_id' => $payload['operator_id'] ?? null,
                // PRD f0cce4d5：保留原始時間以供 Undo 還原 ClassSession.{SessionDate,StartTime,EndTime}
                'operation_type' => $isCombined ? 'substitute_with_reschedule' : 'substitute',
                'original_session_date' => $origDate ?: $dateLabel,
                'original_start_time' => $origStart ?: $startTime,
                'original_end_time' => $origEnd ?: $endTime,
                'created_at_ms' => (int) round(microtime(true) * 1000),
            ];

            // FR-010 / 冪等：同一堂次若已有未撤銷的 substitute 通知，更新 Title/Body/Payload 以反映最新狀態。
            $sourceKey = 'substitute:'.$classSessionId;
            $existing = Notification::where('SourceKey', $sourceKey)->first();
            if ($existing) {
                $existing->Title = mb_substr($title, 0, 120, 'UTF-8');
                $existing->Body = mb_substr($body, 0, 400, 'UTF-8');
                // 合併既有 Payload 與新資料（保留既有 voided / created_at_ms 等不重要差異）
                $merged = is_array($existing->Payload) ? $existing->Payload : [];
                foreach ($payloadData as $k => $v) {
                    $merged[$k] = $v;
                }
                $existing->Payload = $merged;
                $existing->save();

                return $existing;
            }

            return Notification::create([
                'CampusID' => $campusId,
                'Type' => 'substitute',
                'Severity' => 'low',
                'Title' => mb_substr($title, 0, 120, 'UTF-8'),
                'Body' => mb_substr($body, 0, 400, 'UTF-8'),
                'SourceType' => 'ClassSession',
                'SourceID' => $classSessionId > 0 ? $classSessionId : null,
                'SourceKey' => $sourceKey,
                'Payload' => $payloadData,
                'OccurredAt' => now(),
            ]);
        } catch (\Throwable $e) {
            // 通知失敗仍算代課失敗（FR-010 要求同一交易），由呼叫端 catch 回滾。
            throw $e;
        }
    }

    /**
     * 撤銷一筆代課的家長通知：設 ResolvedAt，並於 Payload 寫入 voided 旗標。
     */
    public function voidParentNotificationForSession(int $classSessionId, int $operatorId): int
    {
        if ($classSessionId <= 0) {
            return 0;
        }
        $rows = Notification::where('Type', 'substitute')
            ->where('SourceType', 'ClassSession')
            ->where('SourceID', $classSessionId)
            ->whereNull('ResolvedAt')
            ->get();
        $count = 0;
        foreach ($rows as $row) {
            $payload = is_array($row->Payload) ? $row->Payload : [];
            $payload['voided'] = true;
            $payload['voided_by'] = $operatorId;
            $payload['voided_at_ms'] = (int) round(microtime(true) * 1000);
            $row->Payload = $payload;
            $row->ResolvedAt = now();
            $row->save();
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, array{start_time:string,end_time:string,campus_id:int,source:string}>  $slots
     * @return array<int, array{start_time:string,end_time:string,campus_id:int,source:string}>
     */
    private function mergeBusySlots(array $slots): array
    {
        $key = static fn (array $s): string => $s['campus_id'].'|'.$s['start_time'].'|'.$s['end_time'];
        $seen = [];
        $out = [];
        foreach ($slots as $s) {
            $k = $key($s);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $s;
        }
        usort($out, static fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));

        return $out;
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

    private function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        if ($aStart === '' || $aEnd === '' || $bStart === '' || $bEnd === '') {
            return false;
        }

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /**
     * 與 ScheduleGuardService::capacityForClassType 保持同步的容量對應表。
     * Single Source of Truth：後端計算後以 remaining_capacity 整數下傳前端，
     * 前端無需持有此 map。
     */
    private function capacityForClassType(?string $classType): int
    {
        return match ((string) ($classType ?: 'one_on_one')) {
            'one_on_two'   => 2,
            'one_on_three' => 3,
            'tutoring'     => 4,
            default        => 1,
        };
    }
}
