<?php

namespace Tests\Feature;

use App\Http\Controllers\GitHubIssueController;
use App\Http\Middleware\SecurityHeaders;
use App\Services\PaymentReportTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FrameworkStableConfig2833Test extends TestCase
{
    public function test_proxy_uses_configured_token_and_preserves_public_projection(): void
    {
        config(['services.github.token' => 'isolated-config-token']);
        Http::fake(['api.github.com/*' => Http::response([[
            'id' => 5, 'number' => 3, 'title' => 'Isolated issue', 'state' => 'open',
            'html_url' => 'https://example.test/issues/3', 'labels' => [],
            'created_at' => '2026-09-27', 'updated_at' => '2026-09-27',
            'user' => ['login' => 'fixture'], 'private_extra' => 'excluded',
        ]])]);

        $response = app(GitHubIssueController::class)->index(Request::create('/', 'GET'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $response->getData(true)['data'][0]['number']);
        $this->assertArrayNotHasKey('private_extra', $response->getData(true)['data'][0]);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer isolated-config-token'));
    }

    public function test_missing_configured_proxy_token_makes_no_outgoing_request(): void
    {
        config(['services.github.token' => null]);
        Http::fake();
        $response = app(GitHubIssueController::class)->index(Request::create('/', 'GET'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame([], $response->getData(true)['data']);
        Http::assertNothingSent();
    }

    public function test_report_only_headers_follow_config_without_enforcing_csp(): void
    {
        $middleware = new SecurityHeaders();
        foreach (['isolated-key-a', 'isolated-key-b'] as $key) {
            config(['sentry.csp_report_key' => $key, 'sentry.csp_report_org' => 'fixture-org', 'sentry.csp_report_project_id' => '73']);
            $response = $middleware->handle(Request::create('/'), fn () => response('fixture'));
            $this->assertStringContainsString('sentry_key=' . $key, $response->headers->get('Content-Security-Policy-Report-Only'));
            $this->assertFalse($response->headers->has('Content-Security-Policy'));
        }
        config(['sentry.csp_report_key' => null]);
        $response = $middleware->handle(Request::create('/'), fn () => response('fixture'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_payment_link_signature_follows_existing_application_key(): void
    {
        $service = new PaymentReportTokenService();
        config(['app.key' => 'isolated-app-key-a']);
        $token = $service->generate(73, 4)['token'];
        $this->assertSame(73, $service->verify($token)['scid']);
        config(['app.key' => 'isolated-app-key-b']);
        $this->assertNull($service->verify($token));
    }
}
