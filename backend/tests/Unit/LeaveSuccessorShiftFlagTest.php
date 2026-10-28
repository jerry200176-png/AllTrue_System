<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** #4210: the renewal-shift apply flag is on by default (Founder decision 2026-10-28) and the env override still turns it off. */
class LeaveSuccessorShiftFlagTest extends TestCase
{
    private const KEY = 'LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED';

    private function flag(?string $env): bool
    {
        $original = [$_ENV[self::KEY] ?? null, $_SERVER[self::KEY] ?? null];
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        if ($env !== null) {
            $_ENV[self::KEY] = $_SERVER[self::KEY] = $env;
        }
        try {
            return (bool) (require dirname(__DIR__, 2) . '/config/perfflags.php')['leave_successor_shift_apply_enabled'];
        } finally {
            unset($_ENV[self::KEY], $_SERVER[self::KEY]);
            if ($original[0] !== null) {
                $_ENV[self::KEY] = $original[0];
            }
            if ($original[1] !== null) {
                $_SERVER[self::KEY] = $original[1];
            }
        }
    }

    public function test_the_apply_flag_defaults_on(): void
    {
        $this->assertTrue($this->flag(null));
    }

    /** @dataProvider overrides */
    public function test_the_env_override_still_decides(string $env, bool $expected): void
    {
        $this->assertSame($expected, $this->flag($env));
    }

    /** @return array<string, array{string, bool}> */
    public static function overrides(): array
    {
        return ['false turns it off' => ['false', false], '0 turns it off' => ['0', false], 'true keeps it on' => ['true', true]];
    }
}
