<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return $next($request);
        }

        if (!(bool) ($user->MustChangePassword ?? false)) {
            return $next($request);
        }

        if ($request->is('api/v1/auth/*')) {
            return $next($request);
        }

        if ($request->is('api/v1/me') && in_array($request->method(), ['GET', 'PUT'], true)) {
            return $next($request);
        }

        return response()->json([
            'message' => '請先修改初始密碼',
            'code' => 'PASSWORD_CHANGE_REQUIRED',
            'must_change_password' => true,
        ], 428);
    }
}
