<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\SecurityAuditEvent;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticate the production POP control-plane machine principal.
 *
 * This is deliberately separate from the attendance-device X-API-KEY path:
 * existing device credentials can never acquire POP capabilities by accident.
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
            ->where('Purpose', 'pop_control_plane')
            ->first();
        if (!$client) {
            $this->auditFailure($requiredScope, 'invalid_machine_principal');
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $scopes = is_array($client->Scopes) ? $client->Scopes : [];
        if (!in_array($requiredScope, array_map('strval', $scopes), true)) {
            SecurityAuditEvent::append('pop.machine.auth', 'failure', [
                'campus_id' => (int) $client->CampusID,
                'actor_type' => 'pop_machine',
                'actor_id' => (int) $client->id,
            ], ['reason_code' => 'scope_denied']);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->attributes->set('auth_principal_type', 'machine');
        $request->attributes->set('auth_actor', 'machine:api-client:' . (int) $client->id);
        $request->attributes->set('auth_role', 'pop_machine');
        $request->attributes->set('auth_campus_ids', [(int) $client->CampusID]);
        $request->attributes->set('auth_has_campus', (int) $client->CampusID > 0);
        $request->attributes->set('api_client_id', (int) $client->id);
        $request->attributes->set('api_campus_id', (int) $client->CampusID);

        SecurityAuditEvent::append('pop.machine.auth', 'success', [
            'campus_id' => (int) $client->CampusID,
            'actor_type' => 'pop_machine',
            'actor_id' => (int) $client->id,
        ], ['reason_code' => $requiredScope]);

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
