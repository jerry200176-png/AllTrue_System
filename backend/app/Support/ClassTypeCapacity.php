<?php

namespace App\Support;

/**
 * F8 authority: the only class_type -> seat-capacity map. Unknown/empty falls back
 * to one_on_one semantics (1).
 */
final class ClassTypeCapacity
{
    private const MAP = [
        'one_on_one' => 1,
        'one_on_two' => 2,
        'one_on_three' => 3,
        'tutoring' => 4,
        'trial' => 1,
    ];

    public static function for(?string $classType): int
    {
        return self::MAP[(string) ($classType ?: 'one_on_one')] ?? 1;
    }
}
