<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Route;
use Tests\CreatesApplication;

/**
 * Authorization ratchet (帳務中心重整 治理層, 2026-11-17). On 2026-11-16 seventeen contract and package write routes
 * turned out to be reachable by the teacher role only because they sat in a director+teacher route group
 * (#5400, #5402). This test lists every write route a teacher can reach through a `role:` middleware and compares it
 * with a reviewed list, so a new one cannot appear without a human (or reviewing agent) reading the line in the diff.
 *
 * Adding a route here on purpose: add its line to tests/Fixtures/teacher_writable_routes.txt in the same PR and say
 * in the PR why a teacher needs it and which check inside the controller scopes it to the teacher's own courses.
 * ponytail: only routes that carry a `role:` middleware are listed; routes with no role check at all (public,
 * parent portal, webhooks) are outside this list and need their own review.
 */
class TeacherWritableRoutesRatchetTest extends TestCase
{
    use CreatesApplication;

    public function test_every_write_route_a_teacher_can_reach_is_on_the_reviewed_list(): void
    {
        $actual = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $roleRules = array_values(array_filter($route->gatherMiddleware(), static fn ($m): bool => is_string($m) && str_starts_with($m, 'role:')));
            if ($roleRules === []) {
                continue;
            }
            // Every role rule on the route must let a teacher through (rules stack: group + route).
            foreach ($roleRules as $rule) {
                if (!in_array('teacher', array_map('trim', explode(',', substr($rule, 5))), true)) {
                    continue 2;
                }
            }
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $actual[] = $method . ' ' . $route->uri();
            }
        }
        $actual = array_values(array_unique($actual));
        sort($actual);

        $reviewed = array_values(array_filter(
            array_map('trim', file(base_path('tests/Fixtures/teacher_writable_routes.txt'), FILE_IGNORE_NEW_LINES) ?: []),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
        ));
        sort($reviewed);

        $this->assertSame(
            $reviewed,
            $actual,
            "The write routes a teacher can reach differ from tests/Fixtures/teacher_writable_routes.txt.\n"
            . 'New on a teacher: ' . (implode(', ', array_diff($actual, $reviewed)) ?: '-') . "\n"
            . 'No longer reachable (remove the line): ' . (implode(', ', array_diff($reviewed, $actual)) ?: '-') . "\n"
            . "Full current list:\n" . implode("\n", $actual)
        );
    }
}
