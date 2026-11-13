<?php
// Fixture, tokenized only: a grouped import with function and const entries must not hang the walker.

namespace Fixture\GroupedFunctionImport;

use Fixture\GroupedFunctionImport\Support\{function helper, const FLAG, CountTrait};
use PHPUnit\Framework\TestCase;

class FunctionImportTest extends TestCase
{
    use CountTrait;
}
