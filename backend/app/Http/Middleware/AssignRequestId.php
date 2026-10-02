<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tag every API request/response/log line with one id so an in-app bug report
 * can name the failing request (client records X-Request-Id; see F10).
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next)
    {
        $id = (string) $request->header('X-Request-Id', '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $id)) {
            $id = (string) Str::uuid();
        }
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
