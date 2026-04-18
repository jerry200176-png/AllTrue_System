<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Throttle middleware that keys per (route, IP) and avoids calling
 * Auth::user(), because this project does not ship a Laravel auth
 * guard configuration.
 *
 * Registered as middleware alias `throttle` in Http/Kernel.php.
 */
class ThrottleRequestsByIp extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $routeKey = $route ? ($route->getDomain() . '|' . $route->uri()) : $request->fullUrl();
        return sha1($routeKey . '|' . $request->ip());
    }
}
