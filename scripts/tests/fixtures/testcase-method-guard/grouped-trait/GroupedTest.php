<?php
// Fixture, tokenized only: the trait arrives through a grouped, aliased import.

namespace Fixture\GroupedTrait;

use Fixture\GroupedTrait\Support\{OtherHelper, CountHelper as Helper};
use PHPUnit\Framework\TestCase;

class GroupedTest extends TestCase
{
    use Helper;
}
