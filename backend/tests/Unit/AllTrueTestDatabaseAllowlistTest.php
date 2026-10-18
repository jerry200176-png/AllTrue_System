<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../Support/alltrueTestDatabase.php';

/**
 * Aligns PHP allowlist with scripts/phpunit-isolated.sh validate_schema_name.
 * Trailing LF must not pass (PHP `$` would; `\z` + explicit CR/LF reject).
 */
class AllTrueTestDatabaseAllowlistTest extends TestCase
{
    public function test_allows_ci_exact_and_isolated_suffix(): void
    {
        $this->assertTrue(alltrue_is_allowed_test_database('AllTrue_test'));
        $this->assertTrue(alltrue_is_allowed_test_database('AllTrue_test_work_abc123'));
        $this->assertTrue(alltrue_is_allowed_test_database('AllTrue_test_a_b_c'));
    }

    public function test_rejects_production_empty_and_empty_suffix(): void
    {
        $this->assertFalse(alltrue_is_allowed_test_database(null));
        $this->assertFalse(alltrue_is_allowed_test_database(''));
        $this->assertFalse(alltrue_is_allowed_test_database('AllTrue'));
        $this->assertFalse(alltrue_is_allowed_test_database('AllTrue_prod'));
        $this->assertFalse(alltrue_is_allowed_test_database('AllTrue_test_'));
        $this->assertFalse(alltrue_is_allowed_test_database('AllTrue_test-dash'));
        $this->assertFalse(alltrue_is_allowed_test_database('prefix_AllTrue_test_x'));
    }

    public function test_rejects_trailing_lf_and_cr(): void
    {
        $this->assertFalse(alltrue_is_allowed_test_database("AllTrue_test_x\n"));
        $this->assertFalse(alltrue_is_allowed_test_database("AllTrue_test_x\r"));
        $this->assertFalse(alltrue_is_allowed_test_database("AllTrue_test_x\r\n"));
        $this->assertFalse(alltrue_is_allowed_test_database("AllTrue_test\n"));
        $this->assertFalse(alltrue_is_allowed_test_database("AllTrue_test_x\ny"));
    }

    public function test_rejects_overlong_name(): void
    {
        $tooLong = 'AllTrue_test_'.str_repeat('a', 64);
        $this->assertGreaterThan(64, strlen($tooLong));
        $this->assertFalse(alltrue_is_allowed_test_database($tooLong));
    }
}
