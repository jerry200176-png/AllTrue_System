<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $role = $request->attributes->get('auth_role');
        if (!$role) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Super admin bypasses all role checks
        if ($role === 'super_admin') {
            return $next($request);
        }

        if (!in_array($role, $roles, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
