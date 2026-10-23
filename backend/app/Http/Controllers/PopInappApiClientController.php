<?php

namespace App\Http\Controllers;

use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Operations\Inapp\InappAgentClients;
use App\Operations\Inapp\InappStandingPolicy;
use Illuminate\Http\Request;

/**
 * ADR-POP-015 §3: super-admin issuance of the `pop_inapp_agent` POP requester credential (list / issue / revoke),
 * mirroring TriageTokenController. The raw key is returned once; it can draft and dry-run only, never approve or execute.
 */
class PopInappApiClientController extends Controller
{
    public function __construct()
    {
        $this->middleware(fn (Request $r, \Closure $next) => in_array($r->attributes->get('auth_user')?->status, ['inactive', 'suspended'], true) ? response()->json(['message' => 'Forbidden'], 403) : $next($r));
    }

    public function index()
    {
        return response()->json([
            'data' => InappAgentClients::list(),
            'scopes' => InappAgentClients::SCOPES,
            'operations' => array_keys(InappStandingPolicy::OPERATIONS),
            'ttl_days' => (int) config('pop_inapp.agent_client_ttl_days'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'campus_ids' => ['required', 'array', 'min:1', 'max:50'],
            'campus_ids.*' => ['required', 'integer', 'min:1', 'distinct', 'exists:Campus,id'],
        ]);
        $user = $request->attributes->get('auth_user');
        if (!$user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $created = InappAgentClients::issue($user, trim($data['name']), array_values(array_map('intval', $data['campus_ids'])));
        $this->audit('pop_inapp_agent.issued', $user, (int) $created['record']['id'], ['campus_count' => count($data['campus_ids'])]);

        return response()->json($created, 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, int $id)
    {
        $revoked = InappAgentClients::revoke($id);
        if ($revoked === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $user = $request->attributes->get('auth_user');
        $this->audit('pop_inapp_agent.revoked', $user instanceof User ? $user : null, $id, ['row_count' => count($revoked['cancelled_request_ids'])]);

        return response()->json(['revoked' => true] + $revoked);
    }

    /** @param array<string, int> $metadata */
    private function audit(string $event, ?User $actor, int $clientId, array $metadata): void
    {
        SecurityAuditEvent::append($event, 'success', [
            'actor_type' => 'user',
            'actor_id' => $actor?->getKey(),
            'subject_type' => 'api_client',
            'subject_id' => $clientId,
        ], $metadata + ['client_ip_ref' => SecurityAuditEvent::ref('ip', request()->ip())]);
    }
}
