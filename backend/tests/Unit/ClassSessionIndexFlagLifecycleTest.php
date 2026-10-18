<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithClassSessionIndexFlag;

class ClassSessionIndexFlagLifecycleTest extends TestCase
{
    /** @dataProvider originalChannels */
    public function test_teardown_restores_each_channel_after_repeated_enable($process, $env, $server): void
    {
        $key = 'APPLY_CLASS_SESSION_UNIQUE_INDEX';
        $original = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        $set = static function ($p, $e, $s) use ($key): void {
            putenv($p === false ? $key : $key.'='.$p);
            if ($e === null) unset($_ENV[$key]); else $_ENV[$key] = $e;
            if ($s === null) unset($_SERVER[$key]); else $_SERVER[$key] = $s;
        };
        try {
            $set($process, $env, $server);
            $owner = new class {
                use WithClassSessionIndexFlag;
                public array $callbacks = [];
                public function beforeApplicationDestroyed(callable $callback): void { $this->callbacks[] = $callback; }
                public function enable(): void { $this->enableClassSessionIndexFlag(); }
            };
            $owner->enable(); $owner->enable();
            $this->assertSame('1', getenv($key));
            $this->assertSame('1', $_ENV[$key]);
            $this->assertSame('1', $_SERVER[$key]);
            $this->assertCount(1, $owner->callbacks, 'capture once, never restore a second-call value');
            foreach ($owner->callbacks as $restore) $restore();
            $this->assertSame($process, getenv($key));
            $this->assertSame($env, $_ENV[$key] ?? null);
            $this->assertSame($server, $_SERVER[$key] ?? null);
        } finally {
            $set(...$original);
        }
    }

    public static function originalChannels(): array
    {
        return [[false, null, null], ['0', '0', null], [false, 'legacy', '1']];
    }
}
