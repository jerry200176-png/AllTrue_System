<?php

namespace Tests\Unit;

use App\Support\ClassTypeCapacity;
use PHPUnit\Framework\TestCase;

class ClassTypeCapacityTest extends TestCase
{
    public function test_capacity_map_and_defaults(): void
    {
        $this->assertSame(1, ClassTypeCapacity::for('one_on_one'));
        $this->assertSame(2, ClassTypeCapacity::for('one_on_two'));
        $this->assertSame(3, ClassTypeCapacity::for('one_on_three'));
        $this->assertSame(4, ClassTypeCapacity::for('tutoring'));
        $this->assertSame(1, ClassTypeCapacity::for('trial'));
        $this->assertSame(1, ClassTypeCapacity::for(null));
        $this->assertSame(1, ClassTypeCapacity::for(''));
        $this->assertSame(1, ClassTypeCapacity::for('unknown'));
    }
}
