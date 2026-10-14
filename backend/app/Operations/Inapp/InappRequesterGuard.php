<?php

namespace App\Operations\Inapp;

/**
 * ADR-POP-015 §4 hook: the requesting `pop_inapp_agent` ApiClient must be active and scoped to the
 * request's campus. That client ships in part 2 (which also revokes its outstanding approvals);
 * until then this fails closed, so the standing policy approves and executes nothing.
 */
class InappRequesterGuard
{
    public function allows(object $request): bool
    {
        return false;
    }
}
