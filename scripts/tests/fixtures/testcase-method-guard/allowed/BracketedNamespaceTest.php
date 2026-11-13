<?php
// Fixture, parsed only: a bracketed namespace with its own imports, and a clean test class.

namespace Fixture\Bracketed {
    use PHPUnit\Framework\TestCase;

    class BracketedNamespaceTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
        }
    }
}
