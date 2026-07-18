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
        foreach ($sessions as $session) {
            $key = (int) $session->StudentClassID . '|' . $this->hhmm($session->StartTime);
            $isActive = !in_array(strtolower(trim((string) $session->Status)), self::INACTIVE_STATUSES, true);
            $keyState[$key] = ($keyState[$key] ?? false) || $isActive;
        }

        $kept = [];
        foreach ($rows as $row) {
            $courseId = (int) ($row->student_course_id ?? 0);
            $key = $courseId . '|' . $this->hhmm($row->start_time ?? null);
            // 沒有任何 ClassSession 證據 → 保留；有證據且存在 active → 保留
            if ($courseId <= 0 || !array_key_exists($key, $keyState) || $keyState[$key]) {
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
