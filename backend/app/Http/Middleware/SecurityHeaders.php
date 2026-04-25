<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 安全 HTTP 標頭
 *
 * ⛔ 刻意排除：
 * - Content-Security-Policy：內聯 i18n script 會被擋（事故 D 根因）
 * - X-Content-Type-Options: nosniff：nginx MIME 設定需先驗證才能啟用（事故 D）
 * - HSTS：由 nginx 負責（已設定）
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }
}
