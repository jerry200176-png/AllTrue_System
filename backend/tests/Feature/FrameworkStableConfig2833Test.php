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
    private array $savedEnvironment = [];

    private function seedEnvironment(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->savedEnvironment)) {
            $this->savedEnvironment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }
        $value === null ? putenv($key) : putenv($key . '=' . $value);
        unset($_ENV[$key], $_SERVER[$key]);
        if ($value !== null) $_ENV[$key] = $_SERVER[$key] = $value;
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => [$process, $environment, $server]) {
            $process === false ? putenv($key) : putenv($key . '=' . $process);
            unset($_ENV[$key], $_SERVER[$key]);
            if ($environment !== null) $_ENV[$key] = $environment;
            if ($server !== null) $_SERVER[$key] = $server;
        }
        parent::tearDown();
    }

    public function test_proxy_uses_configured_token_and_preserves_public_projection(): void
    {
        $this->seedEnvironment('GITHUB_TOKEN', 'isolated-config-token');
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
        $this->seedEnvironment('GITHUB_TOKEN', null);
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

    public function test_live_proxy_token_changes_and_revocation_do_not_require_config_reload(): void
    {
        Http::fake(['api.github.com/*' => Http::response([])]);
        $controller = app(GitHubIssueController::class);
        foreach (['fixture-live-a', 'fixture-live-b'] as $token) {
            $this->seedEnvironment('GITHUB_TOKEN', $token);
            $this->assertSame(200, $controller->index(Request::create('/'))->getStatusCode());
            Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ' . $token));
        }
        $this->seedEnvironment('GITHUB_TOKEN', null);
        $this->assertSame(503, $controller->index(Request::create('/'))->getStatusCode());
        Http::assertSentCount(2);
    }

    public function test_registration_missing_key_uses_live_fallback_but_present_null_does_not(): void
    {
        $this->seedEnvironment('DIRECTOR_REGISTRATION_TOKEN', 'fixture-registration');
        $settings = config('app');
        unset($settings['director_registration_token']);
        config(['app' => $settings]);
        $controller = app(\App\Http\Controllers\DirectorAccountController::class);
        $this->assertSame(403, $controller->register(Request::create('/', 'POST'))->getStatusCode());
        config(['app.director_registration_token' => 'fixture-config-override']);
        $this->assertSame(403, $controller->register(Request::create('/', 'POST', ['registration_token' => 'fixture-registration']))->getStatusCode());
        config(['app.director_registration_token' => null]);
        try {
            $controller->register(Request::create('/', 'POST'));
            $this->fail('Missing fields must reach validation, without creating an account.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('name', $error->errors());
        }
    }

    public function test_payment_missing_key_preserves_default_and_live_fallback(): void
    {
        $settings = config('app');
        unset($settings['key']);
        config(['app' => $settings]);
        $this->seedEnvironment('APP_KEY', null);
        $service = new PaymentReportTokenService();
        $token = $service->generate(73, 4)['token'];
        $this->assertSame(73, $service->verify($token)['scid']);
        $this->seedEnvironment('APP_KEY', 'fixture-live-key');
        $this->assertNull($service->verify($token));
        $next = $service->generate(73, 4)['token'];
        config(['app.key' => 'fixture-config-key']);
        $this->assertNull($service->verify($next));
        config(['app.key' => null]);
        $this->expectException(\TypeError::class);
        $service->generate(73, 4);
    }

    public function test_csp_missing_keys_remain_live_and_present_null_disables_report_only(): void
    {
        $settings = config('sentry');
        foreach (['csp_report_key', 'csp_report_org', 'csp_report_project_id'] as $key) unset($settings[$key]);
        config(['sentry' => $settings]);
        $this->seedEnvironment('SENTRY_CSP_REPORT_ORG', 'fixture-org');
        $this->seedEnvironment('SENTRY_CSP_REPORT_PROJECT_ID', '73');
        $middleware = new SecurityHeaders();
        foreach (['fixture-live-a', 'fixture-live-b'] as $key) {
            $this->seedEnvironment('SENTRY_CSP_REPORT_KEY', $key);
            $response = $middleware->handle(Request::create('/'), fn () => response('fixture'));
            $this->assertStringContainsString('sentry_key=' . $key, $response->headers->get('Content-Security-Policy-Report-Only'));
            $this->assertFalse($response->headers->has('Content-Security-Policy'));
        }
        config(['sentry.csp_report_key' => null]);
        $response = $middleware->handle(Request::create('/'), fn () => response('fixture'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }
    public function test_actual_config_cache_does_not_snapshot_live_proxy_or_csp_credentials(): void
    {
        $cache = sys_get_temp_dir() . '/alltrue-live-config-' . bin2hex(random_bytes(8)) . '.php';
        $this->seedEnvironment('APP_CONFIG_CACHE', $cache);
        $this->seedEnvironment('GITHUB_TOKEN', 'fixture-cache-token');
        $this->seedEnvironment('SENTRY_CSP_REPORT_KEY', 'fixture-cache-csp');
        try {
            $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('config:cache'));
            \Illuminate\Container\Container::setInstance($this->app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
            \Illuminate\Database\Eloquent\Model::setConnectionResolver($this->app['db']);
            \Illuminate\Database\Eloquent\Model::setEventDispatcher($this->app['events']);
            (new \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($this->app);
            (new \Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($this->app);
            $this->assertTrue($this->app->configurationIsCached());
            $this->assertSame(require $cache, config()->all());
            $this->assertFalse(config()->has('services.github.token'));
            $this->assertFalse(config()->has('sentry.csp_report_key'));
            $this->assertStringNotContainsString('fixture-cache-token', file_get_contents($cache));
            $this->assertStringNotContainsString('fixture-cache-csp', file_get_contents($cache));
            $this->seedEnvironment('GITHUB_TOKEN', null);
            Http::fake();
            $this->assertSame(503, app(GitHubIssueController::class)->index(Request::create('/'))->getStatusCode());
            Http::assertNothingSent();
        } finally {
            if (is_file($cache)) unlink($cache);
        }
    }

}
