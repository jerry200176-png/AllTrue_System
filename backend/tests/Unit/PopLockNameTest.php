<?php

namespace Tests\Unit;

use App\Operations\PopLockName;
use Tests\TestCase;

class PopLockNameTest extends TestCase
{
    public function test_unit_tests_get_a_short_per_database_suffix(): void
    {
        $name = PopLockName::for('alltrue:pop:executor');
        $this->assertMatchesRegularExpression('/^alltrue:pop:executor:[0-9a-f]{8}$/', $name);
        $this->assertLessThanOrEqual(64, strlen(PopLockName::for('alltrue:pop:' . str_repeat('x', 36))));
    }

    public function test_production_lock_names_are_byte_identical_to_the_unsuffixed_names(): void
    {
        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests());
        $this->assertSame('alltrue:pop:executor', PopLockName::for('alltrue:pop:executor'));
        $this->assertSame('alltrue:pop:standing-policy', PopLockName::for('alltrue:pop:standing-policy'));
    }
}
