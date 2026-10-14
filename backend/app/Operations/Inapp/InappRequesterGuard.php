<?php

namespace App\Operations\Inapp;

/**
 * ADR-POP-015 seams for the part-2 `pop_inapp_agent` ApiClient. Both fail closed until then, so the
 * standing policy approves and executes nothing: allows() = client active and scoped to the request's
 * campus; attributionUserId() = the super admin who issued the client (ApiClient.IssuedByUserID).
 */
class InappRequesterGuard
{
    public function allows(object $request): bool { return false; }

    public function attributionUserId(object $request): ?int { return null; }
}
