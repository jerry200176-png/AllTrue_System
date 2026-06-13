<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * #770 批次排課 CSV 匯入 — 衝突檢查（preview）。
 *
 * 競品 backerwebapp 的 import_schedule_batch 在寫入前做原子性衝突檢查。本階段先交付
 * 「解析 + 衝突分析」preview：同時段同教室／同老師＝衝突（阻擋）；學生同時段已有課＝警告。
 * preview 純讀取、不建立任何資料。
 *
 * 注意：原子性 execute（實際建立排課）涉及課程計費欄位（費率/堂數）為 CSV 所無，需先擴充
 * CSV 規格並對齊 alltrue 課程計費模型，登記 TECH_DEBT 後續處理（見 issue #770）。
 *
 * CSV 欄位：date,start_time,end_time,teacher_id,room_id,subject,student_ids,note
 */
class ScheduleImportController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'csv' => 'required|string|max:200000',
        ]);

        $parsed = $this->parseCsv($data['csv']);
        if (!empty($parsed['error'])) {
            return response()->json(['message' => $parsed['error'], 'code' => 'csv_parse_error'], 422);
        }
        $rows = $parsed['rows'];

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : (array) $request->attributes->get('auth_campus_ids', []);

        $analysis = [];
        $conflictCount = 0;
        $warnCount = 0;

        foreach ($rows as $i => $r) {
            $conflicts = [];
            $warnings = [];

            // 行內格式驗證
            $rowErr = $this->validateRow($r);
            if ($rowErr) {
                $conflicts[] = ['type' => 'format', 'message' => $rowErr];
            } else {
                // DB 衝突：同日期、時間重疊、同教室或同老師（排除已取消）。
                $existing = $this->overlappingSessions($r, $campusIds);
                foreach ($existing as $e) {
                    if ((int) $e->TeacherID === (int) $r['teacher_id']) {
                        $conflicts[] = ['type' => 'teacher', 'message' => "老師於 {$r['date']} {$r['start_time']} 已有排課（堂次 #{$e->id}）"];
                    }
                    if (!empty($r['room_id']) && (int) ($e->room_id ?? 0) === (int) $r['room_id']) {
                        $conflicts[] = ['type' => 'room', 'message' => "教室於 {$r['date']} {$r['start_time']} 已被佔用（堂次 #{$e->id}）"];
                    }
                }
                // 同檔內互相衝突（對稱：配對兩列都標記）
                foreach ($rows as $j => $other) {
                    if ($j === $i || empty($other['date'])) {
                        continue;
                    }
                    if ($other['date'] === $r['date'] && $this->timeOverlap($r, $other)) {
                        if ((int) $other['teacher_id'] === (int) $r['teacher_id']) {
                            $conflicts[] = ['type' => 'teacher_intra', 'message' => "與本檔第 " . ($j + 2) . " 列同老師同時段衝突"];
                        }
                        if (!empty($r['room_id']) && (int) ($other['room_id'] ?? 0) === (int) $r['room_id']) {
                            $conflicts[] = ['type' => 'room_intra', 'message' => "與本檔第 " . ($j + 2) . " 列同教室同時段衝突"];
                        }
                    }
                }
                // 學生衝突 = 警告
                $studentConflicts = $this->studentOverlaps($r, $campusIds);
                foreach ($studentConflicts as $sname) {
                    $warnings[] = ['type' => 'student', 'message' => "學生 {$sname} 於 {$r['date']} {$r['start_time']} 已有其他排課"];
                }
            }

            if ($conflicts) {
                $conflictCount++;
            }
            if ($warnings) {
                $warnCount++;
            }
            $analysis[] = [
                'line' => $i + 2, // CSV 第 1 列為表頭
                'row' => $r,
                'status' => $conflicts ? 'conflict' : ($warnings ? 'warning' : 'ok'),
                'conflicts' => $conflicts,
                'warnings' => $warnings,
            ];
        }

        return response()->json([
            'total' => count($rows),
            'ok' => count($rows) - $conflictCount - $warnCount,
            'conflict' => $conflictCount,
            'warning' => $warnCount,
            'rows' => $analysis,
        ]);
    }

    /** @return array{rows: array<int, array<string,mixed>>, error?: string} */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (count($lines) < 2) {
            return ['rows' => [], 'error' => 'CSV 至少需表頭加一列資料'];
        }
        $header = array_map('trim', str_getcsv((string) array_shift($lines)));
        $expected = ['date', 'start_time', 'end_time', 'teacher_id', 'room_id', 'subject', 'student_ids', 'note'];
        $missing = array_diff(['date', 'start_time', 'end_time', 'teacher_id'], $header);
        if ($missing) {
            return ['rows' => [], 'error' => '缺少必要欄位：' . implode(',', $missing)];
        }
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $assoc = [];
            foreach ($header as $k => $name) {
                $assoc[$name] = $cols[$k] ?? '';
            }
            $assoc['student_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) ($assoc['student_ids'] ?? '')))));
            $rows[] = $assoc;
        }
        return ['rows' => $rows];
    }

    private function validateRow(array $r): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['date'] ?? ''))) {
            return '日期格式須為 YYYY-MM-DD';
        }
        if (!preg_match('/^\d{1,2}:\d{2}$/', (string) ($r['start_time'] ?? '')) || !preg_match('/^\d{1,2}:\d{2}$/', (string) ($r['end_time'] ?? ''))) {
            return '時間格式須為 HH:MM';
        }
        if ((int) ($r['teacher_id'] ?? 0) <= 0) {
            return 'teacher_id 必填且為正整數';
        }
        if ($r['start_time'] >= $r['end_time']) {
            return '結束時間須晚於開始時間';
        }
        return null;
    }

    private function timeOverlap(array $a, array $b): bool
    {
        return $a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time'];
    }

    /** 查 DB 既有重疊堂次（同日期、時間重疊、同老師或同教室）。 */
    private function overlappingSessions(array $r, array $campusIds)
    {
        $q = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereDate('cs.SessionDate', $r['date'])
            ->whereNotIn('cs.Status', ['cancelled'])
            ->where(function ($w) use ($r) {
                $w->where('sc.TeacherID', (int) $r['teacher_id']);
                if (!empty($r['room_id'])) {
                    $w->orWhere('sc.room_id', (int) $r['room_id']);
                }
            })
            // 時間重疊：cs.StartTime < end AND cs.EndTime > start
            ->whereRaw('SUBSTRING(cs.StartTime,1,5) < ?', [$r['end_time']])
            ->whereRaw('COALESCE(SUBSTRING(cs.EndTime,1,5), "23:59") > ?', [$r['start_time']]);
        if (!empty($campusIds)) {
            $q->whereIn('s.CampusID', $campusIds);
        }
        return $q->select('cs.id', 'sc.TeacherID', 'sc.room_id')->limit(50)->get();
    }

    /** 查學生同時段是否已有課 → 回傳學生姓名清單（警告用）。 */
    private function studentOverlaps(array $r, array $campusIds): array
    {
        if (empty($r['student_ids'])) {
            return [];
        }
        $q = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereIn('sc.StudentID', $r['student_ids'])
            ->whereDate('cs.SessionDate', $r['date'])
            ->whereNotIn('cs.Status', ['cancelled'])
            ->whereRaw('SUBSTRING(cs.StartTime,1,5) < ?', [$r['end_time']])
            ->whereRaw('COALESCE(SUBSTRING(cs.EndTime,1,5), "23:59") > ?', [$r['start_time']]);
        if (!empty($campusIds)) {
            $q->whereIn('s.CampusID', $campusIds);
        }
        return $q->distinct()->pluck('s.name')->all();
    }
}
