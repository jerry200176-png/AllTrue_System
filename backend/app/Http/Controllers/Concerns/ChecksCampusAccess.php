<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one campus-isolation check for controllers that take an explicit campus id (#5251): super_admin may act on any real
 * campus, everyone else only on their own. A campus id of 0 or less is never allowed, also for super_admin.
 */
trait ChecksCampusAccess
{
    private function campusAllowed(Request $request, int $campusId): bool
    {
        return $campusId > 0 && ($request->attributes->get('auth_role') === 'super_admin'
            || in_array($campusId, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true));
    }

    private function isDirector(Request $request): bool
    {
        return in_array($request->attributes->get('auth_role'), ['director', 'super_admin'], true);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
