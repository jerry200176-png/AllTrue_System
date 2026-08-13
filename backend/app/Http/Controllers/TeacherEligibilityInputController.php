<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Input and approval endpoints for facts that the core attendance/schedule
 * tables do not own. The report itself remains read-only; these endpoints make
 * the missing-data workflow usable without direct database writes.
 */
class TeacherEligibilityInputController extends Controller
{
    private const EVENT_TYPES = ['holiday', 'official_closure', 'leave'];

    public function index(Request $request)
    {
        $this->ensureTables();
        $campusIds = $this->resolveCampusIds($request);
        if ($campusIds instanceof \Illuminate\Http\JsonResponse) {
            return $campusIds;
        }

        $start = $request->filled('start') ? Carbon::parse($request->input('start'))->toDateString() : null;
        $end = $request->filled('end') ? Carbon::parse($request->input('end'))->toDateString() : null;
        if ($start !== null && $end !== null && $end < $start) {
            abort(422, 'end must be after or equal to start');
        }

        return response()->json([
            'events' => $this->scopedQuery('teacher_payroll_events', $campusIds)
                ->when($start !== null, fn ($query) => $query->whereDate('event_date', '>=', $start))
                ->when($end !== null, fn ($query) => $query->whereDate('event_date', '<=', $end))
                ->orderByDesc('event_date')->orderByDesc('id')->limit(500)->get(),
            'achievements' => $this->scopedQuery('teacher_payroll_achievements', $campusIds)
                ->when($start !== null, fn ($query) => $query->where(function ($nested) use ($start) {
                    $nested->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start);
                }))
                ->when($end !== null, fn ($query) => $query->where(function ($nested) use ($end) {
                    $nested->whereNull('starts_on')->orWhereDate('starts_on', '<=', $end);
                }))
                ->orderByDesc('id')->limit(500)->get(),
            'deductions' => $this->scopedQuery('teacher_payroll_deductions', $campusIds)
                ->when($start !== null, fn ($query) => $query->where(function ($nested) use ($start) {
                    $nested->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start);
                }))
                ->when($end !== null, fn ($query) => $query->where(function ($nested) use ($end) {
                    $nested->whereNull('starts_on')->orWhereDate('starts_on', '<=', $end);
                }))
                ->orderByDesc('id')->limit(500)->get(),
        ]);
    }

    public function storeEvent(Request $request)
    {
        $this->ensureTables();
        $data = $request->validate([
            'teacher_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'event_date' => ['required', 'date'],
            'event_type' => ['required', 'in:' . implode(',', self::EVENT_TYPES)],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'leave_type' => ['nullable', 'string', 'max:32'],
            'holiday_leave_hours' => ['nullable', 'numeric', 'min:0'],
            'makeup_completed' => ['nullable', 'boolean'],
            'evidence' => ['nullable', 'string', 'max:10000'],
        ]);
        $branchId = $this->resolveWriteBranch($request, $data['branch_id'] ?? null);
        $this->assertTeacherScope($request, $data['teacher_id'] ?? null, $branchId);
        $data['makeup_completed'] = array_key_exists('makeup_completed', $data)
            ? ($data['makeup_completed'] === null ? null : (bool) $data['makeup_completed'])
            : null;
        if ($data['event_type'] === 'leave' && ($data['hours'] ?? null) === null) {
            return response()->json(['message' => 'leave event requires hours'], 422);
        }

        $id = DB::table('teacher_payroll_events')->insertGetId([
            ...$data,
            'branch_id' => $branchId,
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'created_by' => $this->actorId($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'status' => 'pending'], 201);
    }

    public function storeAchievement(Request $request)
    {
        $this->ensureTables();
        $data = $request->validate([
            'teacher_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'student_id' => ['nullable', 'integer', 'min:1'],
            'outcome_key' => ['required', 'string', 'max:96'],
            'subject' => ['nullable', 'string', 'max:96'],
            'award_year' => ['nullable', 'integer', 'min:2027', 'max:2100'],
            'evidence' => ['nullable', 'string', 'max:10000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
        ]);
        $branchId = $this->resolveWriteBranch($request, $data['branch_id'] ?? null);
        $this->assertTeacherScope($request, $data['teacher_id'], $branchId);
        if (($data['starts_on'] ?? null) !== null && ($data['ends_on'] ?? null) !== null && $data['ends_on'] < $data['starts_on']) {
            return response()->json(['message' => 'ends_on must be on or after starts_on'], 422);
        }
        if (($data['student_id'] ?? null) !== null) {
            $studentQuery = DB::table('Student')->where('id', $data['student_id']);
            if ($branchId !== null) $studentQuery->where('CampusID', $branchId);
            if (!$studentQuery->exists()) return response()->json(['message' => 'student is outside the selected branch'], 422);
        }

        $id = DB::table('teacher_payroll_achievements')->insertGetId([
            ...$data,
            'branch_id' => $branchId,
            'status' => 'pending',
            'verified_by' => null,
            'verified_at' => null,
            'created_by' => $this->actorId($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'status' => 'pending'], 201);
    }

    public function storeDeduction(Request $request)
    {
        $this->ensureTables();
        $data = $request->validate([
            'teacher_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'deduction_key' => ['required', 'string', 'max:96'],
            'reason' => ['nullable', 'string', 'max:10000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
        ]);
        $branchId = $this->resolveWriteBranch($request, $data['branch_id'] ?? null);
        $this->assertTeacherScope($request, $data['teacher_id'], $branchId);
        if (($data['starts_on'] ?? null) !== null && ($data['ends_on'] ?? null) !== null && $data['ends_on'] < $data['starts_on']) {
            return response()->json(['message' => 'ends_on must be on or after starts_on'], 422);
        }

        $id = DB::table('teacher_payroll_deductions')->insertGetId([
            ...$data,
            'branch_id' => $branchId,
            'status' => 'pending',
            'director_confirmed_by' => null,
            'director_confirmed_at' => null,
            'hq_approved_by' => null,
            'hq_approved_at' => null,
            'created_by' => $this->actorId($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'status' => 'pending'], 201);
    }

    public function approveEvent(Request $request, int $id)
    {
        $this->ensureTables();
        $record = $this->recordForScope($request, 'teacher_payroll_events', $id);
        if (!$record) return response()->json(['message' => 'Not found'], 404);
        DB::table('teacher_payroll_events')->where('id', $id)->update([
            'status' => 'approved', 'approved_by' => $this->actorId($request), 'approved_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'status' => 'approved']);
    }

    public function verifyAchievement(Request $request, int $id)
    {
        $this->ensureTables();
        $record = $this->recordForScope($request, 'teacher_payroll_achievements', $id);
        if (!$record) return response()->json(['message' => 'Not found'], 404);
        DB::table('teacher_payroll_achievements')->where('id', $id)->update([
            'status' => 'verified', 'verified_by' => $this->actorId($request), 'verified_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'status' => 'verified']);
    }

    public function confirmDeduction(Request $request, int $id)
    {
        $this->ensureTables();
        $record = $this->recordForScope($request, 'teacher_payroll_deductions', $id);
        if (!$record) return response()->json(['message' => 'Not found'], 404);
        DB::table('teacher_payroll_deductions')->where('id', $id)->update([
            'director_confirmed_by' => $this->actorId($request), 'director_confirmed_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'status' => 'pending_hq_approval']);
    }

    public function approveDeduction(Request $request, int $id)
    {
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => 'Only headquarters can approve deductions'], 403);
        }
        $this->ensureTables();
        $record = $this->recordForScope($request, 'teacher_payroll_deductions', $id);
        if (!$record) return response()->json(['message' => 'Not found'], 404);
        if (!$record->director_confirmed_at) {
            return response()->json(['message' => 'Director confirmation is required first'], 422);
        }
        DB::table('teacher_payroll_deductions')->where('id', $id)->update([
            'status' => 'approved', 'hq_approved_by' => $this->actorId($request), 'hq_approved_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'status' => 'approved']);
    }

    /**
     * POST /api/v1/finance/teacher-eligibility/salary-profiles
     * 正職老師底薪(可隨時間調整，effective_from 之後的月份結算會採用最新一筆）。
     */
    public function storeSalaryProfile(Request $request)
    {
        $data = $request->validate([
            'teacher_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
        ]);
        $branchId = $this->resolveWriteBranch($request, $data['branch_id'] ?? null);
        $this->assertTeacherScope($request, $data['teacher_id'], $branchId);

        $profile = \App\Models\FulltimeSalaryProfile::create([
            'teacher_id' => $data['teacher_id'],
            'branch_id' => $branchId,
            'base_salary' => $data['base_salary'],
            'effective_from' => $data['effective_from'],
            'created_by' => $this->actorId($request),
        ]);

        return response()->json($profile, 201);
    }

    private function ensureTables(): void
    {
        foreach (['teacher_payroll_events', 'teacher_payroll_achievements', 'teacher_payroll_deductions'] as $table) {
            if (!Schema::hasTable($table)) abort(503, 'Teacher eligibility input tables are not ready');
        }
    }

    private function scopedQuery(string $table, ?array $campusIds)
    {
        return DB::table($table)->when($campusIds !== null, fn ($query) => $query->where(function ($nested) use ($campusIds) {
            $nested->whereNull('branch_id')->orWhereIn('branch_id', $campusIds);
        }));
    }

    private function recordForScope(Request $request, string $table, int $id): ?object
    {
        $campusIds = $this->resolveCampusIds($request);
        if ($campusIds instanceof \Illuminate\Http\JsonResponse) return null;
        return $this->scopedQuery($table, $campusIds)->where('id', $id)->first();
    }

    private function resolveWriteBranch(Request $request, mixed $branchId): ?int
    {
        $role = $request->attributes->get('auth_role');
        $branchId = $branchId !== null ? (int) $branchId : null;
        if ($role === 'super_admin') return $branchId;
        $allowed = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        if ($allowed === []) abort(403, 'Forbidden: no campus assignment');
        if ($branchId === null || !in_array($branchId, $allowed, true)) abort(403, 'Forbidden');
        return $branchId;
    }

    private function resolveCampusIds(Request $request): array|null|\Illuminate\Http\JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        $allowed = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        if ($role === 'super_admin') {
            return $request->filled('branch_id') ? [(int) $request->input('branch_id')] : null;
        }
        if ($allowed === []) return response()->json(['message' => 'Forbidden: no campus assignment'], 403);
        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            if (!in_array($branchId, $allowed, true)) return response()->json(['message' => 'Forbidden'], 403);
            return [$branchId];
        }
        return $allowed;
    }

    private function assertTeacherScope(Request $request, ?int $teacherId, ?int $branchId): void
    {
        if ($teacherId === null) return;
        $query = DB::table('User as u')
            ->join('UserCampus as uc', 'uc.UserID', '=', 'u.id')
            ->where('u.id', $teacherId)->where('u.type', 'T');
        if ($branchId !== null) $query->where('uc.CampusID', $branchId);
        if (!$query->exists()) abort(422, 'teacher is outside the selected branch or is not a teacher');
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->attributes->get('auth_user_id') ?? $request->attributes->get('auth_user')?->id;
        return $id !== null ? (int) $id : null;
    }
}
