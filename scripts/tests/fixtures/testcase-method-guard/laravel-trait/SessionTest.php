<?php
// Fixture, tokenized only: session() collides with a method the parent gets from a trait.

namespace Fixture\LaravelTrait;

use Fixture\Stub\LaravelLikeTestCase;

class SessionTest extends LaravelLikeTestCase
{
    private function session(string $start): string
    {
        return $start;
    }
}
