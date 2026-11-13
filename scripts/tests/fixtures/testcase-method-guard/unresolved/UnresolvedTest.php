<?php
// Fixture, tokenized only: the parent cannot be loaded, so the guard must fail instead of skipping the class.

namespace Fixture\Unresolved;

class UnresolvedTest extends \Missing\NoSuchBase
{
    private function count(): int
    {
        return 1;
    }
}
