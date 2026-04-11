<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Only users with auth_role === super_admin (User.type === S) may proceed.
 */
class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->attributes->get('auth_user')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
