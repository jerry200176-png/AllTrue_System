<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 安全 HTTP 標頭
 *
 * ⛔ 刻意排除（事故 D 教訓）：
 * - Content-Security-Policy（強制擋截版本）：內聯 i18n script 會被擋
 *
 * ✅ 使用 Report-Only 替代：
 * - Content-Security-Policy-Report-Only：只記錄，絕不擋截
 *   → 用 Sentry 接收 violation report，累積資料後再評估是否升為強制 CSP
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

        // CSP Report-Only：不擋截、僅透過 Sentry 記錄違規（需觀察數週再收緊）
        $sentryKey    = config('sentry.csp_report_key', env('SENTRY_CSP_REPORT_KEY'));
        $sentryOrg    = config('sentry.csp_report_org', env('SENTRY_CSP_REPORT_ORG'));
        $sentryProjId = config('sentry.csp_report_project_id', env('SENTRY_CSP_REPORT_PROJECT_ID'));
        if ($sentryKey && $sentryOrg && $sentryProjId) {
            $reportUri = "https://{$sentryOrg}.ingest.us.sentry.io/api/{$sentryProjId}/security/?sentry_key={$sentryKey}";
            $response->headers->set('Content-Security-Policy-Report-Only',
                "default-src 'self' 'unsafe-inline' 'unsafe-eval' https:; " .
                "img-src 'self' data: blob: https:; " .
                "font-src 'self' data: https:; " .
                "connect-src 'self' https:; " .
                "report-uri {$reportUri}"
            );
        }

        return $response;
    }
}
