<?php

namespace Tests\Unit;

use App\Models\UserCampus;
use PHPUnit\Framework\TestCase;

/** #4431 review: the campus-retention guard counts a legacy NULL link as approved, like legacyCampusIds(). */
final class UserCampusApprovedTest extends TestCase
{
    public function test_only_an_explicit_zero_or_false_is_not_approved(): void
    {
        foreach ([null, 1, true, '1'] as $value) {
            self::assertTrue(UserCampus::isApproved($value), var_export($value, true));
        }
        foreach ([0, false, '0'] as $value) {
            self::assertFalse(UserCampus::isApproved($value), var_export($value, true));
        }
    }
}
