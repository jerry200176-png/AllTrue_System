<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

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
