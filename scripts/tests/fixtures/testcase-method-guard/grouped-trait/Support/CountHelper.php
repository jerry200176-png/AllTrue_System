<?php
// Fixture, tokenized only: a shared test trait whose helper collides.

namespace Fixture\GroupedTrait\Support;

trait CountHelper
{
    protected function count(): int
    {
        return 1;
    }
}

trait OtherHelper
{
}
