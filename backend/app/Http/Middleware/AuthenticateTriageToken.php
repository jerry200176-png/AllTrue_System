<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditEvent;
use App\Services\TriageTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Authenticates a read-only triage token on the triage.* routes and writes one
 * security_audit_events row per request (token id, route, report id, IP, status).
 * User session tokens are not accepted here.
 */
final class AuthenticateTriageToken
{
    public function handle(Request $request, Closure $next)
    {
        $pat = TriageTokenService::resolve($request->bearerToken());
        if ($pat === null) {
            $this->audit($request, null, 'failure', 401, 'invalid_token');
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (TriageTokenService::isExpired($pat)) {
            $this->audit($request, (int) $pat->getKey(), 'failure', 401, 'token_expired');
            return response()->json(['message' => 'Token expired', 'code' => 'token_expired'], 401);
        }
        if (!TriageTokenService::ownerIsSuperAdmin($pat)) {
            $this->audit($request, (int) $pat->getKey(), 'denied', 403, 'owner_not_super_admin');
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $pat->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('triage_token_id', (int) $pat->getKey());

        $response = $next($request);
        $status = $response->getStatusCode();
        $this->audit($request, (int) $pat->getKey(), $status < 400 ? 'success' : 'failure', $status, $status < 400 ? 'read' : 'read_failed');

        return $response;
    }

    private function audit(Request $request, ?int $tokenId, string $outcome, int $status, string $reason): void
    {
        $route = $request->route();
        // #4100: on the billing snapshot the path id is a contract; the report it was read for is the query param.
        $isBilling = $route instanceof Route && $route->getName() === 'triage.student-classes.billing-snapshot';
        // Every query a resolved token makes on the billing snapshot is logged, refusals included (Founder: 留下查詢紀錄);
        // the route's own throttle bounds that volume. Every other refusal or failure keeps the cross-route cap below.
        $alwaysAudit = $isBilling && $tokenId !== null && in_array($status, [404, 422], true);
        if (!$alwaysAudit) {
            if ($status >= 400 && !TriageTokenService::mayAuditDenial($request->ip())) { return; } // every refusal or failure shares the cross-route cap
        }
        $bugId = $request->route('id');
        $attachmentId = $request->route('attachmentId');
        $queryBugId = $request->query('bug_report_id');
        $subject = [
            'actor_type' => 'triage_token',
            'actor_id' => $tokenId,
            'subject_type' => 'bug_report',
            'subject_id' => is_numeric($bugId) ? (int) $bugId : null,
        ];
        if ($isBilling) {
            $subject['subject_type'] = 'student_class'; // the path id on this route is a contract, not a report
        }
        SecurityAuditEvent::append('triage_token.read', $outcome, $subject, [
            'attachment_ref' => SecurityAuditEvent::ref('bug_report_attachment', is_numeric($attachmentId) ? (int) $attachmentId : null),
            'bug_report_ref' => $isBilling && is_numeric($queryBugId) ? SecurityAuditEvent::ref('bug_report', (int) $queryBugId) : null,
            'route' => $route instanceof Route ? (string) $route->getName() : '',
            'method' => $request->method(),
            'client_ip_ref' => SecurityAuditEvent::ref('ip', $request->ip()),
            'http_status' => $status,
            'reason_code' => $reason,
        ]);
    }
}
