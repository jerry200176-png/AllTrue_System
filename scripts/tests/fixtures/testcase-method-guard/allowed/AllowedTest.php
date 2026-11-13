<?php
// Fixture for scripts/tests/testcase-method-guard.test.sh. Only tokenized by the guard, never run by PHPUnit.

namespace Fixture\Allowed;

use PHPUnit\Framework\TestCase;

class AllowedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // Names that merely look like the failing ones, in a comment and a string: function count() / session().
    private function loginSession(): string
    {
        return 'function count() { }';
    }

    public function testSomething(): void
    {
        $f = function () {
            return 1;
        };
        $this->assertSame(1, $f());
    }
}

// Not a test class: a plain helper may use any name.
class PlainWorker
{
    public function count(): int
    {
        return 0;
    }
}
