<?php

namespace App\Support;

final class SessionStatus
{
    public const SCHEDULED = 'scheduled';
    public const LEAVE_REQUESTED = 'leave_requested';
    public const LEAVE = 'leave';
    public const LEAVE_ADJUSTED = 'leave_adjusted';
    public const EXCUSED = 'excused';

    /** @return array<int, string> */
    public static function leaveFamily(): array
    {
        return [self::LEAVE, self::LEAVE_REQUESTED, self::LEAVE_ADJUSTED, self::EXCUSED];
    }

    public static function isLeaveLike(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::leaveFamily(), true);
    }

    public static function isPendingLeave(?string $status): bool
    {
        return strtolower(trim((string) $status)) === self::LEAVE_REQUESTED;
    }
}
