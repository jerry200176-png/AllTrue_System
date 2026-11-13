<?php
// Fixture, tokenized only: insteadof and harmless aliases must not be reported.

namespace Fixture\Allowed;

use PHPUnit\Framework\TestCase;

trait RunA
{
    public function run1(): void
    {
    }
}

trait RunB
{
    public function run1(): void
    {
    }
}

class AdaptationTest extends TestCase
{
    use RunA, RunB {
        RunA::run1 insteadof RunB;
        RunB::run1 as protected runB;
    }
}
