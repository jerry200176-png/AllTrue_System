<?php

namespace App\Operations\Inapp;

use Throwable;

/**
 * ADR-POP-015 §2-4 requester checks for a standing-policy approval. Both fail closed on any doubt.
 * allows() = the request was drafted by a usable `pop_inapp_agent` client (active, unexpired, issuer still an
 * active super admin, draft+dry-run scopes) for a governed operation on a campus in the client's explicit list;
 * attributionUserId() = that issuer (ApiClient.IssuedByUserID), only while allows() holds.
 */
class InappRequesterGuard
{
    public function allows(object $request): bool
    {
        try {
            $client = InappAgentClients::forActor((string) ($request->actor ?? ''));
            $params = json_decode((string) ($request->parameters ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $campusId = is_array($params) ? ($params['campus_id'] ?? null) : null;

            return $client !== null && InappAgentClients::denial($client) === null
                && isset(InappStandingPolicy::OPERATIONS[(string) ($request->operation_id ?? '')])
                && is_int($campusId) && in_array($campusId, InappAgentClients::campusIds($client), true);
        } catch (Throwable) {
            return false;
        }
    }

    public function attributionUserId(object $request): ?int
    {
        try {
            $client = $this->allows($request) ? InappAgentClients::forActor((string) $request->actor) : null;
            $issuer = $client ? InappAgentClients::issuer($client) : null;

            return $issuer ? (int) $issuer->getKey() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
