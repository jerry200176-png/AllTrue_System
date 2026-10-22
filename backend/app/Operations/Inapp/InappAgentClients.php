<?php

namespace App\Operations\Inapp;

use App\Models\ApiClient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-POP-015 §3 requester credential: a super-admin-issued `pop_inapp_agent` ApiClient that may only draft and
 * dry-run the operations InappStandingPolicy governs, on an explicit campus list. Only the key's sha256 is stored.
 */
final class InappAgentClients
{
    public const PURPOSE = 'pop_inapp_agent';
    public const SCOPES = ['pop:draft', 'pop:dry-run'];
    /** Distinct from `pop_machine_` (control plane) and `sk_live_` (attendance devices). */
    public const KEY_PREFIX = 'alltrue_pop_inapp_';

    /** POP request actor recorded by PopMachineAuth for this client. */
    public static function actor(int $clientId): string
    {
        return 'machine:api-client:' . $clientId;
    }

    /** @param list<int> $campusIds @return array{key: string, record: array<string, mixed>} */
    public static function issue(User $issuer, string $name, array $campusIds): array
    {
        $key = self::KEY_PREFIX . Str::random(48);
        $client = new ApiClient();
        // CampusID 0: no single campus; the scope is CampusIDs. ApiKeyAuth accepts attendance devices only.
        $client->forceFill([
            'Name' => $name, 'ApiKeyHash' => hash('sha256', $key), 'CampusID' => 0, 'Active' => true,
            'Purpose' => self::PURPOSE, 'Scopes' => self::SCOPES, 'IssuedByUserID' => (int) $issuer->getKey(),
            'CampusIDs' => array_values($campusIds), 'ExpiresAt' => now()->addDays((int) config('pop_inapp.agent_client_ttl_days')),
        ])->save();

        return ['key' => $key, 'record' => self::present($client)];
    }

    /** @return list<array<string, mixed>> */
    public static function list(): array
    {
        return ApiClient::query()->where('Purpose', self::PURPOSE)->orderByDesc('id')->get()
            ->map(fn (ApiClient $client): array => self::present($client))->values()->all();
    }

    /** The pop_inapp_agent client behind a POP request actor, or null (any other actor or purpose). */
    public static function forActor(string $actor): ?ApiClient
    {
        $client = preg_match('/^machine:api-client:([1-9][0-9]{0,17})$/', $actor, $m) === 1
            ? ApiClient::query()->whereKey((int) $m[1])->where('Purpose', self::PURPOSE)->first() : null;

        return $client instanceof ApiClient ? $client : null;
    }

    /** Why the client may not act now; null = usable. Every unknown state denies. */
    public static function denial(?ApiClient $client): ?string
    {
        if (!$client || $client->Purpose !== self::PURPOSE || $client->Active !== true) return 'client_revoked';
        if ($client->ExpiresAt === null || !$client->ExpiresAt->isFuture()) return 'client_expired';
        if (self::issuer($client) === null) return 'issuer_not_active_super_admin';
        if (array_diff(self::SCOPES, array_map('strval', (array) $client->Scopes)) !== []) return 'scope_denied';

        return self::campusIds($client) === [] ? 'campus_scope_empty' : null;
    }

    /** The explicit campus list; missing, empty or any invalid entry denies all. @return list<int> */
    public static function campusIds(ApiClient $client): array
    {
        $ids = $client->CampusIDs;

        return is_array($ids) && $ids !== [] && array_is_list($ids) && array_filter($ids, fn ($id) => !is_int($id) || $id <= 0) === []
            ? array_values(array_unique($ids)) : [];
    }

    /** The issuing super admin while still an active super admin (same rule as the triage token owner). */
    public static function issuer(ApiClient $client): ?User
    {
        $user = $client->IssuedByUserID ? User::query()->find($client->IssuedByUserID) : null;

        return $user instanceof User && $user->type === 'S' && !in_array($user->status, ['inactive', 'suspended'], true) ? $user : null;
    }

    /** Machine route limit: draft a governed in-app operation, or dry-run this client's own in-app request. */
    public static function routeAllows(ApiClient $client, Request $request): bool
    {
        $operation = $request->route('operationId');
        if (!is_string($operation)) {
            $requestId = $request->route('requestId');
            $row = is_string($requestId) ? DB::table('pop_operation_requests')->where('id', $requestId)->first(['operation_id', 'actor']) : null;
            if (!$row || (string) $row->actor !== self::actor((int) $client->id)) return false;
            $operation = (string) $row->operation_id;
        }

        return isset(InappStandingPolicy::OPERATIONS[$operation]);
    }

    /**
     * ADR-POP-015 §4: deactivate the client and, in the same transaction, cancel each of its policy-approved,
     * unexecuted requests with a policy_revoked event (the PopOperationService::quorumRoles() shape).
     * Human-approved requests stay executable. @return array{id: int, cancelled_request_ids: list<string>}|null
     */
    public static function revoke(int $id): ?array
    {
        return DB::transaction(function () use ($id): ?array {
            $client = ApiClient::query()->whereKey($id)->where('Purpose', self::PURPOSE)->lockForUpdate()->first();
            if (!$client instanceof ApiClient) return null;
            $client->forceFill(['Active' => false])->save();
            $cancelled = [];
            $requests = DB::table('pop_operation_requests')->where('actor', self::actor($id))->where('status', 'approved')->pluck('id');
            foreach ($requests as $requestId) {
                $event = DB::table('pop_approval_events')->where('operation_id', $requestId)->where('event_type', 'approved')
                    ->where('approver_role', 'policy')->orderBy('id')->first();
                // Conditional update (row lock): a request the executor already moved on is left alone.
                if (!$event || DB::table('pop_operation_requests')->where('id', $requestId)->where('status', 'approved')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]) !== 1) continue;
                DB::table('pop_approval_events')->insert(array_merge(array_diff_key((array) $event, ['id' => 1]),
                    ['event_type' => 'policy_revoked', 'token_hash' => null, 'expires_at' => now(), 'created_at' => now()]));
                $cancelled[] = (string) $requestId;
            }

            return ['id' => $id, 'cancelled_request_ids' => $cancelled];
        });
    }

    /** Never includes the key or its hash. @return array<string, mixed> */
    private static function present(ApiClient $client): array
    {
        return [
            'id' => (int) $client->id,
            'name' => (string) $client->Name,
            'campus_ids' => self::campusIds($client),
            'scopes' => array_values(array_map('strval', (array) $client->Scopes)),
            'issued_by_user_id' => $client->IssuedByUserID,
            'active' => $client->Active === true,
            'created_at' => $client->created_at?->toIso8601String(),
            'expires_at' => $client->ExpiresAt?->toIso8601String(),
            'expired' => $client->ExpiresAt === null || !$client->ExpiresAt->isFuture(),
        ];
    }
}
