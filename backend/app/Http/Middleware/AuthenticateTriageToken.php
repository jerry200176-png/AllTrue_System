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
        // Founder (留下查詢紀錄): every billing query by a resolved token is logged, refusals and 500s included; others keep the cap.
        // #4004: a resolved token's server error (an attachment read that throws is rendered as a 500 before it
        // reaches this middleware) is always recorded, so another client's flood of refusals cannot drop it.
        $alwaysAudited = $tokenId !== null && ($status >= 500 || ($isBilling && in_array($status, [404, 422], true)));
        if (!$alwaysAudited && $status >= 400 && !TriageTokenService::mayAuditDenial($request->ip())) { return; } // every other refusal or failure shares the cross-route cap
        $bugId = $request->route('id');
        $attachmentId = $request->route('attachmentId');
        $queryBugId = $request->query('bug_report_id');
        $billingSubject = $isBilling ? ['subject_type' => 'student_class'] : []; // later key wins: the path id is a contract
        SecurityAuditEvent::append('triage_token.read', $outcome, [
            'actor_type' => 'triage_token',
            'actor_id' => $tokenId,
            'subject_type' => 'bug_report',
            'subject_id' => is_numeric($bugId) ? (int) $bugId : null,
            ...$billingSubject,
        ], [
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
