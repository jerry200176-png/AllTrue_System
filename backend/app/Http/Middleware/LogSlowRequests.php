<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogSlowRequests
{
    private const SLOW_THRESHOLD_MS = 800;

    /**
     * SLO thresholds (P95 targets) per endpoint prefix.
     * If a single request exceeds the P95 target, log as SLO warning.
     */
    private const SLO_THRESHOLDS = [
        'api/v1/learning-records'          => 1200,
        'api/v1/notifications/unread-count' => 300,
        'api/v1/class-sessions'            => 800,
        'api/v1/students'                  => 600,
        'api/v1/student-classes'           => 800,
    ];

    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);
        $traceId = substr(bin2hex(random_bytes(8)), 0, 16);
        $request->attributes->set('trace_id', $traceId);

        $response = $next($request);

        $durationMs = round((microtime(true) - $start) * 1000, 1);

        $response->headers->set('X-Trace-Id', $traceId);
        $response->headers->set('X-Response-Time', $durationMs . 'ms');
        $response->headers->set('Server-Timing', "total;dur={$durationMs}");

        $path = $request->path();
        $method = $request->method();
        $status = $response->getStatusCode();
        $role = $request->attributes->get('auth_role', 'anon');

        $context = [
            'trace_id'    => $traceId,
            'method'      => $method,
            'path'        => $path,
            'status'      => $status,
            'duration_ms' => $durationMs,
            'role'        => $role,
            'branch_id'   => $request->input('branch_id'),
        ];

        if ($durationMs >= self::SLOW_THRESHOLD_MS) {
            Log::channel('single')->warning("[slow-request] {$method} /{$path} {$durationMs}ms", $context);
        }

        // SLO tracking for monitored endpoints
        foreach (self::SLO_THRESHOLDS as $prefix => $threshold) {
            if (str_starts_with($path, $prefix)) {
                Log::channel('perf')->info('perf_metric', $context);

                if ($durationMs > $threshold) {
                    $context['slo_threshold_ms'] = $threshold;
                    Log::channel('perf')->warning("[slo-breach] {$method} /{$path} {$durationMs}ms > {$threshold}ms", $context);
                }

                if ($status >= 500) {
                    Log::channel('perf')->error("[server-error] {$method} /{$path} status={$status}", $context);
                }
                break;
            }
        }

        return $response;
    }
}
