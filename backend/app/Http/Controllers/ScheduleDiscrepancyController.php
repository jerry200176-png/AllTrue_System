<?php

namespace App\Http\Controllers;

use App\Models\ScheduleDiscrepancy;
use App\Services\ScheduleDiscrepancyNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScheduleDiscrepancyController extends Controller
{
    /**
     * POST /api/v1/schedule-discrepancies
     * Teachers, directors, or super_admin may file a report.
     */
    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|integer',
            'class_session_id' => 'nullable|integer',
            'discrepancy_type' => 'required|in:' . implode(',', ScheduleDiscrepancy::TYPES),
            'session_date' => 'nullable|date',
            'subject' => 'nullable|string|max:64',
            'student_name' => 'nullable|string|max:64',
            'time_range' => 'nullable|string|max:32',
            'corrected_time_range' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:500',
        ]);

        // FR-004: validate corrected_time_range format if provided.
        // Accepts HH:MM-HH:MM (whitespace tolerated; 24h; start must be < end on same day).
        $correctedTimeRange = $this->normalizeCorrectedTimeRange($request->input('corrected_time_range'));
        if ($correctedTimeRange === false) {
            return response()->json([
                'message' => '正確時間格式應為 HH:MM-HH:MM（例：14:00-16:00）',
                'errors' => ['corrected_time_range' => ['正確時間格式應為 HH:MM-HH:MM（例：14:00-16:00）']],
            ], 422);
        }

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $branchId = (int) $request->input('branch_id');
        if (!$this->canAccessBranch($request, $branchId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $type = (string) $request->input('discrepancy_type');
        $classSessionId = $request->input('class_session_id');
        $classSessionId = $classSessionId === null || $classSessionId === '' ? null : (int) $classSessionId;

        // missing_session 必須無 class_session_id，且必須有學生姓名 / 時段 / 科目才有處理依據
        if ($type === 'missing_session') {
            if ($classSessionId !== null) {
                return response()->json([
                    'message' => '此課不在系統中 (missing_session) 不可綁定 class_session_id',
                ], 422);
            }
            if (!$request->filled('student_name') || !$request->filled('subject') || !$request->filled('time_range')) {
                return response()->json([
                    'message' => '回報缺失堂次時，學生姓名、科目、時段為必填',
                ], 422);
            }
        }

        // 若綁定 class_session_id，驗證該堂次屬於同分校，防止跨校偽造
        if ($classSessionId) {
            $session = DB::table('ClassSession as cs')
                ->join('StudentClass as sc', 'cs.StudentClassID', '=', 'sc.ID')
                ->join('Student as s', 'sc.StudentID', '=', 's.id')
                ->where('cs.id', $classSessionId)
                ->select('s.CampusID', 'cs.SessionDate', 'cs.StartTime', 'cs.EndTime')
                ->first();
            if (!$session) {
                return response()->json(['message' => '找不到對應堂次'], 404);
            }
            if ((int) $session->CampusID !== $branchId) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            // FR-003: duplicate guard — 同 class_session_id 已有 pending/acknowledged 回報
            // 回傳現有紀錄（唯讀）而非建立新筆
            $existing = ScheduleDiscrepancy::query()
                ->where('class_session_id', $classSessionId)
                ->whereIn('status', [ScheduleDiscrepancy::STATUS_PENDING, ScheduleDiscrepancy::STATUS_ACKNOWLEDGED])
                ->orderBy('id', 'desc')
                ->first();
            if ($existing) {
                return response()->json([
                    'duplicate' => true,
                    'existing' => $this->formatOne($existing, includeAudit: false),
                ], 200);
            }
        }

        $attributes = [
            'class_session_id' => $classSessionId,
            'reporter_id' => $userId,                     // FR-005 tampering guard: inject from token, never from body
            'branch_id' => $branchId,
            'discrepancy_type' => $type,
            'session_date' => $request->input('session_date'),
            'subject' => $request->input('subject'),
            'student_name' => $request->input('student_name'),
            'time_range' => $request->input('time_range'),
            'notes' => $request->input('notes'),
            'status' => ScheduleDiscrepancy::STATUS_PENDING,
        ];

        // NFR (graceful degradation): if migration hasn't run yet, skip the new
        // column instead of blowing up on "Unknown column". Keeps rollout safe.
        if ($this->hasCorrectedTimeRangeColumn()) {
            $attributes['corrected_time_range'] = $correctedTimeRange;
        }

        $discrepancy = ScheduleDiscrepancy::create($attributes);

        // Fire LINE push after response is sent so the API call stays <500ms (NFR).
        // Failure never blocks the report being saved.
        $this->fireNotification($discrepancy);

        return response()->json([
            'duplicate' => false,
            'discrepancy' => $this->formatOne($discrepancy->fresh(), includeAudit: false),
        ], 201);
    }

    /**
     * GET /api/v1/schedule-discrepancies
     * director/admin/super_admin see all reports for their campus scope.
     */
    public function index(Request $request)
    {
        $request->validate([
            'branch_id' => 'nullable|integer',
            'status' => 'nullable|in:pending,acknowledged,resolved,withdrawn',
            'include_archived' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        // FR-001: director must scope to a single branch; super_admin can omit.
        $campusIds = $this->resolveCampusScope($request, requireBranch: true);
        $perPage = (int) ($request->input('per_page', 20));
        $includeArchived = filter_var($request->input('include_archived', false), FILTER_VALIDATE_BOOLEAN);

        $query = ScheduleDiscrepancy::query()
            ->leftJoin('User as reporter', 'schedule_discrepancies.reporter_id', '=', 'reporter.id')
            ->leftJoin('Campus as campus', 'schedule_discrepancies.branch_id', '=', 'campus.id')
            ->select([
                'schedule_discrepancies.*',
                'reporter.Name as reporter_name',
                'campus.name as branch_name',
            ]);

        if (!empty($campusIds)) {
            $query->whereIn('schedule_discrepancies.branch_id', $campusIds);
        }
        if ($request->filled('status')) {
            $query->where('schedule_discrepancies.status', $request->input('status'));
        }
        if (!$includeArchived) {
            $query->whereNull('schedule_discrepancies.archived_at');
        }

        $query->orderBy('schedule_discrepancies.created_at', 'desc');

        $rows = $query->paginate($perPage);
        $payload = $rows->toArray();
        $payload['data'] = array_map([$this, 'formatListRow'], $payload['data'] ?? []);

        return response()->json($payload);
    }

    /**
     * GET /api/v1/schedule-discrepancies/my
     * Teachers view only their own reports.
     */
    public function mine(Request $request)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'branch_id' => 'nullable|integer',
            'class_session_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($request->input('per_page', 50));
        $query = ScheduleDiscrepancy::query()
            ->where('reporter_id', $userId)
            ->orderBy('created_at', 'desc');

        if ($request->filled('class_session_id')) {
            $query->where('class_session_id', (int) $request->input('class_session_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        $rows = $query->paginate($perPage);
        $payload = $rows->toArray();
        $payload['data'] = array_map(fn ($row) => $this->formatOne(
            ScheduleDiscrepancy::hydrate([$row])->first(),
            includeAudit: true
        ), $payload['data'] ?? []);

        return response()->json($payload);
    }

    /**
     * GET /api/v1/schedule-discrepancies/active-for-session?class_session_id=N
     * Returns the active (pending/acknowledged) report for a given class session,
     * if any. Used by the attendance page to render the "已回報" badge.
     */
    public function activeForSession(Request $request)
    {
        $request->validate([
            'class_session_id' => 'required|integer',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $row = ScheduleDiscrepancy::query()
            ->where('class_session_id', (int) $request->input('class_session_id'))
            ->whereIn('status', [
                ScheduleDiscrepancy::STATUS_PENDING,
                ScheduleDiscrepancy::STATUS_ACKNOWLEDGED,
                ScheduleDiscrepancy::STATUS_RESOLVED,
            ])
            ->orderBy('id', 'desc')
            ->first();

        if (!$row) {
            return response()->json(['discrepancy' => null]);
        }

        return response()->json([
            'discrepancy' => $this->formatOne($row, includeAudit: true),
        ]);
    }

    /**
     * PUT /api/v1/schedule-discrepancies/{id}
     * Director updates status. Transitions:
     *   pending -> acknowledged
     *   pending|acknowledged -> resolved (requires resolution_note ≥10 chars)
     */
    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|in:acknowledged,resolved',
            'resolution_note' => 'nullable|string|max:500',
        ]);

        $userId = $this->resolveUserId($request);
        $role = (string) $request->attributes->get('auth_role');
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!in_array($role, ['director', 'admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $discrepancy = ScheduleDiscrepancy::query()->find($id);
        if (!$discrepancy) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Cross-campus guard: directors can only act on their own campus reports.
        $campusIds = $this->resolveCampusScope($request);
        if (!empty($campusIds) && !in_array((int) $discrepancy->branch_id, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $targetStatus = (string) $request->input('status');

        if ($targetStatus === 'acknowledged') {
            if ($discrepancy->status !== ScheduleDiscrepancy::STATUS_PENDING) {
                return response()->json([
                    'message' => '僅待處理狀態可標記為已確認',
                    'current_status' => $discrepancy->status,
                ], 409);
            }
            $discrepancy->status = ScheduleDiscrepancy::STATUS_ACKNOWLEDGED;
            $discrepancy->acknowledged_by = $userId;
            $discrepancy->acknowledged_at = now();
            $discrepancy->save();
        } elseif ($targetStatus === 'resolved') {
            if (!in_array($discrepancy->status, [
                ScheduleDiscrepancy::STATUS_PENDING,
                ScheduleDiscrepancy::STATUS_ACKNOWLEDGED,
            ], true)) {
                return response()->json([
                    'message' => '此回報已結案，無法再標記為已修正',
                    'current_status' => $discrepancy->status,
                ], 409);
            }

            $note = trim((string) $request->input('resolution_note', ''));
            // FR-008 防呆：處理說明最少 10 字
            if (mb_strlen($note) < 10) {
                return response()->json([
                    'message' => '處理說明為必填，至少 10 個字',
                    'errors' => ['resolution_note' => ['處理說明為必填，至少 10 個字']],
                ], 422);
            }

            // 若從 pending 直接跳 resolved，補紀錄 acknowledged
            if ($discrepancy->status === ScheduleDiscrepancy::STATUS_PENDING) {
                $discrepancy->acknowledged_by = $userId;
                $discrepancy->acknowledged_at = now();
            }
            $discrepancy->status = ScheduleDiscrepancy::STATUS_RESOLVED;
            $discrepancy->resolved_by = $userId;
            $discrepancy->resolved_at = now();
            $discrepancy->resolution_note = $note;
            $discrepancy->save();
        }

        return response()->json([
            'discrepancy' => $this->formatOne($discrepancy->fresh(), includeAudit: true),
        ]);
    }

    /**
     * POST /api/v1/schedule-discrepancies/{id}/withdraw
     * FR-011: teacher may soft-cancel their own report before a director acks it.
     */
    public function withdraw(Request $request, int $id)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $discrepancy = ScheduleDiscrepancy::query()->find($id);
        if (!$discrepancy) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ((int) $discrepancy->reporter_id !== (int) $userId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($discrepancy->status !== ScheduleDiscrepancy::STATUS_PENDING) {
            return response()->json([
                'message' => '主任已開始處理，無法撤銷',
                'current_status' => $discrepancy->status,
            ], 409);
        }

        $discrepancy->status = ScheduleDiscrepancy::STATUS_WITHDRAWN;
        $discrepancy->withdrawn_at = now();
        $discrepancy->save();

        return response()->json([
            'discrepancy' => $this->formatOne($discrepancy->fresh(), includeAudit: true),
        ]);
    }

    /**
     * GET /api/v1/schedule-discrepancies/summary
     * Lightweight counts for the dashboard card.
     */
    public function summary(Request $request)
    {
        $campusIds = $this->resolveCampusScope($request);

        $query = ScheduleDiscrepancy::query()->whereNull('archived_at');
        if (!empty($campusIds)) {
            $query->whereIn('branch_id', $campusIds);
        }

        $counts = $query->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return response()->json([
            'pending' => (int) ($counts['pending'] ?? 0),
            'acknowledged' => (int) ($counts['acknowledged'] ?? 0),
            'resolved' => (int) ($counts['resolved'] ?? 0),
            'withdrawn' => (int) ($counts['withdrawn'] ?? 0),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function resolveUserId(Request $request): ?int
    {
        $authUser = $request->attributes->get('auth_user');
        return $authUser?->id ? (int) $authUser->id : null;
    }

    /**
     * Resolve the list of campus IDs this request is allowed to view.
     *
     * FR-001/002 (2026-04-18): a director must always scope to a single
     * branch — aggregating multiple campuses into one list leaks other
     * branches' private context (student names, teacher disputes). If a
     * multi-campus director forgets branch_id we refuse the request with
     * 422 so the caller fixes itself instead of silently widening scope.
     *
     * super_admin still returns [] (all branches) when no branch_id is
     * provided, because cross-branch oversight is a documented privilege
     * of that role (PM decision, see PRD OQ-01).
     *
     * @return int[] empty array ⇒ no branch filter (super_admin only)
     */
    private function resolveCampusScope(Request $request, bool $requireBranch = false): array
    {
        $role = (string) $request->attributes->get('auth_role');
        $campusIds = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        $branchId = (int) $request->input('branch_id', 0);

        if ($role === 'super_admin') {
            // super_admin honours branch_id when supplied (single-branch drill-in)
            // but otherwise keeps the legacy "see everything" behaviour.
            if ($branchId > 0) {
                return [$branchId];
            }
            return [];
        }

        if ($branchId > 0) {
            if (!empty($campusIds) && !in_array($branchId, $campusIds, true)) {
                abort(403, 'Forbidden');
            }
            return [$branchId];
        }

        // No branch_id supplied by a non-super_admin caller.
        if ($requireBranch && count($campusIds) > 1) {
            abort(response()->json([
                'message' => '請指定分校（branch_id 必填）',
                'errors' => ['branch_id' => ['請指定分校（branch_id 必填）']],
            ], 422));
        }

        return $campusIds;
    }

    /**
     * Normalize + validate `corrected_time_range` (optional HH:MM-HH:MM).
     *
     * Returns:
     *   null    → empty/omitted (valid, store as NULL)
     *   string  → normalized "HH:MM-HH:MM"
     *   false   → invalid (caller should return 422)
     */
    private function normalizeCorrectedTimeRange($raw)
    {
        if ($raw === null) return null;
        $v = trim((string) $raw);
        if ($v === '') return null;
        // Strip spaces so "14:00 - 16:00" is accepted.
        $v = preg_replace('/\s+/', '', $v);
        if (mb_strlen($v) > 32) return false;
        if (!preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $v, $m)) {
            return false;
        }
        [, $h1, $m1, $h2, $m2] = $m;
        $h1 = (int) $h1; $m1 = (int) $m1; $h2 = (int) $h2; $m2 = (int) $m2;
        if ($h1 > 23 || $h2 > 23 || $m1 > 59 || $m2 > 59) return false;
        $start = $h1 * 60 + $m1;
        $end   = $h2 * 60 + $m2;
        if ($start >= $end) return false;
        // Zero-pad hours for consistency.
        return sprintf('%02d:%02d-%02d:%02d', $h1, $m1, $h2, $m2);
    }

    private function hasCorrectedTimeRangeColumn(): bool
    {
        try {
            return Schema::hasColumn('schedule_discrepancies', 'corrected_time_range');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function canAccessBranch(Request $request, int $branchId): bool
    {
        $role = (string) $request->attributes->get('auth_role');
        if ($role === 'super_admin') return true;
        $campusIds = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        return in_array($branchId, $campusIds, true);
    }

    private function fireNotification(ScheduleDiscrepancy $discrepancy): void
    {
        // No queue worker on the Pi — use afterResponse so the request returns fast.
        try {
            dispatch(function () use ($discrepancy) {
                ScheduleDiscrepancyNotifier::notify($discrepancy);
            })->afterResponse();
        } catch (\Throwable $e) {
            // dispatch itself throwing is abnormal — log and continue.
            \Illuminate\Support\Facades\Log::error('schedule_discrepancy.dispatch_failed', [
                'discrepancy_id' => $discrepancy->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatOne(?ScheduleDiscrepancy $d, bool $includeAudit = false): ?array
    {
        if (!$d) return null;

        $out = [
            'id' => (int) $d->id,
            'class_session_id' => $d->class_session_id ? (int) $d->class_session_id : null,
            'branch_id' => (int) $d->branch_id,
            'reporter_id' => (int) $d->reporter_id,
            'discrepancy_type' => $d->discrepancy_type,
            'discrepancy_type_label' => ScheduleDiscrepancy::TYPE_LABELS[$d->discrepancy_type] ?? $d->discrepancy_type,
            'session_date' => $d->session_date ? $d->session_date->format('Y-m-d') : null,
            'subject' => $d->subject,
            'student_name' => $d->student_name,
            'time_range' => $d->time_range,
            'corrected_time_range' => $d->corrected_time_range ?? null,
            'notes' => $d->notes,
            'status' => $d->status,
            'created_at' => $d->created_at?->toIso8601String(),
            'resolution_note' => $d->resolution_note,
        ];

        if ($includeAudit) {
            $out['acknowledged_at'] = $d->acknowledged_at?->toIso8601String();
            $out['resolved_at'] = $d->resolved_at?->toIso8601String();
            $out['withdrawn_at'] = $d->withdrawn_at?->toIso8601String();
        }

        return $out;
    }

    /**
     * Format a row coming from the joined index() query (stdClass-like array).
     */
    private function formatListRow($row): array
    {
        $row = (array) $row;
        $sessionDate = $row['session_date'] ?? null;
        if ($sessionDate && !is_string($sessionDate)) {
            $sessionDate = (string) $sessionDate;
        }
        if ($sessionDate) {
            $sessionDate = substr($sessionDate, 0, 10);
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'class_session_id' => isset($row['class_session_id']) ? (int) $row['class_session_id'] : null,
            'branch_id' => (int) ($row['branch_id'] ?? 0),
            'branch_name' => $row['branch_name'] ?? null,
            'reporter_id' => (int) ($row['reporter_id'] ?? 0),
            'reporter_name' => $row['reporter_name'] ?? null,
            'discrepancy_type' => $row['discrepancy_type'] ?? 'other',
            'discrepancy_type_label' => ScheduleDiscrepancy::TYPE_LABELS[$row['discrepancy_type'] ?? ''] ?? ($row['discrepancy_type'] ?? '其他'),
            'session_date' => $sessionDate,
            'subject' => $row['subject'] ?? null,
            'student_name' => $row['student_name'] ?? null,
            'time_range' => $row['time_range'] ?? null,
            'corrected_time_range' => $row['corrected_time_range'] ?? null,
            'notes' => $row['notes'] ?? null,
            'status' => $row['status'] ?? 'pending',
            'resolution_note' => $row['resolution_note'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'acknowledged_at' => $row['acknowledged_at'] ?? null,
            'acknowledged_by' => isset($row['acknowledged_by']) ? (int) $row['acknowledged_by'] : null,
            'resolved_at' => $row['resolved_at'] ?? null,
            'resolved_by' => isset($row['resolved_by']) ? (int) $row['resolved_by'] : null,
            'withdrawn_at' => $row['withdrawn_at'] ?? null,
            'archived_at' => $row['archived_at'] ?? null,
        ];
    }
}
