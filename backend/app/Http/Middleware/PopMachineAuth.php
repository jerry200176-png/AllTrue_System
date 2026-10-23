<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\SecurityAuditEvent;
use App\Operations\Inapp\InappAgentClients;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticate the production POP control-plane machine principal.
 *
 * This is deliberately separate from the attendance-device X-API-KEY path:
 * existing device credentials can never acquire POP capabilities by accident.
 * A `pop_inapp_agent` client (ADR-POP-015 §3) reaches the same draft/dry-run routes, limited to its explicit
 * campus list and to the in-app operations; a `pop_control_plane` client behaves exactly as before.
 */
final class PopMachineAuth
{
    public function handle(Request $request, Closure $next, string $requiredScope)
    {
        $rawKey = (string) $request->header('X-POP-MACHINE-KEY', '');
        if ($rawKey === '') {
            $this->auditFailure($requiredScope, 'missing_key');
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $client = ApiClient::query()
            ->where('ApiKeyHash', hash('sha256', $rawKey))
            ->where('Active', 1)
            ->whereIn('Purpose', ['pop_control_plane', InappAgentClients::PURPOSE])
            ->first();
        if (!$client instanceof ApiClient) {
            $this->auditFailure($requiredScope, 'invalid_machine_principal');
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $inapp = $client->Purpose === InappAgentClients::PURPOSE;
        $campusIds = $inapp ? InappAgentClients::campusIds($client) : [(int) $client->CampusID];
        $auditContext = [
            'campus_id' => $inapp ? null : (int) $client->CampusID,
            'actor_type' => 'pop_machine',
            'actor_id' => (int) $client->id,
        ];
        $scopes = is_array($client->Scopes) ? $client->Scopes : [];
        $denied = !in_array($requiredScope, array_map('strval', $scopes), true) ? 'scope_denied'
            : ($inapp ? (InappAgentClients::denial($client) ?? (InappAgentClients::routeAllows($client, $request) ? null : 'operation_denied')) : null);
        if ($denied !== null) {
            SecurityAuditEvent::append('pop.machine.auth', 'failure', $auditContext, ['reason_code' => $denied]);
            // A revoked or expired credential is no credential; every other denial is a scope refusal.
            return in_array($denied, ['client_revoked', 'client_expired'], true)
                ? response()->json(['message' => 'Unauthorized'], 401)
                : response()->json(['message' => 'Forbidden'], 403);
        }

        $request->attributes->set('auth_principal_type', 'machine');
        $request->attributes->set('auth_actor', 'machine:api-client:' . (int) $client->id);
        $request->attributes->set('auth_role', 'pop_machine');
        $request->attributes->set('auth_campus_ids', $campusIds);
        $request->attributes->set('auth_has_campus', $inapp ? $campusIds !== [] : (int) $client->CampusID > 0);
        $request->attributes->set('api_client_id', (int) $client->id);
        $request->attributes->set('api_campus_id', (int) $client->CampusID);

        SecurityAuditEvent::append('pop.machine.auth', 'success', $auditContext, ['reason_code' => $requiredScope]);

        return $next($request);
    }

    private function auditFailure(string $scope, string $reason): void
    {
        SecurityAuditEvent::append('pop.machine.auth', 'failure', [], [
            'reason_code' => $reason,
            'source' => $scope,
        ]);
    }
}
