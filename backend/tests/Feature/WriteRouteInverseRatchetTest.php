<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Route;
use Tests\CreatesApplication;

/**
 * "No way back" ratchet (#4991 family R, root-cause plan 2026-11-18). Reports of this family arrive every month
 * because actions ship forward-only and the inverse is added only after someone asks. This test makes every write
 * route say how it is undone.
 *
 * tests/Fixtures/write_route_inverses.txt holds one line per write route:
 *   "METHOD uri"                       not declared yet (the starting list, taken from CI on 2026-11-18)
 *   "METHOD uri => METHOD other/uri"   the route that undoes it
 *   "METHOD uri => none: reason"       cannot be undone, and why (for money: void or 更正帳單 is the inverse)
 *
 * A new write route needs a line WITH "=>". Only the routes in tests/Fixtures/write_routes_grandfathered.txt (the
 * 2026-11-18 list, frozen: lines are only deleted there) may still be without one.
 * ponytail: the declared inverse is text for the reviewer; the test does not call it. A line added to the frozen
 * file is the one thing this test cannot see; it shows in the diff of a file that says it never grows.
 */
class WriteRouteInverseRatchetTest extends TestCase
{
    use CreatesApplication;

    public function test_every_write_route_declares_its_inverse_or_is_on_the_starting_list(): void
    {
        $actual = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $actual[] = $method . ' ' . $route->uri();
            }
        }
        $actual = array_values(array_unique($actual));
        sort($actual);

        $listed = [];
        $undeclared = [];
        foreach (file(base_path('tests/Fixtures/write_route_inverses.txt'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$route, $inverse] = array_map('trim', explode('=>', $line, 2) + [1 => '']);
            $listed[] = $route;
            if ($inverse === '') {
                $undeclared[] = $route;
            }
        }
        sort($listed);

        $this->assertSame(
            $listed,
            $actual,
            "Write routes differ from tests/Fixtures/write_route_inverses.txt.\n"
            . 'New (add "METHOD uri => inverse" or "=> none: reason"): ' . (implode(', ', array_diff($actual, $listed)) ?: '-') . "\n"
            . 'Gone (remove the line): ' . (implode(', ', array_diff($listed, $actual)) ?: '-') . "\n"
            . "Full current list:\n" . implode("\n", $actual)
        );
        $grandfathered = array_values(array_filter(
            array_map('trim', file(base_path('tests/Fixtures/write_routes_grandfathered.txt'), FILE_IGNORE_NEW_LINES) ?: []),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
        ));
        $this->assertSame(
            [],
            array_values(array_diff($undeclared, $grandfathered)),
            'These write routes have no "=>" and are not on the frozen 2026-11-18 list. Declare how each is undone.'
        );
    }
}
