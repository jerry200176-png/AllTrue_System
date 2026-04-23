<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DebugSwipeRfidRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api/v1/swipe-rfid')) {
            return $next($request);
        }

        // #region agent log
        $this->writeDebugLog('H1', 'swipe-rfid request entered middleware', [
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'has_auth_header' => $request->headers->has('Authorization'),
            'user_agent_present' => $request->headers->has('User-Agent'),
        ]);
        // #endregion

        try {
            $response = $next($request);

            $route = $request->route();
            // #region agent log
            $this->writeDebugLog('H2', 'swipe-rfid response from app', [
                'method' => $request->getMethod(),
                'status' => $response->getStatusCode(),
                'route_uri' => $route ? $route->uri() : null,
                'route_methods' => $route ? $route->methods() : null,
                'route_action' => $route ? ($route->getActionName() ?: null) : null,
            ]);
            // #endregion

            return $response;
        } catch (Throwable $e) {
            // #region agent log
            $this->writeDebugLog('H3', 'swipe-rfid exception before controller', [
                'method' => $request->getMethod(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            // #endregion
            throw $e;
        }
    }

    private function writeDebugLog(string $hypothesisId, string $message, array $data): void
    {
        $payload = [
            'sessionId' => 'c39ff4',
            'runId' => 'run-initial',
            'hypothesisId' => $hypothesisId,
            'location' => 'DebugSwipeRfidRequest.php',
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
        ];
        @file_put_contents(base_path('../debug-c39ff4.log'), json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
}

