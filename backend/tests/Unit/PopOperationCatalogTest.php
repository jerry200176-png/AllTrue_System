<?php

namespace Tests\Unit;

use App\Operations\PopOperationCatalog;
use PHPUnit\Framework\TestCase;

final class PopOperationCatalogTest extends TestCase
{
    public function test_catalog_version_is_readable(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');

        self::assertSame(1, $catalog->version());
    }

    public function test_policy_version_is_required_from_the_default_policy(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');

        self::assertSame(1, $catalog->policyVersion());
    }

    public function test_course_contract_repair_is_active_and_binds_to_pi_local_execution(): void
    {
        $entry = (new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml'))->operation('course-contract-repair');

        self::assertSame('active', $entry['lifecycle']);
        self::assertSame('pop-pi-local', $entry['execution_authority']);
        self::assertSame('critical-dual-approval', $entry['approval_policy']);
        self::assertContains('verify', $entry['capabilities']);
        self::assertContains('rollback', $entry['capabilities']);
    }
}
