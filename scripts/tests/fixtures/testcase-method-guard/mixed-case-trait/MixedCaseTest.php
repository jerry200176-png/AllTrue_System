<?php
// Fixture, parsed only: PHP resolves class and trait names case-insensitively, so `helpertrait` is HelperTrait.

namespace Fixture\MixedCaseTrait;

use PHPUnit\Framework\TestCase;

trait HelperTrait
{
    protected function count(): int
    {
        return 1;
    }
}

class MixedCaseTest extends TestCase
{
    use helpertrait;
}
