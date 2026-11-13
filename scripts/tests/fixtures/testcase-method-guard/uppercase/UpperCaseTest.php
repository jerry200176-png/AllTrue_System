<?php
// Fixture, tokenized only: PHP method names are case-insensitive, so Count() is count().

namespace Fixture\Uppercase;

use PHPUnit\Framework\TestCase;

class UpperCaseTest extends TestCase
{
    private function Count(): int
    {
        return 1;
    }
}
