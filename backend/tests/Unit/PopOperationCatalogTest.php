<?php

namespace Tests\Unit;

use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ReflectionMethod;

final class PopOperationCatalogTest extends TestCase
{
    public function test_catalog_version_is_readable(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');

        self::assertSame(2, $catalog->version());
    }

    public function test_policy_version_is_required_from_the_default_policy(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');

        self::assertSame(2, $catalog->policyVersion());
    }

    public function test_course_contract_repair_is_active_and_binds_to_pi_local_execution(): void
    {
        $entry = (new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml'))->operation('course-contract-repair');

        self::assertSame('active', $entry['lifecycle']);
        self::assertSame('pop-pi-local', $entry['execution_authority']);
        self::assertSame('founder-explicit-single-repair', $entry['approval_policy']);
        self::assertSame(['super_admin'], $entry['approver_roles']);
        self::assertTrue($entry['founder_approval_required']);
        self::assertContains('verify', $entry['capabilities']);
        self::assertContains('rollback', $entry['capabilities']);
    }

    public function test_founder_scoped_policy_is_supported_only_for_the_safe_course_repair_shape(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $method = new ReflectionMethod($service, 'approvalRoles');
        $method->setAccessible(true);

        self::assertSame(['super_admin'], $method->invoke($service, $catalog->operation('course-contract-repair')));
        self::assertSame(['director', 'super_admin'], $method->invoke($service, [
            'id' => 'other-operation',
            'approval_policy' => 'critical-dual-approval',
            'approver_roles' => ['director', 'super_admin'],
        ]));

        $invalid = $catalog->operation('course-contract-repair');
        $invalid['blast_radius'] = 'multi_row';
        $this->expectException(RuntimeException::class);
        $method->invoke($service, $invalid);
    }

    public function test_founder_scoped_approval_reference_requires_explicit_founder_go_marker(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $method = new ReflectionMethod($service, 'assertFounderApprovalReference');
        $method->setAccessible(true);
        $entry = $catalog->operation('course-contract-repair');

        $this->expectException(RuntimeException::class);
        $method->invoke($service, $entry, 'director-approval-123');
    }
}
