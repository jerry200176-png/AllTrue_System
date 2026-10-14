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
        if (!in_array((string) $bug->getAttribute('status'), BugReportContractLink::OPEN_REPORT_STATUSES, true)) {
            return $this->reject('bug_report_not_open');
        }
        $courseCampusId = BugReportContractLink::contractCampusId($courseId);
        if ($courseCampusId === null) {
            return $this->reject('contract_not_found');
        }
        if ($courseCampusId !== (int) $bug->getAttribute('CampusID')) {
            return $this->reject('campus_mismatch');
        }

        try {
            $link = DB::transaction(fn () => BugReportContractLink::create([
                'bug_report_id' => $bugId,
                'student_class_id' => $courseId,
                'created_by' => (int) $user->getKey(),
                'created_at' => now(),
                'active' => true,
            ]));
        } catch (QueryException) {
            // uniq_bug_contract_link_active: a report has at most one live contract link.
            return response()->json(['message' => 'This report already has an active contract link', 'code' => 'link_exists'], 409);
        }
        $this->audit('triage_contract_link.created', $user, $link);

        return response()->json(['id' => (int) $link->id, 'bug_report_id' => $bugId, 'student_class_id' => $courseId], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $user = $request->attributes->get('auth_user');
        $link = BugReportContractLink::query()->active()->whereKey($id)->first();
        if (!$user instanceof User || !$link instanceof BugReportContractLink) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $link->forceFill(['revoked_at' => now(), 'revoked_by' => (int) $user->getKey(), 'active' => null])->save();
        $this->audit('triage_contract_link.revoked', $user, $link);

        return response()->json(['revoked' => true, 'id' => (int) $link->id]);
    }

    private function reject(string $code)
    {
        return response()->json(['message' => 'Cannot link this report and contract', 'code' => $code], 422);
    }

    private function audit(string $event, User $actor, BugReportContractLink $link): void
    {
        SecurityAuditEvent::append($event, 'success', [
            'actor_type' => 'user',
            'actor_id' => $actor->getKey(),
            'subject_type' => 'student_class',
            'subject_id' => (int) $link->student_class_id,
        ], [
            'bug_report_ref' => SecurityAuditEvent::ref('bug_report', (int) $link->bug_report_id),
            'client_ip_ref' => SecurityAuditEvent::ref('ip', request()->ip()),
        ]);
    }
}
