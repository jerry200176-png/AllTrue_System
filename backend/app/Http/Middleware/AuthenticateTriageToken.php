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

        // Past this point the token is authenticated, so every outcome is audited without the
        // denial cap (#4004): successes never were capped, and a valid token's failures must
        // not be droppable by another client's flood of refused requests from the same IP.
        // The cap stays on unauthenticated refusals above, where the requester is unknown.
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Laravel 8's routing pipeline normally renders controller exceptions into a 500
            // before they get here; this catches anything that still escapes.
            try {
                $this->audit($request, (int) $pat->getKey(), 'failure', 500, 'read_failed', false);
            } catch (\Throwable $auditError) {
                report($auditError); // never mask the original exception
            }
            throw $e;
        }
        $status = $response->getStatusCode();
        $this->audit($request, (int) $pat->getKey(), $status < 400 ? 'success' : 'failure', $status, $status < 400 ? 'read' : 'read_failed', false);

        return $response;
    }

    private function audit(Request $request, ?int $tokenId, string $outcome, int $status, string $reason, bool $capped = true): void
    {
        if ($capped && $status >= 400 && !TriageTokenService::mayAuditDenial($request->ip())) { return; } // unauthenticated refusals share the cross-route cap
        $route = $request->route();
        $bugId = $request->route('id');
        $attachmentId = $request->route('attachmentId');
        SecurityAuditEvent::append('triage_token.read', $outcome, [
            'actor_type' => 'triage_token',
            'actor_id' => $tokenId,
            'subject_type' => 'bug_report',
            'subject_id' => is_numeric($bugId) ? (int) $bugId : null,
        ], [
            'attachment_ref' => SecurityAuditEvent::ref('bug_report_attachment', is_numeric($attachmentId) ? (int) $attachmentId : null),
            'route' => $route instanceof Route ? (string) $route->getName() : '',
            'method' => $request->method(),
            'client_ip_ref' => SecurityAuditEvent::ref('ip', $request->ip()),
            'http_status' => $status,
            'reason_code' => $reason,
        ]);
    }
}
