<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Student;
use App\Models\ParentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemTrustController extends Controller
{
    public function summary(Request $request)
    {
        $role = (string) $request->attributes->get('auth_role', '');
        $branchId = (int) $request->query('branch_id', 0);

        if ($branchId <= 0 && $role !== 'super_admin') {
            $branchId = (int) $request->attributes->get('auth_current_campus_id', 0);
        }

        if ($branchId <= 0) {
            return response()->json(['message' => 'branch_id is required'], 422);
        }

        if (!$this->canAccessBranch($request, $branchId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $this->buildSummaryPayload($branchId)]);
    }

    public function parentSummary(Request $request)
    {
        $token = (string) ($request->bearerToken() ?? '');
        if ($token === '') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $session = ParentSession::query()
            ->where('TokenHash', hash('sha256', $token))
            ->where('ExpiresAt', '>', now())
            ->first();
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $student = Student::find((int) $session->StudentID);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $branchId = (int) ($student->CampusID ?? 0);
        if ($branchId <= 0) {
            return response()->json(['message' => 'Campus not found'], 422);
        }

        return response()->json(['data' => $this->buildSummaryPayload($branchId)]);
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

    private function readRecentImprovements(int $lookbackDays, int $limit): array
    {
        $path = base_path('../docs/CHANGELOG.md');
        if (!is_readable($path)) {
            return [];
        }

        $content = (string) file_get_contents($path);
        if ($content === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $cutoff = Carbon::today()->subDays(max(0, $lookbackDays));
        $items = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(\d{4}-\d{2}-\d{2})\s+—\s+(.+)$/u', $line, $m)) {
                if ($current && $current['title'] !== '' && $current['summary'] !== '') {
                    $items[] = $current;
                }
                $date = Carbon::createFromFormat('Y-m-d', $m[1]);
                if ($date->lt($cutoff)) {
                    $current = null;
                    continue;
                }
                $current = [
                    'date' => $m[1],
                    'title' => trim($m[2]),
                    'summary' => '',
                ];
                continue;
            }

            if (!$current) {
                continue;
            }

            if (preg_match('/^\-\s+(.+)$/u', $line, $bm)) {
                $bullet = trim($bm[1]);
                if ($bullet !== '') {
                    $current['summary'] = mb_substr($bullet, 0, 220);
                }
            }
        }

        if ($current && $current['title'] !== '' && $current['summary'] !== '') {
            $items[] = $current;
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp($b['date'], $a['date']);
        });

        return array_slice($items, 0, max(1, $limit));
    }

    private function knownIssuesForBranch(int $branchId): array
    {
        $issues = (array) config('system_trust.known_issues', []);
        $filtered = array_values(array_filter($issues, static function (array $issue) use ($branchId): bool {
            $branches = array_map('intval', (array) ($issue['branch_ids'] ?? []));
            if ($branches === []) {
                return true;
            }
            return in_array($branchId, $branches, true);
        }));

        return array_map(static function (array $issue): array {
            return [
                'id' => (string) ($issue['id'] ?? ''),
                'title' => (string) ($issue['title'] ?? '已知問題'),
                'status' => (string) ($issue['status'] ?? 'investigating'),
                'severity' => (string) ($issue['severity'] ?? 'medium'),
                'workaround' => (string) ($issue['workaround'] ?? ''),
                'updated_at' => (string) ($issue['updated_at'] ?? ''),
            ];
        }, $filtered);
    }

    private function reliabilitySnapshot(int $branchId): array
    {
        $today = Carbon::today()->toDateString();
        $openBugs = DB::table('bug_reports')
            ->where('CampusID', $branchId)
            ->whereNotIn('status', ['closed'])
            ->count();

        $highBugs = DB::table('bug_reports')
            ->where('CampusID', $branchId)
            ->whereNotIn('status', ['closed'])
            ->whereIn('severity', ['high', 'critical'])
            ->count();

        $pendingDiscrepancy = DB::table('schedule_discrepancies')
            ->where('branch_id', $branchId)
            ->whereIn('status', ['pending', 'acknowledged'])
            ->count();

        $pendingLearning = DB::table('LearningRecord as lr')
            ->join('StudentClass as sc', 'sc.ID', '=', 'lr.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('st.CampusID', $branchId)
            ->whereIn('lr.Status', ['pending', 'changes_requested'])
            ->whereDate('lr.SessionDate', '<=', $today)
            ->count();

        return [
            'open_bugs' => (int) $openBugs,
            'high_priority_open_bugs' => (int) $highBugs,
            'pending_discrepancies' => (int) $pendingDiscrepancy,
            'pending_learning_reviews' => (int) $pendingLearning,
            'health_status' => 'ok',
        ];
    }

    private function buildSummaryPayload(int $branchId): array
    {
        return [
            'branch_id' => $branchId,
            'generated_at' => now()->toIso8601String(),
            'recent_improvements' => $this->readRecentImprovements(30, 6),
            'known_issues' => $this->knownIssuesForBranch($branchId),
            'reliability_snapshot' => $this->reliabilitySnapshot($branchId),
        ];
    }
}
