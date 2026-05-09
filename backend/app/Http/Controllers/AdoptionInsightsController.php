<?php

namespace App\Http\Controllers;

use App\Models\ExceptionWorkflow;
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
            $tasks[] = [
                'id' => 'lr-' . (int) $row->id,
                'type' => 'learning_review',
                'status' => (string) $row->status,
                'title' => "待審評量：{$row->student_name}",
                'owner' => $row->owner_name,
                'due_at' => $row->due_date,
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
            $tasks[] = [
                'id' => 'ew-' . (int) $row->id,
                'type' => 'exception_workflow',
                'status' => (string) $row->status,
                'title' => "補課案件：{$row->student_name}",
                'owner' => '主任',
                'due_at' => optional($row->due_at)->toDateString(),
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
            $tasks[] = [
                'id' => 'sd-' . (int) $row->id,
                'type' => 'schedule_discrepancy',
                'status' => (string) $row->status,
                'title' => '課表回報：' . ($row->student_name ?: '未指定學生'),
                'owner' => '主任',
                'due_at' => optional($row->session_date)->toDateString(),
                'target' => ['page' => 'schedule-discrepancy', 'id' => (int) $row->id],
            ];
        }

        usort($tasks, static function (array $a, array $b): int {
            $priority = ['pending' => 0, 'changes_requested' => 1, 'open' => 2, 'acknowledged' => 3, 'candidate_ready' => 4];
            $pa = $priority[$a['status']] ?? 99;
            $pb = $priority[$b['status']] ?? 99;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string) ($a['due_at'] ?? ''), (string) ($b['due_at'] ?? ''));
        });

        return response()->json([
            'data' => array_slice($tasks, 0, 30),
            'meta' => [
                'branch_id' => $branchId,
                'generated_at' => now()->toIso8601String(),
                'count' => count($tasks),
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

        $from = Carbon::today()->subDays(6)->startOfDay();
        $to = Carbon::today()->endOfDay();

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

        return response()->json([
            'data' => [
                'window' => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
                'teacher_open_rate_pct' => $teacherRate,
                'director_open_rate_pct' => $directorRate,
                'teacher_opened_users' => $teacherOpened,
                'teacher_total_users' => count($teacherUserIds),
                'director_opened_users' => $directorOpened,
                'director_total_users' => count($directorUserIds),
                'system_completion_rate_pct' => $completionRate,
                'learning_records_filled' => $filledRecords,
                'attended_sessions' => $attendedSessions,
                'flow_submitted' => $flowSubmitted,
                'flow_undone' => $flowUndone,
            ],
            'meta' => ['branch_id' => $branchId, 'generated_at' => now()->toIso8601String()],
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
}

