<?php
// Fixture, tokenized only: the alias count is a method on this class even though the trait declares helper().

namespace Fixture\TraitAlias;

use PHPUnit\Framework\TestCase;

trait HelperTrait
{
    protected function helper(): int
    {
        return 1;
    }
}

class AliasTest extends TestCase
{
    use HelperTrait {
        helper as protected count;
    }
}
