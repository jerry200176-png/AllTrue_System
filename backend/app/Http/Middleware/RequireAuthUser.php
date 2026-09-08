<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Enforce the application's existing AuthToken/AttachAuthUser authentication.
 *
 * AttachAuthUser intentionally resolves identity and allows public routes to
 * continue without a token. Mutating or staff-only routes must opt into this
 * explicit fail-closed gate.
 */
class RequireAuthUser
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->attributes->get('auth_user')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
