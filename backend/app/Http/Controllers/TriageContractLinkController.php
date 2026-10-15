<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\BugReportContractLink;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * #4100 (Founder option B): super-admin session API that links ONE open bug report to ONE course
 * contract for the triage billing snapshot, or soft-revokes that link. API only (no UI yet).
 * The triage token can never reach these routes (not in triage_tokens.allowed_routes).
 */
class TriageContractLinkController extends Controller
{
    public function __construct()
    {
        $this->middleware(fn (Request $r, \Closure $next) => in_array($r->attributes->get('auth_user')?->status, ['inactive', 'suspended'], true) ? response()->json(['message' => 'Forbidden'], 403) : $next($r));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'bug_report_id' => ['required', 'integer', 'min:1'],
            'student_class_id' => ['required', 'integer', 'min:1'],
        ]);
        $user = $request->attributes->get('auth_user');
        if (!$user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $bugId = (int) $data['bug_report_id'];
        $courseId = (int) $data['student_class_id'];

        $bug = BugReport::query()->whereKey($bugId)->first();
        if (!$bug instanceof BugReport) {
            return $this->reject('bug_report_not_found');
        }
        $courseCampusId = BugReportContractLink::contractCampusId($courseId);
        if ($courseCampusId === null) {
            return $this->reject('contract_not_found');
        }
        if ($courseCampusId !== (int) $bug->getAttribute('CampusID')) {
            return $this->reject('campus_mismatch');
        }

        try {
            // The open check runs ONLY under the report's row lock: a concurrent close either commits first (refused here)
            // or waits for this insert and then revokes it through BugReport::saved. No link outlives a close.
            $link = DB::transaction(function () use ($bugId, $courseId, $user) {
                $locked = BugReport::query()->whereKey($bugId)->lockForUpdate()->first(['id', 'status']);
                return $locked instanceof BugReport && in_array((string) $locked->getAttribute('status'), BugReportContractLink::OPEN_REPORT_STATUSES, true)
                    ? BugReportContractLink::create(['bug_report_id' => $bugId, 'student_class_id' => $courseId, 'created_by' => (int) $user->getKey(), 'created_at' => now(), 'active' => true])
                    : null;
            });
        } catch (QueryException $e) {
            // Only uniq_bug_contract_link_active (one live contract per report) is a 409; anything else is a real error.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062 || stripos($e->getMessage(), 'uniq_bug_contract_link_active') === false) {
                throw $e;
            }
            return response()->json(['message' => 'This report already has an active contract link', 'code' => 'link_exists'], 409);
        }
        if (!$link instanceof BugReportContractLink) {
            return $this->reject('bug_report_not_open');
        }
        SecurityAuditEvent::append('triage_contract_link.created', 'success', [
            'actor_type' => 'user', 'actor_id' => $user->getKey(), 'subject_type' => 'student_class', 'subject_id' => (int) $link->student_class_id,
        ], ['bug_report_ref' => SecurityAuditEvent::ref('bug_report', $bugId), 'client_ip_ref' => SecurityAuditEvent::ref('ip', $request->ip())]);

        return response()->json(['id' => (int) $link->id, 'bug_report_id' => $bugId, 'student_class_id' => $courseId], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $user = $request->attributes->get('auth_user');
        // Conditional update inside revoke(): a second (concurrent) revoke gets 404 and writes no audit row.
        if (!$user instanceof User || !BugReportContractLink::revoke($id, (int) $user->getKey(), 'manual_revoke')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['revoked' => true, 'id' => $id]);
    }

    private function reject(string $code)
    {
        return response()->json(['message' => 'Cannot link this report and contract', 'code' => $code], 422);
    }
}
