<?php

namespace App\Http\Controllers;

use App\Exports\TeacherMonthlyAttendanceExport;
use App\Models\TeacherSignIn;
use App\Models\TeacherSignInAdjustment;
use App\Services\TeacherAttendanceMonth;
use App\Services\TeacherClassCalendar;
use App\Support\TeacherProfileDirectory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $teacherName = TeacherProfileDirectory::nameFor((int) $teacherId, '');

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
     * 解析本次請求的有效分校 ID 清單。
     *
     * 回傳值語意：
     *   null            → super_admin，不限校
     *   array<int>      → 使用此清單做 CampusID 過濾
     *   JsonResponse    → 403，呼叫端應直接 return
     *
     * 邏輯：
     *   1. super_admin → null（不過濾）
     *   2. 非 super_admin 且 auth_campus_ids 為空 → 403（帳號未指派分校）
     *   3. 傳入 campus_id 且在 auth_campus_ids 內 → [campus_id]
     *   4. 傳入 campus_id 但不在 auth_campus_ids 內 → 403
     *   5. 未傳 campus_id → fallback 為 auth_campus_ids 全部
     */
    private function resolveEffectiveCampusIds(Request $request): array|\Illuminate\Http\JsonResponse|null
    {
        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);

        if ($role === 'super_admin') {
            // super_admin 可選傳 campus_id 限縮查詢，不傳則看全部
            $reqCampusId = $request->query('campus_id') ? (int) $request->query('campus_id') : null;
            return $reqCampusId !== null ? [$reqCampusId] : null;
        }

        if (empty($campusIds)) {
            return response()->json(['message' => 'Forbidden: no campus assignment'], 403);
        }

        $reqCampusId = $request->query('campus_id') ? (int) $request->query('campus_id') : null;

        if ($reqCampusId !== null) {
            if (! in_array($reqCampusId, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            return [$reqCampusId];
        }

        return $campusIds;
    }

    /**
     * GET /api/v1/teacher-attendance
     * 主任查詢所屬分校老師打卡總覽
     */
    public function index(Request $request)
    {
        $effectiveCampusIds = $this->resolveEffectiveCampusIds($request);
        if ($effectiveCampusIds instanceof \Illuminate\Http\JsonResponse) {
            return $effectiveCampusIds;
        }

        $date      = $request->query('date', now()->toDateString());
        $teacherId = $request->query('teacher_id') ? (int) $request->query('teacher_id') : null;
        $status    = $request->query('status');
        $perPage   = 50;

        $query = DB::table('TeacherSingIn as ts')
            ->leftJoin('User as u', 'u.id', '=', 'ts.TeacherID')
            ->select([
                'ts.id',
                'ts.TeacherID as teacher_id',
                DB::raw("COALESCE(u.Name, '') as teacher_name"),
                'ts.CampusID as campus_id',
                'ts.SignInDT as sign_in_dt',
                'ts.SignOutDT as sign_out_dt',
                'ts.Status as status',
                'ts.Source as source',
            ])
            ->whereDate('ts.SignInDT', $date);

        if ($effectiveCampusIds !== null) {
            $query->whereIn('ts.CampusID', $effectiveCampusIds);
        }

        if ($teacherId) {
            $query->where('ts.TeacherID', $teacherId);
        }

        if ($status) {
            $query->where('ts.Status', $status);
        }

        /** @var \Illuminate\Pagination\LengthAwarePaginator $records */
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

        // 當天每位老師一列（依課表重算，跟月出勤表同一套）；有課沒刷的老師也在裡面
        $days = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            foreach ($this->loadMonth(substr($date, 0, 7), $effectiveCampusIds, $teacherId)['teachers'] as $t) {
                $days[] = ['teacher_id' => $t['teacher_id'], 'teacher_name' => $t['teacher_name']]
                    + collect($t['days'])->firstWhere('date', $date);
            }
        }

        return response()->json($records->toArray() + ['days' => $days]);
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
        ], [
            'new_signin_dt.required'   => '簽到時間必填',
            'new_signin_dt.date'       => '簽到時間格式不正確',
            'new_signout_dt.date'      => '簽退時間格式不正確',
            'new_signout_dt.after'     => '簽退時間必須晚於簽到時間',
            'adjust_reason.required'   => '補卡原因必填',
            'adjust_reason.min'        => '補卡原因至少要寫兩個字',
            'adjust_reason.max'        => '補卡原因最多 500 字',
        ]);

        $role      = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        $authUser  = $request->attributes->get('auth_user');

        $signin = TeacherSignIn::findOrFail($id);

        // 分校隔離
        if ($role !== 'super_admin' && ! empty($campusIds) && ! in_array($signin->CampusID, $campusIds)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        foreach ([$signin->SignInDT, $request->input('new_signin_dt')] as $dt) {
            if ($this->activeMonthClose((int) $signin->CampusID, Carbon::parse($dt)->format('Y-m'))) {
                return response()->json(['message' => '這個月的出勤已確認，要修改請先重新開啟'], 423);
            }
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

            // 原始值已保存至 audit 表；主表改為補卡後的有效時間，前端才能正確顯示。
            // Status（刷卡當下的判斷）不蓋掉，「已補卡」由 audit 表判斷。
            $signin->SignInDT  = $request->input('new_signin_dt');
            $signin->SignOutDT = $request->input('new_signout_dt'); // null 代表未補簽退
            $signin->MDT       = now();
            $signin->save();

            return [$adj, $signin];
        });

        return response()->json([
            'ok'            => true,
            'signin_id'     => $signin->id,
            'adjustment_id' => $adjustment->id,
            'new_status'    => $signin->Status,
        ]);
    }

    /**
     * POST /api/v1/teacher-attendance/month-close  {year_month, campus_id}
     * 主任確認某分校某月的老師出勤；確認後不能補卡。
     */
    public function closeMonth(Request $request)
    {
        [$campusId, $yearMonth, $error] = $this->monthCloseTarget($request);
        if ($error) {
            return $error;
        }
        if ($this->activeMonthClose($campusId, $yearMonth)) {
            return response()->json(['message' => '這個月已經確認過了'], 409);
        }

        DB::table('teacher_attendance_month_closes')->insert([
            'campus_id'         => $campusId,
            'year_month'        => $yearMonth,
            'closed_by_user_id' => $request->attributes->get('auth_user')->id,
            'closed_at'         => now(),
        ]);

        return response()->json(['ok' => true, 'month_close' => $this->monthCloseInfo($campusId, $yearMonth)]);
    }

    /**
     * POST /api/v1/teacher-attendance/month-reopen  {year_month, campus_id, reason}
     * 重新開啟已確認的月份（要寫原因，留紀錄）。
     */
    public function reopenMonth(Request $request)
    {
        $request->validate(['reason' => 'required|string|min:2|max:500']);
        [$campusId, $yearMonth, $error] = $this->monthCloseTarget($request);
        if ($error) {
            return $error;
        }

        $updated = DB::table('teacher_attendance_month_closes')
            ->where('campus_id', $campusId)
            ->where('year_month', $yearMonth)
            ->whereNull('reopened_at')
            ->update([
                'reopened_by_user_id' => $request->attributes->get('auth_user')->id,
                'reopened_at'         => now(),
                'reopen_reason'       => $request->input('reason'),
            ]);
        if (! $updated) {
            return response()->json(['message' => '這個月還沒確認'], 409);
        }

        return response()->json(['ok' => true]);
    }

    /** @return array{0:int,1:string,2:?\Illuminate\Http\JsonResponse} */
    private function monthCloseTarget(Request $request): array
    {
        $request->validate([
            'year_month' => 'required|date_format:Y-m',
            'campus_id'  => 'required|integer',
        ]);
        $campusId = (int) $request->input('campus_id');
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        if ($request->attributes->get('auth_role') !== 'super_admin' && ! in_array($campusId, $campusIds, true)) {
            return [0, '', response()->json(['message' => 'Forbidden'], 403)];
        }

        return [$campusId, (string) $request->input('year_month'), null];
    }

    private function activeMonthClose(int $campusId, string $yearMonth): ?object
    {
        return DB::table('teacher_attendance_month_closes')
            ->where('campus_id', $campusId)
            ->where('year_month', $yearMonth)
            ->whereNull('reopened_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array{closed_at:string,closed_by:string}|null */
    private function monthCloseInfo(int $campusId, string $yearMonth): ?array
    {
        $close = $this->activeMonthClose($campusId, $yearMonth);

        return $close ? [
            'closed_at' => (string) $close->closed_at,
            'closed_by' => (string) (DB::table('User')->where('id', $close->closed_by_user_id)->value('Name') ?? ''),
        ] : null;
    }

    /**
     * GET /api/v1/teacher-attendance/unclosed
     * 主任查詢今日有簽到但截止時間前未簽退的老師（結班檢查）
     */
    public function unclosed(Request $request)
    {
        $effectiveCampusIds = $this->resolveEffectiveCampusIds($request);
        if ($effectiveCampusIds instanceof \Illuminate\Http\JsonResponse) {
            return $effectiveCampusIds;
        }

        $date        = $request->query('date', now()->toDateString());
        $cutoffTime  = $request->query('cutoff_time', '20:00');

        $cutoffDT = Carbon::parse("{$date} {$cutoffTime}");

        $query = DB::table('TeacherSingIn as ts')
            ->leftJoin('User as u', 'u.id', '=', 'ts.TeacherID')
            ->select([
                'ts.TeacherID as teacher_id',
                DB::raw("COALESCE(u.Name, '') as teacher_name"),
                'ts.SignInDT as sign_in_dt',
                'ts.SignOutDT as sign_out_dt',
                'ts.Status as status',
            ])
            ->whereDate('ts.SignInDT', $date)
            ->whereNull('ts.SignOutDT')
            ->where('ts.SignInDT', '<=', $cutoffDT);

        if ($effectiveCampusIds !== null) {
            $query->whereIn('ts.CampusID', $effectiveCampusIds);
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

        $effectiveCampusIds = $this->resolveEffectiveCampusIds($request);
        if ($effectiveCampusIds instanceof \Illuminate\Http\JsonResponse) {
            return $effectiveCampusIds;
        }

        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');
        $format   = $request->query('format', 'csv');

        $query = DB::table('TeacherSingIn as ts')
            ->leftJoin('User as u', 'u.id', '=', 'ts.TeacherID')
            ->select([
                'ts.id',
                'ts.TeacherID as teacher_id',
                DB::raw("COALESCE(u.Name, '') as teacher_name"),
                'ts.CampusID as campus_id',
                'ts.SignInDT as sign_in_dt',
                'ts.SignOutDT as sign_out_dt',
                'ts.Status as status',
                'ts.Source as source',
            ])
            ->whereDate('ts.SignInDT', '>=', $dateFrom)
            ->whereDate('ts.SignInDT', '<=', $dateTo)
            ->orderBy('ts.SignInDT');

        if ($effectiveCampusIds !== null) {
            $query->whereIn('ts.CampusID', $effectiveCampusIds);
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
     * GET /api/v1/teacher-attendance/monthly?year_month=YYYY-MM[&teacher_id=]
     * 月檢視：每位老師每天一列（上班／下班／工時／註記）。老師只看得到自己（勞基法 §30 出勤紀錄副本）。
     */
    public function monthly(Request $request)
    {
        $request->validate(['year_month' => 'required|date_format:Y-m']);
        $yearMonth = $request->query('year_month');

        if ($request->attributes->get('auth_role') === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if (! $teacherId) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $campusIds = null;
        } else {
            $campusIds = $this->resolveEffectiveCampusIds($request);
            if ($campusIds instanceof \Illuminate\Http\JsonResponse) {
                return $campusIds;
            }
            $teacherId = $request->query('teacher_id') ? (int) $request->query('teacher_id') : null;
        }

        $month = $this->loadMonth($yearMonth, $campusIds, $teacherId);

        return response()->json([
            'year_month' => $yearMonth,
            'teachers'   => array_map(fn ($t) => [
                'teacher_id'   => $t['teacher_id'],
                'teacher_name' => $t['teacher_name'],
                'days'         => $t['days'],
                'totals'       => $t['totals'],
            ], $month['teachers']),
            'adjustments' => $month['adjustments'],
            'month_close' => is_array($campusIds) && count($campusIds) === 1 ? $this->monthCloseInfo((int) $campusIds[0], $yearMonth) : null,
        ]);
    }

    /**
     * GET /api/v1/teacher-attendance/export-monthly?year_month=YYYY-MM
     * 主任匯出整月老師出缺勤 XLSX（摘要 + 每位老師一張 + 修正紀錄）
     */
    public function exportMonthly(Request $request)
    {
        $request->validate([
            'year_month' => 'required|date_format:Y-m',
        ]);

        $effectiveCampusIds = $this->resolveEffectiveCampusIds($request);
        if ($effectiveCampusIds instanceof \Illuminate\Http\JsonResponse) {
            return $effectiveCampusIds;
        }

        $yearMonth = $request->query('year_month');
        $month = $this->loadMonth($yearMonth, $effectiveCampusIds, null);

        $campusName = '';
        if (! empty($effectiveCampusIds)) {
            $campusName = DB::table('Campus')->where('id', $effectiveCampusIds[0])->value('name') ?? '';
        }

        Log::info('[teacher-monthly-export]', [
            'user_id'     => $request->attributes->get('auth_user')?->id,
            'year_month'  => $yearMonth,
            'campus_ids'  => $request->attributes->get('auth_campus_ids', []),
            'campus_name' => $campusName,
            'teachers'    => count($month['teachers']),
        ]);

        $filename = "teacher-attendance-{$yearMonth}.xlsx";
        return Excel::download(
            new TeacherMonthlyAttendanceExport($month['teachers'], $month['adjustments'], $yearMonth, $campusName),
            $filename
        );
    }

    /**
     * 撈一個月的刷卡並算好每天一列。$campusIds = null 表示不限分校。
     *
     * @return array{teachers: list<array{teacher_id:int,teacher_name:string,swipes:Collection,days:array,totals:array}>, adjustments: list<array>}
     */
    private function loadMonth(string $yearMonth, ?array $campusIds, ?int $teacherId): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $yearMonth . '-01')->startOfDay();
        $to   = $from->copy()->endOfMonth();

        $query = DB::table('TeacherSingIn as ts')
            ->leftJoin('User as u', 'u.id', '=', 'ts.TeacherID')
            ->select([
                'ts.id',
                'ts.TeacherID as teacher_id',
                DB::raw("COALESCE(u.Name, '') as teacher_name"),
                'ts.CampusID as campus_id',
                'ts.SignInDT as sign_in_dt',
                'ts.SignOutDT as sign_out_dt',
                'ts.Source as source',
                'ts.Memo as memo',
            ])
            ->whereBetween('ts.SignInDT', [$from, $to])
            ->orderBy('ts.SignInDT');
        if ($campusIds !== null) {
            $query->whereIn('ts.CampusID', $campusIds);
        }
        if ($teacherId) {
            $query->where('ts.TeacherID', $teacherId);
        }
        $records = $query->get();

        $adjustments = DB::table('teacher_signin_adjustments as a')
            ->join('TeacherSingIn as ts', 'ts.id', '=', 'a.teacher_signin_id')
            ->leftJoin('User as t', 't.id', '=', 'ts.TeacherID')
            ->leftJoin('User as e', 'e.id', '=', 'a.adjusted_by_user_id')
            ->whereIn('a.teacher_signin_id', $records->pluck('id')->all())
            ->orderBy('a.id')
            ->get([
                'a.teacher_signin_id', 'ts.TeacherID as teacher_id',
                DB::raw("COALESCE(t.Name, '') as teacher_name"),
                DB::raw("COALESCE(e.Name, '') as adjusted_by"),
                'a.adjust_reason', 'a.original_signin_dt', 'a.original_signout_dt',
                'a.new_signin_dt', 'a.new_signout_dt', 'a.created_at',
            ]);
        $adjustedIds = array_fill_keys($adjustments->pluck('teacher_signin_id')->all(), true);
        // 第一次補卡前的原始上班時間（月表用來顯示「原本遲到幾分」）
        $originalIn = $adjustments->groupBy('teacher_signin_id')->map(fn ($g) => $g->first()->original_signin_dt);
        $records->each(function ($r) use ($originalIn) {
            $r->original_sign_in_dt = $originalIn->get($r->id);
        });

        // 跑校：同一天在 2 間以上分校刷卡（看老師全部分校，不受目前分校篩選）
        $runDates = [];
        if ($records->isNotEmpty()) {
            DB::table('TeacherSingIn')
                ->selectRaw('TeacherID, DATE(SignInDT) as d')
                ->whereIn('TeacherID', $records->pluck('teacher_id')->unique()->all())
                ->whereBetween('SignInDT', [$from, $to])
                ->groupBy('TeacherID', DB::raw('DATE(SignInDT)'))
                ->havingRaw('COUNT(DISTINCT CampusID) > 1')
                ->get()
                ->each(function ($r) use (&$runDates) {
                    $runDates[$r->TeacherID][$r->d] = true;
                });
        }

        // 有課的老師就算整月沒刷卡也要列出來（才看得到缺卡）
        $classes = TeacherClassCalendar::load($from->toDateString(), $to->toDateString(), $campusIds, $teacherId);
        $byTeacher = $records->groupBy('teacher_id');
        $ids = collect(array_keys($classes))->merge($byTeacher->keys())->map(fn ($id) => (int) $id)->unique();
        $names = DB::table('User')->whereIn('id', $ids->all())->pluck('Name', 'id');

        $teachers = $ids->map(function ($id) use ($byTeacher, $classes, $names, $yearMonth, $adjustedIds, $runDates) {
            $rows = $byTeacher->get($id, collect());
            $days = TeacherAttendanceMonth::days($rows, $yearMonth, $adjustedIds, $runDates[$id] ?? [], null, $classes[$id] ?? []);
            $name = (string) ($names[$id] ?? '');

            return [
                'teacher_id'   => (int) $id,
                'teacher_name' => $name !== '' ? $name : "老師{$id}",
                'swipes'       => $rows,
                'days'         => $days,
                'totals'       => TeacherAttendanceMonth::totals($days),
            ];
        })->sortBy('teacher_name')->values()->all();

        return [
            'teachers'    => $teachers,
            'adjustments' => $adjustments->map(fn ($a) => (array) $a)->all(),
        ];
    }
}
