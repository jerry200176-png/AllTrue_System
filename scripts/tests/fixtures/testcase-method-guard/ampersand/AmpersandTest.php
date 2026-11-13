<?php
// Fixture, tokenized only: a reference-returning method is still a declaration.

namespace Fixture\Ampersand;

use PHPUnit\Framework\TestCase;

class AmpersandTest extends TestCase
{
    private array $rows = [];

    private function &count(): array
    {
        return $this->rows;
    }
}
