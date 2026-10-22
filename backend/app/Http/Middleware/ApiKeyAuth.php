<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('X-API-KEY');
        if (!$apiKey) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $hash = hash('sha256', $apiKey);
        // Attendance devices only: a POP machine or pop_inapp_agent key (CampusID 0) must never record a swipe.
        $client = ApiClient::where('ApiKeyHash', $hash)
            ->where('Active', 1)
            ->where('Purpose', 'attendance_device')
            ->first();

        if (!$client) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->attributes->set('api_client_id', $client->id);
        $request->attributes->set('api_campus_id', (int) $client->CampusID);

        return $next($request);
    }
}
