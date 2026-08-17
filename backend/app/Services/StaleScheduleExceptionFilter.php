<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 過濾「已失效」的 schedules.status='scheduled' 例外 row（in-app #203 / GitHub #1296）。
 *
 * 不變式：schedules 的 scheduled 例外只是 ClassSession 的排程投影；
 * 當同課程、同日期、同起始時間的 ClassSession 已全部取消／請假，
 * 該 scheduled row 不可再佔用老師時段（衝堂、容量、跨分校檢查都不可計入）。
 *
 * 判定規則（證據導向、保守）：
 * - 該 key（student_course_id + 日期 + HH:MM 起始）完全沒有 ClassSession → 保留
 *   （補課 schedule 依 R13 可不建 ClassSession，仍為真實佔用）。
 * - 該 key 存在至少一筆 active ClassSession → 保留。
 * - 該 key 有 ClassSession 且全部為 cancelled / leave / leave_adjusted / excused → 視為 stale，剔除。
 * - 同課程同日已有 **active** ClassSession（其他起始時間）：此 scheduled 是二次調課
 *   留在中間時段的殘影（in-app #238 / GitHub #1885），不可佔用。請假堂次不算 active，
 *   故「原時段請假 + 同時段另排補課（無 ClassSession）」仍會保留補課列。
 *
 * 狀態集合與 ScheduleGuardService 的 occupancy 排除集合（#557）一致。
 */
class StaleScheduleExceptionFilter
{
    private const INACTIVE_STATUSES = ['cancelled', 'leave', 'leave_adjusted', 'excused'];

    /**
     * 剔除 stale scheduled 例外 row。
     *
     * @param  iterable<int, object>  $rows  需含 student_course_id 與 start_time 欄位
     * @param  string  $ymd  Y-m-d
     * @return array<int, object> 保留的 rows（順序不變）
     */
    public function rejectStale(iterable $rows, string $ymd): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        if (empty($rows)) {
            return [];
        }

        $courseIds = [];
        foreach ($rows as $row) {
            $courseId = (int) ($row->student_course_id ?? 0);
            if ($courseId > 0) {
                $courseIds[$courseId] = true;
            }
        }
        if (empty($courseIds)) {
            return array_values($rows);
        }

        // key = courseId|HH:MM → 該 key 是否存在 active ClassSession
        $sessions = DB::table('ClassSession')
            ->whereIn('StudentClassID', array_keys($courseIds))
            ->whereDate('SessionDate', $ymd)
            ->select('StudentClassID', 'StartTime', 'Status')
            ->get();

        $keyState = [];
        $activeStartsByCourse = [];
        foreach ($sessions as $session) {
            $courseId = (int) $session->StudentClassID;
            $hhmm = $this->hhmm($session->StartTime);
            $key = $courseId . '|' . $hhmm;
            $isActive = !in_array(strtolower(trim((string) $session->Status)), self::INACTIVE_STATUSES, true);
            $keyState[$key] = ($keyState[$key] ?? false) || $isActive;
            if ($isActive) {
                $activeStartsByCourse[$courseId][$hhmm] = true;
            }
        }

        $kept = [];
        foreach ($rows as $row) {
            $courseId = (int) ($row->student_course_id ?? 0);
            $hhmm = $this->hhmm($row->start_time ?? null);
            $key = $courseId . '|' . $hhmm;
            if ($courseId <= 0) {
                $kept[] = $row;
                continue;
            }
            // Same-day second move: live ClassSession exists at another clock.
            $activeStarts = $activeStartsByCourse[$courseId] ?? [];
            if ($activeStarts !== [] && !isset($activeStarts[$hhmm])) {
                continue;
            }
            // 沒有任何 ClassSession 證據 → 保留；有證據且存在 active → 保留
            if (!array_key_exists($key, $keyState) || $keyState[$key]) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    private function hhmm(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $s = trim((string) $value);
        if (preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?/', $s, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $s;
    }
}
