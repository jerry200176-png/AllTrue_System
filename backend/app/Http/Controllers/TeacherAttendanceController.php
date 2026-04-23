<?php

namespace App\Http\Controllers;

use App\Exports\TeacherMonthlyAttendanceExport;
use App\Models\TeacherSignIn;
use App\Models\TeacherSignInAdjustment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class TeacherAttendanceController extends Controller
{
    private const LATE_THRESHOLD_MINUTES = 10;

    /**
     * GET /api/v1/teacher-attendance/today
     * 老師自查今日打卡狀態（teacher 只能查自己，director 可指定 teacher_id）
     */
    public function today(Request $request)
    {
        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        $authTeacherId = $request->attributes->get('auth_teacher_id');

        // 老師只能查自己
        if ($role === 'teacher') {
            $teacherId = $authTeacherId;
        } else {
            $teacherId = $request->query('teacher_id') ? (int) $request->query('teacher_id') : null;
        }

        if (! $teacherId) {
            return response()->json(['message' => 'teacher_id required'], 422);
        }

        // director 分校隔離：不可跨校查別校老師
        if ($role !== 'super_admin' && ! empty($campusIds)) {
            $belongsToCampus = DB::table('UserCampus')
                ->where('UserID', $teacherId)
                ->whereIn('CampusID', $campusIds)
                ->exists();
            if (! $belongsToCampus) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $today = now()->toDateString();

        $record = TeacherSignIn::where('TeacherID', $teacherId)
            ->whereDate('SignInDT', $today)
            ->orderBy('id', 'desc')
            ->first();

        $teacherName = DB::table('Teacher')->where('id', $teacherId)->value('T_Name')
            ?? DB::table('User')->where('id', $teacherId)->value('Name')
            ?? '';

        // 查今日第一堂課
        $firstClass = DB::table('schedules')
            ->where('teacher_id', $teacherId)
            ->where('schedule_date', $today)
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time')
            ->first();

        return response()->json([
            'date'                   => $today,
            'teacher_id'             => $teacherId,
            'teacher_name'           => $teacherName,
            'sign_in_dt'             => $record?->SignInDT,
            'sign_out_dt'            => $record?->SignOutDT,
            'status'                 => $record?->Status ?? 'no_record',
            'source'                 => $record?->Source ?? null,
            'first_class_start_time' => $firstClass?->start_time ?? null,
            'late_threshold_minutes' => self::LATE_THRESHOLD_MINUTES,
        ]);
    }

    /**
     * GET /api/v1/teacher-attendance
     * 主任查詢所屬分校老師打卡總覽
     */
    public function index(Request $request)
    {
        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);

        $date      = $request->query('date', now()->toDateString());
        $teacherId = $request->query('teacher_id') ? (int) $request->query('teacher_id') : null;
        $status    = $request->query('status');
        $perPage   = 50;

        $query = DB::table('TeacherSingIn as ts')
            ->leftJoin('Teacher as t', 't.id', '=', 'ts.TeacherID')
            ->leftJoin('User as u', 'u.id', '=', 'ts.TeacherID')
            ->select([
                'ts.id',
                'ts.TeacherID as teacher_id',
                DB::raw("COALESCE(t.T_Name, u.Name, '') as teacher_name"),
                'ts.CampusID as campus_id',
                'ts.SignInDT as sign_in_dt',
                'ts.SignOutDT as sign_out_dt',
                'ts.Status as status',
                'ts.Source as source',
            ])
            ->whereDate('ts.SignInDT', $date);

        // 分校隔離
        if ($role !== 'super_admin' && ! empty($campusIds)) {
            $query->whereIn('ts.CampusID', $campusIds);
        }

        if ($teacherId) {
            $query->where('ts.TeacherID', $teacherId);
        }

        if ($status) {
            $query->where('ts.Status', $status);
        }

        $records = $query->orderBy('ts.SignInDT', 'desc')->paginate($perPage);

        // 附加最後補卡資訊
        $signinIds = collect($records->items())->pluck('id')->all();
        $latestAdj = DB::table('teacher_signin_adjustments')
            ->whereIn('teacher_signin_id', $signinIds)
            ->select('teacher_signin_id', 'adjust_reason', 'created_at')
            ->orderBy('id', 'desc')
            ->get()
            ->keyBy('teacher_signin_id');

        $records->getCollection()->transform(function ($row) use ($latestAdj) {
            $row->latest_adjustment = $latestAdj->get($row->id) ?? null;
            return $row;
        });

        return response()->json($records);
    }

    /**
     * POST /api/v1/teacher-attendance/{id}/adjust
     * 主任補卡（審計表唯寫，主表原始欄位不修改）
     */
    public function adjust(Request $request, int $id)
    {
        $request->validate([
            'new_signin_dt'  => 'required|date',
            'new_signout_dt' => 'nullable|date|after:new_signin_dt',
            'adjust_reason'  => 'required|string|min:2|max:500',
        ]);

        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        $authUser  = $request->attributes->get('auth_user');

        $signin = TeacherSignIn::findOrFail($id);

        // 分校隔離
        if ($role !== 'super_admin' && ! empty($campusIds) && ! in_array($signin->CampusID, $campusIds)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        [$adjustment, $signin] = DB::transaction(function () use ($request, $signin, $authUser) {
            $adj = TeacherSignInAdjustment::create([
                'teacher_signin_id'   => $signin->id,
                'adjusted_by_user_id' => $authUser->id,
                'adjust_reason'       => $request->input('adjust_reason'),
                'original_signin_dt'  => $signin->SignInDT,
                'original_signout_dt' => $signin->SignOutDT,
                'new_signin_dt'       => $request->input('new_signin_dt'),
                'new_signout_dt'      => $request->input('new_signout_dt'),
            ]);

            // 只更新 Status，原始 SignInDT/SignOutDT 保持不變
            $signin->Status = 'adjusted';
            $signin->MDT    = now();
            $signin->save();

            return [$adj, $signin];
        });

        return response()->json([
            'ok'            => true,
            'signin_id'     => $signin->id,
            'adjustment_id' => $adjustment->id,
            'new_status'    => 'adjusted',
        ]);
    }

    /**
     * GET /api/v1/teacher-attendance/unclosed
     * 主任查詢今日有簽到但截止時間前未簽退的老師（結班檢查）
     */
    public function unclosed(Request $request)
    {
        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);

        $date        = $request->query('date', now()->toDateString());
        $cutoffTime  = $request->query('cutoff_time', '20:00');

        $cutoffDT = Carbon::parse("{$date} {$cutoffTime}");

        $query = DB::table('TeacherSingIn as ts')
            ->leftJoin('Teacher as t', 't.id', '=', 'ts.TeacherID')
            ->leftJoin('User as u', 'u.id', '=', 'ts.TeacherID')
            ->select([
                'ts.TeacherID as teacher_id',
                DB::raw("COALESCE(t.T_Name, u.Name, '') as teacher_name"),
                'ts.SignInDT as sign_in_dt',
                'ts.SignOutDT as sign_out_dt',
                'ts.Status as status',
            ])
            ->whereDate('ts.SignInDT', $date)
            ->whereNull('ts.SignOutDT')
            ->where('ts.SignInDT', '<=', $cutoffDT);

        if ($role !== 'super_admin' && ! empty($campusIds)) {
            $query->whereIn('ts.CampusID', $campusIds);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * GET /api/v1/teacher-attendance/export
     * 主任匯出打卡記錄（CSV / JSON）
     */
    public function export(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'format'    => 'nullable|in:csv,json',
        ]);

        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);

        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');
        $format   = $request->query('format', 'csv');

        $query = DB::table('TeacherSingIn as ts')
            ->leftJoin('Teacher as t', 't.id', '=', 'ts.TeacherID')
            ->leftJoin('User as u', 'u.id', '=', 'ts.TeacherID')
            ->select([
                'ts.id',
                'ts.TeacherID as teacher_id',
                DB::raw("COALESCE(t.T_Name, u.Name, '') as teacher_name"),
                'ts.CampusID as campus_id',
                'ts.SignInDT as sign_in_dt',
                'ts.SignOutDT as sign_out_dt',
                'ts.Status as status',
                'ts.Source as source',
            ])
            ->whereDate('ts.SignInDT', '>=', $dateFrom)
            ->whereDate('ts.SignInDT', '<=', $dateTo)
            ->orderBy('ts.SignInDT');

        if ($role !== 'super_admin' && ! empty($campusIds)) {
            $query->whereIn('ts.CampusID', $campusIds);
        }

        $records = $query->get();

        if ($format === 'json') {
            return response()->json($records);
        }

        // CSV 輸出
        $filename = "teacher-attendance-{$dateFrom}-{$dateTo}.csv";
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($records) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($handle, ['ID', '老師ID', '老師姓名', '分校ID', '簽到時間', '簽退時間', '狀態', '來源']);
            foreach ($records as $row) {
                fputcsv($handle, [
                    $row->id,
                    $row->teacher_id,
                    $row->teacher_name,
                    $row->campus_id,
                    $row->sign_in_dt,
                    $row->sign_out_dt ?? '',
                    $row->status,
                    $row->source,
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * GET /api/v1/teacher-attendance/export-monthly?year_month=YYYY-MM
     * 主任匯出整月老師出缺勤 XLSX（月報摘要 + 明細記錄兩個工作表）
     */
    public function exportMonthly(Request $request)
    {
        $request->validate([
            'year_month' => 'required|date_format:Y-m',
        ]);

        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        $yearMonth = $request->query('year_month');

        $from = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $to   = Carbon::createFromFormat('Y-m', $yearMonth)->endOfMonth();

        // 不匯出未來日期（若為當月，截至今日）
        if ($to->isAfter(now())) {
            $to = now()->endOfDay();
        }

        $query = DB::table('TeacherSingIn as ts')
            ->leftJoin('Teacher as t', 't.id', '=', 'ts.TeacherID')
            ->leftJoin('User as u', 'u.id', '=', 'ts.TeacherID')
            ->leftJoin('Campus as c', 'c.id', '=', 'ts.CampusID')
            ->select([
                'ts.id',
                'ts.TeacherID as teacher_id',
                DB::raw("COALESCE(t.T_Name, u.Name, '') as teacher_name"),
                'ts.CampusID as campus_id',
                'c.name as campus_name',
                'ts.SignInDT as sign_in_dt',
                'ts.SignOutDT as sign_out_dt',
                'ts.Status as status',
            ])
            ->whereBetween('ts.SignInDT', [$from, $to])
            ->orderBy('ts.TeacherID')
            ->orderBy('ts.SignInDT');

        if ($role !== 'super_admin' && ! empty($campusIds)) {
            $query->whereIn('ts.CampusID', $campusIds);
        }

        $records = $query->get();

        // 計算遲到分鐘：JOIN schedules 取每位老師每日首堂 start_time
        if ($records->isNotEmpty()) {
            $teacherIds = $records->pluck('teacher_id')->unique()->values()->all();
            $firstClasses = DB::table('schedules')
                ->selectRaw('teacher_id, DATE(schedule_date) as sdate, MIN(start_time) as first_start')
                ->whereIn('teacher_id', $teacherIds)
                ->whereBetween('schedule_date', [$from->toDateString(), $to->toDateString()])
                ->where('status', '!=', 'cancelled')
                ->groupBy('teacher_id', DB::raw('DATE(schedule_date)'))
                ->get()
                ->keyBy(fn ($r) => $r->teacher_id . '_' . $r->sdate);

            $records = $records->map(function ($rec) use ($firstClasses) {
                $signInDate  = substr((string) $rec->sign_in_dt, 0, 10);
                $key         = $rec->teacher_id . '_' . $signInDate;
                $firstClass  = $firstClasses->get($key);

                if ($firstClass) {
                    $classStart     = Carbon::parse($signInDate . ' ' . $firstClass->first_start);
                    $signIn         = Carbon::parse($rec->sign_in_dt);
                    // For adjusted records ideally we'd use adjustment time; use SignInDT as fallback
                    $lateMinutes    = max(0, (int) $signIn->diffInMinutes($classStart, false) * -1);
                    $rec->late_minutes = $lateMinutes > 0 ? $lateMinutes : 0;
                } else {
                    $rec->late_minutes = null;
                }

                return $rec;
            });
        }

        // 取補卡記錄
        $signinIds   = $records->pluck('id')->all();
        $adjustments = ! empty($signinIds)
            ? DB::table('teacher_signin_adjustments')
                ->whereIn('teacher_signin_id', $signinIds)
                ->select('teacher_signin_id', 'adjust_reason', 'new_signin_dt', 'created_at')
                ->orderBy('id', 'desc')
                ->get()
                ->unique('teacher_signin_id')
            : collect();

        \Log::info('[teacher-monthly-export]', [
            'user_id'    => $request->attributes->get('auth_user')?->id,
            'year_month' => $yearMonth,
            'campus_ids' => $campusIds,
            'count'      => $records->count(),
        ]);

        $filename = "teacher-attendance-{$yearMonth}.xlsx";
        return Excel::download(
            new TeacherMonthlyAttendanceExport($records, $adjustments, $yearMonth),
            $filename
        );
    }
}
