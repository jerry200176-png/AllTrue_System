<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * #995: `GET alerts/tuition` must be registered exactly once and gated to
 * directors only (least privilege — it exposes tuition/financial alerts and is
 * only consumed by the director dashboard + tuition-collection page).
 *
 * Previously it was registered twice: director-only AND director,teacher. Laravel
 * keeps the last registration, so the teacher-inclusive one silently won and
 * widened access. This test pins the effective gate to `role:director`.
 */
class AlertsTuitionRouteTest extends TestCase
{
    private function tuitionRoute(): ?\Illuminate\Routing\Route
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_ends_with($route->uri(), 'alerts/tuition') && in_array('GET', $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    public function test_alerts_tuition_is_director_only(): void
    {
        $route = $this->tuitionRoute();
        $this->assertNotNull($route, 'GET alerts/tuition route must exist');

        $middleware = $route->gatherMiddleware();

        $this->assertContains(
            'role:director',
            $middleware,
            'alerts/tuition must be gated to director only (least privilege).'
        );
        $this->assertNotContains(
            'role:director,teacher',
            $middleware,
            'alerts/tuition must NOT be in the director,teacher group — that widens access to financial alerts (#995).'
        );
    }
}
