<?php

namespace App\Http\Controllers;

use App\Models\ExceptionWorkflow;
use App\Models\Campus;
use App\Models\LearningRecord;
use App\Models\ScheduleDiscrepancy;
use App\Models\User;
use App\Models\UserLoginActivity;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdoptionInsightsController extends Controller
{
    private const SLA_WARNING_HOURS = 4;

    public function taskTracker(Request $request)
    {
        $branchId = $this->resolveBranchId($request);
        if ($branchId <= 0) {
            return response()->json(['message' => 'branch_id is required'], 422);
        }
        if (!$this->canAccessBranch($request, $branchId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $today = Carbon::today()->toDateString();
        $tasks = [];

        $pendingLearning = LearningRecord::query()
            ->from('LearningRecord as lr')
            ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->leftJoin('User as u', 'u.id', '=', 'lr.TeacherID')
            ->whereIn('lr.Status', ['pending', 'changes_requested'])
            ->whereDate('lr.SessionDate', '<=', $today)
            ->where('st.CampusID', $branchId)
            ->orderBy('lr.SessionDate')
            ->limit(20)
            ->get([
                'lr.id',
                'lr.Status as status',
                'lr.SessionDate as due_date',
                DB::raw("COALESCE(st.name, '—') as student_name"),
                DB::raw("COALESCE(u.Name, '未指派') as owner_name"),
            ]);

        foreach ($pendingLearning as $row) {
            $dueAt = (string) $row->due_date;
            $slaLevel = $this->resolveSlaLevel($dueAt);
            $tasks[] = [
                'id' => 'lr-' . (int) $row->id,
                'type' => 'learning_review',
                'status' => (string) $row->status,
                'title' => "待審評量：{$row->student_name}",
                'owner' => $row->owner_name,
                'owner_role' => 'teacher',
                'due_at' => $dueAt,
                'sla_level' => $slaLevel,
                'blocked_count' => 1,
                'target' => ['page' => 'learning', 'recordId' => (int) $row->id],
            ];
        }

        $workflowRows = ExceptionWorkflow::query()
            ->from('exception_workflows as ew')
            ->leftJoin('Student as st', 'st.id', '=', 'ew.student_id')
            ->where('ew.campus_id', $branchId)
            ->whereIn('ew.status', ['open', 'candidate_ready'])
            ->orderByRaw('COALESCE(ew.due_at, ew.created_at) asc')
            ->limit(20)
            ->get([
                'ew.id',
                'ew.status',
                'ew.due_at',
                DB::raw("COALESCE(st.name, '學生') as student_name"),
            ]);

        foreach ($workflowRows as $row) {
            $dueAt = optional($row->due_at)->toDateString();
            $slaLevel = $this->resolveSlaLevel((string) $dueAt);
            $tasks[] = [
                'id' => 'ew-' . (int) $row->id,
                'type' => 'exception_workflow',
                'status' => (string) $row->status,
                'title' => "補課案件：{$row->student_name}",
                'owner' => '主任',
                'owner_role' => 'director',
                'due_at' => $dueAt,
                'sla_level' => $slaLevel,
                'blocked_count' => 1,
                'target' => ['section' => 'exception-workflows'],
            ];
        }

        $discrepancies = ScheduleDiscrepancy::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', ['pending', 'acknowledged'])
            ->orderBy('created_at')
            ->limit(20)
            ->get(['id', 'status', 'student_name', 'session_date', 'discrepancy_type']);

        foreach ($discrepancies as $row) {
            $dueAt = optional($row->session_date)->toDateString();
            $slaLevel = $this->resolveSlaLevel((string) $dueAt);
            $tasks[] = [
                'id' => 'sd-' . (int) $row->id,
                'type' => 'schedule_discrepancy',
                'status' => (string) $row->status,
                'title' => '課表回報：' . ($row->student_name ?: '未指定學生'),
                'owner' => '主任',
                'owner_role' => 'director',
                'due_at' => $dueAt,
                'sla_level' => $slaLevel,
                'blocked_count' => 1,
                'target' => ['page' => 'schedule-discrepancy', 'id' => (int) $row->id],
            ];
        }

        usort($tasks, static function (array $a, array $b): int {
            $slaPriority = ['breached' => 0, 'warning' => 1, 'normal' => 2];
            $priority = ['pending' => 0, 'changes_requested' => 1, 'open' => 2, 'acknowledged' => 3, 'candidate_ready' => 4];
            $sa = $slaPriority[$a['sla_level'] ?? 'normal'] ?? 9;
            $sb = $slaPriority[$b['sla_level'] ?? 'normal'] ?? 9;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            $pa = $priority[$a['status']] ?? 99;
            $pb = $priority[$b['status']] ?? 99;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string) ($a['due_at'] ?? ''), (string) ($b['due_at'] ?? ''));
        });

        $summary = $this->buildTaskSummary($tasks);
        $missionCenter = $this->buildMissionCenterSummary($tasks);

        return response()->json([
            'data' => array_slice($tasks, 0, 30),
            'meta' => [
                'branch_id' => $branchId,
                'generated_at' => now()->toIso8601String(),
                'count' => count($tasks),
                'summary' => $summary,
                'mission_center' => $missionCenter,
            ],
        ]);
    }

    public function activityLog(Request $request)
    {
        $branchId = $this->resolveBranchId($request);
        if ($branchId <= 0) {
            return response()->json(['message' => 'branch_id is required'], 422);
        }
        if (!$this->canAccessBranch($request, $branchId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $items = [];

        if (Schema::hasTable('user_login_activities')) {
            $logins = UserLoginActivity::query()
                ->from('user_login_activities as ula')
                ->join('UserCampus as uc', 'uc.UserID', '=', 'ula.user_id')
                ->join('User as u', 'u.id', '=', 'ula.user_id')
                ->where('uc.CampusID', $branchId)
                ->where('ula.success', true)
                ->orderByDesc('ula.login_at')
                ->limit(20)
                ->get(['ula.user_id', 'u.Name as actor_name', 'ula.login_at']);

            foreach ($logins as $row) {
                $items[] = [
                    'type' => 'login',
                    'actor' => $row->actor_name ?: ('#' . $row->user_id),
                    'action' => '登入系統',
                    'at' => optional($row->login_at)->toIso8601String(),
                ];
            }
        }

        $approvals = LearningRecord::query()
            ->from('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->leftJoin('User as approver', 'approver.id', '=', 'lr.ApprovedBy')
            ->where('st.CampusID', $branchId)
            ->whereNotNull('lr.ApprovedAt')
            ->orderByDesc('lr.ApprovedAt')
            ->limit(20)
            ->get(['lr.id', 'lr.ApprovedAt', DB::raw("COALESCE(approver.Name, '主任') as actor_name")]);

        foreach ($approvals as $row) {
            $items[] = [
                'type' => 'learning_approved',
                'actor' => $row->actor_name,
                'action' => '核准評量',
                'target' => 'record #' . (int) $row->id,
                'at' => optional($row->ApprovedAt)->toIso8601String(),
            ];
        }

        $discrepancyUpdates = ScheduleDiscrepancy::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'status', 'updated_at', 'student_name']);

        foreach ($discrepancyUpdates as $row) {
            $items[] = [
                'type' => 'schedule_discrepancy',
                'actor' => '系統',
                'action' => '課表回報狀態更新：' . (string) $row->status,
                'target' => $row->student_name ?: ('#' . $row->id),
                'at' => optional($row->updated_at)->toIso8601String(),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''));
        });

        return response()->json([
            'data' => array_slice($items, 0, 50),
            'meta' => ['branch_id' => $branchId, 'generated_at' => now()->toIso8601String()],
        ]);
    }

    public function weeklyMetrics(Request $request)
    {
        $branchId = $this->resolveBranchId($request);
        if ($branchId <= 0) {
            return response()->json(['message' => 'branch_id is required'], 422);
        }
        if (!$this->canAccessBranch($request, $branchId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $this->buildWeeklyMetricsPayload($branchId);

        return response()->json([
            'data' => $payload,
            'meta' => ['branch_id' => $branchId, 'generated_at' => now()->toIso8601String()],
        ]);
    }

    public function crossBranchMetrics(Request $request)
    {
        $role = (string) $request->attributes->get('auth_role', '');
        if ($role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $requestedBranchIds = collect((array) $request->query('branch_ids', []))
            ->flatMap(function ($raw) {
                if (is_string($raw)) {
                    return explode(',', $raw);
                }
                return [$raw];
            })
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $campuses = Campus::query()
            ->when($requestedBranchIds->isNotEmpty(), fn ($q) => $q->whereIn('id', $requestedBranchIds->all()))
            ->orderBy('id')
            ->get(['id', 'name']);

        $rows = [];
        foreach ($campuses as $campus) {
            $metrics = $this->buildWeeklyMetricsPayload((int) $campus->id);
            $rows[] = [
                'branch_id' => (int) $campus->id,
                'branch_name' => (string) ($campus->name ?? ('#' . $campus->id)),
                'teacher_open_rate_pct' => (float) ($metrics['teacher_open_rate_pct'] ?? 0),
                'director_open_rate_pct' => (float) ($metrics['director_open_rate_pct'] ?? 0),
                'system_completion_rate_pct' => (float) ($metrics['system_completion_rate_pct'] ?? 0),
                'parent_feedback_reply_rate_pct' => (float) ($metrics['parent_feedback_reply_rate_pct'] ?? 0),
                'bug_reopen_rate_pct' => (float) ($metrics['bug_reopen_rate_pct'] ?? 0),
                'trust_contract_backlog' => (int) ($metrics['trust_contract_backlog'] ?? 0),
            ];
        }

        $count = max(count($rows), 1);
        $summary = [
            'branch_count' => count($rows),
            'teacher_open_rate_pct_avg' => round(array_sum(array_column($rows, 'teacher_open_rate_pct')) / $count, 1),
            'director_open_rate_pct_avg' => round(array_sum(array_column($rows, 'director_open_rate_pct')) / $count, 1),
            'system_completion_rate_pct_avg' => round(array_sum(array_column($rows, 'system_completion_rate_pct')) / $count, 1),
            'parent_feedback_reply_rate_pct_avg' => round(array_sum(array_column($rows, 'parent_feedback_reply_rate_pct')) / $count, 1),
            'bug_reopen_rate_pct_avg' => round(array_sum(array_column($rows, 'bug_reopen_rate_pct')) / $count, 1),
            'trust_contract_backlog_total' => array_sum(array_column($rows, 'trust_contract_backlog')),
        ];

        return response()->json([
            'data' => $rows,
            'meta' => [
                'scope' => 'super_admin_all_branches',
                'generated_at' => now()->toIso8601String(),
                'summary' => $summary,
            ],
        ]);
    }

    public function recordEvent(Request $request)
    {
        $payload = $request->validate([
            'event' => 'required|string|max:64',
            'branch_id' => 'nullable|integer|min:1',
            'meta' => 'nullable|array',
        ]);

        $branchId = (int) ($payload['branch_id'] ?? $request->attributes->get('auth_current_campus_id') ?? 0);
        if ($branchId > 0 && !$this->canAccessBranch($request, $branchId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        Log::channel('daily')->info('adoption_event', [
            'event' => $payload['event'],
            'branch_id' => $branchId > 0 ? $branchId : null,
            'user_id' => (int) ($request->attributes->get('auth_user_id') ?? 0),
            'role' => (string) ($request->attributes->get('auth_role') ?? ''),
            'meta' => $payload['meta'] ?? [],
        ]);

        return response()->json(['ok' => true]);
    }

    private function resolveBranchId(Request $request): int
    {
        $fromQuery = (int) $request->query('branch_id', 0);
        if ($fromQuery > 0) {
            return $fromQuery;
        }
        return (int) $request->attributes->get('auth_current_campus_id', 0);
    }

    private function canAccessBranch(Request $request, int $branchId): bool
    {
        $role = (string) $request->attributes->get('auth_role', '');
        if ($role === 'super_admin') {
            return true;
        }
        $allowed = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        return in_array($branchId, $allowed, true);
    }

    private function resolveSlaLevel(string $dueAt): string
    {
        if ($dueAt === '') {
            return 'normal';
        }
        $due = Carbon::parse($dueAt)->endOfDay();
        $now = Carbon::now();
        if ($due->lt($now)) {
            return 'breached';
        }
        $diff = $due->diffInHours($now, false);
        if ($diff >= -self::SLA_WARNING_HOURS && $diff <= 0) {
            return 'warning';
        }
        return 'normal';
    }

    private function buildTaskSummary(array $tasks): array
    {
        $summary = [
            'due_total' => count($tasks),
            'breached_total' => 0,
            'warning_total' => 0,
            'blocked_total' => 0,
            'done_total' => 0,
            'teacher_due_total' => 0,
            'director_due_total' => 0,
        ];
        foreach ($tasks as $task) {
            if (($task['sla_level'] ?? '') === 'breached') {
                $summary['breached_total']++;
            } elseif (($task['sla_level'] ?? '') === 'warning') {
                $summary['warning_total']++;
            }
            $summary['blocked_total'] += (int) ($task['blocked_count'] ?? 0);
            $ownerRole = (string) ($task['owner_role'] ?? '');
            if ($ownerRole === 'teacher') {
                $summary['teacher_due_total']++;
            } elseif ($ownerRole === 'director') {
                $summary['director_due_total']++;
            }
        }
        return $summary;
    }

    private function buildMissionCenterSummary(array $tasks): array
    {
        $teacherOpen = 0;
        $directorOpen = 0;
        $teacherBreached = 0;
        $directorBreached = 0;

        foreach ($tasks as $task) {
            $ownerRole = (string) ($task['owner_role'] ?? '');
            $slaLevel = (string) ($task['sla_level'] ?? 'normal');
            if ($ownerRole === 'teacher') {
                $teacherOpen++;
                if ($slaLevel === 'breached') {
                    $teacherBreached++;
                }
            } elseif ($ownerRole === 'director') {
                $directorOpen++;
                if ($slaLevel === 'breached') {
                    $directorBreached++;
                }
            }
        }

        return [
            'teacher' => [
                'remaining_total' => $teacherOpen,
                'breached_total' => $teacherBreached,
                'completion_hint' => $teacherOpen === 0 ? 'all_clear' : 'action_required',
            ],
            'director' => [
                'remaining_total' => $directorOpen,
                'breached_total' => $directorBreached,
                'completion_hint' => $directorOpen === 0 ? 'all_clear' : 'action_required',
            ],
        ];
    }

    private function buildTodayWorkflowSummary(int $branchId, Carbon $day): array
    {
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $day->copy()->endOfDay();
        $todayDate = $day->toDateString();

        $dueLearning = LearningRecord::query()
            ->from('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereIn('lr.Status', ['pending', 'changes_requested'])
            ->whereDate('lr.SessionDate', '<=', $todayDate)
            ->count();

        $dueWorkflows = ExceptionWorkflow::query()
            ->where('campus_id', $branchId)
            ->whereIn('status', ['open', 'candidate_ready'])
            ->count();

        $dueDiscrepancies = ScheduleDiscrepancy::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', ['pending', 'acknowledged'])
            ->count();

        $breachedLearning = LearningRecord::query()
            ->from('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereIn('lr.Status', ['pending', 'changes_requested'])
            ->whereDate('lr.SessionDate', '<', $todayDate)
            ->count();

        $breachedWorkflows = ExceptionWorkflow::query()
            ->where('campus_id', $branchId)
            ->whereIn('status', ['open', 'candidate_ready'])
            ->whereDate(DB::raw('COALESCE(due_at, created_at)'), '<', $todayDate)
            ->count();

        $breachedDiscrepancies = ScheduleDiscrepancy::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', ['pending', 'acknowledged'])
            ->whereDate('session_date', '<', $todayDate)
            ->count();

        $doneLearning = LearningRecord::query()
            ->from('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereBetween('lr.ApprovedAt', [$dayStart, $dayEnd])
            ->count();

        $doneWorkflows = ExceptionWorkflow::query()
            ->where('campus_id', $branchId)
            ->where('status', 'confirmed')
            ->whereBetween('updated_at', [$dayStart, $dayEnd])
            ->count();

        $doneDiscrepancies = ScheduleDiscrepancy::query()
            ->where('branch_id', $branchId)
            ->where('status', 'resolved')
            ->whereBetween('updated_at', [$dayStart, $dayEnd])
            ->count();

        return [
            'due_total' => $dueLearning + $dueWorkflows + $dueDiscrepancies,
            'done_total' => $doneLearning + $doneWorkflows + $doneDiscrepancies,
            'breached_total' => $breachedLearning + $breachedWorkflows + $breachedDiscrepancies,
            'as_of' => $todayDate,
        ];
    }

    private function buildWeeklyMetricsPayload(int $branchId): array
    {
        $from = Carbon::today()->subDays(6)->startOfDay();
        $to = Carbon::today()->endOfDay();
        $todaySummary = $this->buildTodayWorkflowSummary($branchId, Carbon::today());

        $teacherUserIds = UserCampus::query()
            ->from('UserCampus as uc')
            ->join('User as u', 'u.id', '=', 'uc.UserID')
            ->where('uc.CampusID', $branchId)
            ->where('u.type', 'T')
            ->pluck('u.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $directorUserIds = UserCampus::query()
            ->from('UserCampus as uc')
            ->join('User as u', 'u.id', '=', 'uc.UserID')
            ->where('uc.CampusID', $branchId)
            ->whereIn('u.type', ['D', 'A'])
            ->pluck('u.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $teacherOpened = 0;
        $directorOpened = 0;
        if (Schema::hasTable('user_login_activities')) {
            $teacherOpened = UserLoginActivity::query()
                ->whereIn('user_id', $teacherUserIds ?: [0])
                ->where('success', true)
                ->whereBetween('login_at', [$from, $to])
                ->distinct('user_id')
                ->count('user_id');
            $directorOpened = UserLoginActivity::query()
                ->whereIn('user_id', $directorUserIds ?: [0])
                ->where('success', true)
                ->whereBetween('login_at', [$from, $to])
                ->distinct('user_id')
                ->count('user_id');
        }

        $attendedSessions = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereBetween('cs.SessionDate', [$from->toDateString(), $to->toDateString()])
            ->whereIn(DB::raw('LOWER(cs.Status)'), ['attended', 'late'])
            ->count();

        $filledRecords = LearningRecord::query()
            ->from('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereBetween('lr.SessionDate', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('lr.Content')
            ->count();

        $flowSubmitted = DB::table('schedules')
            ->where('branch_id', $branchId)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', ['leave', 'rescheduled', 'cancelled'])
            ->count();

        $flowUndone = DB::table('schedules')
            ->where('branch_id', $branchId)
            ->whereBetween('updated_at', [$from, $to])
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->count();

        $teacherRate = count($teacherUserIds) > 0 ? round(($teacherOpened / count($teacherUserIds)) * 100, 1) : 0.0;
        $directorRate = count($directorUserIds) > 0 ? round(($directorOpened / count($directorUserIds)) * 100, 1) : 0.0;
        $completionRate = $attendedSessions > 0 ? round(($filledRecords / $attendedSessions) * 100, 1) : 0.0;

        $prevFrom = Carbon::today()->subDays(13)->startOfDay();
        $prevTo = Carbon::today()->subDays(7)->endOfDay();
        $prevTeacherOpened = 0;
        $prevDirectorOpened = 0;
        if (Schema::hasTable('user_login_activities')) {
            $prevTeacherOpened = UserLoginActivity::query()
                ->whereIn('user_id', $teacherUserIds ?: [0])
                ->where('success', true)
                ->whereBetween('login_at', [$prevFrom, $prevTo])
                ->distinct('user_id')
                ->count('user_id');
            $prevDirectorOpened = UserLoginActivity::query()
                ->whereIn('user_id', $directorUserIds ?: [0])
                ->where('success', true)
                ->whereBetween('login_at', [$prevFrom, $prevTo])
                ->distinct('user_id')
                ->count('user_id');
        }
        $prevTeacherRate = count($teacherUserIds) > 0 ? round(($prevTeacherOpened / count($teacherUserIds)) * 100, 1) : 0.0;
        $prevDirectorRate = count($directorUserIds) > 0 ? round(($prevDirectorOpened / count($directorUserIds)) * 100, 1) : 0.0;

        $teacherActivatedUsers = $this->countTeacherActivatedUsers($branchId, $from, $to);
        $directorActivatedUsers = $this->countDirectorActivatedUsers($branchId, $from, $to);
        $teacherActivationRate = count($teacherUserIds) > 0 ? round(($teacherActivatedUsers / count($teacherUserIds)) * 100, 1) : 0.0;
        $directorActivationRate = count($directorUserIds) > 0 ? round(($directorActivatedUsers / count($directorUserIds)) * 100, 1) : 0.0;
        $teacherActivationWithin24h = $teacherOpened > 0 ? round(($teacherActivatedUsers / $teacherOpened) * 100, 1) : 0.0;
        $directorActivationWithin24h = $directorOpened > 0 ? round(($directorActivatedUsers / $directorOpened) * 100, 1) : 0.0;

        $parentMetrics = $this->buildParentFeedbackMetrics($branchId, $from, $to);
        $qualityMetrics = $this->buildQualityMetrics($branchId, $from, $to, $todaySummary);

        return [
            'window' => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
            'teacher_open_rate_pct' => $teacherRate,
            'director_open_rate_pct' => $directorRate,
            'teacher_opened_users' => $teacherOpened,
            'teacher_total_users' => count($teacherUserIds),
            'director_opened_users' => $directorOpened,
            'director_total_users' => count($directorUserIds),
            'teacher_activated_users' => $teacherActivatedUsers,
            'director_activated_users' => $directorActivatedUsers,
            'teacher_activation_rate_pct' => $teacherActivationRate,
            'director_activation_rate_pct' => $directorActivationRate,
            'activation_funnel' => [
                'teacher' => [
                    'opened_users' => $teacherOpened,
                    'activated_users' => $teacherActivatedUsers,
                    'activation_within_24h_pct' => $teacherActivationWithin24h,
                ],
                'director' => [
                    'opened_users' => $directorOpened,
                    'activated_users' => $directorActivatedUsers,
                    'activation_within_24h_pct' => $directorActivationWithin24h,
                ],
            ],
            'system_completion_rate_pct' => $completionRate,
            'learning_records_filled' => $filledRecords,
            'attended_sessions' => $attendedSessions,
            'flow_submitted' => $flowSubmitted,
            'flow_undone' => $flowUndone,
            'workflow_daily' => $todaySummary,
            'parent_feedback_reply_rate_pct' => $parentMetrics['reply_rate_pct'],
            'parent_feedback_unread_backlog' => $parentMetrics['unread_backlog'],
            'parent_feedback_replied_count' => $parentMetrics['replied_count'],
            'parent_feedback_approved_records' => $parentMetrics['approved_count'],
            'bug_reopen_rate_pct' => $qualityMetrics['bug_reopen_rate_pct'],
            'p1p0_median_lead_hours' => $qualityMetrics['p1p0_median_lead_hours'],
            'trust_contract_backlog' => $qualityMetrics['trust_contract_backlog'],
            'quality' => $qualityMetrics,
            'comparison' => [
                'previous_window' => ['start' => $prevFrom->toDateString(), 'end' => $prevTo->toDateString()],
                'teacher_open_rate_pct' => $prevTeacherRate,
                'director_open_rate_pct' => $prevDirectorRate,
                'delta_teacher_open_rate_pct' => round($teacherRate - $prevTeacherRate, 1),
                'delta_director_open_rate_pct' => round($directorRate - $prevDirectorRate, 1),
            ],
        ];
    }

    private function countTeacherActivatedUsers(int $branchId, Carbon $from, Carbon $to): int
    {
        $learningUsers = LearningRecord::query()
            ->from('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereBetween('lr.SessionDate', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('lr.TeacherID')
            ->distinct('lr.TeacherID')
            ->pluck('lr.TeacherID')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->all();

        $attendanceUsers = DB::table('StudentSingIn as si')
            ->join('Student as st', 'st.id', '=', 'si.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereBetween('si.SignInDT', [$from, $to])
            ->whereNotNull('si.TeacherID')
            ->distinct('si.TeacherID')
            ->pluck('si.TeacherID')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->all();

        return count(array_unique(array_merge($learningUsers, $attendanceUsers)));
    }

    private function countDirectorActivatedUsers(int $branchId, Carbon $from, Carbon $to): int
    {
        $approvalUsers = LearningRecord::query()
            ->from('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereBetween('lr.ApprovedAt', [$from, $to])
            ->whereNotNull('lr.ApprovedBy')
            ->distinct('lr.ApprovedBy')
            ->pluck('lr.ApprovedBy')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->all();

        $bugActionUsers = DB::table('bug_report_status_logs as bl')
            ->join('bug_reports as b', 'b.id', '=', 'bl.bug_report_id')
            ->where('b.CampusID', $branchId)
            ->whereBetween('bl.created_at', [$from, $to])
            ->whereNotNull('bl.changed_by')
            ->distinct('bl.changed_by')
            ->pluck('bl.changed_by')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->all();

        return count(array_unique(array_merge($approvalUsers, $bugActionUsers)));
    }

    private function buildParentFeedbackMetrics(int $branchId, Carbon $from, Carbon $to): array
    {
        $approvedCount = LearningRecord::query()
            ->from('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->where('lr.Status', 'approved')
            ->whereBetween('lr.SessionDate', [$from->toDateString(), $to->toDateString()])
            ->count();

        $repliedCount = DB::table('learning_record_feedbacks as lf')
            ->join('LearningRecord as lr', 'lr.id', '=', 'lf.learning_record_id')
            ->join('Student as st', 'st.id', '=', 'lf.student_id')
            ->where('st.CampusID', $branchId)
            ->where('lr.Status', 'approved')
            ->whereBetween('lf.updated_at', [$from, $to])
            ->distinct('lf.learning_record_id')
            ->count('lf.learning_record_id');

        $unreadBacklog = DB::table('learning_record_feedbacks')
            ->where('campus_id', $branchId)
            ->where(function ($q) {
                $q->whereNull('last_read_by_director_at')
                    ->orWhereColumn('last_read_by_director_at', '<', 'updated_at');
            })
            ->count();

        $replyRate = $approvedCount > 0 ? round(($repliedCount / $approvedCount) * 100, 1) : 0.0;

        return [
            'approved_count' => $approvedCount,
            'replied_count' => $repliedCount,
            'unread_backlog' => $unreadBacklog,
            'reply_rate_pct' => $replyRate,
        ];
    }

    private function buildQualityMetrics(int $branchId, Carbon $from, Carbon $to, array $todaySummary): array
    {
        $resolvedCount = DB::table('bug_report_status_logs as bl')
            ->join('bug_reports as b', 'b.id', '=', 'bl.bug_report_id')
            ->where('b.CampusID', $branchId)
            ->where('bl.to_status', 'resolved')
            ->whereBetween('bl.created_at', [$from, $to])
            ->count();

        $reopenedCount = DB::table('bug_report_status_logs as bl')
            ->join('bug_reports as b', 'b.id', '=', 'bl.bug_report_id')
            ->where('b.CampusID', $branchId)
            ->where('bl.from_status', 'resolved')
            ->where('bl.to_status', 'in_progress')
            ->whereBetween('bl.created_at', [$from, $to])
            ->count();

        $reopenRate = $resolvedCount > 0 ? round(($reopenedCount / $resolvedCount) * 100, 1) : 0.0;

        $durations = DB::table('bug_reports as b')
            ->join('bug_report_status_logs as rs', function ($join) {
                $join->on('rs.bug_report_id', '=', 'b.id')
                    ->where('rs.to_status', '=', 'resolved');
            })
            ->where('b.CampusID', $branchId)
            ->whereIn('b.severity', ['high', 'critical'])
            ->whereBetween('rs.created_at', [$from, $to])
            ->selectRaw('TIMESTAMPDIFF(HOUR, b.created_at, rs.created_at) as lead_hours')
            ->pluck('lead_hours')
            ->map(fn ($v) => max(0, (int) $v))
            ->sort()
            ->values();

        $medianLead = 0.0;
        $n = $durations->count();
        if ($n > 0) {
            $mid = intdiv($n, 2);
            $medianLead = $n % 2 === 1
                ? (float) $durations[$mid]
                : round(((float) $durations[$mid - 1] + (float) $durations[$mid]) / 2, 1);
        }

        return [
            'resolved_count' => $resolvedCount,
            'reopened_count' => $reopenedCount,
            'bug_reopen_rate_pct' => $reopenRate,
            'p1p0_median_lead_hours' => $medianLead,
            'trust_contract_backlog' => (int) ($todaySummary['due_total'] ?? 0),
            'trust_contract_breached_total' => (int) ($todaySummary['breached_total'] ?? 0),
        ];
    }
}

