<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\TestCase;
use Tests\CreatesApplication;

/**
 * Contract test: verifies that the 7 schedule-discrepancies routes are registered in api.php.
 *
 * This test guards against silent route removal by Claude Code or other automated tools.
 * These routes were inadvertently removed on 2026-04-17 and re-added as part of bug fix.
 * See AI_REGRESSION_LESSONS.md — "禁止移除 schedule-discrepancies 路由".
 *
 * Route ordering is also verified: static segments (/my, /summary, /active-for-session)
 * must appear before the dynamic segment (/{id}) to prevent Laravel from treating them as IDs.
 */
class RouteRegistrationTest extends TestCase
{
    use CreatesApplication;

    /**
     * These are the stable entry points for the domains that are most costly
     * to discover broken at runtime. Keep this list intentionally small; the
     * generated route reference remains the inventory, while this test pins
     * the contracts that must not silently drift.
     */
    public static function coreApiRouteProvider(): array
    {
        return [
            'auth login'       => ['POST', 'api/v1/auth/login', 'App\\Http\\Controllers\\AuthController@login'],
            'auth register'    => ['POST', 'api/v1/auth/register', 'App\\Http\\Controllers\\AuthController@register'],
            'rfid swipe'       => ['POST', 'api/v1/swipe-rfid', 'App\\Http\\Controllers\\SwipeRfidController@swipe'],
            'student classes'  => ['GET', 'api/v1/student-classes', 'App\\Http\\Controllers\\StudentClassController@index'],
            'class sessions'   => ['GET', 'api/v1/class-sessions', 'App\\Http\\Controllers\\ClassSessionController@index'],
            'schedules read'   => ['GET', 'api/v1/schedules', 'App\\Http\\Controllers\\ScheduleController@index'],
            'schedules create' => ['POST', 'api/v1/schedules', 'App\\Http\\Controllers\\ScheduleController@store'],
        ];
    }

    public static function popControlPlaneRouteProvider(): array
    {
        return [
            'draft' => ['POST', 'api/v1/pop/operations/{operationId}/draft', 'App\\Http\\Controllers\\PopOperationController@storeDraft'],
            'approval' => ['POST', 'api/v1/pop/operations/requests/{requestId}/approvals', 'App\\Http\\Controllers\\PopOperationController@approve'],
        ];
    }

    /**
     * POP request/approval routes are control-plane entrypoints only. There is
     * intentionally no HTTP execute route; mutation belongs to the Pi-local CLI.
     *
     * @dataProvider popControlPlaneRouteProvider
     */
    public function test_pop_control_plane_routes_are_role_campus_password_gated(
        string $method,
        string $uri,
        string $action
    ): void {
        $route = $this->findRoute($method, $uri);
        $this->assertNotNull($route);
        $this->assertSame($action, $route->getActionName());
        foreach (['role:director,super_admin', 'require_campus', 'require_password_change'] as $required) {
            $this->assertContains($required, $route->gatherMiddleware());
        }
    }

    public function test_pop_has_no_http_execute_entrypoint(): void
    {
        $this->assertNull($this->findRoute('POST', 'api/v1/pop/operations/requests/{requestId}/execute'));
    }

    /**
     * @dataProvider coreApiRouteProvider
     */
    public function test_core_api_route_contract_is_registered(
        string $method,
        string $uri,
        string $action
    ): void {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === ltrim($uri, '/'))
            ->filter(fn ($route) => in_array(strtoupper($method), $route->methods(), true));

        $this->assertCount(1, $routes, "Expected exactly one route for [{$method} {$uri}].");
        $route = $routes->first();

        $this->assertSame(
            $action,
            $route->getActionName(),
            "Route [{$method} {$uri}] must keep its public controller action."
        );
    }

    /**
     * The core staff domains must retain all three gates. A route can still
     * exist and return data while silently losing one of these protections.
     */
    public static function protectedCoreApiRouteProvider(): array
    {
        return [
            'student classes' => ['GET', 'api/v1/student-classes'],
            'class sessions'  => ['GET', 'api/v1/class-sessions'],
            'schedules read'  => ['GET', 'api/v1/schedules'],
            'schedules create' => ['POST', 'api/v1/schedules'],
        ];
    }

    /**
     * @dataProvider protectedCoreApiRouteProvider
     */
    public function test_core_staff_routes_keep_role_campus_and_password_gates(
        string $method,
        string $uri
    ): void {
        $route = $this->findRoute($method, $uri);
        $this->assertNotNull($route, "Route [{$method} {$uri}] must be registered before checking its gates.");

        $middleware = $route->gatherMiddleware();

        foreach (['role:director,teacher', 'require_campus', 'require_password_change'] as $required) {
            $this->assertContains(
                $required,
                $middleware,
                "Route [{$method} {$uri}] must retain middleware [{$required}]."
            );
        }
    }

    /**
     * Public device/account entry points still need their explicit abuse
     * throttles, even though they intentionally have no role middleware.
     */
    public static function publicCoreApiRouteProvider(): array
    {
        return [
            'auth register' => ['POST', 'api/v1/auth/register', 'throttle:10,10'],
            'rfid swipe'    => ['POST', 'api/v1/swipe-rfid', 'throttle:30,1'],
        ];
    }

    /**
     * @dataProvider publicCoreApiRouteProvider
     */
    public function test_public_core_routes_keep_explicit_throttle(
        string $method,
        string $uri,
        string $throttle
    ): void {
        $route = $this->findRoute($method, $uri);
        $this->assertNotNull($route, "Route [{$method} {$uri}] must be registered before checking its throttle.");
        $this->assertContains($throttle, $route->gatherMiddleware());
    }

    private function findRoute(string $method, string $uri): ?\Illuminate\Routing\Route
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === ltrim($uri, '/') && in_array(strtoupper($method), $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @dataProvider scheduleDiscrepancyRouteProvider
     */
    public function test_schedule_discrepancy_route_is_registered(string $method, string $uri): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $found = $routes->first(function ($route) use ($method, $uri) {
            return in_array(strtoupper($method), $route->methods())
                && $route->uri() === ltrim($uri, '/');
        });

        $this->assertNotNull(
            $found,
            "Route [{$method} {$uri}] is not registered. " .
            "This route may have been silently removed. " .
            "See AI_REGRESSION_LESSONS.md — schedule-discrepancies 路由守護."
        );
    }

    public static function scheduleDiscrepancyRouteProvider(): array
    {
        return [
            'POST store'                  => ['POST', 'api/v1/schedule-discrepancies'],
            'POST withdraw'               => ['POST', 'api/v1/schedule-discrepancies/{id}/withdraw'],
            'GET my (static)'             => ['GET',  'api/v1/schedule-discrepancies/my'],
            'GET active-for-session'      => ['GET',  'api/v1/schedule-discrepancies/active-for-session'],
            'GET index (director)'        => ['GET',  'api/v1/schedule-discrepancies'],
            'GET summary (director)'      => ['GET',  'api/v1/schedule-discrepancies/summary'],
            'PUT updateStatus (director)' => ['PUT',  'api/v1/schedule-discrepancies/{id}'],
        ];
    }

    /**
     * Verify that static routes (/my, /summary, /active-for-session) are registered
     * before the dynamic route (/{id}) to prevent Laravel from treating them as IDs.
     */
    public function test_static_schedule_discrepancy_routes_precede_dynamic_id_route(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/schedule-discrepancies'));

        $uris = $routes->map(fn ($r) => $r->uri())->values()->all();

        $staticRoutes = ['api/v1/schedule-discrepancies/my', 'api/v1/schedule-discrepancies/summary', 'api/v1/schedule-discrepancies/active-for-session'];
        $dynamicRoute = 'api/v1/schedule-discrepancies/{id}';

        $dynamicIdx = array_search($dynamicRoute, $uris);
        if ($dynamicIdx === false) {
            $this->markTestSkipped('Dynamic route {id} not found; cannot verify ordering.');
        }

        foreach ($staticRoutes as $static) {
            $staticIdx = array_search($static, $uris);
            if ($staticIdx !== false) {
                $this->assertLessThan(
                    $dynamicIdx,
                    $staticIdx,
                    "Static route [{$static}] must be registered before dynamic route [{$dynamicRoute}]. " .
                    "Otherwise Laravel's router will match static paths as IDs."
                );
            }
        }
    }
}
