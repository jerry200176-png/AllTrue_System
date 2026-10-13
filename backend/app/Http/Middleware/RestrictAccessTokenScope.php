<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditEvent;
use App\Services\TriageTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Deny-by-default boundary for read-only triage tokens (api middleware group).
 *
 * A request that presents a triage token may reach only the GET routes named
 * in config('triage_tokens.allowed_routes'); every other API route answers 403
 * before any other auth runs. This checks the presented token itself, so a new
 * route can never become reachable by forgetting an ability check on it.
 */
final class RestrictAccessTokenScope
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();
        if (!is_string($bearer) || $bearer === '') {
            return $next($request);
        }

        $pat = TriageTokenService::resolve($bearer);
        if ($pat === null && !TriageTokenService::looksLikeTriageToken($bearer)) {
            return $next($request);
        }

        $route = $request->route();
        $routeName = $route instanceof Route ? $route->getName() : null;
        $allowed = (array) config('triage_tokens.allowed_routes', []);
        if (is_string($routeName) && in_array($routeName, $allowed, true)
            && in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        SecurityAuditEvent::append('triage_token.scope_denied', 'denied', [
            'actor_type' => 'triage_token',
            'actor_id' => $pat?->getKey(),
        ], [
            'token_id' => $pat ? (int) $pat->getKey() : 0,
            'method' => $request->method(),
            'route' => is_string($routeName) ? $routeName : ($route instanceof Route ? $route->uri() : ''),
            'client_ip' => (string) $request->ip(),
            'http_status' => 403,
        ]);

        return response()->json(['message' => 'Forbidden', 'code' => 'token_scope_denied'], 403);
    }
}
