<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Reject requests from authenticated users who have no campus assignment.
 *
 * This prevents the "empty campus_ids = access everything" hole where
 * controllers use `when(!empty($campusIds), ...)` patterns. A user
 * with zero UserCampus rows must not be able to query any branch data.
 */
class RequireCampus
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Super admin bypasses campus requirement — can access all branches
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return $next($request);
        }

        $hasCampus = $request->attributes->get('auth_has_campus', false);
        if (!$hasCampus) {
            return response()->json(['message' => 'No campus assigned. Contact administrator.'], 403);
        }

        return $next($request);
    }
}
