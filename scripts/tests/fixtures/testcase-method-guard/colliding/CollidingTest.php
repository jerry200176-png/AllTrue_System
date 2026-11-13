<?php
// Fixture for scripts/tests/testcase-method-guard.test.sh. Only tokenized by the guard, never run by PHPUnit.

namespace Fixture\Colliding;

use PHPUnit\Framework\TestCase;

class CollidingTest extends TestCase
{
    // setUp is an allowed override.
    protected function setUp(): void
    {
        parent::setUp();
    }

    // The mention "function count()" in this comment must not count.
    private function count(): int
    {
        return 1;
    }
}
