<?php

namespace App\Http\Controllers;

use App\Support\AttendanceStatus;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * #768 教學日誌漏交追蹤。
 *
 * 「漏交」= 出缺席狀態需要教學日誌（AttendanceStatus::requiresLog）的堂次，
 * 過了 24 小時緩衝仍沒有對應的 LearningRecord（學習評量）。
 *
 * 主任：看本校各老師漏交清單；老師：看自己的。純讀取、不改任何資料。
 */
class TeachingLogController extends Controller
{
    private const WINDOW_DAYS = 30; // 只看近 30 天，避免歷史堆積

    public function missing(Request $request): JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : (array) $request->attributes->get('auth_campus_ids', []);
        $authTeacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);

        $cutoff = Carbon::now()->subDay()->toDateString();           // 24h 緩衝
        $windowStart = Carbon::now()->subDays(self::WINDOW_DAYS)->toDateString();
        $requiresLog = AttendanceStatus::requiresLogSessionStatuses();

        $q = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('User as t', 't.id', '=', 'sc.TeacherID')
            ->leftJoin('Subject as sub', 'sub.id', '=', 'sc.SubjectID')
            ->leftJoin(DB::raw('(SELECT ClassSessionID, MAX(id) AS mid FROM LearningRecord WHERE VoidedAt IS NULL GROUP BY ClassSessionID) lr'), 'lr.ClassSessionID', '=', 'cs.id')
            ->whereIn('cs.Status', $requiresLog)
            ->whereNull('lr.ClassSessionID')
            ->whereDate('cs.SessionDate', '<=', $cutoff)
            ->whereDate('cs.SessionDate', '>=', $windowStart);

        // 校區範圍：主任只看自己分校的學生堂次。
        if (!empty($campusIds)) {
            $q->whereIn('s.CampusID', $campusIds);
        }
        // 老師只看自己；主任可選 teacher_id 過濾。
        if ($role === 'teacher' && $authTeacherId > 0) {
            $q->where('sc.TeacherID', $authTeacherId);
        } elseif ($request->filled('teacher_id')) {
            $q->where('sc.TeacherID', (int) $request->input('teacher_id'));
        }

        $rows = $q->select([
            'cs.id as class_session_id',
            'cs.SessionDate as session_date',
            'cs.StartTime as start_time',
            'cs.Status as session_status',
            'sc.TeacherID as teacher_id',
            't.Name as teacher_name',
            's.name as student_name',
            'sub.Subject_Name as subject',
        ])->orderBy('sc.TeacherID')->orderBy('cs.SessionDate')->limit(1000)->get();

        // 依老師分組
        $byTeacher = [];
        foreach ($rows as $r) {
            $tid = (int) ($r->teacher_id ?? 0);
            if (!isset($byTeacher[$tid])) {
                $byTeacher[$tid] = [
                    'teacher_id' => $tid,
                    'teacher_name' => $r->teacher_name ?? '—',
                    'missing_count' => 0,
                    'sessions' => [],
                ];
            }
            $byTeacher[$tid]['missing_count']++;
            $byTeacher[$tid]['sessions'][] = [
                'class_session_id' => (int) $r->class_session_id,
                'session_date' => substr((string) $r->session_date, 0, 10),
                'start_time' => substr((string) ($r->start_time ?? ''), 0, 5),
                'student_name' => $r->student_name,
                'subject' => $r->subject,
                'status' => $r->session_status,
                'status_label' => AttendanceStatus::label($this->attendanceCodeForSession((string) $r->session_status)),
            ];
        }

        $teachers = array_values($byTeacher);
        usort($teachers, fn ($a, $b) => $b['missing_count'] <=> $a['missing_count']);

        return response()->json([
            'total_missing' => array_sum(array_column($teachers, 'missing_count')),
            'teacher_count' => count($teachers),
            'window_days' => self::WINDOW_DAYS,
            'teachers' => $teachers,
        ]);
    }

    /** 反查 ClassSession.Status 對應的出缺席代碼（僅供 label 顯示）。 */
    private function attendanceCodeForSession(string $sessionStatus): string
    {
        foreach (AttendanceStatus::META as $code => $m) {
            if ($m['session_status'] === $sessionStatus) {
                return $code;
            }
        }
        return $sessionStatus;
    }
}
