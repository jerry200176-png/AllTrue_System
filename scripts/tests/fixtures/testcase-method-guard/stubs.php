<?php
// Self-test stub: a Laravel-like TestCase that gets session() from a trait, like InteractsWithSession.
namespace Fixture\Stub;

trait InteractsWithSessionStub
{
    public function session(): void
    {
    }
}

abstract class LaravelLikeTestCase extends \PHPUnit\Framework\TestCase
{
    use InteractsWithSessionStub;
}
